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

/* historique d'un prompt (JSON) : 10 dernières modifications, avec le détail ligne à ligne */
if ($p0 === 'prompts' && isset($parts[1]) && ($parts[2] ?? '') === 'history' && $method === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    foreach (prompt_versions((int) $parts[1]) as $v) {
        $d     = local_dt((string) $v['created_at']);
        $out[] = [
            'id'       => (int) $v['id'],
            'when'     => rel_time((string) $v['created_at']),
            'at'       => $d ? $d->format('Y-m-d H:i') : '',
            'added'    => (int) $v['added'],
            'removed'  => (int) $v['removed'],
            'old_body' => (string) $v['old_body'],
            'new_body' => (string) $v['new_body'],
            'diff'     => line_diff((string) $v['old_body'], (string) $v['new_body']),
        ];
    }
    echo json_encode(['ok' => true, 'versions' => $out], JSON_UNESCAPED_UNICODE);
    exit;
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
                // historique : l'ancien texte et le nouveau (10 dernières modifications)
                prompt_version_record($id, (string) $prompt['body'], $body);
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
