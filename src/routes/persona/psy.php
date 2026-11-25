<?php
declare(strict_types=1);

/*
 * Fiche : empreinte psychologique (src/psy.php).
 * Inclus par src/routes/persona.php ($persona, $pid, $slug, $action, $parts, $method).
 *
 *   GET  /p/{slug}/psy[?run=N]   page : dernier résultat (ou le test N), historique, progression
 *   POST /p/{slug}/psy           lance le test en arrière-plan (JSON)
 *   GET  /p/{slug}/psy/status    état d'avancement (JSON)
 */

if ($action !== 'psy') {
    return;
}
$psyAction = $parts[3] ?? '';

if ($psyAction === 'status' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => true, 'job' => psy_job_get($slug)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($psyAction === '' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close();
    $fail = static function (int $status, string $error): never {
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (psy_job_running(psy_job_get($slug))) {
        $fail(409, t('psy.busy'));
    }
    if (!psy_ready()) {
        $fail(400, t('psy.not_ready'));
    }
    $gridKey = (string) ($_POST['grid'] ?? 'personnalite');
    $custom = psy_parse_custom((string) ($_POST['custom'] ?? ''));
    try {
        $grid = psy_grid($gridKey, $custom);
    } catch (RuntimeException $e) {
        $fail(400, $e->getMessage());
    }
    // la grille passe au processus d'arrière-plan par le fichier de la tâche
    psy_job_put($slug, ['state' => 'running', 'step' => 'demarrage', 'done' => 0, 'total' => 0, 'started' => time(), 'beat' => time(),
        'grid' => $grid['key'], 'grid_name' => $grid['name'], 'custom' => $grid['key'] === 'custom' ? $custom : []]);
    if (!psy_spawn($slug)) {
        psy_job_put($slug, ['state' => 'error', 'error' => t('psy.no_spawn'), 'finished' => time()]);
        $fail(500, t('psy.no_spawn'));
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($psyAction === '' && $method === 'GET') {
    $runs = psy_runs($pid);
    $runId = (int) ($_GET['run'] ?? ($runs[0]['id'] ?? 0));
    render('psy', [
        'title'   => t('psy.title', ['name' => $persona['name']]),
        'persona' => $persona,
        'job'     => psy_job_get($slug),
        'runs'    => $runs,
        'run'     => $runId > 0 ? psy_run_get($pid, $runId) : null,
        'ready'   => psy_ready(),
        'can_run' => duo_can_spawn(),
    ]);
    exit;
}

http_response_code(404);
render('notfound', ['title' => t('notfound.title')]);
exit;
