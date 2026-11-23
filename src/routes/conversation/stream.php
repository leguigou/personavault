<?php
declare(strict_types=1);

/*
 * Discussion : envoi d’un message et réponse en streaming (SSE).
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

/* --- envoi d'un message en streaming (SSE) --- */
if ($action === 'stream' && $method === 'POST') {
    csrf_check_json();
    session_write_close(); // le flux peut durer plusieurs minutes : on libère la session
    $regen = ((string) ($_POST['regen'] ?? '')) === '1';
    $godmode = !$regen && ($_POST['godmode'] ?? '') === '1';
    // Un onglet ouvert avant la mise à jour peut encore demander cette ancienne relance.
    if (!$regen && (int) ($_POST['reaction_mid'] ?? 0) > 0) {
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-transform');
        echo "data: {\"done\":true,\"skipped\":true}\n\n";
        exit;
    }
    $text  = trim((string) ($_POST['message'] ?? ''));
    $hasImage = !$regen && !$godmode && upload_present($_FILES['image'] ?? null);
    if (!$regen && $text === '' && !$hasImage) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.message.empty_send')]);
        exit;
    }
    if ($godmode && mb_strlen($text) > CHAT_GODMODE_MAX) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => t('chat.godmode.too_long')], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $chatLock = chat_require_write_lock($cid);
    // Relire la conversation sous verrou : un autre flux a pu finir depuis le routage.
    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$cid]);
    $current = $stmt->fetch();
    $stmt->closeCursor();
    if (!$current) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('notfound.title')]);
        exit;
    }
    $conv = chat_with_persona($current, $persona);

    ignore_user_abort(true);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');

    $sse = static function (array $payload): void {
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    };
    $ping = static function (): bool {
        echo ": ping\n\n";
        flush();
        return connection_aborted() !== 0;
    };

    chat_turn($cid, $conv, $persona, $personaPrompts, [
        'text'          => $text,
        'regen'         => $regen,
        'godmode'       => $godmode,
        'image'         => $hasImage ? $_FILES['image'] : null,
        'reply_to'      => $_POST['reply_to'] ?? null,
        'reply_bubble'  => $_POST['reply_bubble'] ?? 0,
        'reply_version' => $_POST['reply_version'] ?? 0,
        'regen_mid'     => $_POST['regen_mid'] ?? 0,
        'regen_reply'   => $_POST['regen_reply'] ?? 0,
        'fallback'      => ((string) ($_POST['fallback'] ?? '')) === '1',
        'finish_request' => true,
    ], $sse, $ping, $chatLock);
    exit;
}
