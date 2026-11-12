<?php
declare(strict_types=1);

/*
 * Discussion : résumé glissant et souvenirs durables.
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

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
    if ((int) ($conv['facts_enabled'] ?? 1) !== 1) {
        echo json_encode([
            'ok'       => true,
            'added'    => [],
            'skipped'  => t('labels.facts_skip.disabled_here'),
            'memories' => chat_persona_memories((int) $persona['id']),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cursor = (int) ($conv['facts_cursor'] ?? 0);
    $st = db()->prepare("SELECT * FROM messages WHERE conversation_id = ? AND id > ?
                             AND role IN ('user', 'assistant') ORDER BY id ASC");
    $st->execute([$cid, $cursor]);
    $recent = $st->fetchAll();

    // demande explicite : « souviens-toi », « remember that », « recuerda que »… (voir facts_explicit_request)
    $lastUser = '';
    foreach (array_reverse($recent) as $m) {
        if ((string) $m['role'] === 'user') {
            $lastUser = (string) $m['content'];
            break;
        }
    }
    $explicit = facts_explicit_request($lastUser);

    // fréquence choisie dans les réglages (chaque échange, tous les N messages, sur demande…)
    $why = facts_should_run(count($recent), $explicit, !empty($_POST['force']));
    if ($why !== '') {
        echo json_encode(['ok' => true, 'added' => [], 'skipped' => $why, 'memories' => chat_persona_memories((int) $persona['id'])], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        // jusqu'à 30 messages : en mode « tous les N », rien n'est sauté
        $slice = array_slice($recent, -30);
        $res   = facts_extract($cid, (int) $persona['id'], $slice, $explicit);
        if (($res['ok'] ?? false) && $slice) {
            $lastId = (int) end($slice)['id'];
            db()->prepare('UPDATE conversations SET facts_cursor = ? WHERE id = ?')->execute([$lastId, $cid]);
        }
        $res['memories'] = chat_persona_memories((int) $persona['id']);

        // trace visible dans la conversation : la mémoire a bien été mise à jour.
        // Enregistrée en base (rôle « memory ») dans la langue de l'interface : ce rôle n'est
        // jamais renvoyé au modèle (seuls « user » et « assistant » le sont).
        $added = array_values(array_filter(array_map('strval', $res['added'] ?? [])));
        if ($added) {
            $label = implode(' · ', array_map(
                static fn (string $s): string => mb_substr($s, 0, 90),
                array_slice($added, 0, 2)
            ));
            $label = t('labels.chat.memory_updated', ['facts' => $label]) . (count($added) > 2 ? ' (+' . (count($added) - 2) . ')' : '');
            $ins   = db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "memory", ?)');
            $ins->execute([$cid, $label]);
            $res['memory_msg'] = ['id' => (int) db()->lastInsertId(), 'role' => 'memory', 'content' => $label];
        }

        echo json_encode($res, JSON_UNESCAPED_UNICODE);
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
