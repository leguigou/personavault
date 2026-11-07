<?php
declare(strict_types=1);

define('PV_ROOT', dirname(__DIR__));

$GLOBALS['pv_config'] = require PV_ROOT . '/config.php';

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['pv_config'][$key] ?? $default;
}

/* ---------------------------------------------------------------- session */

if (session_status() !== PHP_SESSION_ACTIVE) {
    $lifetime = max(3600, (int) config('session_lifetime', 30 * 24 * 3600));

    // sessions stockées dans le projet : /tmp serait purgé par le système
    $sessionDir = PV_ROOT . '/storage/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0770, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.use_strict_mode', '1');

    session_name((string) config('session_name'));
    $cookieParams = [
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ];
    session_set_cookie_params($cookieParams);
    session_start();

    // glissement : chaque visite repousse l'expiration de 30 jours
    if (!empty($_SESSION['auth_at'])) {
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $lifetime,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ]);
    }
}

/* ------------------------------------------------------------------- base */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = (string) config('db');
    $dir  = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    migrate($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS personas (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    slug        TEXT NOT NULL UNIQUE,
    name        TEXT NOT NULL,
    tagline     TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    avatar      TEXT NOT NULL DEFAULT '',
    tags        TEXT NOT NULL DEFAULT '[]',
    meta        TEXT NOT NULL DEFAULT '[]',
    favorite    INTEGER NOT NULL DEFAULT 0,
    archived    INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS photos (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    persona_id INTEGER NOT NULL REFERENCES personas(id) ON DELETE CASCADE,
    file       TEXT NOT NULL,
    caption    TEXT NOT NULL DEFAULT '',
    sort       INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS prompts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    persona_id INTEGER NOT NULL REFERENCES personas(id) ON DELETE CASCADE,
    title      TEXT NOT NULL DEFAULT '',
    kind       TEXT NOT NULL DEFAULT 'image',
    body       TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS conversations (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    persona_id        INTEGER NOT NULL REFERENCES personas(id) ON DELETE CASCADE,
    title             TEXT NOT NULL DEFAULT '',
    provider          TEXT NOT NULL DEFAULT '',
    model             TEXT NOT NULL DEFAULT '',
    temperature       REAL NOT NULL DEFAULT 0.85,
    max_tokens        INTEGER NOT NULL DEFAULT 400,
    context_window    INTEGER NOT NULL DEFAULT 8192,
    memory_enabled    INTEGER NOT NULL DEFAULT 1,
    directive         TEXT NOT NULL DEFAULT '',
    summary           TEXT NOT NULL DEFAULT '',
    last_compressed_id INTEGER NOT NULL DEFAULT 0,
    compressions      INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS messages (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id   INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    role              TEXT NOT NULL,
    content           TEXT NOT NULL,
    model             TEXT NOT NULL DEFAULT '',
    prompt_tokens     INTEGER NOT NULL DEFAULT 0,
    completion_tokens INTEGER NOT NULL DEFAULT 0,
    latency_ms        INTEGER NOT NULL DEFAULT 0,
    created_at        TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_messages_conv ON messages(conversation_id, id);

CREATE TABLE IF NOT EXISTS user_memories (
    id                     INTEGER PRIMARY KEY AUTOINCREMENT,
    persona_id             INTEGER NULL REFERENCES personas(id) ON DELETE CASCADE,
    category               TEXT NOT NULL DEFAULT 'fait',
    content                TEXT NOT NULL,
    confidence             TEXT NOT NULL DEFAULT 'confirme',
    source_conversation_id INTEGER NULL,
    created_at             TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at             TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_mem_persona ON user_memories(persona_id);

CREATE VIRTUAL TABLE IF NOT EXISTS user_memories_fts USING fts5(
    content,
    category,
    content='user_memories',
    content_rowid='id',
    tokenize="unicode61 remove_diacritics 2"
);

CREATE TRIGGER IF NOT EXISTS mem_ai AFTER INSERT ON user_memories BEGIN
    INSERT INTO user_memories_fts(rowid, content, category) VALUES (new.id, new.content, new.category);
END;

CREATE TRIGGER IF NOT EXISTS mem_ad AFTER DELETE ON user_memories BEGIN
    INSERT INTO user_memories_fts(user_memories_fts, rowid, content, category)
    VALUES ('delete', old.id, old.content, old.category);
END;

CREATE TRIGGER IF NOT EXISTS mem_au AFTER UPDATE ON user_memories BEGIN
    INSERT INTO user_memories_fts(user_memories_fts, rowid, content, category)
    VALUES ('delete', old.id, old.content, old.category);
    INSERT INTO user_memories_fts(rowid, content, category) VALUES (new.id, new.content, new.category);
END;

CREATE INDEX IF NOT EXISTS idx_photos_persona  ON photos(persona_id);
CREATE INDEX IF NOT EXISTS idx_prompts_persona ON prompts(persona_id);
SQL);

    // colonne ajoutée après coup : curseur d'extraction des souvenirs
    $cols = $pdo->query('PRAGMA table_info(conversations)')->fetchAll();
    if (!in_array('facts_cursor', array_column($cols, 'name'), true)) {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN facts_cursor INTEGER NOT NULL DEFAULT 0');
    }
    // souvenirs durables partagés avec toutes les conversations du même persona
    if (!in_array('facts_enabled', array_column($cols, 'name'), true)) {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN facts_enabled INTEGER NOT NULL DEFAULT 1');
    }
    // réglages IA portés par le persona (et non plus par la conversation)
    $pcols = $pdo->query('PRAGMA table_info(personas)')->fetchAll();
    $pnames = array_column($pcols, 'name');
    if (!in_array('model', $pnames, true)) {
        $pdo->exec("ALTER TABLE personas ADD COLUMN model TEXT NOT NULL DEFAULT ''");
        $pdo->exec('ALTER TABLE personas ADD COLUMN temperature REAL NOT NULL DEFAULT 0.85');
        $pdo->exec('ALTER TABLE personas ADD COLUMN max_tokens INTEGER NOT NULL DEFAULT 400');
        $pdo->exec('ALTER TABLE personas ADD COLUMN context_window INTEGER NOT NULL DEFAULT 8192');
        $pdo->exec("ALTER TABLE personas ADD COLUMN directive TEXT NOT NULL DEFAULT ''");
    }

    // coupe d'une réponse en deux bulles
    $mcols = $pdo->query('PRAGMA table_info(messages)')->fetchAll();
    if (!in_array('split_at', array_column($mcols, 'name'), true)) {
        $pdo->exec('ALTER TABLE messages ADD COLUMN split_at INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('split_points', array_column($mcols, 'name'), true)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN split_points TEXT NOT NULL DEFAULT ''");
    }
    // image jointe à un message (vision)
    if (!in_array('image', array_column($mcols, 'name'), true)) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN image TEXT NOT NULL DEFAULT ''");
    }
    // titre de conversation généré automatiquement (remis à 0 si renommé à la main)
    if (!in_array('title_auto', array_column($cols, 'name'), true)) {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN title_auto INTEGER NOT NULL DEFAULT 1');
    }

    // relances spontanées du persona après un long silence
    if (!in_array('auto_followup', array_column($cols, 'name'), true)) {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN auto_followup INTEGER NOT NULL DEFAULT 1');
    }
    if (!in_array('last_followup_at', array_column($cols, 'name'), true)) {
        $pdo->exec("ALTER TABLE conversations ADD COLUMN last_followup_at TEXT NOT NULL DEFAULT ''");
    }
    if (!in_array('unread', array_column($cols, 'name'), true)) {
        $pdo->exec('ALTER TABLE conversations ADD COLUMN unread INTEGER NOT NULL DEFAULT 0');
    }
}

/* ------------------------------------------------------------------ utils */

/* --------------------------------------------------------------- réglages */

/** Préférences d'affichage des conversations (taille, bulles, densité…). */
function ui_style(): array
{
    return [
        'font'    => (float) setting('ui_font_size', '14.5'),
        'bubble'  => (string) setting('ui_bubble_style', 'bulle'),
        'density' => (string) setting('ui_density', 'confort'),
        'avatars' => (int) setting('ui_avatars', '1') === 1,
        'width'   => (string) setting('ui_width', 'normal'),
    ];
}

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = (string) $row['value'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function back(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if ($ref !== '' && str_starts_with($ref, 'http') && str_contains($ref, (string) ($_SERVER['HTTP_HOST'] ?? ''))) {
        redirect($ref);
    }
    redirect($fallback);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $sent = $_POST['_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Session expirée, merci de recharger la page.');
    }
}

function slugify(string $text): string
{
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');
    if ($text === '') {
        $text = 'persona';
    }
    return substr($text, 0, 60);
}

function unique_slug(string $name, ?int $ignoreId = null): string
{
    $base = slugify($name);
    $slug = $base;
    $i    = 2;
    $stmt = db()->prepare('SELECT id FROM personas WHERE slug = ? AND id IS NOT ?');
    while (true) {
        $stmt->execute([$slug, $ignoreId]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $slug = $base . '-' . $i++;
    }
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out   = '';
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : '?';
}

/** Teinte stable dérivée du nom, bornée à la gamme violet → rose de la marque. */
function hue_for(string $name): int
{
    $n = (int) (hexdec(substr(md5($name), 0, 4)) / 65535 * 140);
    return 200 + $n; // 200 → 340
}

function json_list(?string $json): array
{
    $out = json_decode((string) $json, true);
    return is_array($out) ? array_values($out) : [];
}

function prompt_kinds(): array
{
    return [
        'image'    => 'Image',
        'negative' => 'Négatif',
        'video'    => 'Vidéo',
        'system'   => 'Système',
        'other'    => 'Autre',
    ];
}

function fr_date(?string $sqlDate): string
{
    if (!$sqlDate) {
        return '';
    }
    $ts = strtotime($sqlDate . ' UTC');
    if ($ts === false) {
        return '';
    }
    $months = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function parse_tags(string $raw): array
{
    $parts = preg_split('/[,;\n]+/u', $raw) ?: [];
    $out   = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (!in_array($p, $out, true)) {
            $out[] = mb_substr($p, 0, 32);
        }
        if (count($out) >= 12) {
            break;
        }
    }
    return $out;
}

/** Lignes "clé: valeur" -> [[k, v], ...] */
function parse_meta(string $raw): array
{
    $out = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (preg_match('/^([^:]{1,40})\s*:\s*(.*)$/u', $line, $m)) {
            $out[] = ['k' => trim($m[1]), 'v' => trim($m[2])];
        } else {
            $out[] = ['k' => $line, 'v' => ''];
        }
        if (count($out) >= 20) {
            break;
        }
    }
    return $out;
}

function meta_to_text(array $meta): string
{
    $lines = [];
    foreach ($meta as $row) {
        if (!is_array($row) || !isset($row['k'])) {
            continue;
        }
        $lines[] = $row['v'] !== '' ? $row['k'] . ': ' . $row['v'] : (string) $row['k'];
    }
    return implode("\n", $lines);
}

/* ------------------------------------------------------------- auth/limits */

function is_logged(): bool
{
    return !empty($_SESSION['auth_at']);
}

function require_login(): void
{
    if (!is_logged()) {
        redirect('/login');
    }
}

function attempt_login(string $password): bool
{
    $key   = 'login_fails';
    $fails = $_SESSION[$key] ?? ['n' => 0, 'at' => 0];
    $now   = time();

    if ($now - (int) $fails['at'] > (int) config('login_window')) {
        $fails = ['n' => 0, 'at' => 0];
    }
    if ((int) $fails['n'] >= (int) config('login_max_try')) {
        $_SESSION[$key] = $fails;
        return false;
    }

    if (password_verify($password, (string) config('password_hash'))) {
        unset($_SESSION[$key]);
        session_regenerate_id(true);
        $_SESSION['auth_at'] = time();
        return true;
    }

    $fails['n'] = (int) $fails['n'] + 1;
    $fails['at'] = $now;
    $_SESSION[$key] = $fails;
    return false;
}

function login_blocked_for(): int
{
    $fails = $_SESSION['login_fails'] ?? ['n' => 0, 'at' => 0];
    if ((int) $fails['n'] < (int) config('login_max_try')) {
        return 0;
    }
    return max(0, (int) config('login_window') - (time() - (int) $fails['at']));
}

/* ---------------------------------------------------------------- uploads */

function store_upload(array $file, string $prefix = 'p'): string
{
    if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Aucun fichier reçu.');
    }
    if (($file['size'] ?? 0) > (int) config('max_upload')) {
        throw new RuntimeException('Image trop lourde (15 Mo max).');
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        throw new RuntimeException('Ce fichier n’est pas une image valide.');
    }

    $type = $info[2];
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!in_array($type, $allowed, true)) {
        throw new RuntimeException('Format accepté : JPG, PNG, WEBP ou GIF.');
    }

    $img = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG  => imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => imagecreatefromwebp($file['tmp_name']),
        IMAGETYPE_GIF  => imagecreatefromgif($file['tmp_name']),
    };
    if (!$img) {
        throw new RuntimeException('Image illisible.');
    }

    // Orientation EXIF (photos de téléphone)
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rot  = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($rot !== 0) {
            $img = imagerotate($img, $rot, 0) ?: $img;
        }
    }

    $base  = $prefix . bin2hex(random_bytes(8));
    $dir   = (string) config('uploads');
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    save_webp($img, $dir . '/' . $base . '.webp', 1800, 88);
    save_webp($img, $dir . '/' . $base . '.thumb.webp', 700, 84);

    return $base;
}

/**
 * Data URL JPEG (<= $maxSide px) pour envoyer une image à un modèle de vision.
 * LM Studio refuse le WebP : on convertit en JPEG à la volée.
 */
function media_data_url(string $base, int $maxSide = 900): string
{
    $src = (string) config('uploads') . '/' . $base . '.webp';
    if ($base === '' || !is_file($src)) {
        return '';
    }
    $img = @imagecreatefromwebp($src);
    if (!$img) {
        return '';
    }

    $w = imagesx($img);
    $h = imagesy($img);
    $ratio = min(1, $maxSide / max(1, max($w, $h)));
    if ($ratio < 1) {
        $nw  = max(1, (int) round($w * $ratio));
        $nh  = max(1, (int) round($h * $ratio));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $img = $dst;
    }

    ob_start();
    imagejpeg($img, null, 85);
    $jpeg = (string) ob_get_clean();

    return $jpeg === '' ? '' : 'data:image/jpeg;base64,' . base64_encode($jpeg);
}

function save_webp(GdImage $src, string $dest, int $maxSide, int $quality): void
{
    $w = imagesx($src);
    $h = imagesy($src);
    $ratio = min(1, $maxSide / max($w, $h));
    $nw = max(1, (int) round($w * $ratio));
    $nh = max(1, (int) round($h * $ratio));

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagewebp($dst, $dest, $quality);
}

function delete_media(string $base): void
{
    $dir = (string) config('uploads');
    foreach ([$base . '.webp', $base . '.thumb.webp'] as $f) {
        $p = $dir . '/' . $f;
        if ($base !== '' && is_file($p)) {
            @unlink($p);
        }
    }
}

function media_url(?string $file, bool $thumb = true): string
{
    if (!$file) {
        return '';
    }
    return '/media/' . rawurlencode($file . ($thumb ? '.thumb.webp' : '.webp'));
}

/* --------------------------------------------------------------- personas */

function fetch_photos(int $personaId): array
{
    $stmt = db()->prepare('SELECT * FROM photos WHERE persona_id = ? ORDER BY sort ASC, id ASC');
    $stmt->execute([$personaId]);
    return $stmt->fetchAll();
}

function fetch_prompts(int $personaId): array
{
    $stmt = db()->prepare('SELECT * FROM prompts WHERE persona_id = ? ORDER BY id ASC');
    $stmt->execute([$personaId]);
    return $stmt->fetchAll();
}

/* ------------------------------------------------------------------- vues */

require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/backup.php';

function render(string $view, array $data = []): void
{
    $data['flashes'] = take_flashes();
    extract($data, EXTR_SKIP);
    ob_start();
    require PV_ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require PV_ROOT . '/views/layout.php';
}
