<?php
declare(strict_types=1);

/*
 * Sauvegarde : export et import ZIP.
 */

/* -------------------------------------------------------------- sauvegarde */

if ($path === '/backup/export.zip' && $method === 'GET') {
    try {
        $zip = backup_create_zip(((string) ($_GET['conv'] ?? '')) === '1');
    } catch (Throwable $e) {
        flash('error', 'Sauvegarde impossible : ' . public_error($e));
        redirect('/settings#backup');
        exit;
    }
    set_setting('backup_last_at', gmdate('Y-m-d H:i:s'));
    set_setting('backup_last_size', (string) filesize($zip['path']));

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip['name'] . '"');
    header('Content-Length: ' . filesize($zip['path']));
    header('Cache-Control: no-store');
    readfile($zip['path']);
    @unlink($zip['path']);
    exit;
}

if ($path === '/backup/import' && $method === 'POST') {
    csrf_check();
    try {
        $archive = $_FILES['archive'] ?? null;
        if (!upload_present($archive)) {
            throw new RuntimeException('Aucun fichier reçu.');
        }
        if ((int) $archive['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message((int) $archive['error']));
        }
        $res = backup_import_zip((string) $archive['tmp_name']);
        flash('ok', 'Import terminé : ' . plural((int) $res['personas'], 'persona', 'personas') . ', '
            . plural((int) $res['photos'], 'photo', 'photos') . ', ' . plural((int) $res['prompts'], 'prompt', 'prompts') . '.');
        if (!empty($res['errors'])) {
            flash('error', implode(' · ', array_slice($res['errors'], 0, 3)));
        }
    } catch (Throwable $e) {
        flash('error', 'Import impossible : ' . public_error($e));
    }
    redirect('/settings#backup');
}
