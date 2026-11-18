<?php
declare(strict_types=1);

/*
 * Discussion : résumé glissant et souvenirs durables.
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

if ($action === 'relationship' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close();
    ignore_user_abort(true);
    $pending = relationship_memory_on($conv) && relationship_run((int) $persona['id']);
    echo json_encode(['ok' => true, 'pending' => $pending]);
    exit;
}

/* --- compression mémoire (AJAX, après la réponse) --- */
if ($action === 'compress' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    $chatLock = chat_require_write_lock($cid);
    try {
        $conv = chat_conv_effective($cid);
        if ($conv === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => t('notfound.title')]);
            exit;
        }
        $cres = chat_compress($cid, $conv, !empty($_POST['force']));
        $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
        $stmt->execute([$cid]);
        $fresh  = chat_with_persona($stmt->fetch(), $persona);
        $memory = chat_memory_stats($cid, $fresh);
        echo json_encode([
            'ok'         => $cres['ok'],
            'compressed' => $cres['compressed'] ?? 0,
            'reason'     => $cres['reason'] ?? '',
            'summary'    => (string) ($fresh['summary'] ?? ''),
            'memory'     => $memory,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* --- extraction des souvenirs durables (AJAX, après la réponse) --- */
if ($action === 'remember' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    try {
        echo json_encode(facts_remember($cid, $conv, $persona, !empty($_POST['force'])), JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* --- mémoire : enregistrer / effacer le résumé --- */
if ($action === 'memory' && $method === 'POST') {
    csrf_check();
    $op = (string) ($_POST['op'] ?? '');
    if ($op === 'clear') {
        db()->prepare('UPDATE conversations SET summary = "", last_compressed_id = 0, compressions = 0, updated_at = datetime("now") WHERE id = ?')
            ->execute([$cid]);
        flash('ok', t('flash.chat.memory_cleared'));
    } elseif ($op === 'save') {
        $summary = trim((string) ($_POST['summary'] ?? ''));
        db()->prepare('UPDATE conversations SET summary = ?, updated_at = datetime("now") WHERE id = ?')
            ->execute([mb_substr($summary, 0, 4000), $cid]);
        flash('ok', t('flash.chat.summary_saved'));
    }
    redirect('/chat/' . $cid);
}

/* --- activer/désactiver les souvenirs partagés sur cette conversation --- */
if ($action === 'facts-toggle' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    $on = ((string) ($_POST['enabled'] ?? '')) === '1' ? 1 : 0;
    db()->prepare('UPDATE conversations SET facts_enabled = ? WHERE id = ?')->execute([$on, $cid]);
    echo json_encode(['ok' => true, 'enabled' => $on], JSON_UNESCAPED_UNICODE);
    exit;
}

/* --- souvenirs partagés : ajout / suppression depuis la conversation --- */
if (($action === 'fact-add' || $action === 'fact-delete') && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    $pid = (int) $persona['id'];

    if ($action === 'fact-add') {
        $content = trim((string) ($_POST['content'] ?? ''));
        if ($content === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => t('error.memory.empty')]);
            exit;
        }
        $cat = (string) ($_POST['category'] ?? 'fait');
        if (!array_key_exists($cat, facts_categories())) {
            $cat = 'fait';
        }
        $id = facts_add($pid, $content, $cat, 'confirme', $cid);
        echo json_encode(['ok' => true, 'id' => $id, 'memories' => chat_persona_memories($pid)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fid  = (int) ($_POST['fid'] ?? 0);
    $stmt = db()->prepare('SELECT persona_id FROM user_memories WHERE id = ?');
    $stmt->execute([$fid]);
    $owner = $stmt->fetchColumn();
    if ($owner === false) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('error.memory.not_found')]);
        exit;
    }
    if ($owner !== null && (int) $owner !== $pid) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => t('error.memory.other_persona')]);
        exit;
    }
    facts_delete($fid);
    echo json_encode(['ok' => true, 'memories' => chat_persona_memories($pid)], JSON_UNESCAPED_UNICODE);
    exit;
}
