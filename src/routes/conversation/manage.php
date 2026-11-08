<?php
declare(strict_types=1);

/*
 * Discussion : interrupteurs (mémoire, relances), titre, suppression.
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

/* --- options propres à la conversation (mémoire / relances) --- */
if ($action === 'toggles' && $method === 'POST') {
    csrf_check();
    db()->prepare('UPDATE conversations SET memory_enabled = ?, auto_followup = ?, updated_at = datetime("now") WHERE id = ?')
        ->execute([
            !empty($_POST['memory_enabled']) ? 1 : 0,
            !empty($_POST['auto_followup']) ? 1 : 0,
            $cid,
        ]);
    flash('ok', t('flash.chat.toggles_saved'));
    redirect('/chat/' . $cid);
}


if ($action === 'rename' && $method === 'POST') {
    csrf_check();
    $title = trim((string) ($_POST['title'] ?? ''));
    db()->prepare('UPDATE conversations SET title = ?, title_auto = 0, updated_at = datetime("now") WHERE id = ?')
        ->execute([$title !== '' ? mb_substr($title, 0, 120) : $conv['title'], $cid]);
    redirect('/chat/' . $cid);
}

if ($action === 'delete' && $method === 'POST') {
    csrf_check();
    $stmt = db()->prepare("SELECT image FROM messages WHERE conversation_id = ? AND image <> ''");
    $stmt->execute([$cid]);
    $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
    db()->prepare('DELETE FROM conversations WHERE id = ?')->execute([$cid]);
    release_media(...array_map('strval', $images)); // photos envoyées dans le chat
    flash('ok', t('flash.chat.deleted'));
    redirect('/chat');
}

/* --- titre automatique de la conversation --- */
if ($action === 'autotitle' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    try {
        $title = chat_auto_title($cid);
        echo json_encode($title ? ['ok' => true, 'title' => $title] : ['ok' => false], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
