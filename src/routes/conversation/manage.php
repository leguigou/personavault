<?php
declare(strict_types=1);

/*
 * Discussion : interrupteurs (mémoire, relances), titre, suppression.
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona, $personaPrompts).
 */

/* --- modèle de cette conversation seulement (pour tester ; vide = celui du persona) --- */
if ($action === 'model-override' && $method === 'POST') {
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $ajax ? csrf_check_json() : csrf_check();
    $override = mb_substr(trim((string) ($_POST['model'] ?? '')), 0, 200);
    // retour au modèle du persona : on oublie aussi le « dernier modèle utilisé » (le modèle de test)
    db()->prepare($override === ''
        ? "UPDATE conversations SET model_override = '', model = '' WHERE id = ?"
        : 'UPDATE conversations SET model_override = ? WHERE id = ?')
        ->execute($override === '' ? [$cid] : [$override, $cid]);
    $raw = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $raw->execute([$cid]);
    $eff = chat_with_persona((array) $raw->fetch(), $persona); // depuis la ligne brute
    $effective = (string) ($eff['model'] !== '' ? $eff['model'] : chat_llm_config_raw()['model']);
    // nouveau modèle : les autres sont déchargés et il commence à se charger tout de suite
    $purged = [];
    $loading = false;
    if (setting('lms_single_model', '1') === '1' && $effective !== '') {
        $st = lms_status();
        if ($st['reachable'] && (empty($st['available']) || in_array($effective, $st['available'], true))) {
            $purged = lms_make_room_for($effective, $st);
            $already = in_array($effective, array_merge(array_column($st['loaded'], 'key'), array_column($st['loaded'], 'id')), true);
            if (!$already) {
                $loading = lms_load_async($effective, max(2048, (int) ($eff['context_window'] ?? 8192)));
            }
        }
    }
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'       => true,
            'model'    => $effective,
            'override' => $override !== '',
            'purged'   => $purged,
            'loading'  => $loading,
            'message'  => $override !== '' ? t('flash.chat.model_override_set', ['model' => $override]) : t('flash.chat.model_override_cleared'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('/chat/' . $cid);
}

/* --- épingler / désépingler (bouton de la liste des discussions) --- */
if ($action === 'pin' && $method === 'POST') {
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $ajax ? csrf_check_json() : csrf_check();
    $pinned = !empty($_POST['pinned']) ? 1 : 0;
    db()->prepare('UPDATE conversations SET pinned = ? WHERE id = ?')->execute([$pinned, $cid]);
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'pinned' => (bool) $pinned], JSON_UNESCAPED_UNICODE);
        exit;
    }
    redirect('/chat');
}

/* --- options propres à la conversation (mémoire / relances / épinglage) --- */
if ($action === 'toggles' && $method === 'POST') {
    csrf_check();
    // sans toucher à updated_at : changer un réglage ne remonte pas la conversation dans la liste
    db()->prepare('UPDATE conversations SET memory_enabled = ?, auto_followup = ?, pinned = ? WHERE id = ?')
        ->execute([
            !empty($_POST['memory_enabled']) ? 1 : 0,
            !empty($_POST['auto_followup']) ? 1 : 0,
            !empty($_POST['pinned']) ? 1 : 0,
            $cid,
        ]);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        // interrupteur basculé dans le panneau : enregistré tout de suite, sans recharger
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => t('flash.chat.toggles_saved')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('ok', t('flash.chat.toggles_saved'));
    redirect('/chat/' . $cid);
}


/* --- consigne propre à cette conversation (s'ajoute à celle du persona) --- */
if ($action === 'directive' && $method === 'POST') {
    $ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $ajax ? csrf_check_json() : csrf_check();
    $directive = mb_substr(trim((string) ($_POST['directive'] ?? '')), 0, 2000);
    db()->prepare('UPDATE conversations SET directive = ?, updated_at = datetime("now") WHERE id = ?')->execute([$directive, $cid]);
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'message' => t('flash.chat.directive_saved')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash('ok', t('flash.chat.directive_saved'));
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
    $chatLock = chat_try_lock($cid);
    if ($chatLock === false) {
        flash('error', t('error.chat.generation_in_progress'));
        redirect('/chat/' . $cid);
    }
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
