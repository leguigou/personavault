<?php
declare(strict_types=1);

/*
 * Discussion : recherche, édition / suppression de messages, affichage (en dernier).
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

function chat_message_json(array $message): array
{
    return [
        'id' => (int) $message['id'],
        'role' => (string) $message['role'],
        'content' => (string) $message['content'],
        'image' => (string) $message['image'],
        'split_points' => json_list($message['split_points']),
        'version_id' => (int) $message['version_id'],
        'reaction' => chat_reaction_key($message['reaction'], (string) $message['role']),
        'reaction_reply' => (bool) $message['reaction_reply'],
        'at' => gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($message['created_at'] . ' UTC')),
    ];
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

/* --- ajouter, remplacer ou retirer une réaction à un message du personnage --- */
if ($action === 'react' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close();
    $mid = (int) ($_POST['mid'] ?? 0);
    $version = (int) ($_POST['version_id'] ?? 0);
    $requested = $_POST['reaction'] ?? null;
    $reaction = chat_reaction_key($requested, 'assistant');
    if ($reaction === '' && $requested !== '') {
        http_response_code(400);
        echo json_encode(['ok' => false]);
        exit;
    }

    $result = db_tx(static function (PDO $pdo) use ($cid, $mid, $version, $reaction): ?array {
        $stmt = $pdo->prepare("SELECT reaction, version_id FROM messages WHERE id = ? AND conversation_id = ? AND role = 'assistant'");
        $stmt->execute([$mid, $cid]);
        $message = $stmt->fetch();
        if (!$message || (int) $message['version_id'] !== $version) {
            return null;
        }
        $changed = $message['reaction'] !== $reaction;
        $revision = $changed && $reaction !== '' ? bin2hex(random_bytes(8)) : '';
        if ($changed) {
            $pdo->prepare('UPDATE messages SET reaction = ?, reaction_pending = ? WHERE id = ?')
                ->execute([$reaction, $revision, $mid]);
            if ($version > 0) {
                $pdo->prepare('UPDATE reply_versions SET reaction = ? WHERE id = ?')->execute([$reaction, $version]);
            }
        }
        return ['ok' => true, 'reaction' => $reaction, 'reply' => $revision !== '', 'revision' => $revision];
    });
    if ($result === null) {
        http_response_code(409);
        $result = ['ok' => false, 'error' => t('error.chat.reply_changed')];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- modifier un message --- */
if ($action === 'msg-edit' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    $chatLock = chat_require_write_lock($cid);
    $mid     = (int) ($_POST['mid'] ?? 0);
    $content = trim((string) ($_POST['content'] ?? ''));
    if ($content === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.message.empty')]);
        exit;
    }

    $stmt = db()->prepare('SELECT * FROM messages WHERE id = ? AND conversation_id = ?');
    $stmt->execute([$mid, $cid]);
    $msg = $stmt->fetch();
    if (!$msg) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('error.message.not_found')]);
        exit;
    }

    if ((string) $msg['role'] !== 'user') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => t('error.message.not_yours')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // modifier SON message invalide la suite : on la supprime et on rejoue la réponse.
    // Tout ou rien : modification, suppression de la suite et curseurs mémoire.
    $images = db_tx(static function (PDO $pdo) use ($cid, $mid, $content): array {
        $pdo->prepare('UPDATE messages SET content = ?, split_points = "", reaction = "" WHERE id = ?')
            ->execute([$content, $mid]);
        // les réponses gardées répondaient à l'ancien texte : elles ne valent plus
        $pdo->prepare('DELETE FROM reply_versions WHERE user_message_id = ?')->execute([$mid]);
        $st = $pdo->prepare("SELECT image FROM messages WHERE conversation_id = ? AND id > ? AND image <> ''");
        $st->execute([$cid, $mid]);
        $images = $st->fetchAll(PDO::FETCH_COLUMN);
        $pdo->prepare('DELETE FROM messages WHERE conversation_id = ? AND id > ?')->execute([$cid, $mid]);
        chat_invalidate_summary($cid, $mid);
        // Le prochain passage peut relire le message édité.
        $pdo->prepare('UPDATE conversations
                              SET facts_cursor = MIN(facts_cursor, ?)
                            WHERE id = ?')->execute([$mid - 1, $cid]);
        return $images;
    });
    release_media(...array_map('strval', $images));
    $replay = true;

    echo json_encode([
        'ok'           => true,
        'content'      => $content,
        'image'        => (string) ($msg['image'] ?? ''), // la photo jointe reste avec le message
        'split_points' => [],
        'replay'       => $replay,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- passer d'une version de réponse à l'autre (‹ 1/3 ›) --- */
if ($action === 'version' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close();
    $chatLock = chat_require_write_lock($cid);
    $mid = (int) ($_POST['mid'] ?? 0);
    $dir = (int) ($_POST['dir'] ?? 0) < 0 ? -1 : 1;

    $stmt = db()->prepare("SELECT * FROM messages WHERE id = ? AND conversation_id = ? AND role = 'assistant'");
    $stmt->execute([$mid, $cid]);
    $msg  = $stmt->fetch();
    $ids  = $msg ? reply_version_ids(reply_user_message_id($cid, $mid)) : [];
    $pos  = $msg ? array_search((int) $msg['version_id'], $ids, true) : false;
    if ($pos === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('error.message.not_found')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pos = max(0, min(count($ids) - 1, $pos + $dir));

    $st = db()->prepare('SELECT * FROM reply_versions WHERE id = ?');
    $st->execute([$ids[$pos]]);
    $v = $st->fetch();
    if ((int) $v['id'] !== (int) $msg['version_id']) {
        db_tx(static function (PDO $pdo) use ($cid, $mid, $v): void {
            $pdo->prepare("DELETE FROM user_memories WHERE subject = 'story' AND source_message_id = ?")->execute([$mid]);
            $pdo->prepare("UPDATE messages SET content = ?, model = ?, split_points = ?, version_id = ?, reaction = ?, reaction_pending = '' WHERE id = ?")
                ->execute([(string) $v['content'], (string) $v['model'], (string) $v['split_points'], (int) $v['id'], (string) $v['reaction'], $mid]);
            chat_invalidate_summary($cid, $mid);
            $pdo->prepare('UPDATE conversations SET facts_cursor = MIN(facts_cursor, ?) WHERE id = ?')->execute([$mid - 1, $cid]);
        });
    }

    echo json_encode([
        'ok'           => true,
        'content'      => (string) $v['content'],
        'split_points' => json_list($v['split_points'] ?: '[]'),
        'version_id'   => (int) $v['id'],
        'reaction'     => chat_reaction_key($v['reaction'], 'assistant'),
        'versions'     => ['index' => $pos + 1, 'total' => count($ids)],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- supprimer un message (et la réponse qui le suit si c'est un message utilisateur) --- */
if ($action === 'msg-delete' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    $chatLock = chat_require_write_lock($cid);

    $mid  = (int) ($_POST['mid'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM messages WHERE id = ? AND conversation_id = ?');
    $stmt->execute([$mid, $cid]);
    $msg = $stmt->fetch();
    if (!$msg) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('error.message.not_found')]);
        exit;
    }

    $ids = [$mid];
    if ((string) $msg['role'] === 'user') {
        $stmt = db()->prepare("SELECT id, role FROM messages WHERE conversation_id = ? AND id > ?
                               AND role IN ('user', 'assistant') AND reaction_reply = 0 ORDER BY id ASC LIMIT 1");
        $stmt->execute([$cid, $mid]);
        if (($next = $stmt->fetch()) && $next['role'] === 'assistant') {
            $ids[] = (int) $next['id'];
        }
    }

    $in     = implode(',', array_map('intval', $ids));
    $images = db_tx(static function (PDO $pdo) use ($cid, $ids, $in): array {
        $images = $pdo->query("SELECT image FROM messages WHERE conversation_id = " . (int) $cid
            . " AND id IN (" . $in . ") AND image <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $pdo->exec('DELETE FROM messages WHERE conversation_id = ' . (int) $cid . ' AND id IN (' . $in . ')');

        $min = min($ids);
        chat_invalidate_summary($cid, $min);
        // Le curseur des souvenirs durables ne doit pas pointer sur un message supprimé.
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
    $afterGeneration = isset($_GET['generation']);
    if ($afterGeneration) {
        header('Cache-Control: no-store');
        $chatLock = chat_try_lock($cid);
        if ($chatLock === false) {
            echo json_encode(['ok' => true, 'generating' => true]);
            exit;
        }
    }
    $rows   = fetch_messages($cid, $limit, $before);
    $oldest = $rows ? (int) $rows[0]['id'] : 0;
    $left   = $oldest > 0
        ? (int) db()->query('SELECT COUNT(*) FROM messages WHERE conversation_id = ' . (int) $cid . ' AND id < ' . $oldest)->fetchColumn()
        : 0;

    echo json_encode([
        'ok'       => true,
        'messages' => array_map(static fn (array $m): array => chat_message_json($m)
            + ($afterGeneration ? ['versions' => reply_versions_info($cid, $m)] : []), $rows),
        'left'     => $left,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- premier message et suggestions d'une discussion vide --- */
if ($action === 'opening' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close();
    ignore_user_abort(true);
    $chatLock = chat_require_write_lock($cid);
    $conv = chat_conv_effective($cid);
    if ($conv === null) {
        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
    $messages = fetch_messages($cid, 50);
    if ($messages || $conv['opening_questions'] !== '') {
        $questions = chat_opening_questions($conv, $messages, count($messages));
        echo json_encode(['ok' => true, 'messages' => array_map('chat_message_json', $messages), 'questions' => $questions], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $opening = chat_generate_opening($persona, $personaPrompts, $conv);
    $message = db_tx(static function (PDO $pdo) use ($cid, $opening): ?array {
        if ($opening['content'] === '' && !$opening['questions']) {
            return null;
        }
        $pdo->prepare('UPDATE conversations SET opening_questions = ? WHERE id = ?')
            ->execute([json_encode($opening['questions'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $cid]);
        if ($opening['content'] === '') {
            return null;
        }
        $pdo->prepare('INSERT INTO messages (conversation_id, role, content, model) VALUES (?, "assistant", ?, ?)')
            ->execute([$cid, $opening['content'], $opening['model']]);
        return [
            'id' => (int) $pdo->lastInsertId(),
            'role' => 'assistant',
            'content' => $opening['content'],
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    });
    echo json_encode(['ok' => true, 'message' => $message, 'questions' => $opening['questions']], JSON_UNESCAPED_UNICODE);
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
    // ‹ 1/3 › : seulement sous la dernière réponse (celle qu'on peut régénérer)
    $replyVersions = null;
    for ($i = count($messages) - 1; $i >= 0; $i--) {
        if ($messages[$i]['role'] === 'assistant' && !$messages[$i]['reaction_reply']) {
            $info = reply_versions_info($cid, $messages[$i]);
            $replyVersions = $info ? ['mid' => (int) $messages[$i]['id']] + $info : null;
            break;
        }
        if ((string) $messages[$i]['role'] === 'user') {
            break;
        }
    }
    $contactView = setting('ui_chat_list', 'threads') === 'contacts';
    $convs = db()->query(
        'SELECT c.id, c.persona_id, c.title, c.pinned, c.unread, p.name AS persona_name, p.avatar AS persona_avatar
             FROM conversations c JOIN personas p ON p.id = c.persona_id ORDER BY ' . ($contactView
            ? 'COALESCE((SELECT m.created_at FROM messages m WHERE m.conversation_id = c.id AND m.role IN ("user", "assistant")
                 ORDER BY m.id DESC LIMIT 1), c.created_at) DESC, c.id DESC'
            : 'c.pinned DESC, c.updated_at DESC LIMIT 30')
    )->fetchAll();
    $sideContacts = $contactView ? array_slice(chat_contacts($convs), 0, 30, true) : [];

    render('chat', [
        'title'     => $conv['title'],
        'conv'      => $conv,
        'convDirective' => $convDirective,
        'persona'   => $persona,
        'memory'    => chat_memory_stats($cid, $conv),
        'messages'  => $messages,
        'replyVersions' => $replyVersions,
        'totalMsgs' => $total,
        'personaMemories' => chat_persona_memories((int) $persona['id'], 12),
        'olderLeft' => max(0, $total - count($messages)),
        'convs'     => $convs,
        'contactView' => $contactView,
        'sideContacts' => $sideContacts,
        'personaPrompts' => $personaPrompts,
        'chatReady' => chat_llm_ready(),
        'chatModel' => chat_llm_config_raw()['model'],
        'models'    => chat_models_ordered(chat_models()),
    ]);
    exit;
}
