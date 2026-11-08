<?php
declare(strict_types=1);

/*
 * Liste des discussions, modèles disponibles, nouvelle discussion.
 */

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
        'title'       => t('nav.chats'),
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
        flash('error', t('flash.chat.pick_persona'));
        redirect('/chat');
    }
    $stmt = db()->prepare('INSERT INTO conversations (persona_id, title) VALUES (?, ?)');
    // titre par défaut enregistré en base (affiché, jamais envoyé au modèle) : langue de l'interface
    $stmt->execute([$pid, t('labels.chat.default_title', ['name' => $persona['name']])]);
    redirect('/chat/' . (int) db()->lastInsertId());
}
