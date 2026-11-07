<?php
/**
 * Sauvegarde / restauration.
 *
 * Format du ZIP :
 *   personavault-YYYY-MM-DD-HHMM/
 *     manifest.json
 *     README.txt
 *     personas/<slug>/persona.json
 *     personas/<slug>/prompts.json
 *     personas/<slug>/photos/<n>.webp
 *     personas/<slug>/avatar.webp
 *     conversations/conversations.json      (optionnel)
 *
 * Les images sont ré-encodées à l'import (miniature régénérée), et les dossiers
 * sont créés à plat : aucune entrée du ZIP n'est extraite telle quelle.
 */

function backup_tmp_dir(): string
{
    $dir = dirname((string) config('uploads')) . '/tmp';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function backup_json(mixed $data): string
{
    return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Construit l'archive et renvoie [chemin, nom, stats].
 * @return array{path:string, name:string, stats:array}
 */
function backup_create_zip(bool $withConversations = false): array
{
    $stamp = (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))
        ->format('Y-m-d-Hi');
    $root  = 'personavault-' . $stamp;
    $tmp   = backup_tmp_dir() . '/' . $root . '-' . bin2hex(random_bytes(4)) . '.zip';
    $up    = (string) config('uploads');

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Impossible de créer l’archive.');
    }

    $stats = ['personas' => 0, 'photos' => 0, 'prompts' => 0, 'conversations' => 0, 'messages' => 0];

    $personas = db()->query('SELECT * FROM personas ORDER BY id')->fetchAll();
    foreach ($personas as $p) {
        $slug = trim((string) $p['slug']) !== '' ? (string) $p['slug'] : 'persona-' . (int) $p['id'];
        $dir  = $root . '/personas/' . $slug;

        $photos  = fetch_photos((int) $p['id']);
        $prompts = fetch_prompts((int) $p['id']);

        $photoFiles = [];
        foreach ($photos as $ph) {
            $file = (string) $ph['file'];
            $src  = $up . '/' . $file . '.webp';
            if ($file !== '' && is_file($src)) {
                $zip->addFile($src, $dir . '/photos/' . $file . '.webp');
                $photoFiles[] = $file . '.webp';
                $stats['photos']++;
            }
        }

        $avatarFile = null;
        if (trim((string) $p['avatar']) !== '') {
            $src = $up . '/' . $p['avatar'] . '.webp';
            if (is_file($src)) {
                $zip->addFile($src, $dir . '/avatar.webp');
                $avatarFile = 'avatar.webp';
            }
        }

        $personaJson = [
            'name'           => (string) $p['name'],
            'slug'           => $slug,
            'tagline'        => (string) $p['tagline'],
            'description'    => (string) $p['description'],
            'tags'           => json_list($p['tags'] ?? '[]'),
            'meta'           => json_list($p['meta'] ?? '[]'),
            'favorite'       => (int) $p['favorite'],
            'archived'       => (int) $p['archived'],
            'model'          => (string) $p['model'],
            'temperature'    => (float) $p['temperature'],
            'max_tokens'     => (int) $p['max_tokens'],
            'context_window' => (int) $p['context_window'],
            'directive'      => (string) $p['directive'],
            'created_at'     => (string) $p['created_at'],
            'updated_at'     => (string) $p['updated_at'],
            'avatar'         => $avatarFile,
            'photos'         => $photoFiles,
        ];
        $zip->addFromString($dir . '/persona.json', backup_json($personaJson));

        $plist = [];
        foreach ($prompts as $pr) {
            $plist[] = [
                'kind'  => (string) $pr['kind'],
                'title' => (string) $pr['title'],
                'body'  => (string) $pr['body'],
            ];
            $stats['prompts']++;
        }
        $zip->addFromString($dir . '/prompts.json', backup_json($plist));

        $stats['personas']++;
    }

    if ($withConversations) {
        $convs = db()->query('SELECT * FROM conversations ORDER BY id')->fetchAll();
        $out   = [];
        foreach ($convs as $c) {
            $stmt = db()->prepare('SELECT role, content, model, created_at FROM messages WHERE conversation_id = ? ORDER BY id');
            $stmt->execute([(int) $c['id']]);
            $msgs = $stmt->fetchAll();
            $out[] = [
                'id'            => (int) $c['id'],
                'persona_id'    => (int) $c['persona_id'],
                'title'         => (string) $c['title'],
                'model'         => (string) $c['model'],
                'temperature'   => (float) $c['temperature'],
                'max_tokens'    => (int) $c['max_tokens'],
                'context_window' => (int) $c['context_window'],
                'directive'     => (string) $c['directive'],
                'summary'       => (string) $c['summary'],
                'created_at'    => (string) $c['created_at'],
                'updated_at'    => (string) $c['updated_at'],
                'messages'      => array_map(static fn (array $m): array => [
                    'role'       => (string) $m['role'],
                    'content'    => (string) $m['content'],
                    'model'      => (string) $m['model'],
                    'created_at' => (string) $m['created_at'],
                ], $msgs),
            ];
            $stats['conversations']++;
            $stats['messages'] += count($msgs);
        }

        $memories = db()->query('SELECT persona_id, category, content, confidence, created_at FROM user_memories ORDER BY id')->fetchAll();

        $zip->addFromString($root . '/conversations/conversations.json', backup_json([
            'exported_at'   => (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))->format('c'),
            'conversations' => $out,
            'memories'      => array_map(static fn (array $m): array => [
                'persona_id' => $m['persona_id'] !== null ? (int) $m['persona_id'] : null,
                'category'   => (string) $m['category'],
                'content'    => (string) $m['content'],
                'confidence' => (string) $m['confidence'],
                'created_at' => (string) $m['created_at'],
            ], $memories),
        ]));
    }

    $zip->addFromString($root . '/manifest.json', backup_json([
        'app'        => 'PersonaVault',
        'format'     => 1,
        'exported_at' => (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))->format('c'),
        'options'    => ['conversations' => $withConversations],
        'counts'     => $stats,
    ]));

    $zip->addFromString($root . '/README.txt', backup_readme($stats, $withConversations));
    $zip->close();

    return [
        'path'  => $tmp,
        'name'  => $root . '.zip',
        'stats' => $stats,
    ];
}

function backup_readme(array $stats, bool $withConversations): string
{
    return "PersonaVault — sauvegarde\n"
        . "=========================\n\n"
        . "Exportée le " . (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))->format('d/m/Y H:i') . "\n"
        . "Personas : {$stats['personas']} · photos : {$stats['photos']} · prompts : {$stats['prompts']}"
        . ($withConversations ? " · conversations : {$stats['conversations']} ({$stats['messages']} messages)" : '')
        . "\n\nStructure\n---------\n"
        . "personas/<slug>/persona.json   fiche complète (nom, accroche, description, tags, attributs,\n"
        . "                               réglages IA, consigne de jeu)\n"
        . "personas/<slug>/prompts.json   prompts (type, titre, texte)\n"
        . "personas/<slug>/photos/*.webp  galerie\n"
        . "personas/<slug>/avatar.webp    portrait\n"
        . ($withConversations ? "conversations/conversations.json  discussions, messages et souvenirs\n" : '')
        . "\nRestauration\n------------\n"
        . "Réglages → Sauvegarde → Restaurer, en choisissant ce fichier ZIP.\n"
        . "Les personas importés reçoivent un slug unique si le leur existe déjà :\n"
        . "rien n'est écrasé.\n";
}

/**
 * Importe les personas d'une archive (photos ré-encodées, prompts inclus).
 * @return array{personas:int, photos:int, prompts:int, errors:array<string>}
 */
function backup_import_zip(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Archive illisible.');
    }

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = (string) $zip->getNameIndex($i);
    }
    if (!array_filter($names, static fn (string $n): bool => str_ends_with($n, 'manifest.json'))) {
        $zip->close();
        throw new RuntimeException('Ce fichier n’est pas une sauvegarde PersonaVault (manifest.json manquant).');
    }

    $dir   = (string) config('uploads');
    $stats = ['personas' => 0, 'photos' => 0, 'prompts' => 0, 'errors' => []];

    foreach ($names as $name) {
        if (!preg_match('#personas/([^/]+)/persona\.json$#', $name, $m)) {
            continue;
        }
        if (str_contains($name, '..')) {
            continue;
        }
        $folder   = $m[1];
        $persona  = json_decode((string) $zip->getFromName($name), true);
        if (!is_array($persona) || trim((string) ($persona['name'] ?? '')) === '') {
            $stats['errors'][] = $folder . ' : fiche invalide';
            continue;
        }

        $baseSlug = trim((string) ($persona['slug'] ?? '')) !== '' ? (string) $persona['slug'] : 'persona';
        $slug     = unique_slug((string) $persona['name'], null);

        // portrait
        $avatar = '';
        $avatarData = $zip->getFromName('personas/' . $folder . '/avatar.webp');
        if ($avatarData !== false) {
            $img = @imagecreatefromstring($avatarData);
            if ($img) {
                $avatar = 'p' . bin2hex(random_bytes(8));
                save_webp($img, $dir . '/' . $avatar . '.webp', 1800, 88);
                save_webp($img, $dir . '/' . $avatar . '.thumb.webp', 700, 84);
            }
        }

        $stmt = db()->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta, favorite, archived, model, temperature, max_tokens, context_window, directive, created_at, updated_at)
                               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))');
        $stmt->execute([
            $slug,
            (string) $persona['name'],
            (string) ($persona['tagline'] ?? ''),
            (string) ($persona['description'] ?? ''),
            $avatar,
            json_encode($persona['tags'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($persona['meta'] ?? [], JSON_UNESCAPED_UNICODE),
            (int) ($persona['favorite'] ?? 0),
            (int) ($persona['archived'] ?? 0),
            (string) ($persona['model'] ?? ''),
            (float) ($persona['temperature'] ?? 0.85),
            (int) ($persona['max_tokens'] ?? 400),
            (int) ($persona['context_window'] ?? 8192),
            (string) ($persona['directive'] ?? ''),
        ]);
        $pid = (int) db()->lastInsertId();
        $stats['personas']++;

        // photos
        foreach ($names as $entry) {
            if (!str_starts_with($entry, 'personas/' . $folder . '/photos/')) {
                continue;
            }
            $data = $zip->getFromName($entry);
            $img  = $data !== false ? @imagecreatefromstring($data) : false;
            if (!$img) {
                continue;
            }
            $base = 'p' . bin2hex(random_bytes(8));
            save_webp($img, $dir . '/' . $base . '.webp', 1800, 88);
            save_webp($img, $dir . '/' . $base . '.thumb.webp', 700, 84);
            db()->prepare('INSERT INTO photos (persona_id, file, caption, sort) VALUES (?,?,?,?)')
                ->execute([$pid, $base, '', time()]);
            $stats['photos']++;
        }

        // prompts
        $prompts = json_decode((string) $zip->getFromName('personas/' . $folder . '/prompts.json'), true);
        if (is_array($prompts)) {
            $ins = db()->prepare('INSERT INTO prompts (persona_id, kind, title, body) VALUES (?,?,?,?)');
            foreach ($prompts as $pr) {
                $kind = in_array((string) ($pr['kind'] ?? ''), array_keys(prompt_kinds()), true)
                    ? (string) $pr['kind'] : 'system';
                $ins->execute([$pid, $kind, mb_substr((string) ($pr['title'] ?? ''), 0, 120), (string) ($pr['body'] ?? '')]);
                $stats['prompts']++;
            }
        }
    }

    $zip->close();
    return $stats;
}
