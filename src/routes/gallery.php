<?php
declare(strict_types=1);

/*
 * Actions directes sur une photo ou un prompt : /photos/{id}/…, /prompts/{id}/….
 */

/* ------------------------------------------------------------ photos/prompts */

if ($p0 === 'photos' && isset($parts[1]) && $method === 'POST') {
    csrf_check();
    $id   = (int) $parts[1];
    $stmt = db()->prepare('SELECT * FROM photos WHERE id = ?');
    $stmt->execute([$id]);
    $photo = $stmt->fetch();
    if ($photo) {
        if (($parts[2] ?? '') === 'avatar') {
            $stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
            $stmt->execute([(int) $photo['persona_id']]);
            $persona = $stmt->fetch();
            if ($persona) {
                db()->prepare('UPDATE personas SET avatar=?, updated_at=datetime("now") WHERE id=?')
                    ->execute([$photo['file'], $persona['id']]);
                // l'ancien portrait peut être une photo de la galerie : on ne supprime que l'orphelin
                release_media((string) $persona['avatar']);
            }
        } elseif (($parts[2] ?? '') === 'delete') {
            db()->prepare('DELETE FROM photos WHERE id = ?')->execute([$id]);
            // si la photo sert aussi de portrait, le fichier est conservé
            release_media((string) $photo['file']);
        }
    }
    back('/');
}

if ($p0 === 'prompts' && isset($parts[1]) && $method === 'POST') {
    csrf_check();
    $id   = (int) $parts[1];
    $stmt = db()->prepare('SELECT * FROM prompts WHERE id = ?');
    $stmt->execute([$id]);
    $prompt = $stmt->fetch();
    if ($prompt) {
        if (($parts[2] ?? '') === 'delete') {
            db()->prepare('DELETE FROM prompts WHERE id = ?')->execute([$id]);
        } else {
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($body !== '') {
                db()->prepare('UPDATE prompts SET title=?, kind=?, body=? WHERE id=?')
                    ->execute([
                        trim((string) ($_POST['title'] ?? '')),
                        array_key_exists((string) ($_POST['kind'] ?? ''), prompt_kinds()) ? (string) $_POST['kind'] : 'image',
                        $body,
                        $id,
                    ]);
            }
        }
    }
    back('/');
}
