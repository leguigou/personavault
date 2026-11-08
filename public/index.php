<?php
declare(strict_types=1);

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$parts  = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
$p0     = $parts[0] ?? '';

/* ---------------------------------------------------------------- assets */
// Servis AVANT le bootstrap : ni session, ni cookie, ni base de données.

if ($p0 === 'assets') {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    $file = basename($parts[1] ?? '');
    $full = __DIR__ . '/assets/' . $file;
    if ($file === '' || $file[0] === '.' || !is_file($full)) {
        http_response_code(404);
        exit;
    }
    $ext   = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $types = [
        'css'         => 'text/css; charset=utf-8',
        'js'          => 'text/javascript; charset=utf-8',
        'svg'         => 'image/svg+xml',
        'png'         => 'image/png',
        'ico'         => 'image/x-icon',
        'webp'        => 'image/webp',
        'json'        => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
    ];
    $type = $types[$ext] ?? 'application/octet-stream';

    $mtime = (int) filemtime($full);
    $etag  = '"' . dechex($mtime) . '-' . dechex((int) filesize($full)) . '"';
    header('Content-Type: ' . $type);
    // URL versionnée (?v=…) : immuable un an ; sinon une heure
    header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600'));
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);

    $compressible = in_array($ext, ['css', 'js', 'svg', 'json', 'webmanifest'], true);
    if ($compressible) {
        header('Vary: Accept-Encoding');
    }
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    $body = (string) file_get_contents($full);
    if ($compressible && function_exists('gzencode')
        && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')) {
        $gz = gzencode($body, 6);
        if ($gz !== false) {
            $body = $gz;
            header('Content-Encoding: gzip');
        }
    }
    header('Content-Length: ' . strlen($body));
    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

/* ------------------------------------------------------- envoi trop lourd */
// Au-delà de post_max_size, PHP vide $_POST et $_FILES : sans ce contrôle, l'utilisateur
// verrait « Session expirée » (jeton CSRF absent) au lieu de la vraie cause.

if ($method === 'POST' && post_too_large()) {
    $msg = post_too_large_message();
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'json')
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'event-stream');
    http_response_code(413);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (is_logged()) {
        flash('error', $msg);
        header('Location: ' . safe_referer_path('/'), true, 303);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

/* ------------------------------------------------------------------ média */

if ($p0 === 'media' && $method === 'GET') {
    require_login();
    session_write_close();
    $file = rawurldecode($parts[1] ?? '');
    if (!preg_match('/^p[a-f0-9]{16}(\.thumb)?\.webp$/', $file)) {
        http_response_code(404);
        exit;
    }
    $full = (string) config('uploads') . '/' . $file;
    if (!is_file($full)) {
        http_response_code(404);
        exit;
    }
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: image/webp');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($full));
    readfile($full);
    exit;
}

/* ------------------------------------------------------------------ login */

if ($path === '/login') {
    if (is_logged()) {
        redirect('/');
    }
    $error = '';
    if ($method === 'POST') {
        csrf_check();
        $wait = login_blocked_for();
        if ($wait > 0) {
            $error = 'Trop de tentatives. Réessaie dans ' . ceil($wait / 60) . ' min.';
        } elseif (attempt_login((string) ($_POST['password'] ?? ''))) {
            redirect('/');
        } else {
            $error = 'Mot de passe incorrect.';
            $wait  = login_blocked_for();
            if ($wait > 0) {
                $error .= ' Trop de tentatives : réessaie dans ' . ceil($wait / 60) . ' min.';
            }
        }
    }
    render('login', ['title' => 'Connexion', 'error' => $error]);
    exit;
}

if ($path === '/logout' && $method === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    redirect('/login');
}

require_login();

/* --------------------------------------------------------------------- chat */

if ($path === '/chat/models' && $method === 'GET') {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['models' => chat_models()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/chat' && $method === 'GET') {
    $convStmt = db()->query(
        'SELECT c.*, p.name AS persona_name, p.avatar AS persona_avatar, p.slug AS persona_slug,
                (SELECT content FROM messages m WHERE m.conversation_id = c.id AND m.role NOT IN ("notice", "memory") ORDER BY m.id DESC LIMIT 1) AS last_msg,
                (SELECT COUNT(*) FROM messages m2 WHERE m2.conversation_id = c.id AND m2.role NOT IN ("notice", "memory")) AS n
         FROM conversations c JOIN personas p ON p.id = c.persona_id
         ORDER BY c.updated_at DESC'
    );
    $personas = db()->query('SELECT id, slug, name, tagline, avatar, tags FROM personas WHERE archived = 0 ORDER BY name')->fetchAll();

    render('chat_list', [
        'title'       => 'Discussions',
        'conversations' => $convStmt->fetchAll(),
        'personas'    => $personas,
        'chatReady'   => chat_llm_ready(),
        'chatModel'   => chat_llm_config_raw()['model'],
    ]);
    exit;
}

if ($path === '/chat' && $method === 'POST') {
    csrf_check();
    $pid = (int) ($_POST['persona_id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM personas WHERE id = ? AND archived = 0');
    $stmt->execute([$pid]);
    $persona = $stmt->fetch();
    if (!$persona) {
        flash('error', 'Choisis un persona pour démarrer la discussion.');
        redirect('/chat');
    }
    $stmt = db()->prepare('INSERT INTO conversations (persona_id, title) VALUES (?, ?)');
    $stmt->execute([$pid, 'Discussion avec ' . $persona['name']]);
    redirect('/chat/' . (int) db()->lastInsertId());
}

if (preg_match('#^/chat/(\d+)(?:/([\w-]+))?$#', $path, $m)) {
    $cid = (int) $m[1];
    $action = $m[2] ?? '';

    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$cid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        http_response_code(404);
        render('notfound', ['title' => 'Introuvable']);
        exit;
    }

    $stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
    $stmt->execute([(int) $conv['persona_id']]);
    $persona = $stmt->fetch();
    if (!$persona) {
        http_response_code(404);
        render('notfound', ['title' => 'Introuvable']);
        exit;
    }

    $personaPrompts = fetch_prompts((int) $persona['id']);
    $conv           = chat_with_persona($conv, $persona);

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
            echo json_encode(['ok' => false, 'error' => 'Message vide.']);
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
                $sse(['error' => 'Aucun message à régénérer.', 'kind' => 'regen']);
                exit;
            }
            $text      = (string) $lastUser['content'];
            $userMsgId = (int) $lastUser['id'];
            db()->prepare("DELETE FROM messages WHERE conversation_id = ? AND role = 'assistant' AND id > ?")
                ->execute([$cid, $userMsgId]);
        }

        if (!$regen) {
            try {
                $mem = chat_memory_stats($cid, $conv);
                // même critère que chat_compress : les messages pas encore résumés
                if ($mem['recent_tokens'] >= chat_memory_threshold()) {
                    $cres = chat_compress($cid, $conv);
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
                $sse(['error' => 'Image refusée : ' . public_error($e), 'kind' => 'image']);
                exit;
            }
        }

        // uniquement ce qui n'est pas déjà dans le résumé (+ léger recouvrement)
        $history = fetch_context_messages($cid, $conv);
        if ($regen) {
            // régénérer un message qui contenait une image : on la renvoie
            foreach ($history as $h) {
                if ((int) $h['id'] === $userMsgId && (string) ($h['image'] ?? '') !== '') {
                    $imageBase = (string) $h['image'];
                }
            }
            $history = array_values(array_filter($history, static fn (array $m): bool => (int) $m['id'] !== $userMsgId));
        }

        $imageDataUrl = $imageBase !== '' ? media_data_url($imageBase) : '';

        $built = chat_build_messages($persona, $personaPrompts, $conv, $history, $text, $imageDataUrl);

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
                if ($sentAny || stripos($e->getMessage(), 'Réponse vide') === false) {
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
                db()->prepare('UPDATE conversations SET updated_at = datetime("now") WHERE id = ?')->execute([$cid]);
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

        $out = [
            'done'  => true,
            'reply' => [
                'id'       => $assistantId,
                'role'     => 'assistant',
                'content'  => $res['content'],
                'model'    => $res['model'],
                'split_points' => $splitPoints,
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

    /* --- état du serveur / du modèle chargé (AJAX) --- */
    if ($action === 'status' && $method === 'GET') {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        $st     = lms_status();
        $wanted = (string) ($conv['model'] !== '' ? $conv['model'] : chat_llm_config()['model']);
        $ids    = array_column($st['loaded'], 'id');
        $keys   = array_column($st['loaded'], 'key');
        echo json_encode([
            'ok'        => $st['reachable'],
            'reachable' => $st['reachable'],
            'loaded'    => $st['loaded'],
            'total'     => (int) $st['total'],
            'wanted'    => $wanted,
            'url'       => lms_root(),
            'is_loaded' => in_array($wanted, $ids, true) || in_array($wanted, $keys, true),
            'vision'    => ($st['types'][$wanted] ?? '') === 'vlm',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- chargement manuel du modèle (AJAX) --- */
    if ($action === 'load' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json(['kind' => 'auth']);
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes

        $st = lms_status();
        if (!$st['reachable']) {
            echo json_encode([
                'ok'    => false,
                'kind'  => 'down',
                'error' => 'LM Studio ne répond pas : le serveur n’est pas lancé (ou l’adresse est fausse).',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $model = (string) ($conv['model'] !== '' ? $conv['model'] : chat_llm_config()['model']);
        if (!empty($st['available']) && !in_array($model, $st['available'], true)) {
            $alt = (string) chat_llm_config()['model'];
            $model = in_array($alt, $st['available'], true) ? $alt : '';
        }
        if ($model === '') {
            echo json_encode([
                'ok'    => false,
                'kind'  => 'not_found',
                'error' => 'Ce modèle n’est pas téléchargé dans LM Studio.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $ids   = array_column($st['loaded'], 'id');
        $keys  = array_column($st['loaded'], 'key');
        if (in_array($model, $ids, true) || in_array($model, $keys, true)) {
            echo json_encode(['ok' => true, 'already' => true, 'model' => $model], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $ctx = max(2048, (int) ($conv['context_window'] ?? 8192));
        $ok  = lms_load_async($model, $ctx);
        echo json_encode([
            'ok'      => $ok,
            'started' => $ok,
            'model'   => $model,
            'ctx'     => $ctx,
            'error'   => $ok ? null : 'Impossible de lancer le chargement.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- compression mémoire (AJAX, après la réponse) --- */
    if ($action === 'compress' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        try {
            $cres = chat_compress($cid, $conv, !empty($_POST['force']));
            $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
            $stmt->execute([$cid]);
            $fresh  = chat_with_persona($stmt->fetch(), $persona);
            $memory = chat_memory_stats($cid, $fresh);
            echo json_encode([
                'ok'         => $cres['ok'],
                'compressed' => $cres['compressed'] ?? 0,
                'reason'     => $cres['reason'] ?? '',
                'summary'    => (string) ($fresh['summary'] ?? ''),
                'memory'     => $memory,
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /* --- extraction des souvenirs durables (AJAX, après la réponse) --- */
    if ($action === 'remember' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        if ((int) ($conv['facts_enabled'] ?? 1) !== 1) {
            echo json_encode([
                'ok'       => true,
                'added'    => [],
                'skipped'  => 'souvenirs désactivés pour cette conversation',
                'memories' => chat_persona_memories((int) $persona['id']),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $cursor = (int) ($conv['facts_cursor'] ?? 0);
        $st = db()->prepare("SELECT * FROM messages WHERE conversation_id = ? AND id > ?
                             AND role IN ('user', 'assistant') ORDER BY id ASC");
        $st->execute([$cid, $cursor]);
        $recent = $st->fetchAll();

        // demande explicite : « souviens-toi », « retiens », « note que »…
        $lastUser = '';
        foreach (array_reverse($recent) as $m) {
            if ((string) $m['role'] === 'user') {
                $lastUser = (string) $m['content'];
                break;
            }
        }
        $explicit = (bool) preg_match(
            "/(souviens[- ]?toi|rappelle[- ]?toi|retiens|m[ée]morise|note (bien )?que|n'oublie pas|garde en m[ée]moire)/iu",
            $lastUser
        );

        // fréquence choisie dans les réglages (chaque échange, tous les N messages, sur demande…)
        $why = facts_should_run(count($recent), $explicit, !empty($_POST['force']));
        if ($why !== '') {
            echo json_encode(['ok' => true, 'added' => [], 'skipped' => $why, 'memories' => chat_persona_memories((int) $persona['id'])], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            // jusqu'à 30 messages : en mode « tous les N », rien n'est sauté
            $slice = array_slice($recent, -30);
            $res   = facts_extract($cid, (int) $persona['id'], $slice, $explicit);
            if (($res['ok'] ?? false) && $slice) {
                $lastId = (int) end($slice)['id'];
                db()->prepare('UPDATE conversations SET facts_cursor = ? WHERE id = ?')->execute([$lastId, $cid]);
            }
            $res['memories'] = chat_persona_memories((int) $persona['id']);

            // trace visible dans la conversation : la mémoire a bien été mise à jour
            $added = array_values(array_filter(array_map('strval', $res['added'] ?? [])));
            if ($added) {
                $label = implode(' · ', array_map(
                    static fn (string $s): string => mb_substr($s, 0, 90),
                    array_slice($added, 0, 2)
                ));
                $label = 'Mémoire mise à jour : ' . $label . (count($added) > 2 ? ' (+' . (count($added) - 2) . ')' : '');
                $ins   = db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "memory", ?)');
                $ins->execute([$cid, $label]);
                $res['memory_msg'] = ['id' => (int) db()->lastInsertId(), 'role' => 'memory', 'content' => $label];
            }

            echo json_encode($res, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /* --- mémoire : enregistrer / effacer le résumé --- */
    if ($action === 'memory' && $method === 'POST') {
        csrf_check();
        $op = (string) ($_POST['op'] ?? '');
        if ($op === 'clear') {
            db()->prepare('UPDATE conversations SET summary = "", last_compressed_id = 0, compressions = 0, updated_at = datetime("now") WHERE id = ?')
                ->execute([$cid]);
            flash('ok', 'Mémoire effacée : la prochaine compression repartira de zéro.');
        } elseif ($op === 'save') {
            $summary = trim((string) ($_POST['summary'] ?? ''));
            db()->prepare('UPDATE conversations SET summary = ?, updated_at = datetime("now") WHERE id = ?')
                ->execute([mb_substr($summary, 0, 4000), $cid]);
            flash('ok', 'Résumé enregistré.');
        }
        redirect('/chat/' . $cid);
    }

    /* --- options propres à la conversation (mémoire / relances) --- */
    if ($action === 'toggles' && $method === 'POST') {
        csrf_check();
        db()->prepare('UPDATE conversations SET memory_enabled = ?, auto_followup = ?, updated_at = datetime("now") WHERE id = ?')
            ->execute([
                !empty($_POST['memory_enabled']) ? 1 : 0,
                !empty($_POST['auto_followup']) ? 1 : 0,
                $cid,
            ]);
        flash('ok', 'Réglages de la conversation enregistrés.');
        redirect('/chat/' . $cid);
    }

    /* --- réglages de la conversation --- */
    if ($action === 'settings' && $method === 'POST') {
        csrf_check();
        $temp = (float) ($_POST['temperature'] ?? 0.85);
        $temp = max(0.0, min(2.0, $temp));
        $maxTok = max(64, min(4096, (int) ($_POST['max_tokens'] ?? 400)));
        $ctx   = max(2048, min(131072, (int) ($_POST['context_window'] ?? 8192)));
        $model = trim((string) ($_POST['model'] ?? ''));

        db()->prepare('UPDATE conversations SET model = ?, temperature = ?, max_tokens = ?, context_window = ?, memory_enabled = ?, auto_followup = ?, directive = ?, updated_at = datetime("now") WHERE id = ?')
            ->execute([
                $model,
                $temp,
                $maxTok,
                $ctx,
                !empty($_POST['memory_enabled']) ? 1 : 0,
                !empty($_POST['auto_followup']) ? 1 : 0,
                trim((string) ($_POST['directive'] ?? '')),
                $cid,
            ]);
        $changed = $model !== '' && $model !== (string) $conv['model'];
        if ($model !== '') {
            set_setting('chat_llm_model', $model);
        }
        $purged = $changed ? lms_purge() : [];
        flash('ok', 'Réglages de la conversation enregistrés.'
            . ($purged ? ' Mémoire du serveur purgée.' : ''));
        if ($changed) {
            db()->prepare('UPDATE conversations SET model = ? WHERE id = ?')->execute([$model, $cid]);
        }
        redirect('/chat/' . $cid);
    }

    if ($action === 'rename' && $method === 'POST') {
        csrf_check();
        $title = trim((string) ($_POST['title'] ?? ''));
        db()->prepare('UPDATE conversations SET title = ?, title_auto = 0, updated_at = datetime("now") WHERE id = ?')
            ->execute([$title !== '' ? mb_substr($title, 0, 120) : $conv['title'], $cid]);
        redirect('/chat/' . $cid);
    }

    if ($action === 'delete' && $method === 'POST') {
        csrf_check();
        $stmt = db()->prepare("SELECT image FROM messages WHERE conversation_id = ? AND image <> ''");
        $stmt->execute([$cid]);
        $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
        db()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$cid]);
        release_media(...array_map('strval', $images)); // photos envoyées dans le chat
        flash('ok', 'Conversation supprimée.');
        redirect('/chat');
    }

    /* --- titre automatique de la conversation --- */
    if ($action === 'autotitle' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        try {
            $title = chat_auto_title($cid);
            echo json_encode($title ? ['ok' => true, 'title' => $title] : ['ok' => false], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /* --- recherche dans toutes les conversations du même persona --- */
    if ($action === 'search' && $method === 'GET') {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            echo json_encode(['ok' => true, 'results' => []]);
            exit;
        }

        $rows = chat_persona_search((int) $persona['id'], $q, 10);
        $out  = [];
        foreach ($rows as $r) {
            $content = trim((string) $r['content']);
            $pos   = mb_stripos($content, $q);
            if ($pos === false) {
                // recherche par mots : on centre l'extrait sur le premier mot présent
                foreach (text_words($q, 2) as $w) {
                    $pos = mb_stripos($content, $w);
                    if ($pos !== false) {
                        break;
                    }
                }
            }
            $start = $pos !== false ? max(0, $pos - 70) : 0;
            if ($start > 0) {
                // on recale sur un début de mot
                $space = mb_strpos($content, ' ', $start);
                if ($space !== false && $space - $start < 25) {
                    $start = $space + 1;
                }
            }
            $excerpt = ($start > 0 ? '…' : '') . mb_substr($content, $start, 220) . (mb_strlen($content) > $start + 220 ? '…' : '');
            $out[] = [
                'id'              => (int) $r['id'],
                'conversation_id' => (int) $r['conversation_id'],
                'title'           => (string) $r['title'],
                'role'            => (string) $r['role'],
                'date'            => fr_date((string) $r['created_at']),
                'excerpt'         => $excerpt,
                'content'         => mb_substr($content, 0, 500),
            ];
        }
        echo json_encode(['ok' => true, 'results' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- activer/désactiver les souvenirs partagés sur cette conversation --- */
    if ($action === 'facts-toggle' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        $on = ((string) ($_POST['enabled'] ?? '')) === '1' ? 1 : 0;
        db()->prepare('UPDATE conversations SET facts_enabled = ? WHERE id = ?')->execute([$on, $cid]);
        echo json_encode(['ok' => true, 'enabled' => $on], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- souvenirs partagés : ajout / suppression depuis la conversation --- */
    if (($action === 'fact-add' || $action === 'fact-delete') && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        $pid = (int) $persona['id'];

        if ($action === 'fact-add') {
            $content = trim((string) ($_POST['content'] ?? ''));
            if ($content === '') {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Souvenir vide.']);
                exit;
            }
            $cat = (string) ($_POST['category'] ?? 'fait');
            if (!array_key_exists($cat, facts_categories())) {
                $cat = 'fait';
            }
            $id = facts_add($pid, $content, $cat, 'confirme', $cid);
            echo json_encode(['ok' => true, 'id' => $id, 'memories' => chat_persona_memories($pid)], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $fid  = (int) ($_POST['fid'] ?? 0);
        $stmt = db()->prepare('SELECT persona_id FROM user_memories WHERE id = ?');
        $stmt->execute([$fid]);
        $owner = $stmt->fetchColumn();
        if ($owner === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Souvenir introuvable.']);
            exit;
        }
        if ($owner !== null && (int) $owner !== $pid) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Ce souvenir appartient à un autre persona.']);
            exit;
        }
        facts_delete($fid);
        echo json_encode(['ok' => true, 'memories' => chat_persona_memories($pid)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- modifier un message --- */
    if ($action === 'msg-edit' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        $mid     = (int) ($_POST['mid'] ?? 0);
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Le message ne peut pas être vide.']);
            exit;
        }

        $stmt = db()->prepare('SELECT * FROM messages WHERE id = ? AND conversation_id = ?');
        $stmt->execute([$mid, $cid]);
        $msg = $stmt->fetch();
        if (!$msg) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message introuvable.']);
            exit;
        }

        if ((string) $msg['role'] !== 'user') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Seuls tes propres messages sont modifiables.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // modifier SON message invalide la suite : on la supprime et on rejoue la réponse.
        // Tout ou rien : modification, suppression de la suite et curseurs mémoire.
        $images = db_tx(static function (PDO $pdo) use ($cid, $mid, $content): array {
            $pdo->prepare('UPDATE messages SET content = ?, split_points = "" WHERE id = ?')
                ->execute([$content, $mid]);
            $st = $pdo->prepare("SELECT image FROM messages WHERE conversation_id = ? AND id > ? AND image <> ''");
            $st->execute([$cid, $mid]);
            $images = $st->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare('DELETE FROM messages WHERE conversation_id = ? AND id > ?')->execute([$cid, $mid]);
            // les curseurs de mémoire ne peuvent plus pointer au-delà du message édité
            $pdo->prepare('UPDATE conversations
                              SET last_compressed_id = MIN(last_compressed_id, ?),
                                  facts_cursor = MIN(facts_cursor, ?)
                            WHERE id = ?')->execute([$mid, $mid, $cid]);
            return $images;
        });
        release_media(...array_map('strval', $images));
        $replay = true;

        echo json_encode([
            'ok'           => true,
            'content'      => $content,
            'split_points' => [],
            'replay'       => $replay,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- supprimer un message (et la réponse qui le suit si c'est un message utilisateur) --- */
    if ($action === 'msg-delete' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes

        $mid  = (int) ($_POST['mid'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM messages WHERE id = ? AND conversation_id = ?');
        $stmt->execute([$mid, $cid]);
        $msg = $stmt->fetch();
        if (!$msg) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Message introuvable.']);
            exit;
        }

        $ids = [$mid];
        if ((string) $msg['role'] === 'user') {
            $stmt = db()->prepare("SELECT id FROM messages WHERE conversation_id = ? AND id > ? AND role = 'assistant' ORDER BY id ASC LIMIT 1");
            $stmt->execute([$cid, $mid]);
            if ($next = $stmt->fetchColumn()) {
                $ids[] = (int) $next;
            }
        }

        $in     = implode(',', array_map('intval', $ids));
        $images = db_tx(static function (PDO $pdo) use ($cid, $ids, $in): array {
            $images = $pdo->query("SELECT image FROM messages WHERE conversation_id = " . (int) $cid
                . " AND id IN (" . $in . ") AND image <> ''")->fetchAll(PDO::FETCH_COLUMN);
            $pdo->exec('DELETE FROM messages WHERE conversation_id = ' . (int) $cid . ' AND id IN (' . $in . ')');

            // le curseur de mémoire ne doit pas pointer sur un message supprimé
            $min = min($ids);
            $pdo->prepare('UPDATE conversations SET last_compressed_id = COALESCE((SELECT MAX(id) FROM messages WHERE conversation_id = ? AND id < ?), 0) WHERE id = ? AND last_compressed_id >= ?')
                ->execute([$cid, $min, $cid, $min]);
            // même logique pour le curseur des souvenirs durables
            $pdo->prepare('UPDATE conversations SET facts_cursor = COALESCE((SELECT MAX(id) FROM messages WHERE conversation_id = ? AND id < ?), 0) WHERE id = ? AND facts_cursor >= ?')
                ->execute([$cid, $min, $cid, $min]);
            return $images;
        });
        release_media(...array_map('strval', $images));

        echo json_encode(['ok' => true, 'deleted' => $ids], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- messages plus anciens (pagination) --- */
    if ($action === 'messages' && $method === 'GET') {
        session_write_close();
        header('Content-Type: application/json; charset=utf-8');
        $before = max(0, (int) ($_GET['before'] ?? 0));
        $limit  = min(100, max(10, (int) ($_GET['limit'] ?? 50)));
        $rows   = fetch_messages($cid, $limit, $before);
        $oldest = $rows ? (int) $rows[0]['id'] : 0;
        $left   = $oldest > 0
            ? (int) db()->query('SELECT COUNT(*) FROM messages WHERE conversation_id = ' . (int) $cid . ' AND id < ' . $oldest)->fetchColumn()
            : 0;

        echo json_encode([
            'ok'       => true,
            'messages' => array_map(static fn (array $m): array => [
                'id'       => (int) $m['id'],
                'role'     => (string) $m['role'],
                'content'  => (string) $m['content'],
                'image'    => (string) ($m['image'] ?? ''),
                'split_points' => json_list($m['split_points'] ?? '[]'),
            ], $rows),
            'left'     => $left,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- affichage --- */
    if ($action === '' && $method === 'GET') {
        if ((int) ($conv['unread'] ?? 0) === 1) {
            db()->prepare('UPDATE conversations SET unread = 0 WHERE id = ?')->execute([$cid]);
            $conv['unread'] = 0;
        }
        $total    = (int) db()->query('SELECT COUNT(*) FROM messages WHERE conversation_id = ' . (int) $cid)->fetchColumn();
        $messages = fetch_messages($cid, 50);
        $convs = db()->query(
            'SELECT c.id, c.title, c.updated_at, c.unread, p.name AS persona_name, p.avatar AS persona_avatar
             FROM conversations c JOIN personas p ON p.id = c.persona_id
             ORDER BY c.updated_at DESC LIMIT 30'
        )->fetchAll();

        render('chat', [
            'title'     => $conv['title'],
            'conv'      => $conv,
            'persona'   => $persona,
            'memory'    => chat_memory_stats($cid, $conv),
            'messages'  => $messages,
            'totalMsgs' => $total,
            'personaMemories' => chat_persona_memories((int) $persona['id'], 12),
            'olderLeft' => max(0, $total - count($messages)),
            'convs'     => $convs,
            'personaPrompts' => $personaPrompts,
            'chatReady' => chat_llm_ready(),
            'chatModel' => chat_llm_config_raw()['model'],
            'models'    => chat_models_ordered(chat_models()),
        ]);
        exit;
    }
}

/* -------------------------------------------------------------- sauvegarde */

if ($path === '/backup/export.zip' && $method === 'GET') {
    try {
        $zip = backup_create_zip(((string) ($_GET['conv'] ?? '')) === '1');
    } catch (Throwable $e) {
        flash('error', 'Sauvegarde impossible : ' . public_error($e));
        redirect('/settings#backup');
        exit;
    }
    set_setting('backup_last_at', gmdate('Y-m-d H:i:s'));
    set_setting('backup_last_size', (string) filesize($zip['path']));

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip['name'] . '"');
    header('Content-Length: ' . filesize($zip['path']));
    header('Cache-Control: no-store');
    readfile($zip['path']);
    @unlink($zip['path']);
    exit;
}

if ($path === '/backup/import' && $method === 'POST') {
    csrf_check();
    try {
        $archive = $_FILES['archive'] ?? null;
        if (!upload_present($archive)) {
            throw new RuntimeException('Aucun fichier reçu.');
        }
        if ((int) $archive['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message((int) $archive['error']));
        }
        $res = backup_import_zip((string) $archive['tmp_name']);
        flash('ok', 'Import terminé : ' . $res['personas'] . ' persona(s), '
            . $res['photos'] . ' photo(s), ' . $res['prompts'] . ' prompt(s).');
        if (!empty($res['errors'])) {
            flash('error', implode(' · ', array_slice($res['errors'], 0, 3)));
        }
    } catch (Throwable $e) {
        flash('error', 'Import impossible : ' . public_error($e));
    }
    redirect('/settings#backup');
}

/* --------------------------------------------------------------- souvenirs */

if ($path === '/memories' && $method === 'GET') {
    $q    = trim((string) ($_GET['q'] ?? ''));
    $rows = facts_list(null, $q);
    $personas = db()->query('SELECT id, name FROM personas ORDER BY name')->fetchAll();
    render('memories', [
        'title'    => 'Souvenirs',
        'rows'     => $rows,
        'q'        => $q,
        'personas' => $personas,
        'stats'    => [
            'total'   => (int) db()->query('SELECT COUNT(*) FROM user_memories')->fetchColumn(),
            'shared'  => (int) db()->query('SELECT COUNT(*) FROM user_memories WHERE persona_id IS NULL')->fetchColumn(),
        ],
    ]);
    exit;
}

if ($path === '/memories' && $method === 'POST') {
    csrf_check();
    $content = trim((string) ($_POST['content'] ?? ''));
    if ($content === '') {
        flash('error', 'Le souvenir est vide.');
        redirect('/memories');
    }
    facts_add(
        (int) ($_POST['persona_id'] ?? 0),
        $content,
        (string) ($_POST['category'] ?? 'fait'),
        (string) ($_POST['confidence'] ?? 'confirme'),
        null
    );
    flash('ok', 'Souvenir ajouté.');
    redirect('/memories');
}

if (preg_match('#^/memories/(\d+)(?:/(\w+))?$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $mid    = (int) $m[1];
    $action = $m[2] ?? '';
    if ($action === 'delete') {
        facts_delete($mid);
        flash('ok', 'Souvenir supprimé.');
    } else {
        facts_update(
            $mid,
            (string) ($_POST['content'] ?? ''),
            (string) ($_POST['category'] ?? 'fait'),
            (string) ($_POST['confidence'] ?? 'confirme')
        );
        flash('ok', 'Souvenir mis à jour.');
    }
    redirect('/memories');
}

/* ---------------------------------------------------------------- réglages */

if ($path === '/settings/prompts' && $method === 'POST') {
    csrf_check();
    $defaults = engine_prompt_defaults();
    foreach (['memory', 'facts', 'followup'] as $key) {
        if (!empty($_POST['reset_' . $key])) {
            set_setting('prompt_' . $key, '');
            continue;
        }
        $value = trim((string) ($_POST['prompt_' . $key] ?? ''));
        // valeur identique au défaut → on n'enregistre rien (retour au défaut)
        set_setting('prompt_' . $key, $value === trim($defaults[$key]) ? '' : $value);
    }
    flash('ok', 'Prompts enregistrés.');
    redirect('/settings#prompts');
}

if ($path === '/settings/ui' && $method === 'POST') {
    csrf_check();

    $font = (float) ($_POST['ui_font_size'] ?? 14.5);
    $font = max(11.0, min(22.0, $font));

    $bubble = (string) ($_POST['ui_bubble_style'] ?? 'bulle');
    if (!in_array($bubble, ['bulle', 'plat', 'carte'], true)) {
        $bubble = 'bulle';
    }
    $density = (string) ($_POST['ui_density'] ?? 'confort');
    if (!in_array($density, ['confort', 'compact'], true)) {
        $density = 'confort';
    }
    $width = (string) ($_POST['ui_width'] ?? 'normal');
    if (!in_array($width, ['normal', 'large'], true)) {
        $width = 'normal';
    }

    set_setting('ui_font_size', (string) $font);
    set_setting('ui_bubble_style', $bubble);
    set_setting('ui_density', $density);
    set_setting('ui_width', $width);
    set_setting('ui_avatars', !empty($_POST['ui_avatars']) ? '1' : '0');

    flash('ok', 'Apparence enregistrée.');
    redirect('/settings#look');
}

if ($path === '/settings') {
    if ($method === 'POST') {
        csrf_check();
        $provider = trim((string) ($_POST['provider'] ?? 'deepseek'));
        $baseUrl  = trim((string) ($_POST['base_url'] ?? ''));
        $model    = trim((string) ($_POST['model'] ?? ''));
        $apiKey   = trim((string) ($_POST['api_key'] ?? ''));

        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            flash('error', 'L’URL doit commencer par http:// ou https://');
            redirect('/settings');
        }
        if ($model === '') {
            flash('error', 'Il manque le nom du modèle.');
            redirect('/settings');
        }

        set_setting('llm_provider', $provider);
        set_setting('llm_base_url', rtrim($baseUrl, '/'));
        set_setting('llm_model', $model);
        if (($apiKey === '' && !empty($_POST['clear_key']))) {
            set_setting('llm_api_key', '');
        } elseif ($apiKey !== '') {
            set_setting('llm_api_key', $apiKey);
        }
        flash('ok', 'Réglages enregistrés.');
        redirect('/settings');
    }

    $chatCfg    = chat_llm_config_raw();
    $chatModels = chat_models($chatCfg['base_url'], $chatCfg['key']); // un seul appel réseau
    $llmCfg     = llm_config();
    render('settings', [
        'title'      => 'Réglages',
        'llm'        => $llmCfg,
        'hasKey'     => $llmCfg['key'] !== '',
        'chat'       => $chatCfg,
        'chatModels'     => $chatModels,
        'chatModelList'  => chat_models_ordered($chatModels),
        'chatHasKey' => $chatCfg['key'] !== '',
        'chatReasoning' => setting('chat_reasoning_effort', 'none'),
        'style'         => ui_style(),
        'backupStats'   => [
            'personas'      => (int) db()->query('SELECT COUNT(*) FROM personas')->fetchColumn(),
            'photos'        => (int) db()->query('SELECT COUNT(*) FROM photos')->fetchColumn(),
            'prompts'       => (int) db()->query('SELECT COUNT(*) FROM prompts')->fetchColumn(),
            'conversations' => (int) db()->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),
        ],
    ]);
    exit;
}

if ($path === '/settings/chat' && $method === 'POST') {
    csrf_check();
    $baseUrl = trim((string) ($_POST['chat_base_url'] ?? ''));
    $model   = trim((string) ($_POST['chat_model'] ?? ''));
    $key     = trim((string) ($_POST['chat_api_key'] ?? ''));

    if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
        flash('error', 'L’URL doit commencer par http:// ou https://');
        redirect('/settings');
    }

    $previousModel = chat_llm_config_raw()['model'];
    set_setting('chat_llm_provider', trim((string) ($_POST['chat_provider'] ?? 'lmstudio')));
    set_setting('chat_memory_threshold', (string) max(600, min(100000, (int) ($_POST['chat_memory_threshold'] ?? 3000))));
    set_setting('chat_memory_target', (string) max(300, min(50000, (int) ($_POST['chat_memory_target'] ?? 1600))));
    $effort = (string) ($_POST['chat_reasoning_effort'] ?? 'none');
    set_setting('chat_reasoning_effort', in_array($effort, ['none', 'low', 'medium', 'high'], true) ? $effort : 'none');
    set_setting('chat_split_enabled', !empty($_POST['chat_split_enabled']) ? '1' : '0');
    set_setting('chat_split_words', (string) max(15, min(120, (int) ($_POST['chat_split_words'] ?? 50))));
    set_setting('chat_split_max_bubbles', (string) max(1, min(8, (int) ($_POST['chat_split_max_bubbles'] ?? 2))));
    $dmin = max(0.5, min(3.0, (float) ($_POST['chat_split_delay_min'] ?? 1)));
    $dmax = max(0.5, min(4.0, (float) ($_POST['chat_split_delay_max'] ?? 2)));
    if ($dmax < $dmin) {
        [$dmin, $dmax] = [$dmax, $dmin];
    }
    set_setting('chat_split_delay_min', (string) $dmin);
    set_setting('chat_split_delay_max', (string) $dmax);

    set_setting('chat_followup_enabled', !empty($_POST['chat_followup_enabled']) ? '1' : '0');
    set_setting('chat_followup_idle_hours', (string) max(1, min(72, (int) ($_POST['chat_followup_idle_hours'] ?? 6))));
    set_setting('chat_followup_from', (string) max(0, min(23, (int) ($_POST['chat_followup_from'] ?? 8))));
    set_setting('chat_followup_to', (string) max(1, min(24, (int) ($_POST['chat_followup_to'] ?? 23))));

    // souvenirs durables : quand et avec quel modèle les extraire
    $ff = (string) ($_POST['facts_frequency'] ?? 'each');
    set_setting('facts_frequency', in_array($ff, ['each', 'every', 'explicit', 'off'], true) ? $ff : 'each');
    set_setting('facts_every', (string) max(2, min(30, (int) ($_POST['facts_every'] ?? 6))));
    $ft = (string) ($_POST['facts_timing'] ?? 'now');
    set_setting('facts_timing', in_array($ft, ['now', 'idle'], true) ? $ft : 'now');
    set_setting('facts_idle_seconds', (string) max(10, min(600, (int) ($_POST['facts_idle_seconds'] ?? 45))));
    $fm = (string) ($_POST['facts_model'] ?? 'chat');
    set_setting('facts_model', in_array($fm, ['chat', 'remote'], true) ? $fm : 'chat');

    set_setting('chat_llm_base_url', rtrim($baseUrl, '/'));
    set_setting('chat_llm_model', $model);
    if ($key !== '') {
        set_setting('chat_llm_api_key', $key);
    } elseif (!empty($_POST['chat_clear_key'])) {
        set_setting('chat_llm_api_key', '');
    }
    $purged = [];
    if ($model !== '' && $model !== $previousModel) {
        // on libère la VRAM avant de charger le nouveau modèle
        $purged = lms_purge();
    }
    flash('ok', 'Réglages de discussion enregistrés.'
        . ($purged ? ' Mémoire du serveur purgée (' . count($purged) . ' modèle(s) déchargé(s)).' : ''));
    redirect('/settings');
}

if ($path === '/settings/purge' && $method === 'POST') {
    csrf_check_json();
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $before = lms_loaded_models();
        $done   = lms_purge();
        echo json_encode([
            'ok'       => true,
            'before'   => array_map(fn ($m) => $m['id'], $before),
            'unloaded' => $done,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($path === '/settings/test' && $method === 'POST') {
    csrf_check_json();
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    $stored  = llm_config();
    $key     = trim((string) ($_POST['api_key'] ?? ''));
    $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
    // la clé ENREGISTRÉE n'est envoyée qu'à l'adresse enregistrée : tester une autre
    // adresse exige de saisir la clé (évite de l'exfiltrer vers un serveur quelconque)
    $sameUrl = $baseUrl !== '' && strcasecmp($baseUrl, rtrim($stored['base_url'], '/')) === 0;
    $cfg     = [
        'base_url' => $baseUrl,
        'model'    => trim((string) ($_POST['model'] ?? '')),
        'key'      => $key !== '' ? $key : ($sameUrl ? $stored['key'] : ''),
    ];
    try {
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new RuntimeException('L’URL doit commencer par http:// ou https://');
        }
        if ($cfg['key'] === '' && $key === '' && !$sameUrl && $stored['key'] !== '') {
            throw new RuntimeException('Adresse différente de celle enregistrée : saisis la clé API pour la tester.');
        }
        $t0  = microtime(true);
        $out = llm_chat([
            ['role' => 'system', 'content' => 'Tu réponds uniquement par le mot OK.'],
            ['role' => 'user', 'content' => 'ping'],
        ], false, 30, $cfg);
        echo json_encode([
            'ok'      => true,
            'ms'      => (int) round((microtime(true) - $t0) * 1000),
            'modele'  => $cfg['model'],
            'reponse' => mb_substr(trim($out), 0, 40),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* --------------------------------------------------------------- bibliothèque */

if ($path === '/' && $method === 'GET') {
    $q    = trim((string) ($_GET['q'] ?? ''));
    $tag  = trim((string) ($_GET['tag'] ?? ''));
    $fav  = ($_GET['fav'] ?? '') === '1';
    $arch = ($_GET['arch'] ?? '') === '1';

    $sql    = 'SELECT * FROM personas WHERE archived = ?';
    $params = [$arch ? 1 : 0];

    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR tagline LIKE ? OR description LIKE ? OR tags LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($tag !== '') {
        // échappe les jokers SQL : le tag vient de l'URL
        $needle   = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tag);
        $sql     .= " AND tags LIKE ? ESCAPE '\\'";
        $params[] = '%"' . $needle . '"%';
    }
    if ($fav) {
        $sql .= ' AND favorite = 1';
    }
    $sql .= ' ORDER BY favorite DESC, updated_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $personas = $stmt->fetchAll();

    // compteurs par persona
    $counts = [];
    foreach (db()->query('SELECT persona_id, COUNT(*) n FROM photos GROUP BY persona_id') as $r) {
        $counts['p' . $r['persona_id']] = (int) $r['n'];
    }
    foreach (db()->query('SELECT persona_id, COUNT(*) n FROM prompts GROUP BY persona_id') as $r) {
        $counts['t' . $r['persona_id']] = (int) $r['n'];
    }

    // tous les tags existants
    $allTags = [];
    foreach (db()->query('SELECT tags FROM personas WHERE archived = 0') as $r) {
        foreach (json_list($r['tags']) as $t) {
            $allTags[(string) $t] = ($allTags[(string) $t] ?? 0) + 1;
        }
    }
    arsort($allTags);

    render('library', [
        'title'    => 'Personas',
        'personas' => $personas,
        'counts'   => $counts,
        'q'        => $q,
        'tag'      => $tag,
        'fav'      => $fav,
        'arch'     => $arch,
        'allTags'  => $allTags,
        'total'    => (int) db()->query('SELECT COUNT(*) FROM personas WHERE archived = 0')->fetchColumn(),
    ]);
    exit;
}

/* --------------------------------------------------------------- création */

if ($path === '/p/new' && $method === 'GET') {
    render('form', ['title' => 'Nouveau persona', 'persona' => null, 'photos' => [], 'prompts' => []]);
    exit;
}

if ($path === '/p' && $method === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash('error', 'Il faut au moins un nom.');
        redirect('/p/new');
    }
    $tags = parse_tags((string) ($_POST['tags'] ?? ''));
    $meta = parse_meta((string) ($_POST['meta'] ?? ''));
    $slug = unique_slug($name);

    $avatar = '';
    try {
        if (upload_present($_FILES['avatar'] ?? null)) {
            $avatar = store_upload($_FILES['avatar']);
        }
    } catch (Throwable $e) {
        flash('error', 'Avatar : ' . public_error($e));
    }

    $stmt = db()->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $slug,
        $name,
        trim((string) ($_POST['tagline'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        $avatar,
        json_encode($tags, JSON_UNESCAPED_UNICODE),
        json_encode($meta, JSON_UNESCAPED_UNICODE),
    ]);
    $id = (int) db()->lastInsertId();
    $stmt = db()->prepare('UPDATE personas SET updated_at = datetime("now") WHERE id = ?');
    $stmt->execute([$id]);

    flash('ok', $name . ' rejoint la bibliothèque.');
    redirect('/p/' . $slug);
}

/* ------------------------------------------------------------------ détail */

if (count($parts) >= 2 && $p0 === 'p') {
    $slug = $parts[1];
    $stmt = db()->prepare('SELECT * FROM personas WHERE slug = ?');
    $stmt->execute([$slug]);
    $persona = $stmt->fetch();
    if (!$persona) {
        http_response_code(404);
        render('notfound', ['title' => 'Introuvable']);
        exit;
    }
    $pid = (int) $persona['id'];

    $action = $parts[2] ?? '';

    /* --- complétion IA (AJAX) --- */
    /* --- proposer une consigne de jeu à partir du prompt système --- */
    if ($action === 'ai-directive' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        if (!llm_ready()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Configure d’abord le LLM (Réglages → Intelligence).']);
            exit;
        }
        try {
            $proposal = persona_ai_directive($persona, fetch_prompts($pid));
            echo json_encode(['ok' => true] + $proposal, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'ai-fill' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        csrf_check_json();
        session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
        if (!llm_ready()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Configure d’abord le LLM (roue crantée → Réglages).']);
            exit;
        }
        try {
            $prompts = fetch_prompts($pid);
            if (!$prompts) {
                throw new RuntimeException('Ajoute au moins un prompt : c’est la matière première de l’IA.');
            }

            $ai = llm_complete_persona($persona, $prompts);

            $changes = [];
            $tagline = trim((string) $persona['tagline']);
            $desc    = trim((string) $persona['description']);

            $aiTagline = trim((string) ($ai['tagline'] ?? ''));
            if ($tagline === '' && $aiTagline !== '') {
                $tagline = mb_substr($aiTagline, 0, 120);
                $changes[] = 'accroche';
            }
            $aiDesc = trim((string) ($ai['description'] ?? ''));
            if ($desc === '' && $aiDesc !== '') {
                $desc = mb_substr($aiDesc, 0, 1200);
                $changes[] = 'description';
            }

            $tags = json_list($persona['tags']);
            if (isset($ai['tags']) && is_array($ai['tags'])) {
                foreach ($ai['tags'] as $t) {
                    if (!is_scalar($t)) {
                        continue;
                    }
                    $t = mb_strtolower(trim(ltrim(trim((string) $t), '#')));
                    $t = mb_substr(preg_replace('/\s+/u', '-', $t) ?? $t, 0, 32);
                    if ($t === '' || in_array($t, $tags, true) || count($tags) >= 12) {
                        continue;
                    }
                    $tags[] = $t;
                    $changes[] = 'tags';
                }
            }

            $meta  = json_list($persona['meta']);
            $known = [];
            foreach ($meta as $row) {
                if (is_array($row) && isset($row['k'])) {
                    $known[mb_strtolower(trim((string) $row['k']))] = true;
                }
            }
            if (isset($ai['meta']) && is_array($ai['meta'])) {
                foreach ($ai['meta'] as $row) {
                    if (!is_array($row) || !isset($row['k']) || count($meta) >= 20) {
                        continue;
                    }
                    $k = trim((string) $row['k']);
                    $k = mb_substr($k, 0, 40);
                    $lower = mb_strtolower($k);
                    if ($k === '' || isset($known[$lower])) {
                        continue;
                    }
                    $v = is_scalar($row['v'] ?? null) ? trim((string) $row['v']) : '';
                    $meta[] = ['k' => $k, 'v' => mb_substr($v, 0, 200)];
                    $known[$lower] = true;
                    $changes[] = 'attributs';
                }
            }

            if (!$changes) {
                echo json_encode(['ok' => true, 'changed' => [], 'message' => 'Rien à compléter : la fiche est déjà bien renseignée.']);
                exit;
            }

            $stmt = db()->prepare('UPDATE personas SET tagline=?, description=?, tags=?, meta=?, updated_at=datetime("now") WHERE id=?');
            $stmt->execute([
                $tagline,
                $desc,
                json_encode($tags, JSON_UNESCAPED_UNICODE),
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                $pid,
            ]);

            $changes = array_values(array_unique($changes));
            echo json_encode([
                'ok'      => true,
                'changed' => $changes,
                'message' => 'Complété : ' . implode(', ', $changes) . '.',
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /* --- mise à jour --- */
    if ($action === '' && $method === 'POST') {
        csrf_check();
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            flash('error', 'Le nom ne peut pas être vide.');
            redirect('/p/' . $slug . '/edit');
        }
        $newSlug = unique_slug($name, $pid) === $slug ? $slug : unique_slug($name, $pid);
        $tags    = parse_tags((string) ($_POST['tags'] ?? ''));
        $meta    = parse_meta((string) ($_POST['meta'] ?? ''));
        $avatar  = $persona['avatar'];
        try {
            if (upload_present($_FILES['avatar'] ?? null)) {
                $avatar = store_upload($_FILES['avatar']);
            }
        } catch (Throwable $e) {
            flash('error', 'Avatar : ' . public_error($e));
        }
        $aiModel  = trim((string) ($_POST['model'] ?? ''));
        $aiTemp   = max(0.0, min(2.0, (float) ($_POST['temperature'] ?? 0.85)));
        $aiMax    = max(64, min(4096, (int) ($_POST['max_tokens'] ?? 400)));
        $aiCtx    = max(2048, min(131072, (int) ($_POST['context_window'] ?? 8192)));
        $aiDirect = trim((string) ($_POST['directive'] ?? ''));

        $stmt = db()->prepare('UPDATE personas SET slug=?, name=?, tagline=?, description=?, avatar=?, tags=?, meta=?, model=?, temperature=?, max_tokens=?, context_window=?, directive=?, updated_at=datetime("now") WHERE id=?');
        $stmt->execute([
            $newSlug,
            $name,
            trim((string) ($_POST['tagline'] ?? '')),
            trim((string) ($_POST['description'] ?? '')),
            $avatar,
            json_encode($tags, JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $aiModel,
            $aiTemp,
            $aiMax,
            $aiCtx,
            $aiDirect,
            $pid,
        ]);
        if ((string) $avatar !== (string) $persona['avatar']) {
            // l'ancien portrait peut encore servir dans la galerie : supprimé seulement s'il n'est plus référencé
            release_media((string) $persona['avatar']);
        }
        flash('ok', 'Fiche mise à jour.');
        redirect('/p/' . $newSlug);
    }

    if ($action === 'edit' && $method === 'GET') {
        $photos  = fetch_photos($pid);
        $prompts = fetch_prompts($pid);
        render('form', [
            'title'      => 'Modifier ' . $persona['name'],
            'persona'    => $persona,
            'photos'     => $photos,
            'prompts'    => $prompts,
            'chatModels' => chat_models_ordered(chat_models()),
        ]);
        exit;
    }

    if ($action === 'delete' && $method === 'POST') {
        csrf_check();
        $files = array_map(static fn (array $ph): string => (string) $ph['file'], fetch_photos($pid));
        $files[] = (string) $persona['avatar'];
        $stmt = db()->prepare("SELECT m.image FROM messages m JOIN conversations c ON c.id = m.conversation_id
                               WHERE c.persona_id = ? AND m.image <> ''");
        $stmt->execute([$pid]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $img) {
            $files[] = (string) $img;
        }
        $stmt = db()->prepare('DELETE FROM personas WHERE id = ?');
        $stmt->execute([$pid]);
        release_media(...$files); // après la suppression en cascade : plus aucune référence
        flash('ok', $persona['name'] . ' a été supprimé.');
        redirect('/');
    }

    if ($action === 'favorite' && $method === 'POST') {
        csrf_check();
        $stmt = db()->prepare('UPDATE personas SET favorite = 1 - favorite WHERE id = ?');
        $stmt->execute([$pid]);
        redirect(safe_referer_path('/p/' . $slug));
    }

    if ($action === 'archive' && $method === 'POST') {
        csrf_check();
        $stmt = db()->prepare('UPDATE personas SET archived = 1 - archived WHERE id = ?');
        $stmt->execute([$pid]);
        flash('ok', $persona['archived'] ? 'Persona restauré.' : 'Persona archivé.');
        redirect($persona['archived'] ? '/p/' . $slug : '/');
    }

    if ($action === 'photos' && $method === 'POST') {
        // appel AJAX (collage presse-papier) → réponse JSON, sinon redirection classique
        $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

        if ($wantsJson) {
            csrf_check_json();
            session_write_close(); // réponse JSON : aucun message flash à écrire en session
        } else {
            csrf_check();
        }

        $files  = $_FILES['photos'] ?? null;
        $n      = 0;
        $errors = [];
        if ($files && is_array($files['tmp_name'] ?? null)) {
            foreach ($files['tmp_name'] as $i => $tmp) {
                $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                try {
                    $base = store_upload([
                        'tmp_name' => $tmp,
                        'error'    => $err,
                        'size'     => $files['size'][$i] ?? 0,
                    ]);
                    $stmt = db()->prepare('INSERT INTO photos (persona_id, file, caption, sort) VALUES (?,?,?,?)');
                    $stmt->execute([$pid, $base, '', time()]);
                    $n++;
                } catch (Throwable $e) {
                    $msg      = public_error($e);
                    $errors[] = $msg;
                    if (!$wantsJson) {
                        flash('error', $msg);
                    }
                }
            }
        }

        // pas encore de portrait : la première image collée devient l'avatar
        if ($n > 0 && (string) $persona['avatar'] === '') {
            $stmt = db()->prepare('SELECT file FROM photos WHERE persona_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$pid]);
            $last = (string) $stmt->fetchColumn();
            if ($last !== '') {
                db()->prepare('UPDATE personas SET avatar = ? WHERE id = ?')->execute([$last, $pid]);
            }
        }

        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            if ($n === 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => $errors[0] ?? 'Aucune image reçue.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            db()->prepare('UPDATE personas SET updated_at=datetime("now") WHERE id=?')->execute([$pid]);
            echo json_encode(['ok' => true, 'added' => $n], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($n > 0) {
            db()->prepare('UPDATE personas SET updated_at=datetime("now") WHERE id=?')->execute([$pid]);
            flash('ok', $n . ' photo' . ($n > 1 ? 's' : '') . ' ajoutée' . ($n > 1 ? 's' : '') . '.');
        } else {
            flash('error', 'Aucune photo ajoutée.');
        }
        redirect('/p/' . $slug);
    }

    if ($action === 'prompts' && $method === 'POST') {
        csrf_check();
        $body = trim((string) ($_POST['body'] ?? ''));
        if ($body === '') {
            flash('error', 'Le prompt est vide.');
            redirect('/p/' . $slug);
        }
        $stmt = db()->prepare('INSERT INTO prompts (persona_id, title, kind, body) VALUES (?,?,?,?)');
        $stmt->execute([
            $pid,
            trim((string) ($_POST['title'] ?? '')),
            array_key_exists((string) ($_POST['kind'] ?? ''), prompt_kinds()) ? (string) $_POST['kind'] : 'image',
            $body,
        ]);
        db()->prepare('UPDATE personas SET updated_at=datetime("now") WHERE id=?')->execute([$pid]);
        flash('ok', 'Prompt enregistré.');
        redirect('/p/' . $slug);
    }

    /* --- détail --- */
    $photos  = fetch_photos($pid);
    $prompts = fetch_prompts($pid);
    render('detail', [
        'title'   => $persona['name'],
        'persona' => $persona,
        'photos'  => $photos,
        'prompts' => $prompts,
    ]);
    exit;
}

/* ------------------------------------------------------------ photos/prompts */

if ($p0 === 'photos' && isset($parts[1]) && $method === 'POST') {
    csrf_check();
    $id   = (int) $parts[1];
    $stmt = db()->prepare('SELECT * FROM photos WHERE id = ?');
    $stmt->execute([$id]);
    $photo = $stmt->fetch();
    if ($photo) {
        if (($parts[2] ?? '') === 'avatar') {
            $stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
            $stmt->execute([(int) $photo['persona_id']]);
            $persona = $stmt->fetch();
            if ($persona) {
                db()->prepare('UPDATE personas SET avatar=?, updated_at=datetime("now") WHERE id=?')
                    ->execute([$photo['file'], $persona['id']]);
                // l'ancien portrait peut être une photo de la galerie : on ne supprime que l'orphelin
                release_media((string) $persona['avatar']);
            }
        } elseif (($parts[2] ?? '') === 'delete') {
            db()->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
            // si la photo sert aussi de portrait, le fichier est conservé
            release_media((string) $photo['file']);
        }
    }
    back('/');
}

if ($p0 === 'prompts' && isset($parts[1]) && $method === 'POST') {
    csrf_check();
    $id   = (int) $parts[1];
    $stmt = db()->prepare('SELECT * FROM prompts WHERE id = ?');
    $stmt->execute([$id]);
    $prompt = $stmt->fetch();
    if ($prompt) {
        if (($parts[2] ?? '') === 'delete') {
            db()->prepare('DELETE FROM prompts WHERE id = ?')->execute([$id]);
        } else {
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($body !== '') {
                db()->prepare('UPDATE prompts SET title=?, kind=?, body=? WHERE id=?')
                    ->execute([
                        trim((string) ($_POST['title'] ?? '')),
                        array_key_exists((string) ($_POST['kind'] ?? ''), prompt_kinds()) ? (string) $_POST['kind'] : 'image',
                        $body,
                        $id,
                    ]);
            }
        }
    }
    back('/');
}

/* ------------------------------------------------------------------ export */

if ($path === '/export.json') {
    $out = ['exported_at' => gmdate('c'), 'personas' => []];
    foreach (db()->query('SELECT * FROM personas ORDER BY name') as $p) {
        $p['tags']   = json_list($p['tags']);
        $p['meta']   = json_list($p['meta']);
        $p['photos'] = fetch_photos((int) $p['id']);
        foreach ($p['photos'] as &$ph) {
            $ph['url'] = media_url($ph['file'], false);
        }
        $p['prompts'] = fetch_prompts((int) $p['id']);
        $out['personas'][] = $p;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="personas-' . date('Y-m-d') . '.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
render('notfound', ['title' => 'Introuvable']);

/* ----------------------------------------------------------------- helpers */
