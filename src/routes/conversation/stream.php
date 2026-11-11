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
    $text  = trim((string) ($_POST['message'] ?? ''));
    $hasImage = upload_present($_FILES['image'] ?? null);
    if (!$regen && $text === '' && !$hasImage) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.message.empty_send')]);
        exit;
    }

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

    $regenMid = (int) ($_POST['regen_mid'] ?? 0);

    if ($regen) {
        // on rejoue le dernier message de l'utilisateur (ou celui qu'on vient de modifier)
        if ($regenMid > 0) {
            $ust = db()->prepare("SELECT id, content FROM messages WHERE conversation_id = ? AND role = 'user' AND id = ?");
            $ust->execute([$cid, $regenMid]);
        } else {
            $ust = db()->prepare("SELECT id, content FROM messages WHERE conversation_id = ? AND role = 'user' ORDER BY id DESC LIMIT 1");
            $ust->execute([$cid]);
        }
        $lastUser = $ust->fetch();
        if (!$lastUser) {
            $sse(['error' => t('error.message.nothing_to_regen'), 'kind' => 'regen']);
            exit;
        }
        $text      = (string) $lastUser['content'];
        $userMsgId = (int) $lastUser['id'];
        // photo jointe à ce message : relue en base (le message n'est pas toujours dans
        // l'historique envoyé, par exemple s'il a déjà été résumé dans la mémoire)
        $imgSt = db()->prepare('SELECT image FROM messages WHERE id = ?');
        $imgSt->execute([$userMsgId]);
        $regenImage = (string) $imgSt->fetchColumn();
        // l'ancienne réponse n'est pas perdue : elle devient une version (‹ 1/2 ›)
        reply_version_archive($cid, $userMsgId);
        db()->prepare("DELETE FROM messages WHERE conversation_id = ? AND role = 'assistant' AND id > ?")
            ->execute([$cid, $userMsgId]);
    }

    if (!$regen) {
        try {
            $mem = chat_memory_stats($cid, $conv);
            // même critère que chat_compress : les messages pas encore résumés
            if ($mem['recent_tokens'] >= chat_memory_threshold()) {
                // la compression peut prendre du temps : on le dit à l'interface, et des
                // commentaires SSE réguliers gardent la connexion vivante derrière un proxy
                $sse(['phase' => 'memory']);
                $cres = chat_compress($cid, $conv, false, static function (): void {
                    echo ": ping\n\n";
                    flush();
                });
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
        $history = array_values(array_filter($history, static fn (array $m): bool => (int) $m['id'] !== $userMsgId));
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
    $built = chat_build_messages($persona, $personaPrompts, $conv, $history, $text, $imageDataUrl, $historyVision);

    if (!$regen) {
        $ins = db()->prepare('INSERT INTO messages (conversation_id, role, content, image) VALUES (?, "user", ?, ?)');
        $ins->execute([$cid, $text, $imageBase]);
        $userMsgId = (int) db()->lastInsertId();
        $sse(['start' => true, 'user' => [
            'id'      => $userMsgId,
            'role'    => 'user',
            'content' => $text,
            'image'   => $imageBase,
        ]]);
    } else {
        $sse(['start' => true, 'regen' => true]);
    }

    $mode    = ((string) ($_POST['fallback'] ?? '')) === '1' ? 'fallback' : 'auto';
    $sentAny = false;

    // bouton « Arrêter » : le client coupe la connexion. connection_aborted() ne le voit
    // qu'après une écriture : on envoie un commentaire SSE (ignoré par le client) toutes
    // les 2 s, y compris pendant que le modèle « réfléchit » sans rien émettre.
    $lastPing    = microtime(true);
    $shouldAbort = static function () use (&$lastPing): bool {
        if (microtime(true) - $lastPing >= 2.0) {
            $lastPing = microtime(true);
            echo ": ping\n\n";
            flush();
        }
        return connection_aborted() === 1;
    };

    $runStream = static function () use ($built, $conv, $sse, &$sentAny, $mode, $shouldAbort): array {
        return chat_reply_stream(
            $built['messages'],
            $conv,
            static function (string $delta) use ($sse, &$sentAny): void {
                $sentAny = true;
                $sse(['delta' => $delta]);
            },
            $mode,
            $shouldAbort
        );
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
        if (!$sentAny && !$regen && $imageBase === '') {
            // rien n'a été produit : on retire le message pour permettre un nouvel essai propre
            db()->prepare('DELETE FROM messages WHERE id = ?')->execute([$userMsgId]);
        }
        if ($regen) {
            // rien d'enregistré : la réponse précédente revient (visible au rechargement)
            reply_version_restore_last($cid, $userMsgId);
        }
        $sse(['error' => public_error($e), 'kind' => $kind, 'partial' => $sentAny]);
        exit;
    }

    // génération interrompue par l'utilisateur : le client garde à l'écran le texte
    // déjà reçu, on enregistre donc ce partiel (et rien si rien n'a été produit)
    if (!empty($res['aborted'])) {
        $partial = trim((string) $res['content']);
        if ($partial !== '') {
            db()->prepare('INSERT INTO messages (conversation_id, role, content, model, prompt_tokens, completion_tokens, latency_ms)
                               VALUES (?, "assistant", ?, ?, 0, 0, ?)')
                ->execute([$cid, $partial, (string) $res['model'], (int) $res['ms']]);
            if ($regen) {
                reply_version_register($cid, $userMsgId, (int) db()->lastInsertId());
            }
            db()->prepare('UPDATE conversations SET updated_at = datetime("now") WHERE id = ?')->execute([$cid]);
        } elseif ($regen) {
            reply_version_restore_last($cid, $userMsgId);
        }
        exit;
    }

    $splitPoints = (string) setting('chat_split_enabled', '1') === '1'
        ? chat_split_points(
            (string) $res['content'],
            (int) setting('chat_split_words', '50'),
            (int) setting('chat_split_max_bubbles', '2')
        )
        : [];

    $stmt = db()->prepare('INSERT INTO messages (conversation_id, role, content, model, prompt_tokens, completion_tokens, latency_ms, split_points)
                               VALUES (?, "assistant", ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $cid,
        $res['content'],
        $res['model'],
        (int) $res['prompt_tokens'],
        (int) $res['completion_tokens'],
        (int) $res['ms'],
        $splitPoints ? json_encode($splitPoints) : '',
    ]);
    $assistantId = (int) db()->lastInsertId();
    $versions    = null;
    if ($regen) {
        reply_version_register($cid, $userMsgId, $assistantId);
        $stv = db()->prepare('SELECT * FROM messages WHERE id = ?');
        $stv->execute([$assistantId]);
        $versions = reply_versions_info($cid, (array) $stv->fetch());
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
        ],
        'meta'  => [
            'model'    => $res['model'],
            'ms'       => (int) $res['ms'],
            'tokens'   => (int) ($res['prompt_tokens'] + $res['completion_tokens']),
            'kept'     => $built['kept'],
            'provider' => $res['provider'],
        ],
    ];

    if (!empty($res['notice'])) {
        $stmt = db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "notice", ?)');
        $stmt->execute([$cid, $res['notice']]);
        $out['notice'] = ['id' => (int) db()->lastInsertId(), 'role' => 'notice', 'content' => $res['notice']];
    }

    // ne jamais écraser le modèle choisi par un modèle de secours
    $storedModel = $res['provider'] === 'chat' ? (string) $res['model'] : (string) $conv['model'];
    db()->prepare('UPDATE conversations SET model = ?, provider = ?, updated_at = datetime("now") WHERE id = ?')
        ->execute([$storedModel, $res['provider'], $cid]);

    $sse($out);
    exit;
}
