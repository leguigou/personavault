<?php
declare(strict_types=1);

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path   = rtrim($path, '/');
if ($path === '') {
    $path = '/';
}
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$parts  = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));
$p0     = $parts[0] ?? '';

/* ---------------------------------------------------------------- assets */
// Servis AVANT le bootstrap : ni session, ni cookie, ni base de données.

if ($p0 === 'assets') {
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    $file = basename($parts[1] ?? '');
    $full = __DIR__ . '/assets/' . $file;
    if ($file === '' || $file[0] === '.' || !is_file($full)) {
        http_response_code(404);
        exit;
    }
    $ext   = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $types = [
        'css'         => 'text/css; charset=utf-8',
        'js'          => 'text/javascript; charset=utf-8',
        'svg'         => 'image/svg+xml',
        'png'         => 'image/png',
        'ico'         => 'image/x-icon',
        'webp'        => 'image/webp',
        'json'        => 'application/json; charset=utf-8',
        'webmanifest' => 'application/manifest+json; charset=utf-8',
    ];
    $type = $types[$ext] ?? 'application/octet-stream';

    $mtime = (int) filemtime($full);
    $etag  = '"' . dechex($mtime) . '-' . dechex((int) filesize($full)) . '"';
    header('Content-Type: ' . $type);
    // URL versionnée (?v=…) : immuable un an ; sinon une heure
    header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600'));
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('ETag: ' . $etag);

    $compressible = in_array($ext, ['css', 'js', 'svg', 'json', 'webmanifest'], true);
    if ($compressible) {
        header('Vary: Accept-Encoding');
    }
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    $body = (string) file_get_contents($full);
    if ($compressible && function_exists('gzencode')
        && str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')), 'gzip')) {
        $gz = gzencode($body, 6);
        if ($gz !== false) {
            $body = $gz;
            header('Content-Encoding: gzip');
        }
    }
    header('Content-Length: ' . strlen($body));
    if ($method !== 'HEAD') {
        echo $body;
    }
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

/* ------------------------------------------------------- envoi trop lourd */
// Au-delà de post_max_size, PHP vide $_POST et $_FILES : sans ce contrôle, l'utilisateur
// verrait « Session expirée » (jeton CSRF absent) au lieu de la vraie cause.

if ($method === 'POST' && post_too_large()) {
    $msg = post_too_large_message();
    $wantsJson = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'json')
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'event-stream');
    http_response_code(413);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (is_logged()) {
        flash('error', $msg);
        header('Location: ' . safe_referer_path('/'), true, 303);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg);
}

/* ---------------------------------------------------------------- routes */
// Chaque fichier teste son chemin et se termine par exit quand il répond.
// L'ordre compte : les routes publiques d'abord, puis require_login().

$routes = dirname(__DIR__) . '/src/routes/';
require $routes . 'media.php';
require $routes . 'auth.php';

require_login();

foreach (['chat', 'conversation', 'backup', 'memories', 'settings', 'library', 'persona', 'gallery', 'export'] as $route) {
    require $routes . $route . '.php';
}

http_response_code(404);
render('notfound', ['title' => 'Introuvable']);
