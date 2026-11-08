<?php
declare(strict_types=1);

/*
 * Fiche : mise à jour, formulaire, suppression, favori, archive.
 * Inclus par src/routes/persona.php ($persona, $pid, $slug, $action).
 */

/* --- mise à jour --- */
if ($action === '' && $method === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash('error', 'Le nom ne peut pas être vide.');
        redirect('/p/' . $slug . '/edit');
    }
    $newSlug = unique_slug($name, $pid) === $slug ? $slug : unique_slug($name, $pid);
    $tags    = parse_tags((string) ($_POST['tags'] ?? ''));
    $meta    = parse_meta((string) ($_POST['meta'] ?? ''));
    $avatar  = $persona['avatar'];
    try {
        if (upload_present($_FILES['avatar'] ?? null)) {
            $avatar = store_upload($_FILES['avatar']);
        }
    } catch (Throwable $e) {
        flash('error', 'Avatar : ' . public_error($e));
    }
    $aiModel  = trim((string) ($_POST['model'] ?? ''));
    $aiTemp   = max(0.0, min(2.0, (float) ($_POST['temperature'] ?? 0.85)));
    $aiMax    = max(64, min(4096, (int) ($_POST['max_tokens'] ?? 400)));
    $aiCtx    = max(2048, min(131072, (int) ($_POST['context_window'] ?? 8192)));
    $aiDirect = trim((string) ($_POST['directive'] ?? ''));

    $stmt = db()->prepare('UPDATE personas SET slug=?, name=?, tagline=?, description=?, avatar=?, tags=?, meta=?, model=?, temperature=?, max_tokens=?, context_window=?, directive=?, updated_at=datetime("now") WHERE id=?');
    $stmt->execute([
        $newSlug,
        $name,
        trim((string) ($_POST['tagline'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        $avatar,
        json_encode($tags, JSON_UNESCAPED_UNICODE),
        json_encode($meta, JSON_UNESCAPED_UNICODE),
        $aiModel,
        $aiTemp,
        $aiMax,
        $aiCtx,
        $aiDirect,
        $pid,
    ]);
    if ((string) $avatar !== (string) $persona['avatar']) {
        // l'ancien portrait peut encore servir dans la galerie : supprimé seulement s'il n'est plus référencé
        release_media((string) $persona['avatar']);
    }
    flash('ok', 'Fiche mise à jour.');
    redirect('/p/' . $newSlug);
}

if ($action === 'edit' && $method === 'GET') {
    $photos  = fetch_photos($pid);
    $prompts = fetch_prompts($pid);
    render('form', [
        'title'      => 'Modifier ' . $persona['name'],
        'persona'    => $persona,
        'photos'     => $photos,
        'prompts'    => $prompts,
        'chatModels' => chat_models_ordered(chat_models()),
    ]);
    exit;
}

if ($action === 'delete' && $method === 'POST') {
    csrf_check();
    $files = array_map(static fn (array $ph): string => (string) $ph['file'], fetch_photos($pid));
    $files[] = (string) $persona['avatar'];
    $stmt = db()->prepare("SELECT m.image FROM messages m JOIN conversations c ON c.id = m.conversation_id
                               WHERE c.persona_id = ? AND m.image <> ''");
    $stmt->execute([$pid]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $img) {
        $files[] = (string) $img;
    }
    $stmt = db()->prepare('DELETE FROM personas WHERE id = ?');
    $stmt->execute([$pid]);
    release_media(...$files); // après la suppression en cascade : plus aucune référence
    flash('ok', $persona['name'] . ' a été supprimé.');
    redirect('/');
}

if ($action === 'favorite' && $method === 'POST') {
    csrf_check();
    $stmt = db()->prepare('UPDATE personas SET favorite = 1 - favorite WHERE id = ?');
    $stmt->execute([$pid]);
    redirect(safe_referer_path('/p/' . $slug));
}

if ($action === 'archive' && $method === 'POST') {
    csrf_check();
    $stmt = db()->prepare('UPDATE personas SET archived = 1 - archived WHERE id = ?');
    $stmt->execute([$pid]);
    flash('ok', $persona['archived'] ? 'Persona restauré.' : 'Persona archivé.');
    redirect($persona['archived'] ? '/p/' . $slug : '/');
}
