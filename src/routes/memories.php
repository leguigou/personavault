<?php
declare(strict_types=1);

/*
 * Page Souvenirs : liste, ajout, modification, suppression.
 */

/* --------------------------------------------------------------- souvenirs */

if ($path === '/memories' && $method === 'GET') {
    $q    = trim((string) ($_GET['q'] ?? ''));
    $rows = facts_list(null, $q);
    $personas = db()->query('SELECT id, name FROM personas ORDER BY name')->fetchAll();
    render('memories', [
        'title'    => t('labels.title.memories'),
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
        flash('error', t('flash.memory.empty'));
        redirect('/memories');
    }
    facts_add(
        (int) ($_POST['persona_id'] ?? 0),
        $content,
        (string) ($_POST['category'] ?? 'fait'),
        (string) ($_POST['confidence'] ?? 'confirme'),
        null
    );
    flash('ok', t('flash.memory.added'));
    redirect('/memories');
}

if (preg_match('#^/memories/(\d+)(?:/(\w+))?$#', $path, $m) && $method === 'POST') {
    csrf_check();
    $mid    = (int) $m[1];
    $action = $m[2] ?? '';
    if ($action === 'delete') {
        facts_delete($mid);
        flash('ok', t('flash.memory.deleted'));
    } else {
        facts_update(
            $mid,
            (string) ($_POST['content'] ?? ''),
            (string) ($_POST['category'] ?? 'fait'),
            (string) ($_POST['confidence'] ?? 'confirme')
        );
        flash('ok', t('flash.memory.updated'));
    }
    redirect('/memories');
}
