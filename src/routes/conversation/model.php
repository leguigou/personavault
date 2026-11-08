<?php
declare(strict_types=1);

/*
 * Discussion : état du serveur de modèles et chargement du modèle.
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

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
