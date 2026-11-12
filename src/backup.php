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
 *     conversations/media/<image>.webp
 *
 * Les images sont ré-encodées à l'import (miniature régénérée), et les dossiers
 * sont créés à plat : aucune entrée du ZIP n'est extraite telle quelle.
 */

require_once __DIR__ . '/backup_conversations.php';

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
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

/**
 * Construit l'archive et renvoie [chemin, nom, stats].
 * @return array{path:string, name:string, stats:array}
 */
function backup_create_zip(bool $withConversations = false): array
{
    $pdo = db();
    $stamp = (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))
        ->format('Y-m-d-Hi');
    $root  = 'personavault-' . $stamp;
    $tmp   = backup_tmp_dir() . '/' . $root . '-' . bin2hex(random_bytes(4)) . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException(t('error.backup.create'));
    }

    $stats = ['personas' => 0, 'photos' => 0, 'prompts' => 0, 'conversations' => 0, 'messages' => 0, 'memories' => 0];
    $closed = false;
    try {
        // Fiches, messages et versions proviennent du même instant de lecture SQLite.
        $pdo->beginTransaction();
        foreach ($pdo->query('SELECT * FROM personas ORDER BY id')->fetchAll() as $p) {
            backup_add_persona($zip, $root, $p, $stats);
        }
        if ($withConversations) {
            backup_add_conversations($zip, $root, $stats);
        }
        $pdo->commit();

        $manifest = backup_json([
            'app'        => 'PersonaVault',
            'format'     => $withConversations ? 2 : 1,
            'exported_at' => (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))->format('c'),
            'options'    => ['conversations' => $withConversations],
            'counts'     => $stats,
        ]);
        if (!$zip->addFromString($root . '/manifest.json', $manifest)
            || !$zip->addFromString($root . '/README.txt', backup_readme($stats, $withConversations))) {
            throw new RuntimeException(t('error.backup.create'));
        }
        $closed = @$zip->close();
        if (!$closed) {
            throw new RuntimeException(t('error.backup.create'));
        }
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException(t('error.backup.create'));
        }
        try {
            backup_archive_index($zip, true);
        } finally {
            $zip->close();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$closed) {
            $zip->unchangeAll();
            @$zip->close();
        }
        @unlink($tmp);
        throw $e;
    }

    return [
        'path'  => $tmp,
        'name'  => $root . '.zip',
        'stats' => $stats,
    ];
}

/**
 * Archive d'UNE fiche, au même format que la sauvegarde complète (manifest.json compris) :
 * elle se réimporte par backup_import_zip(), depuis la bibliothèque ou les réglages.
 * @return array{path:string, name:string, stats:array}
 */
function backup_create_persona_zip(array $persona): array
{
    $slug = trim((string) $persona['slug']) !== '' ? (string) $persona['slug'] : 'persona-' . (int) $persona['id'];
    $root = 'personavault-' . $slug;
    $tmp  = backup_tmp_dir() . '/' . $root . '-' . bin2hex(random_bytes(4)) . '.zip';

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException(t('error.backup.create'));
    }
    $stats = ['personas' => 0, 'photos' => 0, 'prompts' => 0, 'conversations' => 0, 'messages' => 0];
    backup_add_persona($zip, $root, $persona, $stats);
    $zip->addFromString($root . '/manifest.json', backup_json([
        'app'         => 'PersonaVault',
        'format'      => 1,
        'scope'       => 'persona',
        'exported_at' => (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))->format('c'),
        'options'     => ['conversations' => false],
        'counts'      => $stats,
    ]));
    $zip->addFromString($root . '/README.txt', backup_readme($stats, false));
    $zip->close();

    return ['path' => $tmp, 'name' => $root . '.zip', 'stats' => $stats];
}

/**
 * Ajoute un persona à l'archive : <racine>/personas/<slug>/persona.json, prompts.json,
 * avatar.webp et photos/. Partagé par la sauvegarde complète et l'export d'une fiche.
 */
function backup_add_persona(ZipArchive $zip, string $root, array $p, array &$stats): void
{
    $up   = (string) config('uploads');
    $slug =trim((string) $p['slug']) !== '' ? (string) $p['slug'] : 'persona-' . (int) $p['id'];
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
        'id'             => (int) $p['id'],
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

/** README.txt de l'archive, dans la langue de l'interface. */
function backup_readme(array $stats, bool $withConversations): string
{
    $title     = t('labels.backup_readme.title');
    $structure = t('labels.backup_readme.structure');
    $restore   = t('labels.backup_readme.restore');
    $exported  = (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))
        ->format(t('labels.backup_readme.date_format'));

    return $title . "\n"
        . str_repeat('=', mb_strlen($title)) . "\n\n"
        . t('labels.backup_readme.exported', ['date' => $exported]) . "\n"
        . t('labels.backup_readme.counts', ['personas' => $stats['personas'], 'photos' => $stats['photos'], 'prompts' => $stats['prompts']])
        . ($withConversations
            ? ' · ' . t('labels.backup_readme.conversations', ['conversations' => $stats['conversations'], 'messages' => $stats['messages']])
            : '')
        . "\n\n" . $structure . "\n" . str_repeat('-', mb_strlen($structure)) . "\n"
        . 'personas/<slug>/persona.json   ' . t('labels.backup_readme.persona_json') . "\n"
        . '                               ' . t('labels.backup_readme.persona_json_2') . "\n"
        . 'personas/<slug>/prompts.json   ' . t('labels.backup_readme.prompts_json') . "\n"
        . 'personas/<slug>/photos/*.webp  ' . t('labels.backup_readme.photos') . "\n"
        . 'personas/<slug>/avatar.webp    ' . t('labels.backup_readme.avatar') . "\n"
        . ($withConversations
            ? 'conversations/conversations.json  ' . t('labels.backup_readme.conversations_json') . "\n"
                . 'conversations/media/*.webp       ' . t('labels.backup_readme.chat_media') . "\n"
            : '')
        . "\n" . $restore . "\n" . str_repeat('-', mb_strlen($restore)) . "\n"
        . t('labels.backup_readme.restore_how') . "\n"
        . t('labels.backup_readme.restore_slug') . "\n"
        . t('labels.backup_readme.restore_safe') . "\n";
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
        throw new RuntimeException(t('error.backup.entry_too_big_size', ['size' => human_bytes((int) ($stat['size'] ?? 0))]));
    }
    $data = $zip->getFromIndex($index, $max + 1);
    if ($data === false) {
        throw new RuntimeException(t('error.backup.entry_unreadable'));
    }
    if (strlen($data) > $max) {
        throw new RuntimeException(t('error.backup.entry_too_big'));
    }
    return $data;
}

/** Indexe les entrées sans les extraire et applique les limites du ZIP. */
function backup_archive_index(ZipArchive $zip, bool $checkEntrySizes = false): array
{
    $maxEntries = (int) config('zip_max_entries', 5000);
    if ($zip->numFiles > $maxEntries) {
        throw new RuntimeException(t('error.backup.too_many_files', ['count' => $zip->numFiles, 'max' => $maxEntries]));
    }

    $index = [];
    $total = 0;
    $maxEntry = (int) config('zip_max_entry_size', 40 * 1024 * 1024);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if (!is_array($stat)) {
            continue;
        }
        $size = (int) $stat['size'];
        if ($checkEntrySizes && $size > $maxEntry) {
            throw new RuntimeException(t('error.backup.entry_too_big_size', ['size' => human_bytes($size)]));
        }
        $index[(string) $stat['name']] = $i;
        $total += $size;
    }
    $maxTotal = (int) config('zip_max_total', 2 * 1024 * 1024 * 1024);
    if ($total > $maxTotal) {
        throw new RuntimeException(t('error.backup.too_large', ['size' => human_bytes($total), 'max' => human_bytes($maxTotal)]));
    }
    return $index;
}

/**
 * Importe les personas d'une archive, puis les conversations et souvenirs inclus.
 *
 * Les entrées sont repérées par leur suffixe « personas/<dossier>/persona.json » : le
 * dossier racine ajouté à l'export (personavault-AAAA-MM-JJ-HHMM/) est donc géré, de même
 * qu'une archive refaite à la main sans ce dossier. Rien n'est extrait sur le disque.
 * Chaque persona est importé dans sa propre transaction.
 *
 * @return array{personas:int, photos:int, prompts:int, conversations:int, messages:int, memories:int, errors:array<string>, slugs:array<string>}
 */
function backup_import_zip(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException(t('error.backup.unreadable'));
    }

    try {
        $index = backup_archive_index($zip);
        $manifests = array_filter(array_keys($index), static fn (string $n): bool => basename($n) === 'manifest.json');
        if (!$manifests) {
            throw new RuntimeException(t('error.backup.not_a_backup'));
        }

        $stats = ['personas' => 0, 'photos' => 0, 'prompts' => 0, 'conversations' => 0, 'messages' => 0, 'memories' => 0, 'errors' => [], 'slugs' => []];
        $personasByRoot = [];

        foreach ($index as $name => $entryIndex) {
            if (!preg_match('#^(.*/)?personas/([^/]+)/persona\.json$#', $name, $m) || str_contains($name, '..')) {
                continue;
            }
            [, $root, $folder] = $m;
            $prefix = substr($name, 0, -strlen('persona.json')); // « …/personas/<dossier>/ »
            $personasByRoot[$root][$folder] = null;

            try {
                $res = backup_import_one($zip, $index, $prefix, $entryIndex);
                $personasByRoot[$root][$folder] = ['id' => $res['id'], 'source_id' => $res['source_id']];
                $stats['personas']++;
                $stats['slugs'][]  = $res['slug'];
                $stats['photos']  += $res['photos'];
                $stats['prompts'] += $res['prompts'];
                foreach ($res['errors'] as $err) {
                    $stats['errors'][] = t('error.backup.item', ['item' => $folder, 'error' => $err]);
                }
            } catch (Throwable $e) {
                $stats['errors'][] = t('error.backup.item', ['item' => $folder, 'error' => public_error($e, t('error.internal_short'))]);
            }
        }

        foreach ($manifests as $manifest) {
            $root = substr($manifest, 0, -strlen('manifest.json'));
            $entry = $root . 'conversations/conversations.json';
            if (!isset($index[$entry])) {
                continue;
            }
            try {
                $meta = json_decode(backup_read_entry($zip, $index[$manifest]), true);
                $format = $meta['format'] ?? null;
                if (!in_array($format, [1, 2], true)) {
                    throw new RuntimeException(t('error.backup.invalid_chat_data'));
                }
                backup_restore_conversations($zip, $index, $root, $personasByRoot[$root] ?? [], $format === 1, $stats);
            } catch (Throwable $e) {
                $stats['errors'][] = t('error.backup.item', ['item' => $entry, 'error' => public_error($e, t('error.internal_short'))]);
            }
        }
        return $stats;
    } finally {
        $zip->close();
    }
}

/** Bilan affiché par les deux formulaires d'import ZIP. */
function backup_import_notice(array $stats): string
{
    $notice = t('flash.backup.imported', [
        'personas' => t('labels.count.personas', $stats['personas']),
        'photos'   => t('labels.count.photos', $stats['photos']),
        'prompts'  => t('labels.count.prompts', $stats['prompts']),
    ]);
    $conversations = $stats['conversations'] ?? 0;
    $memories = $stats['memories'] ?? 0;
    if ($conversations || $memories) {
        $notice .= ' ' . t('flash.backup.chats_imported', [
            'conversations' => $conversations, 'messages' => $stats['messages'], 'memories' => $memories,
        ]);
    }
    return $notice;
}

/**
 * Importe UN persona. Les images sont décodées et écrites d'abord, puis tout est inséré
 * en une transaction ; en cas d'échec, les fichiers déjà écrits sont supprimés.
 *
 * @return array{id:int, source_id:int, slug:string, photos:int, prompts:int, errors:array<string>}
 */
function backup_import_one(ZipArchive $zip, array $index, string $prefix, int $personaIndex): array
{
    $persona = json_decode(backup_read_entry($zip, $personaIndex), true);
    if (!is_array($persona) || trim(backup_str($persona['name'] ?? '')) === '') {
        throw new RuntimeException(t('error.backup.invalid_persona'));
    }

    // prompts (lus avant les images : une erreur ici ne laisse aucun fichier derrière elle)
    $prompts = [];
    if (isset($index[$prefix . 'prompts.json'])) {
        $list = json_decode(backup_read_entry($zip, $index[$prefix . 'prompts.json']), true);
        if (is_array($list)) {
            $prompts = $list;
        }
    }

    $errors = [];

    // portrait
    $avatar = '';
    if (isset($index[$prefix . 'avatar.webp'])) {
        try {
            $avatar = store_gd_image(decode_image_string(backup_read_entry($zip, $index[$prefix . 'avatar.webp'])));
        } catch (Throwable $e) {
            $errors[] = t('error.backup.avatar_skipped', ['error' => public_error($e, t('error.internal_short'))]);
        }
    }

    // photos
    $photos = [];
    foreach ($index as $entry => $i) {
        if (!str_starts_with($entry, $prefix . 'photos/') || str_ends_with($entry, '/')) {
            continue;
        }
        try {
            $photos[] = store_gd_image(decode_image_string(backup_read_entry($zip, $i)));
        } catch (Throwable $e) {
            $errors[] = t('error.backup.photo_skipped', ['file' => basename($entry), 'error' => public_error($e, t('error.internal_short'))]);
        }
    }

    $res = backup_insert_persona($persona, $prompts, $avatar, $photos);

    return $res + ['source_id' => backup_id($persona['id'] ?? null), 'photos' => count($photos), 'errors' => $errors];
}

/**
 * Insère un persona (format de persona.json / prompts.json) en une transaction, sous un slug
 * inédit : un persona existant n'est jamais écrasé. Tous les champs sont validés et bornés
 * (scalaires seulement, longueurs du formulaire). Les images ($avatar, $photos) sont déjà
 * ré-encodées ; en cas d'échec, leurs fichiers sont supprimés.
 * Partagé par l'import de sauvegarde et l'import de fiches (src/cards.php).
 *
 * @param array $prompts liste de ['kind' => …, 'title' => …, 'body' => …]
 * @return array{id:int, slug:string, prompts:int}
 */
function backup_insert_persona(array $persona, array $prompts, string $avatar = '', array $photos = []): array
{
    $written = array_values(array_filter(array_merge([$avatar], $photos), static fn (string $b): bool => $b !== ''));

    try {
        $name = trim(backup_str($persona['name'] ?? '', 200));
        if ($name === '') {
            throw new RuntimeException(t('error.backup.invalid_persona'));
        }

        $clean = [];
        foreach ($prompts as $pr) {
            if (!is_array($pr) || trim(backup_str($pr['body'] ?? '')) === '') {
                continue;
            }
            $kind = backup_str($pr['kind'] ?? '');
            $clean[] = [
                array_key_exists($kind, prompt_kinds()) ? $kind : 'other',
                backup_str($pr['title'] ?? '', 120),
                backup_str($pr['body'] ?? ''),
            ];
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

        $identity = db_tx(static function (PDO $pdo) use ($persona, $name, $avatar, $photos, $clean, $tags, $meta): array {
            $slug = unique_slug($name, null);
            $stmt = $pdo->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta, favorite, archived, model, temperature, max_tokens, context_window, directive, created_at, updated_at)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,datetime("now"),datetime("now"))');
            $stmt->execute([
                $slug,
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
            foreach ($clean as [$kind, $title, $body]) {
                $ins->execute([$pid, $kind, $title, $body]);
            }
            return ['id' => $pid, 'slug' => $slug];
        });
    } catch (Throwable $e) {
        foreach ($written as $base) {
            delete_media($base);
        }
        throw $e;
    }

    return $identity + ['prompts' => count($clean)];
}
