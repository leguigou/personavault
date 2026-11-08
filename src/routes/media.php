<?php
declare(strict_types=1);

/*
 * Images stockées hors de public/ : /media/{fichier} (authentifié).
 */

/* ------------------------------------------------------------------ média */

if ($p0 === 'media' && $method === 'GET') {
    require_login();
    session_write_close();
    $file = rawurldecode($parts[1] ?? '');
    if (!preg_match('/^p[a-f0-9]{16}(\.thumb)?\.webp$/', $file)) {
        http_response_code(404);
        exit;
    }
    $full = (string) config('uploads') . '/' . $file;
    if (!is_file($full)) {
        http_response_code(404);
        exit;
    }
    header_remove('Pragma');
    header_remove('Expires');
    header('Content-Type: image/webp');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('Content-Length: ' . (string) filesize($full));
    readfile($full);
    exit;
}
