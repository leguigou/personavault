<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$parts  = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
$p0     = $parts[0] ?? '';

/* ------------------------------------------------------------------ média */

if ($p0 === 'media' && $method === 'GET') {
    require_login();
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
    header('Content-Type: image/webp');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($full));
    readfile($full);
    exit;
}

/* ------------------------------------------------------------- assets dev */

if ($p0 === 'assets') {
    $file = basename($parts[1] ?? '');
    $full = __DIR__ . '/assets/' . $file;
    if (!is_file($full)) {
        http_response_code(404);
        exit;
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    header('Content-Type: ' . (['css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml'][$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=3600');
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

    /* --- envoi d'un message (AJAX) --- */
    if ($action === 'send' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $sent = (string) ($_POST['_token'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }

        $text = trim((string) ($_POST['message'] ?? ''));
        if ($text === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Message vide.']);
            exit;
        }

        // compression avant la réponse si le contexte devient trop lourd
        try {
            $mem = chat_memory_stats($cid, $conv);
            if ($mem['context_tokens'] > chat_memory_threshold()) {
                $cres = chat_compress($cid, $conv);
                if (($cres['ok'] ?? false) && ($cres['compressed'] ?? 0) > 0) {
                    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
                    $stmt->execute([$cid]);
                    $conv = $stmt->fetch();
                }
            }
        } catch (Throwable $e) {
            // la compression ne doit jamais empêcher de répondre
        }

        $history = fetch_messages($cid);
        $built   = chat_build_messages($persona, $personaPrompts, $conv, $history, $text);

        $ins = db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "user", ?)');
        $ins->execute([$cid, $text]);
        $userMsgId = (int) db()->lastInsertId();

        try {
            $res = chat_reply($built['messages'], $conv);
        } catch (Throwable $e) {
            http_response_code(502);
            echo json_encode([
                'ok'      => false,
                'error'   => $e->getMessage(),
                'user'    => ['id' => $userMsgId, 'role' => 'user', 'content' => $text],
            ], JSON_UNESCAPED_UNICODE);
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
            'ok'      => true,
            'user'    => ['id' => $userMsgId, 'role' => 'user', 'content' => $text],
            'reply'   => [
                'id'       => $assistantId,
                'role'     => 'assistant',
                'content'  => $res['content'],
                'model'    => $res['model'],
                'split_points' => $splitPoints,
            ],
            'meta'    => [
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

        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- envoi d'un message en streaming (SSE) --- */
    if ($action === 'stream' && $method === 'POST') {
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }
        $regen = ((string) ($_POST['regen'] ?? '')) === '1';
        $text  = trim((string) ($_POST['message'] ?? ''));
        $hasImage = !empty($_FILES['image']['tmp_name']);
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
        session_write_close();

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
                if ($mem['context_tokens'] > chat_memory_threshold()) {
                    $cres = chat_compress($cid, $conv);
                    if (($cres['ok'] ?? false) && ($cres['compressed'] ?? 0) > 0) {
                        $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
                        $stmt->execute([$cid]);
                        $conv = $stmt->fetch();
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
                $sse(['error' => 'Image refusée : ' . $e->getMessage(), 'kind' => 'image']);
                exit;
            }
        }

        $history = fetch_messages($cid);
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

        $runStream = static function () use ($built, $conv, $sse, &$sentAny, $mode): array {
            return chat_reply_stream(
                $built['messages'],
                $conv,
                static function (string $delta) use ($sse, &$sentAny): void {
                    $sentAny = true;
                    $sse(['delta' => $delta]);
                },
                $mode
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
            $sse(['error' => $e->getMessage(), 'kind' => $kind, 'partial' => $sentAny]);
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
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.', 'kind' => 'auth']);
            exit;
        }

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
        $sent = (string) ($_POST['_token'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée.']);
            exit;
        }
        try {
            $cres = chat_compress($cid, $conv, !empty($_POST['force']));
            $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
            $stmt->execute([$cid]);
            $fresh  = $stmt->fetch();
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
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    /* --- extraction des souvenirs durables (AJAX, après la réponse) --- */
    if ($action === 'remember' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $sent = (string) ($_POST['_token'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée.']);
            exit;
        }
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
        $recent = [];
        foreach (fetch_messages($cid) as $m) {
            if (in_array((string) $m['role'], ['user', 'assistant'], true) && (int) $m['id'] > $cursor) {
                $recent[] = $m;
            }
        }

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

        if (count($recent) < ($explicit ? 1 : 2)) {
            echo json_encode(['ok' => true, 'added' => [], 'skipped' => 'pas assez de nouveaux messages', 'memories' => chat_persona_memories((int) $persona['id'])], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $slice = array_slice($recent, -8);
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
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
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
        db()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$cid]);
        flash('ok', 'Conversation supprimée.');
        redirect('/chat');
    }

    /* --- titre automatique de la conversation --- */
    if ($action === 'autotitle' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée.']);
            exit;
        }
        try {
            $title = chat_auto_title($cid);
            echo json_encode($title ? ['ok' => true, 'title' => $title] : ['ok' => false], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    /* --- recherche dans toutes les conversations du même persona --- */
    if ($action === 'search' && $method === 'GET') {
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
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }
        $on = ((string) ($_POST['enabled'] ?? '')) === '1' ? 1 : 0;
        db()->prepare('UPDATE conversations SET facts_enabled = ? WHERE id = ?')->execute([$on, $cid]);
        echo json_encode(['ok' => true, 'enabled' => $on], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- souvenirs partagés : ajout / suppression depuis la conversation --- */
    if (($action === 'fact-add' || $action === 'fact-delete') && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }
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
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }
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

        db()->prepare('UPDATE messages SET content = ?, split_points = "" WHERE id = ?')
            ->execute([$content, $mid]);

        // modifier SON message invalide la suite : on la supprime et on rejoue la réponse
        $replay = false;
        if ((string) $msg['role'] === 'user') {
            db()->prepare('DELETE FROM messages WHERE conversation_id = ? AND id > ?')->execute([$cid, $mid]);
            // les curseurs de mémoire ne peuvent plus pointer au-delà du message édité
            db()->prepare('UPDATE conversations
                              SET last_compressed_id = MIN(last_compressed_id, ?),
                                  facts_cursor = MIN(facts_cursor, ?)
                            WHERE id = ?')->execute([$mid, $mid, $cid]);
            $replay = true;
        }

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
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }

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

        $in = implode(',', array_map('intval', $ids));
        db()->exec('DELETE FROM messages WHERE conversation_id = ' . (int) $cid . ' AND id IN (' . $in . ')');

        // le curseur de mémoire ne doit pas pointer sur un message supprimé
        $min = min($ids);
        db()->prepare('UPDATE conversations SET last_compressed_id = COALESCE((SELECT MAX(id) FROM messages WHERE conversation_id = ? AND id < ?), 0) WHERE id = ? AND last_compressed_id >= ?')
            ->execute([$cid, $min, $cid, $min]);
        // même logique pour le curseur des souvenirs durables
        db()->prepare('UPDATE conversations SET facts_cursor = COALESCE((SELECT MAX(id) FROM messages WHERE conversation_id = ? AND id < ?), 0) WHERE id = ? AND facts_cursor >= ?')
            ->execute([$cid, $min, $cid, $min]);

        echo json_encode(['ok' => true, 'deleted' => $ids], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* --- messages plus anciens (pagination) --- */
    if ($action === 'messages' && $method === 'GET') {
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
        flash('error', 'Sauvegarde impossible : ' . $e->getMessage());
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
        if (empty($_FILES['archive']['tmp_name'])) {
            throw new RuntimeException('Aucun fichier reçu.');
        }
        $res = backup_import_zip((string) $_FILES['archive']['tmp_name']);
        flash('ok', 'Import terminé : ' . $res['personas'] . ' persona(s), '
            . $res['photos'] . ' photo(s), ' . $res['prompts'] . ' prompt(s).');
        if (!empty($res['errors'])) {
            flash('error', implode(' · ', array_slice($res['errors'], 0, 3)));
        }
    } catch (Throwable $e) {
        flash('error', 'Import impossible : ' . $e->getMessage());
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

    $chatCfg = chat_llm_config_raw();
    render('settings', [
        'title'      => 'Réglages',
        'llm'        => llm_config(),
        'hasKey'     => llm_config()['key'] !== '',
        'chat'       => $chatCfg,
        'chatModels'     => chat_models($chatCfg['base_url'], $chatCfg['key']),
        'chatModelList'  => chat_models_ordered(chat_models($chatCfg['base_url'], $chatCfg['key'])),
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
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
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
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($path === '/settings/test' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $stored = llm_config();
    $key    = trim((string) ($_POST['api_key'] ?? ''));
    $cfg    = [
        'base_url' => rtrim(trim((string) ($_POST['base_url'] ?? '')), '/'),
        'model'    => trim((string) ($_POST['model'] ?? '')),
        'key'      => $key !== '' ? $key : $stored['key'],
    ];
    try {
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
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
        if (!empty($_FILES['avatar']['tmp_name'])) {
            $avatar = store_upload($_FILES['avatar']);
        }
    } catch (Throwable $e) {
        flash('error', 'Avatar : ' . $e->getMessage());
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
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), (string) ($_POST['_token'] ?? ''))) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }
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
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'ai-fill' && $method === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        $sent = (string) ($_POST['_token'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
            exit;
        }
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
            echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
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
            if (!empty($_FILES['avatar']['tmp_name'])) {
                $avatar = store_upload($_FILES['avatar']);
                delete_media((string) $persona['avatar']);
            }
        } catch (Throwable $e) {
            flash('error', 'Avatar : ' . $e->getMessage());
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
        foreach (fetch_photos($pid) as $ph) {
            delete_media((string) $ph['file']);
        }
        delete_media((string) $persona['avatar']);
        $stmt = db()->prepare('DELETE FROM personas WHERE id = ?');
        $stmt->execute([$pid]);
        flash('ok', $persona['name'] . ' a été supprimé.');
        redirect('/');
    }

    if ($action === 'favorite' && $method === 'POST') {
        csrf_check();
        $stmt = db()->prepare('UPDATE personas SET favorite = 1 - favorite WHERE id = ?');
        $stmt->execute([$pid]);
        redirect($_SERVER['HTTP_REFERER'] ?? '/p/' . $slug);
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

        $sentToken = (string) ($_POST['_token'] ?? '');
        if (!hash_equals((string) ($_SESSION['csrf'] ?? ''), $sentToken)) {
            if ($wantsJson) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(419);
                echo json_encode(['ok' => false, 'error' => 'Session expirée, recharge la page.']);
                exit;
            }
            csrf_check();
        }

        $files  = $_FILES['photos'] ?? null;
        $n      = 0;
        $errors = [];
        if ($files && is_array($files['tmp_name'])) {
            foreach ($files['tmp_name'] as $i => $tmp) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                try {
                    $base = store_upload([
                        'tmp_name' => $tmp,
                        'error'    => UPLOAD_ERR_OK,
                        'size'     => $files['size'][$i],
                    ]);
                    $stmt = db()->prepare('INSERT INTO photos (persona_id, file, caption, sort) VALUES (?,?,?,?)');
                    $stmt->execute([$pid, $base, '', time()]);
                    $n++;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                    flash('error', $e->getMessage());
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
                echo json_encode(['ok' => false, 'error' => $errors[0] ?? 'Aucune image reçue.']);
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
            (string) ($_POST['kind'] ?? 'image'),
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
                delete_media((string) $persona['avatar']);
                db()->prepare('UPDATE personas SET avatar=?, updated_at=datetime("now") WHERE id=?')
                    ->execute([$photo['file'], $persona['id']]);
            }
        } elseif (($parts[2] ?? '') === 'delete') {
            delete_media((string) $photo['file']);
            db()->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
        }
    }
    redirect((string) ($_SERVER['HTTP_REFERER'] ?? '/'));
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
                        (string) ($_POST['kind'] ?? 'image'),
                        $body,
                        $id,
                    ]);
            }
        }
    }
    redirect((string) ($_SERVER['HTTP_REFERER'] ?? '/'));
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
