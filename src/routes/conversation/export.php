<?php
declare(strict_types=1);

/*
 * Export d'une conversation : texte brut, Markdown (téléchargement) et page imprimable
 * (« Enregistrer en PDF » depuis la boîte d'impression du navigateur).
 * Inclus par src/routes/conversation.php ($cid, $action, $conv, $persona).
 * Toute la conversation est exportée, lue ligne à ligne (pas de limite de 50 messages).
 */

require_once __DIR__ . '/export_format.php';

/** Messages de la conversation, du plus ancien au plus récent, lus au fil de l'eau. */
$exportRows = static function (int $cid): Generator {
    $stmt = db()->prepare('SELECT id, role, content, image, split_points, created_at
                             FROM messages WHERE conversation_id = ? ORDER BY id ASC');
    $stmt->execute([$cid]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        yield $row;
    }
};

if (($action === 'export.txt' || $action === 'export.md') && $method === 'GET') {
    session_write_close();
    $format = $action === 'export.md' ? 'md' : 'txt';
    $count  = (int) db()->query("SELECT COUNT(*) FROM messages WHERE conversation_id = " . (int) $cid
        . " AND role IN ('user', 'assistant')")->fetchColumn();
    $meta = [
        'title'       => (string) $conv['title'],
        'persona'     => (string) $persona['name'],
        'count'       => $count,
        'exported_at' => gmdate('Y-m-d H:i:s'),
    ];
    $day = (new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris'))))->format('Y-m-d');

    header('Content-Type: ' . ($format === 'md' ? 'text/markdown' : 'text/plain') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_filename((string) $conv['title'], $format, $day) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    foreach (export_chunks($exportRows($cid), $meta, $format) as $chunk) {
        echo $chunk;
    }
    exit;
}

if ($action === 'print' && $method === 'GET') {
    session_write_close();
    $count = (int) db()->query("SELECT COUNT(*) FROM messages WHERE conversation_id = " . (int) $cid
        . " AND role IN ('user', 'assistant')")->fetchColumn();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    // page autonome (sans le layout de l'application) : tout le contenu est échappé dans la vue
    (static function (array $conv, array $persona, iterable $rows, int $count, bool $auto): void {
        require PV_ROOT . '/views/chat_print.php';
    })($conv, $persona, $exportRows($cid), $count, ($_GET['auto'] ?? '') === '1');
    exit;
}
