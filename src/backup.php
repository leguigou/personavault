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
        mkdir($dir, 0700, true);
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

/** Valeur scalaire d'un JSON importé, en chaîne bornée (sinon $default). */
function backup_str(mixed $v, int $max = 0, string $default = ''): string
{
    if (!is_scalar($v)) {
        return $default;
    }
    $s = (string) $v;
    return $max > 0 ? mb_substr($s, 0, $max) : $s;
}

/**
 * Lit une entrée du ZIP en bornant la taille décompressée (protection « zip bomb ») :
 * on ne lit jamais plus de zip_max_entry_size octets, quelle que soit la taille annoncée.
 */
function backup_read_entry(ZipArchive $zip, int $index): string
{
    $max  = (int) config('zip_max_entry_size', 40 * 1024 * 1024);
    $stat = $zip->statIndex($index);
    if (!is_array($stat) || (int) $stat['size'] > $max) {
        throw new RuntimeException('entrée trop volumineuse (' . human_bytes((int) ($stat['size'] ?? 0)) . ')');
    }
    $data = $zip->getFromIndex($index, $max + 1);
    if ($data === false) {
        throw new RuntimeException('entrée illisible');
    }
    if (strlen($data) > $max) {
        throw new RuntimeException('entrée trop volumineuse');
    }
    return $data;
}

/**
 * Importe les personas d'une archive (photos ré-encodées, prompts inclus).
 *
 * Les entrées sont repérées par leur suffixe « personas/<dossier>/persona.json » : le
 * dossier racine ajouté à l'export (personavault-AAAA-MM-JJ-HHMM/) est donc géré, de même
 * qu'une archive refaite à la main sans ce dossier. Rien n'est extrait sur le disque.
 * Chaque persona est importé dans sa propre transaction.
 *
 * @return array{personas:int, photos:int, prompts:int, errors:array<string>}
 */
function backup_import_zip(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Archive illisible.');
    }

    try {
        $maxEntries = (int) config('zip_max_entries', 5000);
        if ($zip->numFiles > $maxEntries) {
            throw new RuntimeException('Archive refusée : trop de fichiers (' . $zip->numFiles . ', ' . $maxEntries . ' max).');
        }

        $index = [];   // nom → index
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                continue;
            }
            $index[(string) $stat['name']] = $i;
            $total += (int) $stat['size'];
        }
        $maxTotal = (int) config('zip_max_total', 2 * 1024 * 1024 * 1024);
        if ($total > $maxTotal) {
            throw new RuntimeException('Archive refusée : ' . human_bytes($total) . ' une fois décompressée (' . human_bytes($maxTotal) . ' max).');
        }
        if (!array_filter(array_keys($index), static fn (string $n): bool => str_ends_with($n, 'manifest.json'))) {
            throw new RuntimeException('Ce fichier n’est pas une sauvegarde PersonaVault (manifest.json manquant).');
        }

        $dir   = (string) config('uploads');
        $stats = ['personas' => 0, 'photos' => 0, 'prompts' => 0, 'errors' => []];

        foreach ($index as $name => $entryIndex) {
            if (!preg_match('#(?:^|/)personas/([^/]+)/persona\.json$#', $name, $m) || str_contains($name, '..')) {
                continue;
            }
            $folder = $m[1];
            $prefix = substr($name, 0, -strlen('persona.json')); // « …/personas/<dossier>/ »

            try {
                $res = backup_import_one($zip, $index, $prefix, $entryIndex);
                $stats['personas']++;
                $stats['photos']  += $res['photos'];
                $stats['prompts'] += $res['prompts'];
                foreach ($res['errors'] as $err) {
                    $stats['errors'][] = $folder . ' : ' . $err;
                }
            } catch (Throwable $e) {
                $stats['errors'][] = $folder . ' : ' . public_error($e, 'erreur interne');
            }
        }

        return $stats;
    } finally {
        $zip->close();
    }
}

/**
 * Importe UN persona. Les images sont décodées et écrites d'abord, puis tout est inséré
 * en une transaction ; en cas d'échec, les fichiers déjà écrits sont supprimés.
 *
 * @return array{photos:int, prompts:int, errors:array<string>}
 */
function backup_import_one(ZipArchive $zip, array $index, string $prefix, int $personaIndex): array
{
    $persona = json_decode(backup_read_entry($zip, $personaIndex), true);
    if (!is_array($persona) || trim(backup_str($persona['name'] ?? '')) === '') {
        throw new RuntimeException('fiche invalide');
    }

    $errors  = [];
    $written = []; // fichiers créés, à supprimer si la transaction échoue

    // portrait
    $avatar = '';
    if (isset($index[$prefix . 'avatar.webp'])) {
        try {
            $avatar    = store_gd_image(decode_image_string(backup_read_entry($zip, $index[$prefix . 'avatar.webp'])));
            $written[] = $avatar;
        } catch (Throwable $e) {
            $errors[] = 'portrait ignoré (' . public_error($e, 'erreur interne') . ')';
        }
    }

    // photos
    $photos = [];
    foreach ($index as $entry => $i) {
        if (!str_starts_with($entry, $prefix . 'photos/') || str_ends_with($entry, '/')) {
            continue;
        }
        try {
            $base      = store_gd_image(decode_image_string(backup_read_entry($zip, $i)));
            $photos[]  = $base;
            $written[] = $base;
        } catch (Throwable $e) {
            $errors[] = basename($entry) . ' ignorée (' . public_error($e, 'erreur interne') . ')';
        }
    }

    // prompts
    $prompts = [];
    if (isset($index[$prefix . 'prompts.json'])) {
        $list = json_decode(backup_read_entry($zip, $index[$prefix . 'prompts.json']), true);
        if (is_array($list)) {
            foreach ($list as $pr) {
                if (!is_array($pr) || trim(backup_str($pr['body'] ?? '')) === '') {
                    continue;
                }
                $kind = backup_str($pr['kind'] ?? '');
                $prompts[] = [
                    array_key_exists($kind, prompt_kinds()) ? $kind : 'other',
                    backup_str($pr['title'] ?? '', 120),
                    backup_str($pr['body'] ?? ''),
                ];
            }
        }
    }

    // tags / attributs : uniquement des scalaires, bornés comme dans le formulaire
    $tags = [];
    foreach ((array) ($persona['tags'] ?? []) as $t) {
        $t = trim(backup_str($t, 32));
        if ($t !== '' && !in_array($t, $tags, true) && count($tags) < 12) {
            $tags[] = $t;
        }
    }
    $meta = [];
    foreach ((array) ($persona['meta'] ?? []) as $row) {
        if (is_array($row) && trim(backup_str($row['k'] ?? '')) !== '' && count($meta) < 20) {
            $meta[] = ['k' => trim(backup_str($row['k'], 40)), 'v' => backup_str($row['v'] ?? '', 200)];
        }
    }

    try {
        db_tx(static function (PDO $pdo) use ($persona, $avatar, $photos, $prompts, $tags, $meta): void {
            $name = backup_str($persona['name'], 200);
            $stmt = $pdo->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta, favorite, archived, model, temperature, max_tokens, context_window, directive, created_at, updated_at)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))');
            $stmt->execute([
                unique_slug($name, null),
                $name,
                backup_str($persona['tagline'] ?? '', 1000),
                backup_str($persona['description'] ?? '', 20000),
                $avatar,
                json_encode($tags, JSON_UNESCAPED_UNICODE),
                json_encode($meta, JSON_UNESCAPED_UNICODE),
                (int) !empty($persona['favorite']),
                (int) !empty($persona['archived']),
                backup_str($persona['model'] ?? '', 200),
                max(0.0, min(2.0, (float) backup_str($persona['temperature'] ?? '0.85', 0, '0.85'))),
                max(64, min(4096, (int) backup_str($persona['max_tokens'] ?? '400', 0, '400'))),
                max(2048, min(131072, (int) backup_str($persona['context_window'] ?? '8192', 0, '8192'))),
                backup_str($persona['directive'] ?? '', 5000),
            ]);
            $pid = (int) $pdo->lastInsertId();

            $ph = $pdo->prepare('INSERT INTO photos (persona_id, file, caption, sort) VALUES (?,?,?,?)');
            foreach ($photos as $n => $base) {
                $ph->execute([$pid, $base, '', time() + $n]);
            }
            $ins = $pdo->prepare('INSERT INTO prompts (persona_id, kind, title, body) VALUES (?,?,?,?)');
            foreach ($prompts as [$kind, $title, $body]) {
                $ins->execute([$pid, $kind, $title, $body]);
            }
        });
    } catch (Throwable $e) {
        foreach ($written as $base) {
            delete_media($base);
        }
        throw $e;
    }

    return ['photos' => count($photos), 'prompts' => count($prompts), 'errors' => $errors];
}
