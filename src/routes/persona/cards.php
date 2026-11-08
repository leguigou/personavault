<?php
declare(strict_types=1);

/*
 * Fiche : export (archive PersonaVault, Character Card JSON ou PNG).
 *   GET /p/{slug}/export.zip   GET /p/{slug}/card.json   GET /p/{slug}/card.png
 * L'import est dans src/routes/library.php (POST /import).
 */

if ($method === 'GET' && in_array($action, ['export.zip', 'card.json', 'card.png'], true)) {
    $file = (string) $persona['slug'];
    try {
        if ($action === 'export.zip') {
            $zip = backup_create_persona_zip($persona);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zip['name'] . '"');
            header('Content-Length: ' . filesize($zip['path']));
            header('Cache-Control: no-store');
            readfile($zip['path']);
            @unlink($zip['path']);
            exit;
        }
        if ($action === 'card.json') {
            $body = card_json($persona);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $file . '.card.json"');
        } else {
            $body = card_png($persona);
            header('Content-Type: image/png');
            header('Content-Disposition: attachment; filename="' . $file . '.card.png"');
        }
    } catch (Throwable $e) {
        flash('error', t('cards.flash.export_failed', ['error' => public_error($e)]));
        redirect('/p/' . $file);
    }
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
    exit;
}
