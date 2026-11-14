<?php
declare(strict_types=1);

/*
 * Liste des discussions, modèles disponibles, nouvelle discussion.
 */

/* --------------------------------------------------------------------- chat */

if ($path === '/chat/unread' && $method === 'GET') {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['unread' => chat_has_unread()]);
    exit;
}

if ($path === '/chat/models' && $method === 'GET') {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['models' => chat_models()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/chat' && $method === 'GET') {
    $contactView = setting('ui_chat_list', 'threads') === 'contacts';
    $convStmt = db()->query(
        'SELECT c.*, p.name AS persona_name, p.avatar AS persona_avatar, p.slug AS persona_slug,
                (SELECT content FROM messages m WHERE m.conversation_id = c.id AND m.role NOT IN ("notice", "memory") ORDER BY m.id DESC LIMIT 1) AS last_msg,
                (SELECT role FROM messages m WHERE m.conversation_id = c.id AND m.role NOT IN ("notice", "memory") ORDER BY m.id DESC LIMIT 1) AS last_role,
                (SELECT image FROM messages m WHERE m.conversation_id = c.id AND m.role NOT IN ("notice", "memory") ORDER BY m.id DESC LIMIT 1) AS last_image,
                (SELECT created_at FROM messages m WHERE m.conversation_id = c.id AND m.role NOT IN ("notice", "memory") ORDER BY m.id DESC LIMIT 1) AS last_at,
                (SELECT COUNT(*) FROM messages m2 WHERE m2.conversation_id = c.id AND m2.role NOT IN ("notice", "memory")) AS n
         FROM conversations c JOIN personas p ON p.id = c.persona_id
         ORDER BY ' . ($contactView ? 'COALESCE(last_at, c.created_at)' : 'c.pinned DESC, COALESCE(last_at, c.updated_at)') . ' DESC, c.id DESC'
    );
    $conversations = $convStmt->fetchAll();
    render('chat_list', [
        'title'       => t('nav.chats'),
        'conversations' => $conversations,
        'contacts'    => $contactView ? chat_contacts($conversations) : [],
        'contactView' => $contactView,
        'chatReady'   => chat_llm_ready(),
        'chatModel'   => chat_llm_config_raw()['model'],
    ]);
    exit;
}

if ($path === '/chat' && $method === 'POST') {
    csrf_check();
    $pid = (int) ($_POST['persona_id'] ?? 0);
    $stmt = db()->prepare('SELECT id, name FROM personas WHERE id = ?');
    $stmt->execute([$pid]);
    $persona = $stmt->fetch();
    $cid = $persona ? chat_create_conversation($persona, !empty($_POST['resume'])) : 0;
    if (!$cid) {
        flash('error', t('flash.chat.pick_persona'));
        redirect('/chat');
    }
    redirect('/chat/' . $cid);
}
