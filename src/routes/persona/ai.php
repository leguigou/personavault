<?php
declare(strict_types=1);

/*
 * Fiche : complétion et consigne de jeu proposées par l’IA (AJAX).
 * Inclus par src/routes/persona.php ($persona, $pid, $slug, $action).
 */

/* --- complétion IA (AJAX) --- */
/* --- proposer une consigne de jeu à partir du prompt système --- */
if ($action === 'ai-directive' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    if (!llm_ready()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.ai.configure_settings')]);
        exit;
    }
    try {
        $proposal = persona_ai_directive($persona, fetch_prompts($pid));
        echo json_encode(['ok' => true] + $proposal, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* --- rédiger / améliorer un prompt avec l'IA (éditeur de prompt) --- */
if ($action === 'ai-prompt' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    if (!llm_ready()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.ai.configure_settings')]);
        exit;
    }
    try {
        $res = llm_visual_prompt(
            $persona,
            fetch_prompts($pid),
            (string) ($_POST['kind'] ?? 'image'),
            mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120),
            mb_substr((string) ($_POST['body'] ?? ''), 0, 20000),
            (int) ($_POST['prompt_id'] ?? 0),
            mb_substr(trim((string) ($_POST['instruction'] ?? '')), 0, 1000),
            (string) ($_POST['mode'] ?? '')
        );
        echo json_encode(['ok' => true] + $res, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

