<?php
/**
 * Auto-test rapide, sans serveur ni réseau : base et dossiers temporaires, jamais la vraie base.
 *
 * Vérifie : migrations versionnées, aller-retour export → import ZIP (portrait, photos,
 * prompts), comptage des références d'images, refus des images géantes, limites du ZIP,
 * jeton CSRF vide refusé, blocage des tentatives de connexion.
 *
 * Usage : php bin/selftest.php        (code de sortie 0 = tout est bon)
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/pv-selftest-' . bin2hex(random_bytes(4));
mkdir($tmp . '/uploads', 0700, true);

define('PV_TEST_CONFIG', [
    'db'              => $tmp . '/test.sqlite',
    'uploads'         => $tmp . '/uploads',
    'password_hash'   => password_hash('bon-mot-de-passe', PASSWORD_DEFAULT),
    'zip_max_entries' => 50,
]);

require __DIR__ . '/../src/bootstrap.php';

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  ÉCHEC ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
};
$throws = static function (callable $fn, string $needle = ''): bool {
    try {
        $fn();
    } catch (Throwable $e) {
        return $needle === '' || stripos($e->getMessage(), $needle) !== false;
    }
    return false;
};
$pngFile = static function (int $w, int $h, array $rgb) use ($tmp): string {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    $f = $tmp . '/src-' . bin2hex(random_bytes(3)) . '.png';
    imagepng($img, $f);
    return $f;
};
$upload = static fn (string $f): array => ['tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)];
$mediaExists = static fn (string $base): bool => $base !== ''
    && is_file(config('uploads') . '/' . $base . '.webp')
    && is_file(config('uploads') . '/' . $base . '.thumb.webp');

try {
    echo "Migrations\n";
    $pdo = db();
    $check((int) $pdo->query('PRAGMA user_version')->fetchColumn() === PV_SCHEMA_VERSION, 'user_version = ' . PV_SCHEMA_VERSION);
    migrate($pdo); // idempotent
    $check(in_array('image', table_columns($pdo, 'messages'), true), 'colonne messages.image présente');

    // base « ancienne » (version 0, colonnes ajoutées après coup manquantes)
    $legacy = new PDO('sqlite:' . $tmp . '/legacy.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $legacy->exec("CREATE TABLE personas (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
        tagline TEXT NOT NULL DEFAULT '', description TEXT NOT NULL DEFAULT '', avatar TEXT NOT NULL DEFAULT '',
        tags TEXT NOT NULL DEFAULT '[]', meta TEXT NOT NULL DEFAULT '[]', favorite INTEGER NOT NULL DEFAULT 0,
        archived INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now')), model TEXT NOT NULL DEFAULT '')");
    $legacy->exec("INSERT INTO personas (slug, name) VALUES ('ancien', 'Ancien')");
    migrate($legacy);
    $check((int) $legacy->query('PRAGMA user_version')->fetchColumn() === PV_SCHEMA_VERSION, 'base existante migrée');
    $check(in_array('directive', table_columns($legacy, 'personas'), true), 'colonnes manquantes ajoutées (personas.directive)');
    $check((int) $legacy->query('SELECT COUNT(*) FROM personas')->fetchColumn() === 1, 'données conservées');

    echo "Images\n";
    $avatar = store_upload($upload($pngFile(320, 200, [200, 40, 90])));
    $photo1 = store_upload($upload($pngFile(240, 240, [20, 120, 200])));
    $photo2 = store_upload($upload($pngFile(200, 300, [30, 200, 60])));
    $chatImg = store_upload($upload($pngFile(100, 100, [10, 10, 10])));
    $check($mediaExists($avatar) && $mediaExists($photo1) && $mediaExists($photo2), 'upload → WebP + miniature');

    // PNG dont l'en-tête annonce 20 000 × 20 000 px : refusé AVANT décodage
    $ihdr = pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0);
    $bomb = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    file_put_contents($tmp . '/bomb.png', $bomb);
    $check($throws(static fn () => store_upload($upload($tmp . '/bomb.png')), 'trop grande'), 'image de 400 Mpx refusée sans décodage');
    $check($throws(static fn () => store_upload(['tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0]), 'upload_max_filesize'), 'UPLOAD_ERR_INI_SIZE → message explicite');

    echo "Données\n";
    db()->prepare("INSERT INTO personas (slug, name, tagline, avatar, tags, meta, model) VALUES ('lisa', 'Lisa', 'Accroche', ?, ?, ?, 'qwen')")
        ->execute([$photo1, json_encode(['brune', 'paris']), json_encode([['k' => 'Âge', 'v' => '29']])]);
    $pid = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO photos (persona_id, file) VALUES (?, ?), (?, ?)')->execute([$pid, $photo1, $pid, $photo2]);
    db()->prepare("INSERT INTO prompts (persona_id, kind, title, body) VALUES (?, 'system', 'Sys', 'Tu es Lisa.'), (?, 'image', 'Portrait', 'photo, 35mm')")
        ->execute([$pid, $pid]);
    db()->prepare('INSERT INTO conversations (persona_id, title) VALUES (?, ?)')->execute([$pid, 'Test']);
    $cid = (int) db()->lastInsertId();
    db()->prepare("INSERT INTO messages (conversation_id, role, content, image) VALUES (?, 'user', 'regarde', ?)")->execute([$cid, $chatImg]);
    // l'avatar d'origine n'est plus référencé, photo1 sert à la fois de portrait et de photo
    release_media($avatar);
    $check(!$mediaExists($avatar), 'fichier orphelin supprimé');
    release_media($photo1, $chatImg);
    $check($mediaExists($photo1), 'photo de galerie servant de portrait conservée');
    $check($mediaExists($chatImg), 'image envoyée dans le chat conservée');
    $check(media_ref_count($photo1) === 2, 'photo1 référencée 2 fois (galerie + portrait)');

    echo "Sauvegarde\n";
    $zip = backup_create_zip(true);
    $za  = new ZipArchive();
    $za->open($zip['path']);
    $names = [];
    for ($i = 0; $i < $za->numFiles; $i++) {
        $names[] = (string) $za->getNameIndex($i);
    }
    $za->close();
    $check((bool) preg_grep('#^personavault-[^/]+/personas/lisa/persona\.json$#', $names), 'export avec dossier racine');

    $res = backup_import_zip($zip['path']);
    $check($res['personas'] === 1 && $res['photos'] === 2 && $res['prompts'] === 2 && !$res['errors'],
        'import : 1 persona, 2 photos, 2 prompts (' . json_encode($res, JSON_UNESCAPED_UNICODE) . ')');
    $new = db()->query("SELECT * FROM personas WHERE slug = 'lisa-2'")->fetch();
    $check(is_array($new), 'persona importé sous un nouveau slug');
    if (is_array($new)) {
        $nid = (int) $new['id'];
        $check($mediaExists((string) $new['avatar']), 'portrait importé (fichier présent)');
        $files = array_column(fetch_photos($nid), 'file');
        $check(count($files) === 2 && count(array_filter($files, $mediaExists)) === 2, 'photos importées (fichiers présents)');
        $bodies = array_column(fetch_prompts($nid), 'body');
        sort($bodies);
        $check($bodies === ['Tu es Lisa.', 'photo, 35mm'], 'prompts importés à l’identique');
        $check(json_list($new['tags']) === ['brune', 'paris'] && $new['model'] === 'qwen', 'tags et réglages IA importés');
    }

    // archive sans dossier racine (refaite à la main) : toujours acceptée
    $flat = $tmp . '/flat.zip';
    $src  = new ZipArchive();
    $src->open($zip['path']);
    $dst = new ZipArchive();
    $dst->open($flat, ZipArchive::CREATE);
    for ($i = 0; $i < $src->numFiles; $i++) {
        $n = (string) $src->getNameIndex($i);
        $dst->addFromString((string) preg_replace('#^[^/]+/#', '', $n), (string) $src->getFromIndex($i));
    }
    $dst->close();
    $src->close();
    $res2 = backup_import_zip($flat);
    $check($res2['personas'] === 1 && $res2['photos'] === 2 && $res2['prompts'] === 2, 'import d’une archive sans dossier racine');

    // trop d'entrées (limite de test : 50)
    $many = $tmp . '/many.zip';
    $z = new ZipArchive();
    $z->open($many, ZipArchive::CREATE);
    $z->addFromString('manifest.json', '{}');
    for ($i = 0; $i < 60; $i++) {
        $z->addFromString('x/' . $i . '.txt', 'x');
    }
    $z->close();
    $check($throws(static fn () => backup_import_zip($many), 'trop de fichiers'), 'archive avec trop d’entrées refusée');
    @unlink($zip['path']);

    echo "Sécurité\n";
    $_SESSION = [];
    $_POST    = ['_token' => ''];
    $check(!csrf_valid(), 'jeton vide refusé (session sans jeton)');
    $_SESSION['csrf'] = 'abc';
    $check(!csrf_valid(), 'jeton vide refusé (session avec jeton)');
    $_POST['_token'] = 'abc';
    $check(csrf_valid(), 'jeton correct accepté');

    $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    for ($i = 0; $i < (int) config('login_max_try'); $i++) {
        attempt_login('mauvais');
    }
    $check(login_blocked_for() > 0, 'IP bloquée après ' . config('login_max_try') . ' échecs (compteur en base)');
    $check(attempt_login('bon-mot-de-passe') === false, 'même le bon mot de passe est refusé pendant le blocage');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.8';
    $check(login_blocked_for() === 0, 'autre IP non bloquée');
} catch (Throwable $e) {
    echo '  ÉCHEC exception : ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
    $failures++;
} finally {
    // ménage du dossier temporaire
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmp);
}

echo PHP_EOL . ($failures === 0 ? 'Selftest OK' : $failures . ' échec(s)') . PHP_EOL;
exit($failures === 0 ? 0 : 1);
