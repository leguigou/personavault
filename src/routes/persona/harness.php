<?php
declare(strict_types=1);

/*
 * Fiche : test de la voix du persona (« harnais »).
 * Inclus par src/routes/persona.php ($persona, $pid, $slug, $action, $parts, $method).
 *
 *   GET  /p/{slug}/harness          page de test
 *   POST /p/{slug}/harness          lance le job en arrière-plan (JSON)
 *   GET  /p/{slug}/harness/status   état d'avancement (JSON)
 */

$hAction = $parts[3] ?? '';
$job = harness_job_get($slug);

if ($action !== 'harness') {
    return;
}

/* --- état du job --- */
if ($hAction === 'status' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'job' => harness_job_get($slug)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- lancer un test --- */
if ($hAction === '' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue : ne bloque pas les autres onglets

    $runs = max(1, min(5, (int) ($_POST['runs'] ?? 3)));
    $provider = harness_provider_clean((string) ($_POST['provider'] ?? 'local'));
    $current = harness_job_get($slug);
    if ($current !== null && ($current['state'] ?? '') === 'running'
        && (int) ($current['started'] ?? 0) > time() - 300) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => t('harness.busy')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!harness_config_ready($provider)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t($provider === 'remote' ? 'harness.no_remote' : 'harness.no_local')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    harness_job_put($slug, [
        'state'    => 'running',
        'step'     => 'demarrage',
        'done'     => 0,
        'total'    => 0,
        'runs'     => $runs,
        'provider' => $provider,
        'started'  => time(),
    ]);

    if (!harness_spawn($slug, $runs, $provider)) {
        harness_job_put($slug, ['state' => 'error', 'error' => t('harness.no_spawn'), 'finished' => time()]);
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => t('harness.no_spawn')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['ok' => true, 'runs' => $runs], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- page de test --- */
if ($hAction === '' && $method === 'GET') {
    $lastProvider = harness_provider_clean((string) ($job['provider'] ?? 'local'));
    render('harness', [
        'title'     => t('harness.title', ['name' => $persona['name']]),
        'persona'   => $persona,
        'job'       => $job,
        'runs'      => 3,
        'can_run'   => duo_can_spawn(),
        'provider'  => $lastProvider,
        'providers' => [
            'local'  => ['ready' => harness_config_ready('local'),  'model' => harness_config_label('local')],
            'remote' => ['ready' => harness_config_ready('remote'), 'model' => harness_config_label('remote')],
        ],
    ]);
    exit;
}

http_response_code(404);
render('notfound', ['title' => t('notfound.title')]);
exit;
