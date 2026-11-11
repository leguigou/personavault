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
        // modèles qui lisent les images : 👁 dans la liste « Modèle de cette conversation »
        'vision_models' => array_keys(array_filter($st['types'], static fn ($t) => $t === 'vlm')),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- résultat du chargement en cours : réussi, échoué (avec le message de LM Studio) ou pas fini --- */
if ($action === 'load-result' && $method === 'GET') {
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    $r = lms_load_result();
    if (($r['done'] ?? false) && !($r['ok'] ?? false)) {
        $r['hint'] = lms_load_hint((string) $r['error']);
    }
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
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
            'error' => t('error.lms.down'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // le modèle de la conversation, et lui seul : s'il n'existe pas dans LM Studio, on le dit
    // (avant, un autre modèle était chargé à sa place sans prévenir)
    $model = (string) ($conv['model'] !== '' ? $conv['model'] : chat_llm_config()['model']);
    if ($model === '' || (!empty($st['available']) && !in_array($model, $st['available'], true))) {
        echo json_encode([
            'ok'    => false,
            'kind'  => 'not_found',
            'model' => $model,
            'error' => $model === '' ? t('error.lms.not_downloaded') : t('error.lms.model_missing', ['model' => $model]),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 1. libérer la mémoire : les autres modèles chargés sont déchargés avant le chargement
    $purge = lms_purge_except($model);

    $ids   = array_column($st['loaded'], 'id');
    $keys  = array_column($st['loaded'], 'key');
    if (in_array($model, $ids, true) || in_array($model, $keys, true)) {
        echo json_encode([
            'ok'      => true,
            'already' => true,
            'model'   => $model,
            'purged'  => $purge['unloaded'],
            'purge_failed' => $purge['failed'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $ctx = max(2048, (int) ($conv['context_window'] ?? 8192));
    $ok  = lms_load_async($model, $ctx);
    echo json_encode([
        'ok'      => $ok,
        'started' => $ok,
        'purged'  => $purge['unloaded'],
        'purge_failed' => $purge['failed'],
        'model'   => $model,
        'ctx'     => $ctx,
        'error'   => $ok ? null : t('error.lms.load_failed'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
