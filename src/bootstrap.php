<?php
declare(strict_types=1);

define('PV_ROOT', dirname(__DIR__));
define('PV_SCHEMA_VERSION', 7);

$GLOBALS['pv_config'] = require PV_ROOT . '/config.php';
// surcharges de test (bin/selftest.php) : base et dossiers temporaires
if (defined('PV_TEST_CONFIG') && is_array(PV_TEST_CONFIG)) {
    $GLOBALS['pv_config'] = array_replace_recursive($GLOBALS['pv_config'], PV_TEST_CONFIG);
}

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['pv_config'][$key] ?? $default;
}

function is_cli(): bool
{
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

/** Requête arrivée en HTTPS (directement, ou via un reverse proxy de confiance). */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (config('trust_proxy', false)) {
        $proto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https';
    }
    return false;
}

/** Adresse IP du client (dernier saut ajouté par le proxy si trust_proxy est activé). */
function client_ip(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
    if (config('trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $hops = array_values(array_filter(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']))));
        $last = $hops ? end($hops) : '';
        if ($last !== '' && filter_var($last, FILTER_VALIDATE_IP)) {
            $ip = $last;
        }
    }
    return $ip;
}

/* ------------------------------------------------------------ en-têtes */

if (!is_cli()) {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');

    // exception non rattrapée : message générique au client, détail dans le journal
    set_exception_handler(static function (Throwable $e): void {
        error_log('[PersonaVault] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        try {
            echo t('error.internal_logged');
        } catch (Throwable $ignored) {
            echo 'Erreur interne. Le détail a été écrit dans le journal du serveur.';
        }
    });
}

/* ---------------------------------------------------------------- session */

if (!is_cli() && !defined('PV_NO_SESSION') && session_status() !== PHP_SESSION_ACTIVE) {
    $lifetime = max(3600, (int) config('session_lifetime', 30 * 24 * 3600));

    // sessions stockées dans le projet : /tmp serait purgé par le système
    $sessionDir = PV_ROOT . '/storage/sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0700, true);
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
        'secure'   => request_is_https(),
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
            'secure'   => request_is_https(),
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
        mkdir($dir, 0700, true);
    }
    $isNew = !is_file($path);

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    if ($isNew) {
        @chmod($path, 0600);
    }
    // explicite (PDO met 60 s par défaut) : plusieurs workers écrivent en parallèle
    $pdo->exec('PRAGMA busy_timeout = 15000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    migrate($pdo);

    return $pdo;
}

/**
 * Exécute $fn dans une transaction BEGIN IMMEDIATE (verrou d'écriture pris d'emblée :
 * pas d'erreur « database is locked » au moment de passer de la lecture à l'écriture).
 */
function db_tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    try {
        $out = $fn($pdo);
        $pdo->exec('COMMIT');
        return $out;
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $ignored) {
            // transaction déjà annulée par SQLite
        }
        throw $e;
    }
}

/**
 * Migrations versionnées (PRAGMA user_version). Chaque étape reste idempotente :
 * une base existante (version 0, déjà migrée « à l'ancienne ») passe sans erreur.
 */
function migrate(PDO $pdo): void
{
    if ((int) $pdo->query('PRAGMA user_version')->fetchColumn() >= PV_SCHEMA_VERSION) {
        return;
    }

    $pdo->exec('BEGIN IMMEDIATE');
    try {
        // relu sous verrou : un autre worker a pu migrer entre-temps
        $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            migrate_v1($pdo);
        }
        if ($version < 2) {
            migrate_v2($pdo);
        }
        if ($version < 3) {
            migrate_v3($pdo);
        }
        if ($version < 4) {
            migrate_v4($pdo);
        }
        if ($version < 5) {
            migrate_v5($pdo);
        }
        if ($version < 6) {
            migrate_v6($pdo);
        }
        if ($version < 7) {
            migrate_v7($pdo);
        }
        if ($version < PV_SCHEMA_VERSION) {
            $pdo->exec('PRAGMA user_version = ' . PV_SCHEMA_VERSION);
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable $ignored) {
        }
        throw $e;
    }
}

/** Colonnes d'une table. */
function table_columns(PDO $pdo, string $table): array
{
    return array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
}

/** Ajoute une colonne si elle n'existe pas encore. */
function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!in_array($column, table_columns($pdo, $table), true)) {
        $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}

/** v1 : schéma historique complet (tables, FTS, colonnes ajoutées au fil du temps). */
function migrate_v1(PDO $pdo): void
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

    // curseur d'extraction des souvenirs, souvenirs partagés, titre auto, relances
    add_column_if_missing($pdo, 'conversations', 'facts_cursor', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'conversations', 'facts_enabled', 'INTEGER NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'conversations', 'title_auto', 'INTEGER NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'conversations', 'auto_followup', 'INTEGER NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'conversations', 'last_followup_at', "TEXT NOT NULL DEFAULT ''");
    add_column_if_missing($pdo, 'conversations', 'unread', 'INTEGER NOT NULL DEFAULT 0');

    // réglages IA portés par le persona (et non plus par la conversation)
    add_column_if_missing($pdo, 'personas', 'model', "TEXT NOT NULL DEFAULT ''");
    add_column_if_missing($pdo, 'personas', 'temperature', 'REAL NOT NULL DEFAULT 0.85');
    add_column_if_missing($pdo, 'personas', 'max_tokens', 'INTEGER NOT NULL DEFAULT 400');
    add_column_if_missing($pdo, 'personas', 'context_window', 'INTEGER NOT NULL DEFAULT 8192');
    add_column_if_missing($pdo, 'personas', 'directive', "TEXT NOT NULL DEFAULT ''");

    // coupe d'une réponse en bulles, image jointe (vision)
    add_column_if_missing($pdo, 'messages', 'split_at', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'messages', 'split_points', "TEXT NOT NULL DEFAULT ''");
    add_column_if_missing($pdo, 'messages', 'image', "TEXT NOT NULL DEFAULT ''");
}

/** v2 : tentatives de connexion en base, index de tri / jointure des conversations. */
function migrate_v2(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_login_ip_at ON login_attempts(ip, at);
CREATE INDEX IF NOT EXISTS idx_login_at ON login_attempts(at);
CREATE INDEX IF NOT EXISTS idx_conv_persona ON conversations(persona_id);
CREATE INDEX IF NOT EXISTS idx_conv_updated ON conversations(updated_at);
SQL);
}

/**
 * v3 : index plein texte des messages (recherche et rappel entre conversations).
 * Contenu externe (la table messages) tenu à jour par triggers, comme pour les souvenirs ;
 * les messages déjà présents sont indexés par « rebuild ».
 */
function migrate_v3(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
    content,
    content='messages',
    content_rowid='id',
    tokenize="unicode61 remove_diacritics 2"
);

CREATE TRIGGER IF NOT EXISTS msg_ai AFTER INSERT ON messages BEGIN
    INSERT INTO messages_fts(rowid, content) VALUES (new.id, new.content);
END;

CREATE TRIGGER IF NOT EXISTS msg_ad AFTER DELETE ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, content) VALUES ('delete', old.id, old.content);
END;

CREATE TRIGGER IF NOT EXISTS msg_au AFTER UPDATE OF content ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, content) VALUES ('delete', old.id, old.content);
    INSERT INTO messages_fts(rowid, content) VALUES (new.id, new.content);
END;

INSERT INTO messages_fts(messages_fts) VALUES ('rebuild');
SQL);
}

/**
 * v4 : versions d'une réponse régénérée (‹ 1/3 ›) et conversations épinglées.
 * reply_versions garde chaque réponse obtenue pour un même message de l'utilisateur ;
 * messages.version_id désigne la version affichée (0 = réponse jamais régénérée).
 */
function migrate_v4(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS reply_versions (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    conversation_id  INTEGER NOT NULL REFERENCES conversations(id) ON DELETE CASCADE,
    user_message_id  INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    content          TEXT NOT NULL,
    model            TEXT NOT NULL DEFAULT '',
    split_points     TEXT NOT NULL DEFAULT '',
    created_at       TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_reply_versions_user ON reply_versions(user_message_id, id);
SQL);
    add_column_if_missing($pdo, 'messages', 'version_id', 'INTEGER NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'conversations', 'pinned', 'INTEGER NOT NULL DEFAULT 0');
}

/** v5 : modèle choisi pour une seule conversation (vide = celui du persona). */
function migrate_v5(PDO $pdo): void
{
    add_column_if_missing($pdo, 'conversations', 'model_override', "TEXT NOT NULL DEFAULT ''");
}

/** v7 : historique des modifications de prompts (10 dernières par prompt). */
function migrate_v7(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS prompt_versions (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        prompt_id  INTEGER NOT NULL REFERENCES prompts(id) ON DELETE CASCADE,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        old_body   TEXT NOT NULL DEFAULT '',
        new_body   TEXT NOT NULL DEFAULT '',
        added      INTEGER NOT NULL DEFAULT 0,
        removed    INTEGER NOT NULL DEFAULT 0
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_prompt_versions_prompt ON prompt_versions(prompt_id, id)');
}

/** v6 : journal des échanges avec les modèles (20 derniers appels). */
function migrate_v6(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS llm_logs (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at        TEXT NOT NULL DEFAULT (datetime('now')),
        purpose           TEXT NOT NULL DEFAULT 'other',
        conversation_id   INTEGER,
        endpoint          TEXT NOT NULL DEFAULT '',
        model             TEXT NOT NULL DEFAULT '',
        status            TEXT NOT NULL DEFAULT 'ok',
        http_code         INTEGER NOT NULL DEFAULT 0,
        ms                INTEGER NOT NULL DEFAULT 0,
        prompt_tokens     INTEGER NOT NULL DEFAULT 0,
        completion_tokens INTEGER NOT NULL DEFAULT 0,
        params            TEXT NOT NULL DEFAULT '{}',
        request           TEXT NOT NULL DEFAULT '[]',
        response          TEXT NOT NULL DEFAULT '',
        error             TEXT NOT NULL DEFAULT ''
    )");
}

/* --------------------------------------------------------------- réglages */

/** Préférences d'affichage des conversations (taille, bulles, densité…). */
/**
 * URL d'un fichier de public/assets/ avec une version tirée de son contenu (?v=…).
 * Les fichiers versionnés sont mis en cache un an par le navigateur : la version doit donc
 * changer à chaque modification, sinon le navigateur garde l'ancien fichier. Calculée ici
 * automatiquement (empreinte du fichier) plutôt qu'à la main.
 */
function asset_url(string $file): string
{
    static $cache = [];
    if (!isset($cache[$file])) {
        $path = PV_ROOT . '/public/assets/' . basename($file);
        $hash = is_file($path) ? substr((string) md5_file($path), 0, 10) : '0';
        $cache[$file] = '/assets/' . rawurlencode(basename($file)) . '?v=' . $hash;
    }
    return $cache[$file];
}

function ui_style(): array
{
    return [
        'font'    => (float) setting('ui_font_size', '14.5'),
        'bubble'  => (string) setting('ui_bubble_style', 'bulle'),
        'density' => (string) setting('ui_density', 'confort'),
        'avatars' => (int) setting('ui_avatars', '1') === 1,
        'width'   => (string) setting('ui_width', 'normal'),
        // style de la barre de navigation du bas (mobile) : float | pill | classic
        // (capsule flottante par défaut, y compris pour l'ancienne valeur « default »)
        'tabbar'  => in_array(setting('ui_tabbar', 'float'), ['float', 'pill', 'classic'], true)
            ? setting('ui_tabbar', 'float') : 'float',
        // thème : couleur principale (palettes dans app.css, html[data-accent]) et mode
        'accent'  => in_array(setting('ui_accent', 'violet'), UI_ACCENTS, true)
            ? setting('ui_accent', 'violet') : 'violet',
        'mode'    => in_array(setting('ui_mode', 'dark'), UI_MODES, true)
            ? setting('ui_mode', 'dark') : 'dark',
    ];
}

/** Couleurs principales proposées (la première est celle par défaut). */
const UI_ACCENTS = ['violet', 'ocean', 'emerald', 'coral', 'rose', 'amber'];
/** Modes : sombre (par défaut), clair, automatique (suit le réglage du téléphone). */
const UI_MODES = ['dark', 'light', 'auto'];
/** Couleur de la barre du navigateur (<meta name="theme-color">) selon le mode affiché. */
const UI_THEME_COLORS = ['dark' => '#08080f', 'light' => '#f4f3f8'];

function setting(string $key, string $default = ''): string
{
    if (!isset($GLOBALS['pv_settings_cache'])) {
        $cache = [];
        foreach (db()->query('SELECT key, value FROM settings') as $row) {
            $cache[$row['key']] = (string) $row['value'];
        }
        $GLOBALS['pv_settings_cache'] = $cache;
    }
    return array_key_exists($key, $GLOBALS['pv_settings_cache']) ? $GLOBALS['pv_settings_cache'][$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                           ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
    // le cache de la requête reste cohérent avec la base
    if (isset($GLOBALS['pv_settings_cache'])) {
        $GLOBALS['pv_settings_cache'][$key] = $value;
    }
}

/* ------------------------------------------------------------------ utils */

/**
 * « 0 photo », « 1 photo », « 2 photos » : en français, le pluriel commence à 2.
 * Réservé aux scripts bin/ (en français) : l'interface utilise les pluriels de t().
 */
function plural(int $n, string $one, string $many): string
{
    return $n . ' ' . ($n >= 2 ? $many : $one);
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

/**
 * Chemin local (même hôte) tiré du Referer, sinon $fallback.
 * Évite toute redirection ouverte vers un autre site.
 */
function safe_referer_path(string $fallback = '/'): string
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref === '') {
        return $fallback;
    }
    $parts = parse_url($ref);
    if (!is_array($parts)) {
        return $fallback;
    }
    $host = (string) ($parts['host'] ?? '');
    if (isset($parts['port'])) {
        $host .= ':' . $parts['port'];
    }
    if ($host === '' || strcasecmp($host, (string) ($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
        return $fallback;
    }
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
        return $fallback;
    }
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function back(string $fallback = '/'): never
{
    redirect(safe_referer_path($fallback));
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

/**
 * Message d'erreur montrable au client. Les erreurs « métier » (RuntimeException levées
 * par l'app, erreurs LLM) passent telles quelles ; les erreurs techniques (SQL, PHP)
 * sont remplacées par un message générique. Tout est journalisé côté serveur.
 */
function public_error(Throwable $e, ?string $generic = null): string
{
    error_log('[PersonaVault] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if ($e instanceof PDOException || !($e instanceof RuntimeException)) {
        return $generic ?? t('error.internal');
    }
    return $e->getMessage();
}

/** Taille d'une directive php.ini (« 8M », « 2G »…) en octets. */
function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return 0;
    }
    $num  = (int) $value;
    $unit = strtolower(substr($value, -1));
    return match ($unit) {
        'g'     => $num * 1024 ** 3,
        'm'     => $num * 1024 ** 2,
        'k'     => $num * 1024,
        default => $num,
    };
}

function human_bytes(int $bytes): string
{
    if ($bytes >= 1024 ** 2) {
        $dec = t('labels.number.decimal');
        return t('labels.bytes.mb', ['n' => rtrim(rtrim(number_format($bytes / 1024 ** 2, 1, $dec, ''), '0'), $dec)]);
    }
    if ($bytes >= 1024) {
        return t('labels.bytes.kb', ['n' => (int) round($bytes / 1024)]);
    }
    return t('labels.bytes.b', ['n' => $bytes]);
}

/** La requête dépasse-t-elle post_max_size ? (PHP vide alors $_POST et $_FILES) */
function post_too_large(): bool
{
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $max = ini_bytes((string) ini_get('post_max_size'));
    return $max > 0 && $len > $max;
}

function post_too_large_message(): string
{
    return t('error.upload.post_too_large', ['size' => human_bytes(ini_bytes((string) ini_get('post_max_size')))]);
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => t('error.upload.ini_size', ['size' => human_bytes(ini_bytes((string) ini_get('upload_max_filesize')))]),
        UPLOAD_ERR_FORM_SIZE  => t('error.upload.form_size'),
        UPLOAD_ERR_PARTIAL    => t('error.upload.partial'),
        UPLOAD_ERR_NO_FILE    => t('error.upload.no_file'),
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => t('error.upload.cant_write'),
        UPLOAD_ERR_EXTENSION  => t('error.upload.extension'),
        default               => t('error.upload.unknown', ['code' => $code]),
    };
}

/** Un fichier a-t-il été soumis dans ce champ (même en erreur) ? */
function upload_present(?array $file): bool
{
    return is_array($file) && isset($file['error']) && !is_array($file['error'])
        && (int) $file['error'] !== UPLOAD_ERR_NO_FILE;
}

/* ------------------------------------------------------------------- csrf */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Jeton valide ? Un jeton vide n'est jamais accepté (même si la session n'en a pas). */
function csrf_valid(): bool
{
    $expected = (string) ($_SESSION['csrf'] ?? '');
    $sent     = $_POST['_token'] ?? '';
    return $expected !== '' && is_string($sent) && $sent !== '' && hash_equals($expected, $sent);
}

function csrf_check(): void
{
    if (!csrf_valid()) {
        http_response_code(419);
        exit(t('error.csrf.expired_page'));
    }
}

/** Variante AJAX : réponse JSON 419. */
function csrf_check_json(array $extra = []): void
{
    if (!csrf_valid()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(419);
        echo json_encode(['ok' => false, 'error' => t('error.csrf.expired')] + $extra, JSON_UNESCAPED_UNICODE);
        exit;
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
    if (in_array($base, ['new', 'assist'], true)) {
        $base .= '-persona'; // adresses réservées (/p/new, /p/assist)
    }
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
        'image'    => t('labels.prompt_kind.image'),
        'negative' => t('labels.prompt_kind.negative'),
        'video'    => t('labels.prompt_kind.video'),
        'system'   => t('labels.prompt_kind.system'),
        'other'    => t('labels.prompt_kind.other'),
    ];
}

/** Date courte dans la langue de l'interface (« 8 nov. 2026 ») ; nom historique conservé. */
function fr_date(?string $sqlDate): string
{
    if (!$sqlDate) {
        return '';
    }
    $ts = strtotime($sqlDate . ' UTC');
    if ($ts === false) {
        return '';
    }
    $months = [
        t('date.month_short.1'), t('date.month_short.2'), t('date.month_short.3'), t('date.month_short.4'),
        t('date.month_short.5'), t('date.month_short.6'), t('date.month_short.7'), t('date.month_short.8'),
        t('date.month_short.9'), t('date.month_short.10'), t('date.month_short.11'), t('date.month_short.12'),
    ];
    return t('date.short', [
        'day'   => date('j', $ts),
        'month' => $months[(int) date('n', $ts) - 1],
        'year'  => date('Y', $ts),
    ]);
}

/** Horodatage SQL (UTC) → date dans le fuseau configuré, ou null. */
function local_dt(?string $sqlDate): ?DateTimeImmutable
{
    if (!$sqlDate) {
        return null;
    }
    try {
        return (new DateTimeImmutable($sqlDate, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) config('timezone', 'Europe/Paris')));
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Date courte façon messagerie, pour une liste : « 5 min », « 14:32 », « Hier », « mar. »,
 * puis « 8 nov. 2026 » au-delà d'une semaine.
 */
function rel_time(?string $sqlDate): string
{
    $d = local_dt($sqlDate);
    if (!$d) {
        return '';
    }
    $now  = new DateTimeImmutable('now', $d->getTimezone());
    $secs = $now->getTimestamp() - $d->getTimestamp();
    if ($secs < 60) {
        return t('date.just_now');
    }
    if ($secs < 3600) {
        return t('date.minutes_short', ['n' => (int) floor($secs / 60)]);
    }
    $days = (int) $now->setTime(0, 0)->diff($d->setTime(0, 0))->days;
    if ($days === 0) {
        return $d->format(t('date.time_format'));
    }
    if ($days === 1) {
        return t('date.yesterday');
    }
    if ($days < 7) {
        $wd = [
            t('date.weekday_short.0'), t('date.weekday_short.1'), t('date.weekday_short.2'), t('date.weekday_short.3'),
            t('date.weekday_short.4'), t('date.weekday_short.5'), t('date.weekday_short.6'),
        ];
        return $wd[(int) $d->format('w')];
    }
    return fr_date($sqlDate);
}

/** Durée en minutes lisible : « 30 min », « 1 h », « 2 h 30 ». */
function idle_duration_label(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h === 0) {
        return t('date.duration_min', ['m' => $m]);
    }
    return $m === 0 ? t('date.duration_h', ['h' => $h]) : t('date.duration_hm', ['h' => $h, 'm' => sprintf('%02d', $m)]);
}

/** Date relative en toutes lettres : « à l'instant », « il y a 5 min », « hier à 14:32 »… */
function rel_time_long(?string $sqlDate): string
{
    $d = local_dt($sqlDate);
    if (!$d) {
        return '';
    }
    $now  = new DateTimeImmutable('now', $d->getTimezone());
    $secs = $now->getTimestamp() - $d->getTimestamp();
    if ($secs < 60) {
        return t('date.just_now_long');
    }
    if ($secs < 3600) {
        return t('date.minutes_ago', ['n' => (int) floor($secs / 60)]);
    }
    $days = (int) $now->setTime(0, 0)->diff($d->setTime(0, 0))->days;
    $day  = $days === 0 ? t('date.today') : ($days === 1 ? t('date.yesterday_long') : fr_date($sqlDate));
    return t('date.at_time', ['day' => $day, 'time' => $d->format(t('date.time_format'))]);
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

/**
 * Secondes de blocage restantes. Les échecs sont comptés EN BASE (et non en session :
 * supprimer le cookie ne remet plus le compteur à zéro), par IP et globalement.
 */
function login_blocked_for(): int
{
    $window = max(60, (int) config('login_window', 900));
    $now    = time();
    $since  = $now - $window;

    $stmt = db()->prepare('SELECT COUNT(*), MAX(at) FROM login_attempts WHERE ip = ? AND at > ?');
    $stmt->execute([client_ip(), $since]);
    [$n, $last] = $stmt->fetch(PDO::FETCH_NUM);
    $wait = 0;
    if ((int) $n >= (int) config('login_max_try', 5)) {
        $wait = $window - ($now - (int) $last);
    }

    $stmt = db()->prepare('SELECT COUNT(*), MAX(at) FROM login_attempts WHERE at > ?');
    $stmt->execute([$since]);
    [$g, $glast] = $stmt->fetch(PDO::FETCH_NUM);
    if ((int) $g >= (int) config('login_global_max', 30)) {
        $wait = max($wait, $window - ($now - (int) $glast));
    }

    return max(0, $wait);
}

function attempt_login(string $password): bool
{
    if (login_blocked_for() > 0) {
        return false;
    }

    $hash = (string) config('password_hash');
    if ($hash !== '' && password_verify($password, $hash)) {
        db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
        session_regenerate_id(true);
        $_SESSION['auth_at'] = time();
        return true;
    }

    $now = time();
    db()->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([client_ip(), $now]);
    // ménage : on ne garde que la fenêtre utile
    db()->prepare('DELETE FROM login_attempts WHERE at < ?')
        ->execute([$now - 4 * max(60, (int) config('login_window', 900))]);
    // ralentit le brute-force même sous le seuil
    usleep(400000);
    return false;
}

/* ---------------------------------------------------------------- uploads */

/** Vérifie type et dimensions AVANT tout décodage GD (bombes de décompression). */
function image_check_info(array|false $info): int
{
    if (!$info) {
        throw new RuntimeException(t('error.image.invalid'));
    }
    $type = (int) $info[2];
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!in_array($type, $allowed, true)) {
        throw new RuntimeException(t('error.image.format'));
    }
    $w = (int) $info[0];
    $h = (int) $info[1];
    $maxPixels = (int) config('max_pixels', 40_000_000);
    if ($w <= 0 || $h <= 0 || $w * $h > $maxPixels) {
        throw new RuntimeException(t('error.image.too_big', ['w' => $w, 'h' => $h, 'max' => (int) round($maxPixels / 1e6)]));
    }
    return $type;
}

/** Décode une image en mémoire (import ZIP) après contrôle du type et des dimensions. */
function decode_image_string(string $data): GdImage
{
    image_check_info(@getimagesizefromstring($data));
    $img = @imagecreatefromstring($data);
    if (!$img) {
        throw new RuntimeException(t('error.image.unreadable'));
    }
    return $img;
}

/** Enregistre une image GD en WebP (grand format + miniature) sous un nom aléatoire. */
function store_gd_image(GdImage $img, string $prefix = 'p'): string
{
    $base = $prefix . bin2hex(random_bytes(8));
    $dir  = (string) config('uploads');
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    save_webp($img, $dir . '/' . $base . '.webp', 1800, 88, (int) config('media_max_bytes', 500 * 1024));
    save_webp($img, $dir . '/' . $base . '.thumb.webp', 700, 84);
    return $base;
}

function store_upload(array $file, string $prefix = 'p'): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        throw new RuntimeException(upload_error_message($err === UPLOAD_ERR_OK ? UPLOAD_ERR_NO_FILE : $err));
    }
    $max = (int) config('max_upload');
    if (($file['size'] ?? 0) > $max) {
        throw new RuntimeException(t('error.image.too_heavy', ['max' => human_bytes($max)]));
    }

    $type = image_check_info(@getimagesize($file['tmp_name']));

    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_WEBP => @imagecreatefromwebp($file['tmp_name']),
        IMAGETYPE_GIF  => @imagecreatefromgif($file['tmp_name']),
    };
    if (!$img) {
        throw new RuntimeException(t('error.image.unreadable'));
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

    return store_gd_image($img, $prefix);
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

/**
 * Enregistre $src en WebP, côté le plus long ≤ $maxSide. Avec $maxBytes > 0, la qualité
 * baisse par paliers (puis l'image rétrécit) jusqu'à tenir dans ce poids : une photo de
 * 8 Mo finit autour de 500 Ko sans perte visible à l'écran.
 */
function save_webp(GdImage $src, string $dest, int $maxSide, int $quality, int $maxBytes = 0): void
{
    $w = imagesx($src);
    $h = imagesy($src);
    $side = $maxSide;
    $data = '';
    for ($pass = 0; $pass < 4; $pass++) {
        $ratio = min(1, $side / max($w, $h));
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        foreach ($maxBytes > 0 ? array_unique([$quality, 80, 72, 64]) : [$quality] as $q) {
            ob_start();
            imagewebp($dst, null, $q);
            $data = (string) ob_get_clean();
            if ($maxBytes <= 0 || strlen($data) <= $maxBytes) {
                break 2;
            }
        }
        $side = (int) round(min($side, max($w, $h)) * 0.8); // encore trop lourd : plus petit
    }
    if (file_put_contents($dest, $data) === false) {
        throw new RuntimeException('écriture impossible : ' . basename($dest));
    }
}

/** Suppression brute des fichiers d'une image (sans vérifier les références). */
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

/**
 * Copie physique d'une image (grand format + miniature) sous un nouveau nom aléatoire.
 * Renvoie le nouveau nom, ou '' si l'original est introuvable.
 */
function media_copy(string $base): string
{
    $dir = (string) config('uploads');
    // même format que les noms créés par store_gd_image() et servis par /media
    if (!preg_match('/^p[0-9a-f]{16}$/', $base) || !is_file($dir . '/' . $base . '.webp')) {
        return '';
    }
    do {
        $new = 'p' . bin2hex(random_bytes(8));
    } while (is_file($dir . '/' . $new . '.webp'));
    foreach (['.webp', '.thumb.webp'] as $ext) {
        if (is_file($dir . '/' . $base . $ext) && !copy($dir . '/' . $base . $ext, $dir . '/' . $new . $ext)) {
            delete_media($new);
            throw new RuntimeException('copie impossible : ' . $base . $ext);
        }
    }
    return $new;
}

/** Nombre de références en base vers un fichier image (galerie, portrait, chat). */
function media_ref_count(string $base): int
{
    if ($base === '') {
        return 0;
    }
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM photos WHERE file = ?)
              + (SELECT COUNT(*) FROM personas WHERE avatar = ?)
              + (SELECT COUNT(*) FROM messages WHERE image = ?)'
    );
    $stmt->execute([$base, $base, $base]);
    return (int) $stmt->fetchColumn();
}

/**
 * Supprime les fichiers qui ne sont plus référencés nulle part.
 * À appeler APRÈS la mise à jour / suppression des lignes en base.
 */
function release_media(string ...$bases): void
{
    foreach (array_unique(array_filter($bases, static fn (string $b): bool => $b !== '')) as $base) {
        if (media_ref_count($base) === 0) {
            delete_media($base);
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

require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/llm.php';
require_once __DIR__ . '/llm_log.php';
require_once __DIR__ . '/assist.php';
require_once __DIR__ . '/prompts.php';
require_once __DIR__ . '/prompt_history.php';
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/facts.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/cards.php';

function render(string $view, array $data = []): void
{
    $data['flashes'] = take_flashes();
    extract($data, EXTR_SKIP);
    ob_start();
    require PV_ROOT . '/views/' . $view . '.php';
    $content = ob_get_clean();
    require PV_ROOT . '/views/layout.php';
}
