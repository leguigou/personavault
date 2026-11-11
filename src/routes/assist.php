<?php
declare(strict_types=1);

/*
 * Assistant de création de persona (voir src/assist.php) :
 *   GET  /p/assist          nouveau persona
 *   GET  /p/{slug}/assist   compléter une fiche existante
 *   POST /assist/turn       un tour de conversation (JSON)
 *   POST /assist/create     créer la fiche, ou l'appliquer sans rien écraser (JSON)
 */

/** Persona ciblé par une requête de l'assistant (slug facultatif), avec ses prompts. */
$assistTarget = static function (string $slug): array {
    if ($slug === '') {
        return [null, []];
    }
    $st = db()->prepare('SELECT * FROM personas WHERE slug = ?');
    $st->execute([$slug]);
    $persona = $st->fetch() ?: null;
    if (!$persona) {
        throw new RuntimeException(t('notfound.title'));
    }
    return [$persona, fetch_prompts((int) $persona['id'])];
};

if ($method === 'GET' && preg_match('#^/p/(?:([a-z0-9-]+)/)?assist$#', $path, $m)) {
    $slug    = $m[1] ?? '';
    $persona = null;
    $prompts = [];
    if ($slug !== '') {
        try {
            [$persona, $prompts] = $assistTarget($slug);
        } catch (Throwable $e) {
            http_response_code(404);
            render('notfound', ['title' => t('notfound.title')]);
            exit;
        }
    }
    $draft = $persona ? assist_from_persona($persona) : assist_clean([]);
    render('assist', [
        'title'    => $persona ? t('assist.title_complete', ['name' => $persona['name']]) : t('assist.title_new'),
        'persona'  => $persona,
        'draft'    => $draft,
        'progress' => assist_progress($draft, $prompts),
        'ready'    => llm_ready(),
    ]);
    exit;
}

if ($method === 'POST' && ($path === '/assist/turn' || $path === '/assist/create')) {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // appel long : ne bloque pas les autres requêtes
    @set_time_limit(300); // personnage existant : recherche en ligne + deux appels au modèle
    if (!llm_ready()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.ai.configure_settings')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        [$persona, $prompts] = $assistTarget(trim((string) ($_POST['slug'] ?? '')));
        $draft = json_decode((string) ($_POST['draft'] ?? '{}'), true);
        $draft = is_array($draft) ? $draft : [];
        if ($path === '/assist/turn') {
            $history = json_decode((string) ($_POST['history'] ?? '[]'), true);
            $action  = (string) ($_POST['action'] ?? 'reply');
            $action  = in_array($action, ['start', 'reply', 'suggest'], true) ? $action : 'reply';
            $res     = assist_turn($draft, is_array($history) ? $history : [], $action, (string) ($_POST['answer'] ?? ''), $persona, $prompts);
            echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
            exit;
        }
        $final = assist_finalize($draft, $persona, $prompts);
        session_start(); // rouverte pour le message affiché sur la fiche
        if ($persona) {
            $changes = assist_apply($persona, $prompts, $final);
            flash('ok', $changes
                ? t('assist.flash.applied', ['fields' => implode(t('labels.list_separator'), $changes)])
                : t('labels.ai.nothing_to_fill'));
            echo json_encode(['ok' => true, 'redirect' => '/p/' . $persona['slug']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $slug = assist_create($final);
        flash('ok', t('assist.flash.created', ['name' => $final['draft']['name']]));
        echo json_encode(['ok' => true, 'redirect' => '/p/' . $slug], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
