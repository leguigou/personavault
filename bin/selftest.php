<?php
/**
 * Auto-test rapide, sans serveur ni réseau : base et dossiers temporaires, jamais la vraie base.
 *
 * Vérifie : migrations versionnées, aller-retour export → import ZIP (portrait, photos,
 * prompts), comptage des références d'images, refus des images géantes, limites du ZIP,
 * jeton CSRF vide refusé, blocage des tentatives de connexion, export de conversation.
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
