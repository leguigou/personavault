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
        flash('error', t('flash.persona.name_empty'));
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
        flash('error', t('flash.persona.avatar_error', ['error' => public_error($e)]));
    }
    $aiModel  = trim((string) ($_POST['model'] ?? ''));
    $aiTemp   = max(0.0, min(2.0, (float) ($_POST['temperature'] ?? 0.85)));
    $aiMax    = max(64, min(4096, (int) ($_POST['max_tokens'] ?? 400)));
    $aiCtx    = max(2048, min(131072, (int) ($_POST['context_window'] ?? 8192)));
    $aiDirect = trim((string) ($_POST['directive'] ?? ''));

    $stmt = db()->prepare('UPDATE personas SET slug=?, name=?, tagline=?, description=?, avatar=?, tags=?, meta=?, model=?, temperature=?, max_tokens=?, context_window=?, directive=?, reasoning_effort=?, spontaneous_gifs=?, updated_at=datetime("now") WHERE id=?');
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
        chat_reasoning_effort($_POST['reasoning_effort'] ?? $persona['reasoning_effort']),
        (int) (($_POST['spontaneous_gifs'] ?? (string) $persona['spontaneous_gifs']) === '1'),
        $pid,
    ]);
    if ((string) $avatar !== (string) $persona['avatar']) {
        // l'ancien portrait peut encore servir dans la galerie : supprimé seulement s'il n'est plus référencé
        release_media((string) $persona['avatar']);
    }
    flash('ok', t('flash.persona.updated'));
    redirect('/p/' . $newSlug);
}

if ($action === 'edit' && $method === 'GET') {
    $photos  = fetch_photos($pid);
    $prompts = fetch_prompts($pid);
    render('form', [
        'title'      => t('labels.title.edit_persona', ['name' => $persona['name']]),
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
    flash('ok', t('flash.persona.deleted', ['name' => $persona['name']]));
    redirect('/library');
}

if ($action === 'favorite' && $method === 'POST') {
    csrf_check();
    $stmt = db()->prepare('UPDATE personas SET favorite = 1 - favorite WHERE id = ?');
    $stmt->execute([$pid]);
    redirect(safe_referer_path('/p/' . $slug));
}

/*
 * Dupliquer : nouvelle fiche « X (copie) » avec les mêmes textes, réglages IA, photos et prompts.
 * Les fichiers photo sont recopiés : les deux fiches sont totalement indépendantes (supprimer
 * ou remplacer une photo d'un côté ne touche jamais l'autre). Ni conversations ni souvenirs :
 * la copie repart de zéro.
 */
if ($action === 'duplicate' && $method === 'POST') {
    csrf_check();
    $name    = mb_substr(t('persona.copy_name', ['name' => (string) $persona['name']]), 0, 120);
    $newSlug = unique_slug($name);

    // copie des fichiers d'abord (hors transaction) ; un même fichier n'est copié qu'une fois,
    // pour que le portrait reste aussi l'une des photos de la galerie s'il l'était
    $map    = [];
    $copyOf = static function (string $base) use (&$map): string {
        if ($base === '') {
            return '';
        }
        return $map[$base] ??= media_copy($base);
    };
    try {
        $avatar = $copyOf((string) $persona['avatar']);
        $photos = [];
        foreach (fetch_photos($pid) as $ph) {
            $file = $copyOf((string) $ph['file']);
            if ($file !== '') {
                $photos[] = [$file, (string) $ph['caption'], (int) $ph['sort']];
            }
        }
        $newId = db_tx(static function (PDO $pdo) use ($persona, $pid, $name, $newSlug, $avatar, $photos): int {
            // toutes les colonnes de la fiche (y compris celles ajoutées par les migrations)
            $cols = array_values(array_diff(table_columns($pdo, 'personas'), ['id', 'slug', 'name', 'avatar', 'favorite', 'archived', 'created_at', 'updated_at']));
            $vals = array_map(static fn (string $c) => $persona[$c], $cols);
            $pdo->prepare('INSERT INTO personas (slug, name, avatar, ' . implode(', ', $cols) . ') VALUES (?, ?, ?' . str_repeat(', ?', count($cols)) . ')')
                ->execute([$newSlug, $name, $avatar, ...$vals]);
            $id  = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO photos (persona_id, file, caption, sort) VALUES (?, ?, ?, ?)');
            foreach ($photos as [$file, $caption, $sort]) {
                $ins->execute([$id, $file, $caption, $sort]);
            }
            $pdo->prepare('INSERT INTO prompts (persona_id, title, kind, body) SELECT ?, title, kind, body FROM prompts WHERE persona_id = ? ORDER BY id')
                ->execute([$id, $pid]);
            return $id;
        });
    } catch (Throwable $e) {
        // rien d'enregistré : on retire les copies de fichiers déjà faites
        foreach ($map as $copy) {
            if ($copy !== '') {
                delete_media($copy);
            }
        }
        flash('error', t('flash.persona.duplicate_failed', ['error' => public_error($e)]));
        redirect('/p/' . $slug);
    }
    flash('ok', t('flash.persona.duplicated', ['name' => $name]));
    redirect('/p/' . $newSlug);
}

if ($action === 'archive' && $method === 'POST') {
    csrf_check();
    $stmt = db()->prepare('UPDATE personas SET archived = 1 - archived WHERE id = ?');
    $stmt->execute([$pid]);
    flash('ok', $persona['archived'] ? t('flash.persona.restored') : t('flash.persona.archived'));
    redirect($persona['archived'] ? '/p/' . $slug : '/');
}
