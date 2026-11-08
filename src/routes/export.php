<?php
declare(strict_types=1);

/*
 * Export JSON de la bibliothèque.
 */

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
