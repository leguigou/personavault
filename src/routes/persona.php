<?php
declare(strict_types=1);

/*
 * Une fiche : /p/{slug}[/action]. Charge le persona puis délègue à src/routes/persona/*.php.
 */

if (count($parts) >= 2 && $p0 === 'p') {
    $slug = $parts[1];
    $stmt = db()->prepare('SELECT * FROM personas WHERE slug = ?');
    $stmt->execute([$slug]);
    $persona = $stmt->fetch();
    if (!$persona) {
        http_response_code(404);
        render('notfound', ['title' => t('notfound.title')]);
        exit;
    }
    $pid = (int) $persona['id'];

    $action = $parts[2] ?? '';

    require __DIR__ . '/persona/ai.php';
    require __DIR__ . '/persona/edit.php';
    require __DIR__ . '/persona/media.php';
    require __DIR__ . '/persona/cards.php';
    require __DIR__ . '/persona/show.php'; // affichage par défaut, en dernier
}
