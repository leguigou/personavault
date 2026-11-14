<?php
declare(strict_types=1);

/*
 * Discussion : envoi d’un message et réponse en streaming (SSE).
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

/* --- envoi d'un message en streaming (SSE) --- */
if ($action === 'stream' && $method === 'POST') {
    csrf_check_json();
    session_write_close(); // le flux peut durer plusieurs minutes : on libère la session
    $regen = ((string) ($_POST['regen'] ?? '')) === '1';
    $reactionMid = $regen ? 0 : max(0, (int) ($_POST['reaction_mid'] ?? 0));
    $reactionOnly = $reactionMid > 0;
    $text  = trim((string) ($_POST['message'] ?? ''));
    $hasImage = !$regen && !$reactionOnly && upload_present($_FILES['image'] ?? null);
    if (!$regen && !$reactionOnly && $text === '' && !$hasImage) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.message.empty_send')]);
        exit;
    }

    $chatLock = chat_require_write_lock($cid);
    // Relire la conversation sous verrou : un autre flux a pu finir depuis le routage.
    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$cid]);
    $current = $stmt->fetch();
    if (!$current) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('notfound.title')]);
        exit;
    }
    $conv = chat_with_persona($current, $persona);

    ignore_user_abort(true);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $sse = static function (array $payload): void {
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    };
    $ping = static function (): bool {
        echo ": ping\n\n";
        flush();
        return connection_aborted() !== 0;
    };

    $reactionTarget = null;
    $userMsgId = 0;
    if ($reactionOnly) {
        $stmt = db()->prepare("SELECT id, role, content, reaction, reaction_pending FROM messages
                                WHERE conversation_id = ? AND id = ? AND role = 'assistant'
                                  AND version_id = ? AND reaction_pending = ? AND reaction_pending <> '' AND reaction = ?");
        $revision = $_POST['reaction_revision'] ?? '';
        $stmt->execute([$cid, $reactionMid, (int) ($_POST['reaction_version'] ?? 0),
            is_string($revision) ? $revision : '', chat_reaction_key($_POST['reaction'] ?? '', 'assistant')]);
        $reactionTarget = $stmt->fetch() ?: null;
        if ($reactionTarget === null) {
            $sse(['done' => true, 'skipped' => true]);
            exit;
        }
        $text = '';
        $afterId = (int) db()->query('SELECT MAX(id) FROM messages WHERE conversation_id = ' . $cid)->fetchColumn();
        $sse(['start' => true, 'after_id' => $afterId]);
    }

    if ($regen) {
        $ust = db()->prepare("SELECT id, content, image FROM messages WHERE conversation_id = ? AND role = 'user' ORDER BY id DESC LIMIT 1");
        $ust->execute([$cid]);
        $lastUser = $ust->fetch();
        if (!$lastUser) {
            $sse(['error' => t('error.message.nothing_to_regen'), 'kind' => 'regen']);
            exit;
        }
        $text      = (string) $lastUser['content'];
        $userMsgId = (int) $lastUser['id'];
        $regenImage = (string) $lastUser['image'];
        $stmt = db()->prepare("SELECT MAX(id) FROM messages WHERE conversation_id = ? AND role = 'assistant' AND reaction_reply = 0 AND id > ?");
        $stmt->execute([$cid, $userMsgId]);
        $previousReplyId = (int) $stmt->fetchColumn();
        $regenMid = (int) ($_POST['regen_mid'] ?? 0);
        $regenReply = (int) ($_POST['regen_reply'] ?? 0);
        if (($regenMid > 0 && $regenMid !== $userMsgId) || ($regenReply > 0 && $regenReply !== $previousReplyId)) {
            $sse(['error' => t('error.chat.reply_changed'), 'kind' => 'regen']);
            exit;
        }
        $sse(['start' => true, 'regen' => true, 'user_id' => $userMsgId, 'after_id' => max($userMsgId, $previousReplyId)]);
    }

    if ($ping()) {
        exit;
    }

    // modèle chargé avec la bonne taille de mémoire AVANT tout appel (compression comprise) :
    // sinon LM Studio le charge avec sa taille par défaut et coupe l'historique sans prévenir
    try {
        $conv = chat_fit_context(
            $conv,
            static function () use ($sse): void {
                $sse(['phase' => 'loading']);
            },
            $ping
        )['conv'];
    } catch (Throwable $e) {
        // jamais bloquant : la requête de discussion dira elle-même ce qui ne va pas
    }
    if ($ping()) {
        exit;
    }

    if (!$regen && !$reactionOnly) {
        try {
            $mem = chat_memory_stats($cid, $conv);
            // même critère que chat_compress : les messages pas encore résumés
            if ($mem['recent_tokens'] >= chat_memory_threshold()) {
                // la compression peut prendre du temps : on le dit à l'interface, et des
                // commentaires SSE réguliers gardent la connexion vivante derrière un proxy
                $sse(['phase' => 'memory']);
                $cres = chat_compress($cid, $conv, false, $ping);
                if (($cres['ok'] ?? false) && ($cres['compressed'] ?? 0) > 0) {
                    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
                    $stmt->execute([$cid]);
                    // réappliquer les réglages du persona (modèle, fenêtre, consigne…)
                    $conv = chat_with_persona($stmt->fetch(), $persona);
                }
            }
        } catch (Throwable $e) {
            // la compression ne bloque jamais la réponse
        }
    }

    // image jointe (vision) : stockée en WebP, envoyée au modèle en base64
    $imageBase = '';
    if ($hasImage) {
        try {
            $imageBase = store_upload($_FILES['image']);
        } catch (Throwable $e) {
            $sse(['error' => t('error.image.refused', ['error' => public_error($e)]), 'kind' => 'image']);
            exit;
        }
    }

    // uniquement ce qui n'est pas déjà dans le résumé (+ léger recouvrement)
    $history = fetch_context_messages($cid, $conv);
    if ($regen) {
        // régénérer (ou rejouer après modification) un message avec photo : on la renvoie
        if ($regenImage !== '') {
            $imageBase = $regenImage;
        }
        $history = array_values(array_filter($history, static fn (array $m): bool => (int) $m['id'] < $userMsgId));
    }

    $imageDataUrl = $imageBase !== '' ? media_data_url($imageBase) : '';

    // une photo dans les derniers messages ? on vérifie (une seule fois) si le modèle lit les images
    $historyVision = false;
    foreach (array_slice($history, -6) as $h) {
        if ((string) ($h['image'] ?? '') !== '') {
            $wantedModel   = (string) chat_llm_config_for($conv)['model'];
            $historyVision = (lms_status()['types'][$wantedModel] ?? '') === 'vlm';
            break;
        }
    }
    $canReact = !$regen && !$reactionOnly && chat_can_react($cid);
    $built = chat_build_messages($persona, $personaPrompts, $conv, $history, $text, $imageDataUrl, $historyVision, $canReact, $reactionTarget);

    if ($ping()) {
        if (!$regen && $imageBase !== '') {
            release_media($imageBase);
        }
        exit;
    }

    if (!$regen && !$reactionOnly) {
        $ins = db()->prepare('INSERT INTO messages (conversation_id, role, content, image) VALUES (?, "user", ?, ?)');
        $ins->execute([$cid, $text, $imageBase]);
        $userMsgId = (int) db()->lastInsertId();
        $sse(['start' => true, 'user' => [
            'id'      => $userMsgId,
            'role'    => 'user',
            'content' => $text,
            'image'   => $imageBase,
        ]]);
    }

    $mode    = ((string) ($_POST['fallback'] ?? '')) === '1' ? 'fallback' : 'auto';
    $sentAny = false;

    // bouton « Arrêter » : le client coupe la connexion. connection_aborted() ne le voit
    // qu'après une écriture : on envoie un commentaire SSE (ignoré par le client) toutes
    // les 2 s, y compris pendant que le modèle « réfléchit » sans rien émettre.
    $lastPing    = microtime(true);
    $shouldAbort = static function () use (&$lastPing, $ping): bool {
        if (microtime(true) - $lastPing >= 2.0) {
            $lastPing = microtime(true);
            return $ping();
        }
        return connection_aborted() !== 0;
    };

    $runStream = static function () use ($built, $conv, $sse, &$sentAny, $mode, $shouldAbort, $canReact): array {
        $filtered = new ChatReactionStream($canReact);
        $res = chat_reply_stream(
            $built['messages'],
            $conv,
            static function (string $delta) use ($sse, &$sentAny, $filtered): void {
                $delta = $filtered->push($delta);
                if ($delta === '') {
                    return;
                }
                $sentAny = true;
                $sse(['delta' => $delta]);
            },
            $mode,
            $shouldAbort
        );
        $tail = $filtered->finish();
        if ($tail !== '') {
            $sentAny = true;
            $sse(['delta' => $tail]);
        }
        $res['reaction'] = $filtered->reaction((string) $res['content']);
        $res['content'] = trim($filtered->content);
        if ($res['content'] === '' && empty($res['aborted'])) {
            throw new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
        }
        return $res;
    };

    try {
        try {
            $res = $runStream();
        } catch (Throwable $e) {
            // le tout premier appel qui suit le chargement d'un modèle de vision revient
            // parfois vide (encodeur d'images qui s'initialise) : on retente une fois
            if ($sentAny || $e->getCode() !== LLM_ERR_EMPTY) {
                throw $e;
            }
            $res = $runStream();
        }
    } catch (Throwable $e) {
        $kind = $e instanceof LlmUnavailable ? $e->kind : 'error';
        if (!$sentAny && !$regen && !$reactionOnly && $imageBase === '') {
            // rien n'a été produit : on retire le message pour permettre un nouvel essai propre
            db()->prepare('DELETE FROM messages WHERE id = ?')->execute([$userMsgId]);
        }
        $sse([
            'error' => public_error($e),
            'kind' => $kind,
            'partial' => $sentAny,
            'kept_user' => !$regen && !$reactionOnly && ($sentAny || $imageBase !== ''),
        ]);
        exit;
    }

    $aborted = !empty($res['aborted']);
    if ($aborted) {
        $res['content'] = trim((string) $res['content']);
        if ($res['content'] === '') {
            exit;
        }
    }

    $splitPoints = !$aborted && (string) setting('chat_split_enabled', '1') === '1'
        ? chat_split_points(
            (string) $res['content'],
            (int) setting('chat_split_words', '50'),
            (int) setting('chat_split_max_bubbles', '2')
        )
        : [];

    try {
        [$assistantId, $versions, $notice, $versionId] = db_tx(static function (PDO $pdo) use ($cid, $regen, $reactionOnly, $userMsgId, $res, $splitPoints, $aborted, $conv, $reactionTarget): array {
            if ($reactionOnly) {
                $active = $pdo->prepare('SELECT 1 FROM messages WHERE id = ? AND reaction_pending = ?');
                $active->execute([$reactionTarget['id'], $reactionTarget['reaction_pending']]);
                if (!$active->fetchColumn()) {
                    return [0, null, null, 0];
                }
            }
            if ($regen) {
                // L'ancienne réponse reste visible jusqu'à la validation de la transaction.
                reply_version_archive($cid, $userMsgId);
                $pdo->prepare("DELETE FROM messages WHERE conversation_id = ? AND role = 'assistant' AND reaction_reply = 0 AND id > ?")
                    ->execute([$cid, $userMsgId]);
            }

            $pdo->prepare('INSERT INTO messages (conversation_id, role, content, model, prompt_tokens, completion_tokens, latency_ms, split_points, reaction_reply)
                           VALUES (?, "assistant", ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $cid, $res['content'], $res['model'], (int) $res['prompt_tokens'], (int) $res['completion_tokens'],
                    (int) $res['ms'], $splitPoints ? json_encode($splitPoints) : '', $reactionOnly ? 1 : 0,
                ]);
            $assistantId = (int) $pdo->lastInsertId();
            $versions = null;
            $versionId = 0;
            if ($regen) {
                reply_version_register($cid, $userMsgId, $assistantId);
                $stv = $pdo->prepare('SELECT * FROM messages WHERE id = ?');
                $stv->execute([$assistantId]);
                $versioned = $stv->fetch();
                $versions = reply_versions_info($cid, $versioned);
                $versionId = (int) $versioned['version_id'];
            }

            $notice = null;
            if ($aborted) {
                $pdo->prepare('UPDATE conversations SET updated_at = datetime("now") WHERE id = ?')->execute([$cid]);
            } else {
                if ($res['reaction'] !== '') {
                    $pdo->prepare("UPDATE messages SET reaction = ? WHERE id = ? AND role = 'user'")
                        ->execute([$res['reaction'], $userMsgId]);
                }
                if ($reactionOnly) {
                    $pdo->prepare("UPDATE messages SET reaction_pending = '' WHERE id = ?")->execute([$reactionTarget['id']]);
                }
                if (!empty($res['notice'])) {
                    $pdo->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "notice", ?)')
                        ->execute([$cid, $res['notice']]);
                    $notice = ['id' => (int) $pdo->lastInsertId(), 'role' => 'notice', 'content' => $res['notice']];
                }
                // Ne jamais écraser le modèle choisi par un modèle de secours.
                $storedModel = $res['provider'] === 'chat' ? (string) $res['model'] : (string) $conv['model'];
                $pdo->prepare('UPDATE conversations SET model = ?, provider = ?, updated_at = datetime("now") WHERE id = ?')
                    ->execute([$storedModel, $res['provider'], $cid]);
            }
            return [$assistantId, $versions, $notice, $versionId];
        });
    } catch (Throwable $e) {
        $sse(['error' => public_error($e), 'kind' => 'error', 'kept_user' => !$regen && !$reactionOnly]);
        exit;
    }
    if ($assistantId === 0) {
        $sse(['done' => true, 'skipped' => true]);
        exit;
    }
    if ($aborted) {
        exit;
    }

    $out = [
        'done'  => true,
        'reply' => [
            'id'       => $assistantId,
            'role'     => 'assistant',
            'content'  => $res['content'],
            'model'    => $res['model'],
            'split_points' => $splitPoints,
            'versions' => $versions,
            'version_id' => $versionId,
            'reaction_reply' => $reactionOnly,
        ],
        'user_reaction' => $res['reaction'] !== '' ? ['id' => $userMsgId, 'reaction' => $res['reaction']] : null,
        'meta'  => [
            'model'    => $res['model'],
            'ms'       => (int) $res['ms'],
            'tokens'   => (int) ($res['prompt_tokens'] + $res['completion_tokens']),
            'kept'     => $built['kept'],
            'provider' => $res['provider'],
        ],
    ];

    if ($notice !== null) {
        $out['notice'] = $notice;
    }

    fclose($chatLock);
    $sse($out);
    exit;
}
