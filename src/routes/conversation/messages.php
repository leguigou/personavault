<?php
declare(strict_types=1);

/*
 * Discussion : recherche, édition / suppression de messages, affichage (en dernier).
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

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

/* --- modifier un message --- */
if ($action === 'msg-edit' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
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
        echo json_encode(['ok' => false, 'error' => t('error.message.not_found')]);
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
            'at'       => gmdate('Y-m-d\\TH:i:s\\Z', (int) strtotime((string) $m['created_at'] . ' UTC')),
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
        'convDirective' => $convDirective,
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
