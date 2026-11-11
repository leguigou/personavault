<?php
/**
 * Auto-test rapide, sans serveur ni réseau : base et dossiers temporaires, jamais la vraie base.
 *
 * Vérifie : migrations versionnées, aller-retour export → import ZIP (portrait, photos,
 * prompts), comptage des références d'images, refus des images géantes, limites du ZIP,
 * jeton CSRF vide refusé, blocage des tentatives de connexion, export de conversation.
 * fiches (archive d’une fiche, Character Card JSON / PNG, V1 / V2 / V3, fichiers refusés),
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
    'card_max_json'   => 64 * 1024,
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

    echo "Modèle d'une conversation\n";
    set_setting('chat_llm_model', 'modele-global');
    $mp = ['model' => 'modele-persona', 'directive' => ''];
    $check(chat_llm_config_for(chat_with_persona(['model_override' => 'modele-test', 'model' => 'ancien'], $mp))['model'] === 'modele-test', 'modèle de test prioritaire');
    $check(chat_llm_config_for(chat_with_persona(['model_override' => '', 'model' => 'ancien'], $mp))['model'] === 'modele-persona', 'sinon le modèle du persona');
    $check(chat_llm_config_for(chat_with_persona(['model_override' => '', 'model' => 'ancien'], ['model' => '', 'directive' => '']))['model'] === 'modele-global', 'sinon le modèle global (pas l\'ancien modèle utilisé)');

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

    echo "Fiches\n";
    $bySlug = static function (string $slug): array {
        $st = db()->prepare('SELECT * FROM personas WHERE slug = ?');
        $st->execute([$slug]);
        return (array) $st->fetch();
    };
    $promptRows = static fn (array $p): array => array_map(
        static fn (array $r): array => [$r['kind'], $r['title'], $r['body']],
        fetch_prompts((int) ($p['id'] ?? 0))
    );
    // même persona, au slug et aux fichiers près
    $same = static function (array $a, array $b) use ($promptRows): bool {
        foreach (['name', 'tagline', 'description', 'directive', 'model', 'temperature', 'max_tokens', 'context_window', 'favorite'] as $k) {
            if ((string) ($a[$k] ?? '') !== (string) ($b[$k] ?? '')) {
                return false;
            }
        }
        return json_list($a['tags'] ?? '') === json_list($b['tags'] ?? '')
            && json_list($a['meta'] ?? '') === json_list($b['meta'] ?? '')
            && $promptRows($a) === $promptRows($b);
    };
    $lisaId = (int) $bySlug('lisa')['id'];
    db()->prepare("UPDATE personas SET description = ?, directive = ?, favorite = 1, max_tokens = 600 WHERE id = ?")
        ->execute(["Photographe.\nVit à <b>Paris</b>.", 'Réponds court.', $lisaId]);
    $lisa   = $bySlug('lisa');
    $before = (int) db()->query('SELECT COUNT(*) FROM personas')->fetchColumn();

    // archive PersonaVault d'une seule fiche
    $pz = backup_create_persona_zip($lisa);
    $za = new ZipArchive();
    $za->open($pz['path']);
    $check($za->locateName('personavault-lisa/manifest.json') !== false && $za->numFiles === 7,
        'archive d’une fiche : manifest, README, persona.json, prompts.json, portrait, 2 photos');
    $za->close();
    $res = backup_import_zip($pz['path']);
    @unlink($pz['path']);
    $imp = $bySlug((string) ($res['slugs'][0] ?? ''));
    $check($res['personas'] === 1 && $res['photos'] === 2 && ($imp['slug'] ?? '') !== 'lisa', 'archive d’une fiche réimportée sous un nouveau slug');
    $check($imp && $same($lisa, $imp), 'archive d’une fiche : champs, attributs, consigne et prompts identiques');
    $check($imp && $mediaExists((string) $imp['avatar']) && count(fetch_photos((int) $imp['id'])) === 2, 'archive d’une fiche : portrait et photos');

    // Character Card V2 en JSON
    $card = card_build($lisa);
    $cd   = $card['data'];
    $check($card['spec'] === 'chara_card_v2' && $card['spec_version'] === '2.0' && $cd['name'] === 'Lisa', 'carte V2 : spec et nom');
    $check($cd['system_prompt'] === 'Tu es Lisa.' && $cd['post_history_instructions'] === 'Réponds court.' && $cd['creator_notes'] === 'Accroche',
        'carte V2 : prompt système, consigne, accroche → notes du créateur');
    $check($cd['personality'] === 'Âge: 29' && $cd['tags'] === ['brune', 'paris'] && isset($cd['extensions']['personavault']['prompts']),
        'carte V2 : attributs → personnalité, tags, extension personavault');
    $r   = card_import_array(card_decode_json(card_json($lisa)));
    $imp = $bySlug($r['slug']);
    $check($imp && $same($lisa, $imp) && $imp['avatar'] === '', 'aller-retour JSON : persona identique (sans portrait)');

    // Character Card V2 en PNG
    $png    = card_png($lisa);
    $chunks = png_text_chunks($png);
    $info   = getimagesizefromstring($png);
    $check(isset($chunks['chara']) && json_decode((string) base64_decode($chunks['chara']), true) === $card, 'PNG : bloc tEXt « chara » = la carte en base64');
    $check(is_array($info) && $info[2] === IMAGETYPE_PNG && $info[0] === 240 && $info[1] === 240 && imagecreatefromstring($png) !== false,
        'PNG : image valide (le portrait)');
    file_put_contents($tmp . '/lisa.card.png', $png);
    $r   = card_import_file($tmp . '/lisa.card.png');
    $imp = $bySlug($r['slugs'][0]);
    $check($imp && $same($lisa, $imp) && $mediaExists((string) $imp['avatar']), 'aller-retour PNG : persona identique, portrait ré-encodé');
    $noAvatar = card_png(['avatar' => ''] + $lisa);
    $info     = getimagesizefromstring($noAvatar);
    $check(is_array($info) && $info[0] === 512 && $info[1] === 768 && isset(png_text_chunks($noAvatar)['chara']), 'PNG sans portrait : image aux initiales générée');

    // cartes d'autres applications : V1, V2, V3
    $labels = card_field_labels();
    $r  = card_import_array(['name' => 'Ancienne', 'description' => 'Desc V1', 'personality' => 'calme', 'scenario' => 'un bar',
        'first_mes' => 'Salut !', 'mes_example' => '<START> exemple']);
    $v1 = $bySlug($r['slug']);
    $check($v1 && $v1['description'] === 'Desc V1' && array_column($promptRows($v1), 1) === [$labels['personality'], $labels['scenario'], $labels['first_mes'], $labels['mes_example']],
        'carte V1 : description + personnalité, scénario, premier message, exemples → prompts « Autre »');
    $r  = card_import_array(['char_name' => 'Tavern', 'char_persona' => 'joyeux']);
    $check(($bySlug($r['slug'])['name'] ?? '') === 'Tavern', 'carte V1 (anciens noms char_name, char_persona)');

    $v2card = ['spec' => 'chara_card_v2', 'spec_version' => '2.0', 'data' => [
        'name' => '<script>alert(1)</script>Zoé', 'description' => 'Desc', 'personality' => 'vive', 'scenario' => '',
        'first_mes' => 'Coucou', 'mes_example' => '', 'creator_notes' => 'Une seule ligne', 'system_prompt' => 'Tu es Zoé.',
        'post_history_instructions' => 'Reste dans le rôle.', 'alternate_greetings' => ['Bonsoir', ['pas une chaîne']],
        'tags' => ['a', 'b', ['x'], 'a'], 'creator' => 'Moi', 'character_version' => '1.2', 'extensions' => ['autre_app' => ['x' => 1]],
    ]];
    $r  = card_import_array($v2card);
    $v2 = $bySlug($r['slug']);
    $check($v2 && $v2['tagline'] === 'Une seule ligne' && $v2['directive'] === 'Reste dans le rôle.' && json_list($v2['tags']) === ['a', 'b'],
        'carte V2 : notes courtes → accroche, consigne, tags (non-chaînes et doublons écartés)');
    $check($v2 && json_list($v2['meta']) === [['k' => $labels['creator'], 'v' => 'Moi'], ['k' => $labels['character_version'], 'v' => '1.2']]
        && $promptRows($v2)[0] === ['system', '', 'Tu es Zoé.'] && count($promptRows($v2)) === 4,
        'carte V2 : créateur et version → attributs, prompt système, salutations');
    $check($v2 && !str_contains(e($v2['name']), '<script>'), 'HTML de la carte stocké tel quel, échappé à l’affichage');
    $back = card_build($v2)['data'];
    $check($back['personality'] === 'vive' && $back['first_mes'] === 'Coucou' && $back['alternate_greetings'] === ['Bonsoir']
        && $back['creator'] === 'Moi' && $back['character_version'] === '1.2' && $back['system_prompt'] === 'Tu es Zoé.',
        'carte importée puis réexportée : champs de carte retrouvés');

    $v3 = ['spec' => 'chara_card_v3', 'spec_version' => '3.0', 'data' => ['name' => 'Trois', 'description' => 'V3', 'nickname' => 'T', 'group_only_greetings' => []]];
    $v3png = png_add_text(png_add_text((string) file_get_contents($pngFile(40, 60, [90, 90, 200])), 'chara', base64_encode('{"name":"Ignorée"}')),
        'ccv3', base64_encode(json_encode($v3)));
    file_put_contents($tmp . '/v3.png', $v3png);
    $r = card_import_file($tmp . '/v3.png');
    $t3 = $bySlug($r['slugs'][0]);
    $check(($t3['name'] ?? '') === 'Trois' && $t3['description'] === 'V3' && $mediaExists((string) $t3['avatar']), 'carte V3 en PNG : bloc « ccv3 » prioritaire, portrait importé');

    // fichiers refusés proprement
    $count = (int) db()->query('SELECT COUNT(*) FROM personas')->fetchColumn();
    file_put_contents($tmp . '/bad.txt', 'bonjour');
    file_put_contents($tmp . '/bad.json', '{"name": "x",');
    file_put_contents($tmp . '/big.json', '{"name":"Gros","description":"' . str_repeat('a', 70 * 1024) . '"}');
    file_put_contents($tmp . '/plain.png', (string) file_get_contents($pngFile(10, 10, [0, 0, 0])));
    $check($throws(static fn () => card_import_file($tmp . '/bad.txt'), 'format non reconnu'), 'fichier quelconque refusé');
    $check($throws(static fn () => card_import_file($tmp . '/bad.json'), 'JSON valide'), 'JSON invalide refusé');
    $check($throws(static fn () => card_import_file($tmp . '/big.json'), 'volumineuse'), 'JSON trop gros refusé (limite de test : 64 Ko)');
    $check($throws(static fn () => card_import_file($tmp . '/plain.png'), 'ne contient pas'), 'PNG sans fiche refusé');
    $bigPng = png_add_text((string) file_get_contents($tmp . '/plain.png'), 'chara', base64_encode(str_repeat('x', 70 * 1024)));
    $check($throws(static fn () => card_from_png($bigPng), 'volumineuse'), 'PNG avec une fiche trop grosse refusé');
    $check($throws(static fn () => card_import_array(['spec' => 'autre_chose', 'data' => []]), 'non pris en charge'), 'spec inconnue refusée');
    $check($throws(static fn () => card_import_array(['name' => ['pas', 'un', 'texte']]), 'pas de nom'), 'nom non textuel refusé');
    $check($throws(static fn () => card_import_array(['spec' => 'chara_card_v2', 'data' => 'x']), 'Character Card'), 'carte V2 sans objet data refusée');
    $check(png_text_chunks("\x89PNG\r\n\x1a\n" . pack('N', 0x7fffffff) . 'tEXtxx') === [], 'PNG aux longueurs de bloc incohérentes : lecture bornée, sans erreur');
    $check((int) db()->query('SELECT COUNT(*) FROM personas')->fetchColumn() === $count, 'aucun persona créé par les fichiers refusés');
    $check($before + 7 === $count && $bySlug('lisa')['name'] === 'Lisa', 'persona d’origine jamais écrasé');

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

    echo PHP_EOL . '— mémoire de conversation' . PHP_EOL;
    $pid = (int) db()->query('SELECT id FROM personas ORDER BY id LIMIT 1')->fetchColumn();
    db()->prepare('INSERT INTO conversations (persona_id, title) VALUES (?, ?)')->execute([$pid, 'mémoire']);
    $cid = (int) db()->lastInsertId();
    $ids = [];
    for ($i = 1; $i <= 10; $i++) {
        db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)')
            ->execute([$cid, $i % 2 ? 'user' : 'assistant', 'message ' . $i]);
        $ids[$i] = (int) db()->lastInsertId();
    }
    $conv = db()->query('SELECT * FROM conversations WHERE id = ' . $cid)->fetch();
    $check(count(fetch_context_messages($cid, $conv)) === 10, 'sans résumé : tout l’historique est envoyé');
    db()->prepare('UPDATE conversations SET summary = ?, last_compressed_id = ? WHERE id = ?')
        ->execute(['Sujet : test', $ids[6], $cid]);
    $conv = db()->query('SELECT * FROM conversations WHERE id = ' . $cid)->fetch();
    $sent = array_map(static fn (array $m): string => (string) $m['content'], fetch_context_messages($cid, $conv));
    $check($sent === ['message 5', 'message 6', 'message 7', 'message 8', 'message 9', 'message 10'],
        'avec résumé : messages non résumés + 2 de raccord seulement');
    $conv['memory_enabled'] = 0;
    $check(count(fetch_context_messages($cid, $conv)) === 10, 'mémoire désactivée : historique complet');
    $stats = chat_memory_stats($cid, ['memory_enabled' => 1] + $conv);
    $check($stats['unsummarized'] === 4 && $stats['context_tokens'] > $stats['recent_tokens'], 'statistiques : 4 non résumés, contexte estimé cohérent');

    $persona = db()->query('SELECT * FROM personas WHERE id = ' . $pid)->fetch();
    $built = chat_build_messages($persona, [], ['memory_enabled' => 1] + $conv, fetch_context_messages($cid, ['memory_enabled' => 1] + $conv), 'bonjour');
    $first = $built['messages'][0]['content'];
    $last  = (string) end($built['messages'])['content'];
    $check(!str_contains($first, 'Contexte réel') && str_contains($first, 'Sujet : test'), 'prompt système stable (résumé, sans l’heure)');
    $check(str_starts_with($last, '<contexte>') && str_ends_with($last, 'bonjour'), 'heure et souvenirs dans le dernier message');

    // les derniers échanges d'abord : on remonte du plus récent sans trou ; même si la place
    // manque (fenêtre minuscule, longue consigne), les CHAT_MIN_RECENT derniers sont toujours là
    $hist = [];
    for ($i = 1; $i <= 120; $i++) {
        $hist[] = ['id' => 1000 + $i, 'role' => $i % 2 ? 'user' : 'assistant', 'content' => 'M' . $i . ' ' . str_repeat('texte ', 60), 'image' => ''];
    }
    $roomy = chat_build_messages($persona, [], ['memory_enabled' => 0, 'context_window' => 8192, 'max_tokens' => 400] + $conv, $hist, 'et maintenant ?');
    $tags  = array_map(static fn (array $m): string => strtok((string) $m['content'], ' '), array_slice($roomy['messages'], 1, -1));
    $n     = count($tags);
    $check($n > CHAT_MIN_RECENT && $n < 120 && end($tags) === 'M120' && $tags === array_map(static fn (int $k): string => 'M' . $k, range(121 - $n, 120)),
        'contexte : les derniers messages, sans trou, du plus ancien gardé au plus récent (' . $n . ')');
    $tight = chat_build_messages($persona, [], ['memory_enabled' => 0, 'context_window' => 2048, 'max_tokens' => 1200] + $conv, $hist, 'et maintenant ?');
    $tags  = array_map(static fn (array $m): string => strtok((string) $m['content'], ' '), array_slice($tight['messages'], 1, -1));
    $check($tags === ['M115', 'M116', 'M117', 'M118', 'M119', 'M120'] && str_ends_with((string) end($tight['messages'])['content'], 'et maintenant ?'),
        'contexte serré : les ' . CHAT_MIN_RECENT . ' derniers messages restent (raccourcis au besoin)');

    echo PHP_EOL . '— index plein texte des messages' . PHP_EOL;
    db()->prepare('INSERT INTO conversations (persona_id, title) VALUES (?, ?)')->execute([$pid, 'ailleurs']);
    $cid2 = (int) db()->lastInsertId();
    $ins  = db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)');
    $ins->execute([$cid2, 'user', 'On ira manger des pastéis à Lisbonne en été']);
    $mLis = (int) db()->lastInsertId();
    $ins->execute([$cid2, 'assistant', 'Le café était délicieux']);
    $ins->execute([$cid2, 'notice', 'Lisbonne (notice technique)']);
    $hits = static fn (string $q): array => array_column(chat_persona_search($pid, $q, 10), 'id');
    $check($hits('lisb') === [$mLis], 'début de mot : « lisb » trouve « Lisbonne » (notices exclues)');
    $check(count($hits('pasteis')) === 1 && count($hits('CAFE')) === 1, 'accents et majuscules ignorés');
    $check(count($hits('lisbonne été')) === 1 && $hits('lisbonne hiver') === [], 'tous les mots doivent être présents');
    $check(count($hits('"; DROP TABLE messages; --')) === 0 && count($hits('a* OR NEAR(')) >= 0, 'requête avec caractères spéciaux sans erreur');
    db()->prepare('UPDATE messages SET content = ? WHERE id = ?')->execute(['On ira à Porto', $mLis]);
    $check($hits('lisbonne') === [] && $hits('porto') === [$mLis], 'index mis à jour après modification');
    db()->prepare('DELETE FROM messages WHERE id = ?')->execute([$mLis]);
    $check($hits('porto') === [], 'index mis à jour après suppression');
    $ins->execute([$cid2, 'user', 'Tu te souviens du concert de jazz à Porto ?']);
    $ins->execute([$cid2, 'user', 'Porto, jazz, concert, encore du jazz au concert de Porto']);
    $rec = chat_recall_snippets($pid, 'Tu te souviens du concert de jazz ?', $cid, 2);
    $check(count($rec) === 2 && str_contains((string) $rec[0]['content'], 'encore du jazz'), 'rappel : les extraits les plus pertinents d’abord');
    $check((int) db()->query("SELECT COUNT(*) FROM messages_fts WHERE messages_fts MATCH 'jazz'")->fetchColumn() === 2, 'index cohérent avec la table');

    echo PHP_EOL . '— fréquence des souvenirs' . PHP_EOL;
    set_setting('facts_frequency', 'every');
    set_setting('facts_every', '4');
    $check(facts_should_run(3, false) !== '' && facts_should_run(4, false) === '', 'tous les 4 messages : attend puis lance');
    $check(facts_should_run(1, true) === '', '« retiens que… » lance tout de suite');
    set_setting('facts_frequency', 'explicit');
    $check(facts_should_run(10, false) !== '' && facts_should_run(1, true) === '', 'mode « sur demande »');
    set_setting('facts_frequency', 'off');
    $check(facts_should_run(10, true) !== '', 'mode « jamais »');
    set_setting('facts_frequency', 'each');

    echo PHP_EOL . '— titres automatiques' . PHP_EOL;
    $check(chat_title_mode() === 'remote', 'par défaut : modèle distant (comportement d’origine)');
    set_setting('chat_title_model', 'off');
    $check(chat_title_mode() === 'off' && chat_auto_title($cid) === null, 'désactivés : aucun appel, aucun titre');
    set_setting('chat_title_model', 'n’importe quoi');
    $check(chat_title_mode() === 'remote', 'valeur inconnue → modèle distant');
    set_setting('chat_title_model', 'remote');

    echo PHP_EOL . '— détection multilingue (rappel, demande de mémorisation, mots vides)' . PHP_EOL;
    $all  = static fn (callable $fn, array $list): bool => count(array_filter($list, $fn)) === count($list);
    $none = static fn (callable $fn, array $list): bool => !array_filter($list, $fn);
    $check($all('chat_has_recall_cue', ['Tu te souviens de notre balade ?', 'La dernière fois on parlait de Porto', 'Comme convenu, je passe demain']),
        'rappel : français');
    $check($all('chat_has_recall_cue', ['Do you remember our trip?', 'Last time we talked about jazz', 'As agreed, I’ll come tomorrow']),
        'rappel : anglais');
    $check($all('chat_has_recall_cue', ['¿Te acuerdas del concierto?', 'La última vez hablamos de Oporto', 'Como quedamos, paso mañana']),
        'rappel : espagnol');
    $check($none('chat_has_recall_cue', ['Salut, ça va ?', 'What are you doing tonight?', '¿Qué haces esta noche?']),
        'rappel : pas de faux positif sur une phrase ordinaire (3 langues)');
    $check($all('facts_explicit_request', ['Retiens que je suis végétarien', 'Souviens-toi que mon chat s’appelle Tom', 'N’oublie pas mon anniversaire']),
        'demande de mémorisation : français');
    $check($all('facts_explicit_request', ['Remember that I’m allergic to nuts', 'Please keep in mind that I work nights', "Don't forget my birthday is in May"]),
        'demande de mémorisation : anglais');
    $check($all('facts_explicit_request', ['Recuerda que soy vegetariano', 'No olvides que trabajo de noche', 'Apunta que mi gato se llama Tom']),
        'demande de mémorisation : espagnol');
    $check($none('facts_explicit_request', ['Tu te souviens de ce jour ?', 'Do you remember that day?', '¿Recuerdas aquel día?', 'J’aime le jazz', 'I like jazz', 'Me gusta el jazz']),
        'demande de mémorisation : une question « tu te souviens ? » n’en est pas une (3 langues)');

    // mots vides : une phrase faite uniquement d'indices de rappel ne cherche rien
    $ins->execute([$cid2, 'user', 'I remember we talked about it yesterday']);
    $ins->execute([$cid2, 'user', 'Hablamos de eso, ¿te acuerdas? La última vez']);
    $check(chat_recall_snippets($pid, 'Do you remember what we talked about yesterday?', $cid, 3) === [], 'rappel : mots vides anglais ignorés');
    $check(chat_recall_snippets($pid, '¿Te acuerdas de lo que hablamos la última vez?', $cid, 3) === [], 'rappel : mots vides espagnols ignorés');
    $rec = chat_recall_snippets($pid, 'Do you remember the jazz concert?', $cid, 2);
    $check(count($rec) === 2 && str_contains((string) $rec[0]['content'], 'concert'), 'rappel en anglais : les mots utiles sont cherchés');
    $ins->execute([$cid2, 'user', 'Fuimos al concierto de jazz en Oporto']);
    $rec = chat_recall_snippets($pid, '¿Te acuerdas del concierto en Oporto?', $cid, 2);
    $check(count($rec) === 1 && str_contains((string) $rec[0]['content'], 'Fuimos'), 'rappel en espagnol : les mots utiles sont cherchés');

    // souvenirs : lettres espagnoles (ñ, á…) gardées dans les mots-clés
    facts_add($pid, 'Toma café cada mañana', 'preference');
    facts_add($pid, 'Vit à Lyon', 'fait');
    $rel = facts_relevant($pid, '¿Qué tomas por la mañana?', 1);
    $check(count($rel) === 1 && str_contains((string) $rel[0]['content'], 'mañana'), 'souvenirs pertinents : « mañana » reste un mot entier');
    $check(count(facts_relevant($pid, 'Remember what I like', 1)) === 1, 'souvenirs : phrase anglaise faite de mots vides → souvenirs récents');

    echo PHP_EOL . '— textes serveur selon la langue (le contenu envoyé au modèle reste en français)' . PHP_EOL;
    $memRow = [['category' => 'preference', 'content' => 'aime le jazz']];
    i18n_set_locale('fr');
    $check(fr_date('2026-11-08 10:00:00') === '8 nov. 2026' && human_bytes(1536 * 1024) === '1,5 Mo', 'français : date et taille inchangées');
    $check(prompt_kinds()['negative'] === 'Négatif' && facts_categories()['decision'] === 'Décision', 'français : libellés inchangés');
    i18n_set_locale('en');
    $check(fr_date('2026-11-08 10:00:00') === 'Nov 8, 2026' && human_bytes(1536 * 1024) === '1.5 MB', 'anglais : date et taille');
    $check(prompt_kinds()['negative'] === 'Negative' && facts_confidences()['hypothese'] === 'Hypothesis', 'anglais : libellés');
    $check(facts_format($memRow) === '- [Préférence] aime le jazz', 'anglais : souvenirs envoyés au modèle toujours en français');
    $check(chat_llm_date('2026-11-08 10:00:00') === '8 nov. 2026', 'anglais : date du contexte du modèle toujours en français');
    $check(str_contains(chat_ring(['percent' => 10, 'window' => 8192, 'context_tokens' => 1200, 'compressions' => 1]), 'Context 1,200 / 8,192 tokens'),
        'anglais : titre de l’anneau de contexte');
    i18n_set_locale('es');
    $check(fr_date('2026-11-08 10:00:00') === '8 nov. 2026' && t('flash.persona.photos_added', 1) === '1 foto añadida.', 'espagnol : date et pluriel');
    $check($throws(static fn () => store_upload($upload($tmp . '/bomb.png')), 'demasiado grande'), 'espagnol : erreur d’upload traduite');
    $empty = new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
    $check((new LlmUnavailable('x', 'error', $empty))->getCode() === LLM_ERR_EMPTY, 'erreur « réponse vide » reconnue par son code, quelle que soit la langue');
    i18n_set_locale(null);

    echo PHP_EOL . '— traductions' . PHP_EOL;
    i18n_set_locale('fr');
    $check(t('nav.library') === 'Bibliothèque' && t('login.blocked', 3) === 'Trop de tentatives. Réessaie dans 3 min.', 'français : texte et paramètre {n}');
    i18n_set_locale('en');
    $check(t('nav.library') === 'Library' && t('login.blocked', 3) === 'Too many attempts. Try again in 3 min.', 'anglais : texte et paramètre {n}');
    $unknown = 'cle' . '.inexistante'; // construite : bin/i18n-check.php ne doit pas la signaler
    $check(t($unknown) === $unknown, 'clé inconnue : la clé elle-même (rien ne casse)');
    $check((i18n_js()['modal.cancel'] ?? '') === 'Cancel' && !isset(i18n_js()['nav.library']), 'textes « js.* » seulement, dans la langue choisie');
    $html = t_html('login.no_password', ['example' => '<code>a</code>', 'local' => '<code>b</code>']);
    $check(str_contains($html, '<code>a</code>') && str_contains($html, '<code>b</code>'), 't_html : fragments HTML insérés');
    $check(i18n_plural_form('fr', 0) === 'one' && i18n_plural_form('fr', 1) === 'one' && i18n_plural_form('fr', 2) === 'other', 'pluriel français : 0 et 1 au singulier');
    $check(i18n_plural_form('en', 0) === 'other' && i18n_plural_form('en', 1) === 'one' && i18n_plural_form('en', 2) === 'other', 'pluriel anglais : seul 1 au singulier');
    $langs = array_keys(i18n_available());
    $check(i18n_from_header('en-GB,en;q=0.8,fr;q=0.5', $langs) === 'en' && i18n_from_header('fr-CA', $langs) === 'fr', 'langue du navigateur reconnue (en-GB → en, fr-CA → fr)');
    $check(i18n_from_header('de-DE,de;q=0.9', $langs) === 'fr' && i18n_from_header('de;q=1, en;q=0.5', $langs) === 'en', 'langue absente : suivante dans la liste, sinon français');
    $check($langs[0] === 'fr' && in_array('es', $langs, true), 'français proposé en premier, espagnol installé');
    i18n_set_locale('es');
    $check(t('nav.library') === 'Biblioteca' && t('login.blocked', 3) === 'Demasiados intentos. Vuelve a intentarlo en 3 min.', 'espagnol : texte et paramètre {n}');
    i18n_set_locale(null);

    echo PHP_EOL . '— export de conversation' . PHP_EOL;
    require_once __DIR__ . '/../src/routes/conversation/export_format.php';
    $rows = [
        ['role' => 'user', 'content' => "Salut <b>toi</b>\n# pas un titre", 'image' => 'abc', 'created_at' => '2026-11-08 13:05:00'],
        ['role' => 'memory', 'content' => 'Mémoire mise à jour : aime le fado', 'image' => '', 'created_at' => '2026-11-08 13:05:10'],
        ['role' => 'assistant', 'content' => 'Bonjour !', 'image' => '', 'split_points' => '[]', 'created_at' => '2026-11-08 13:06:00'],
        ['role' => 'system', 'content' => 'jamais exporté', 'image' => '', 'created_at' => '2026-11-08 13:07:00'],
    ];
    $meta = ['title' => 'Week-end à Lisbonne', 'persona' => 'Camille', 'count' => 2, 'exported_at' => '2026-11-08 20:00:00'];
    i18n_set_locale('fr');
    $txt = export_render($rows, $meta, 'txt');
    $check(str_starts_with($txt, "Week-end à Lisbonne\nConversation avec Camille\nExportée le 8 nov. 2026 à 21:00 · 2 messages\n"), 'texte : en-tête (titre, persona, date locale, nombre)');
    $check(str_contains($txt, "[8 nov. 2026 à 14:05] Moi : [Photo] Salut <b>toi</b>\n# pas un titre\n\n"), 'texte : « [date heure] Moi : … », heure de Paris, photo signalée');
    $check(str_contains($txt, "\n  (Mémoire mise à jour : aime le fado)\n\n[8 nov. 2026 à 14:06] Camille : Bonjour !\n"), 'texte : souvenir discret, puis réponse du persona');
    $check(!str_contains($txt, 'jamais exporté'), 'texte : rôles inconnus exclus');
    $md = export_render($rows, $meta, 'md');
    $check(str_starts_with($md, "# Week-end à Lisbonne\n\n- Conversation avec Camille\n"), 'Markdown : titre et liste d’en-tête');
    $check(str_contains($md, "**Moi** · *8 nov. 2026 à 14:05*\n\n*[Photo]*\n\nSalut &lt;b&gt;toi&lt;/b&gt;  \n\\# pas un titre\n\n"), 'Markdown : HTML neutralisé, « # » échappé, saut de ligne conservé');
    $check(str_contains($md, "> *Mémoire mise à jour : aime le fado*"), 'Markdown : souvenir en citation discrète');
    i18n_set_locale('en');
    $txt = export_render($rows, $meta, 'txt');
    $check(preg_match('/^Exported on .*2026, 9:00 PM · 2 messages$/m', $txt) === 1, 'anglais : en-tête et heure traduits');
    $check(str_contains($txt, '2:05 PM] Me: [Photo] Salut'), 'anglais : « Me: »');
    i18n_set_locale(null);
    $check(export_filename('Week-end à Lisbonne !', 'md', '2026-11-08') === 'week-end-a-lisbonne-2026-11-08.md'
        && export_filename('???', 'txt', '2026-11-08') === 'conversation-2026-11-08.txt', 'nom de fichier propre à partir du titre');
    $check(export_bubbles(['content' => 'Un. Deux.', 'split_points' => '[4]']) === ['Un.', 'Deux.'], 'page imprimable : découpage en bulles');
    // bouton IA des prompts : bon mode selon le type, jamais de réécriture d'un prompt système
    $check(llm_prompt_mode('image', '', '', '') === 'create' && llm_prompt_mode('system', '', 'ton froid', '') === 'create', 'IA prompt : vide → création (consigne comprise)');
    $check(llm_prompt_mode('image', 'x', '', '') === 'optimize' && llm_prompt_mode('video', 'x', '', 'optimize') === 'optimize', 'IA prompt : image ou vidéo remplie → optimisation');
    $check(llm_prompt_mode('system', 'x', '', 'optimize') === 'complete' && llm_prompt_mode('other', 'x', '', '') === 'complete', 'IA prompt : système / autre rempli → complément seulement, jamais réécrit');
    $check(llm_prompt_mode('system', 'x', '', 'analyze') === 'analyze' && llm_prompt_mode('system', 'x', 'ton froid', '') === 'instruct', 'IA prompt : analyse et consigne explicites');
    $check(llm_strip_known_lines("Tu es Camille.\nTu tutoies.", "tu tutoies.\nTu as les yeux verts.") === 'Tu as les yeux verts.', 'IA prompt : un complément ne répète pas les lignes existantes');

    // historique des prompts : lignes + / −, 10 dernières modifications seulement
    $check(line_diff_counts("a\nb\nc", "a\nB\nc\nd") === ['added' => 2, 'removed' => 1], 'historique : lignes ajoutées et supprimées');
    $check(line_diff_counts("a\r\nb", "a\nb") === ['added' => 0, 'removed' => 0], 'historique : fins de ligne Windows ignorées');
    $__pp = (int) db()->query('SELECT id FROM personas ORDER BY id LIMIT 1')->fetchColumn();
    db()->prepare('INSERT INTO prompts (persona_id, title, kind, body) VALUES (?, ?, ?, ?)')->execute([$__pp, 'h', 'image', 'v0']);
    $__pr = (int) db()->lastInsertId();
    for ($__i = 1; $__i <= 13; $__i++) {
        prompt_version_record($__pr, 'v' . ($__i - 1), 'v' . $__i);
    }
    prompt_version_record($__pr, 'v13', 'v13'); // texte inchangé : rien d'enregistré
    $__vs = prompt_versions($__pr);
    $check(count($__vs) === 10 && $__vs[0]['new_body'] === 'v13' && $__vs[9]['new_body'] === 'v4', 'historique : 10 dernières modifications, la plus récente en haut');
    db()->prepare('DELETE FROM prompts WHERE id = ?')->execute([$__pr]);
    $check(prompt_versions($__pr) === [], 'historique : supprimé avec le prompt');

    // journal des échanges : image remplacée par sa taille, 20 entrées au plus, jamais la clé
    $__lm = llm_log_messages([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Regarde'], ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . str_repeat('A', 4096)]]]]]);
    $check(($__lm[0]['content'] ?? '') === "Regarde\n[image ~3 Ko]", 'journal : photo remplacée par sa taille');
    db()->exec('DELETE FROM llm_logs');
    for ($__i = 0; $__i < 25; $__i++) {
        llm_log_write(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'q' . $__i]]], 'http://127.0.0.1:1/v1', ['purpose' => 'test'], ['status' => 'ok', 'response' => 'r' . $__i]);
    }
    $__rows = llm_log_list();
    $check(count($__rows) === 20 && $__rows[0]['response'] === 'r24' && $__rows[19]['response'] === 'r5', 'journal : 20 derniers échanges, le plus récent en haut');
    try {
        llm_request([['role' => 'user', 'content' => 'ping']], ['base_url' => 'http://127.0.0.1:1/v1', 'model' => 'm', 'key' => 'sk-secret'], ['timeout' => 2, 'purpose' => 'test']);
    } catch (Throwable $__e) {
    }
    $__last = llm_log_list()[0] ?? [];
    $check(($__last['status'] ?? '') === 'error' && ($__last['error'] ?? '') !== ''
        && !str_contains(json_encode($__last), 'sk-secret'), 'journal : erreur de connexion enregistrée, sans la clé');
    db()->exec('DELETE FROM llm_logs');
    // 51 mots : coupe après « tout.. », pas sur le « ! » final (sinon aucune coupe côté serveur
    // alors que le navigateur en affichait une → bulle répétée)
    $__t = trim(str_repeat('mot ', 44)) . ' et tout.. mais bon on fait avec !';
    $__p = chat_split_points($__t, 50, 2);
    $check(count($__p) === 1 && mb_substr($__t, $__p[0]) === 'mais bon on fait avec !', 'bulles : coupe à la dernière phrase, jamais en fin de texte');
    $__p = chat_split_points("\n\n" . $__t, 50, 2);
    $check(count($__p) === 1 && mb_substr("\n\n" . $__t, $__p[0]) === 'mais bon on fait avec !', 'bulles : décalage juste si la réponse commence par un saut de ligne');
    echo PHP_EOL . '— thèmes' . PHP_EOL;
    $ui = ui_style();
    $check($ui['accent'] === 'violet' && $ui['mode'] === 'dark', 'par défaut : Violet + Sombre (rendu historique)');
    set_setting('ui_accent', 'ocean');
    set_setting('ui_mode', 'auto');
    $ui = ui_style();
    $check($ui['accent'] === 'ocean' && $ui['mode'] === 'auto', 'couleur et mode enregistrés relus');
    set_setting('ui_accent', 'fluo"><script>');
    set_setting('ui_mode', 'nuit');
    $ui = ui_style();
    $check($ui['accent'] === 'violet' && $ui['mode'] === 'dark', 'valeur inconnue → valeur par défaut');
    $css = (string) file_get_contents(PV_ROOT . '/public/assets/app.css');
    $missing = array_filter(array_slice(UI_ACCENTS, 1), static fn ($a) => !str_contains($css, 'html[data-accent="' . $a . '"]'));
    $check($missing === [], 'chaque couleur proposée a sa palette dans app.css');
    $check(str_contains($css, 'html[data-mode="light"]') && str_contains($css, 'html[data-mode="auto"]'), 'modes clair et automatique présents dans app.css');
    $check(isset(UI_THEME_COLORS['dark'], UI_THEME_COLORS['light']), 'couleur de barre du navigateur pour chaque mode');
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

echo PHP_EOL . ($failures === 0 ? 'Selftest OK' : plural($failures, 'échec', 'échecs')) . PHP_EOL;
exit($failures === 0 ? 0 : 1);
