<?php
declare(strict_types=1);

/*
 * Fiche : affichage (toute action non reconnue retombe ici).
 * Inclus en dernier par src/routes/persona.php.
 */

if ($action === 'prompt-audit' && in_array($method, ['GET', 'POST'], true)) {
    if ($method === 'POST') {
        csrf_check();
    }
    header('Cache-Control: no-store');
    require_once __DIR__ . '/../../persona_prompt_audit.php';
    $stmt = db()->prepare('SELECT * FROM conversations WHERE persona_id = ? ORDER BY updated_at DESC, id DESC');
    $stmt->execute([$pid]);
    $conversations = array_column($stmt->fetchAll(), null, 'id');
    $selectedId = (int) filter_input(INPUT_POST, 'conversation_id', FILTER_VALIDATE_INT);
    $selected = $conversations[$selectedId] ?? [];
    $message = trim((string) filter_input(INPUT_POST, 'message'));
    if ($message === '') {
        $message = t('persona.audit.example');
    }
    render('persona_prompt_audit', [
        'title' => t('persona.audit.title'),
        'persona' => $persona,
        'conversations' => $conversations,
        'selected' => $selected,
        'message' => $message,
        'audit' => persona_prompt_audit($persona, fetch_prompts($pid), $selected, $message),
    ]);
    exit;
}

/* --- détail --- */
$photos  = fetch_photos($pid);
$prompts = fetch_prompts($pid);
render('detail', [
    'title'   => $persona['name'],
    'persona' => $persona,
    'photos'  => $photos,
    'prompts' => $prompts,
    'latestConversation' => chat_latest_conversation($pid),
]);
exit;
