<?php
declare(strict_types=1);

/*
 * Une discussion : /chat/{id}[/action]. Charge la conversation et le persona,
 * puis délègue chaque action à src/routes/conversation/*.php.
 * Actions avec extension : export.txt et export.md (voir conversation/export.php).
 */

if (preg_match('#^/chat/(\d+)(?:/([\w-]+(?:\.(?:txt|md))?))?$#', $path, $m)) {
    $cid = (int) $m[1];
    $action = $m[2] ?? '';

    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$cid]);
    $conv = $stmt->fetch();
    if (!$conv) {
        http_response_code(404);
        render('notfound', ['title' => t('notfound.title')]);
        exit;
    }

    $stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
    $stmt->execute([(int) $conv['persona_id']]);
    $persona = $stmt->fetch();
    if (!$persona) {
        http_response_code(404);
        render('notfound', ['title' => t('notfound.title')]);
        exit;
    }

    $personaPrompts = fetch_prompts((int) $persona['id']);
    $conv           = chat_with_persona($conv, $persona);

    // une action = un bloc qui se termine par exit ; l'ordre est indifférent,
    // sauf l'affichage (action vide), placé en dernier dans messages.php
    require __DIR__ . '/conversation/stream.php';
    require __DIR__ . '/conversation/model.php';
    require __DIR__ . '/conversation/memory.php';
    require __DIR__ . '/conversation/manage.php';
    require __DIR__ . '/conversation/export.php';
    require __DIR__ . '/conversation/messages.php';
}
