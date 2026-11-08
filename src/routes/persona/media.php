<?php
declare(strict_types=1);

/*
 * Fiche : ajout de photos et de prompts.
 * Inclus par src/routes/persona.php ($persona, $pid, $slug, $action).
 */

if ($action === 'photos' && $method === 'POST') {
    // appel AJAX (collage presse-papier) → réponse JSON, sinon redirection classique
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

    if ($wantsJson) {
        csrf_check_json();
        session_write_close(); // réponse JSON : aucun message flash à écrire en session
    } else {
        csrf_check();
    }

    $files  = $_FILES['photos'] ?? null;
    $n      = 0;
    $errors = [];
    if ($files && is_array($files['tmp_name'] ?? null)) {
        foreach ($files['tmp_name'] as $i => $tmp) {
            $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            try {
                $base = store_upload([
                    'tmp_name' => $tmp,
                    'error'    => $err,
                    'size'     => $files['size'][$i] ?? 0,
                ]);
                $stmt = db()->prepare('INSERT INTO photos (persona_id, file, caption, sort) VALUES (?,?,?,?)');
                $stmt->execute([$pid, $base, '', time()]);
                $n++;
            } catch (Throwable $e) {
                $msg      = public_error($e);
                $errors[] = $msg;
                if (!$wantsJson) {
                    flash('error', $msg);
                }
            }
        }
    }

    // pas encore de portrait : la première image collée devient l'avatar
    if ($n > 0 && (string) $persona['avatar'] === '') {
        $stmt = db()->prepare('SELECT file FROM photos WHERE persona_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$pid]);
        $last = (string) $stmt->fetchColumn();
        if ($last !== '') {
            db()->prepare('UPDATE personas SET avatar = ? WHERE id = ?')->execute([$last, $pid]);
        }
    }

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        if ($n === 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $errors[0] ?? t('error.upload.no_image')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        db()->prepare('UPDATE personas SET updated_at=datetime("now") WHERE id=?')->execute([$pid]);
        echo json_encode(['ok' => true, 'added' => $n], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($n > 0) {
        db()->prepare('UPDATE personas SET updated_at=datetime("now") WHERE id=?')->execute([$pid]);
        flash('ok', t('flash.persona.photos_added', $n));
    } else {
        flash('error', t('flash.persona.no_photo'));
    }
    redirect('/p/' . $slug);
}

if ($action === 'prompts' && $method === 'POST') {
    csrf_check();
    $body = trim((string) ($_POST['body'] ?? ''));
    if ($body === '') {
        flash('error', t('flash.prompt.empty'));
        redirect('/p/' . $slug);
    }
    $stmt = db()->prepare('INSERT INTO prompts (persona_id, title, kind, body) VALUES (?,?,?,?)');
    $stmt->execute([
        $pid,
        trim((string) ($_POST['title'] ?? '')),
        array_key_exists((string) ($_POST['kind'] ?? ''), prompt_kinds()) ? (string) $_POST['kind'] : 'image',
        $body,
    ]);
    db()->prepare('UPDATE personas SET updated_at=datetime("now") WHERE id=?')->execute([$pid]);
    flash('ok', t('flash.prompt.saved'));
    redirect('/p/' . $slug);
}
