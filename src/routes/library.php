<?php
declare(strict_types=1);

/*
 * Bibliothèque (accueil) et création d’un persona.
 */

/* --------------------------------------------------------------- bibliothèque */

if ($path === '/' && $method === 'GET') {
    $q    = trim((string) ($_GET['q'] ?? ''));
    $tag  = trim((string) ($_GET['tag'] ?? ''));
    $fav  = ($_GET['fav'] ?? '') === '1';
    $arch = ($_GET['arch'] ?? '') === '1';

    $sql    = 'SELECT * FROM personas WHERE archived = ?';
    $params = [$arch ? 1 : 0];

    if ($q !== '') {
        $sql .= ' AND (name LIKE ? OR tagline LIKE ? OR description LIKE ? OR tags LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    if ($tag !== '') {
        // échappe les jokers SQL : le tag vient de l'URL
        $needle   = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $tag);
        $sql     .= " AND tags LIKE ? ESCAPE '\\'";
        $params[] = '%"' . $needle . '"%';
    }
    if ($fav) {
        $sql .= ' AND favorite = 1';
    }
    $sql .= ' ORDER BY favorite DESC, updated_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $personas = $stmt->fetchAll();

    // compteurs par persona
    $counts = [];
    foreach (db()->query('SELECT persona_id, COUNT(*) n FROM photos GROUP BY persona_id') as $r) {
        $counts['p' . $r['persona_id']] = (int) $r['n'];
    }
    foreach (db()->query('SELECT persona_id, COUNT(*) n FROM prompts GROUP BY persona_id') as $r) {
        $counts['t' . $r['persona_id']] = (int) $r['n'];
    }

    // tous les tags existants
    $allTags = [];
    foreach (db()->query('SELECT tags FROM personas WHERE archived = 0') as $r) {
        foreach (json_list($r['tags']) as $t) {
            $allTags[(string) $t] = ($allTags[(string) $t] ?? 0) + 1;
        }
    }
    arsort($allTags);

    render('library', [
        'title'    => t('app.default_title'),
        'personas' => $personas,
        'counts'   => $counts,
        'q'        => $q,
        'tag'      => $tag,
        'fav'      => $fav,
        'arch'     => $arch,
        'allTags'  => $allTags,
        'total'    => (int) db()->query('SELECT COUNT(*) FROM personas WHERE archived = 0')->fetchColumn(),
    ]);
    exit;
}

/* --------------------------------------------------------------- création */

if ($path === '/p/new' && $method === 'GET') {
    render('form', ['title' => t('nav.new_persona'), 'persona' => null, 'photos' => [], 'prompts' => []]);
    exit;
}

if ($path === '/p' && $method === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        flash('error', t('flash.persona.name_required'));
        redirect('/p/new');
    }
    $tags = parse_tags((string) ($_POST['tags'] ?? ''));
    $meta = parse_meta((string) ($_POST['meta'] ?? ''));
    $slug = unique_slug($name);

    $avatar = '';
    try {
        if (upload_present($_FILES['avatar'] ?? null)) {
            $avatar = store_upload($_FILES['avatar']);
        }
    } catch (Throwable $e) {
        flash('error', t('flash.persona.avatar_error', ['error' => public_error($e)]));
    }

    $stmt = db()->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $slug,
        $name,
        trim((string) ($_POST['tagline'] ?? '')),
        trim((string) ($_POST['description'] ?? '')),
        $avatar,
        json_encode($tags, JSON_UNESCAPED_UNICODE),
        json_encode($meta, JSON_UNESCAPED_UNICODE),
    ]);
    $id = (int) db()->lastInsertId();
    $stmt = db()->prepare('UPDATE personas SET updated_at = datetime("now") WHERE id = ?');
    $stmt->execute([$id]);

    flash('ok', t('flash.persona.created', ['name' => $name]));
    redirect('/p/' . $slug);
}
