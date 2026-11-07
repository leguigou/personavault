<?php
/**
 * Ménage de la base et des fichiers.
 *
 * Usage :
 *   php bin/cleanup.php            (diagnostic, ne supprime rien)
 *   php bin/cleanup.php --apply    (supprime réellement)
 *
 * Retire : prompts / photos / souvenirs rattachés à un persona supprimé,
 * messages orphelins, fichiers média non référencés, temporaires de sauvegarde.
 */
require __DIR__ . '/../src/bootstrap.php';

$apply = in_array('--apply', $argv ?? [], true);
$pdo   = db();
$pdo->exec('PRAGMA foreign_keys = ON');

$report = [];
$total  = 0;

$checks = [
    'prompts sans persona'   => 'SELECT id FROM prompts WHERE persona_id NOT IN (SELECT id FROM personas)',
    'photos sans persona'    => 'SELECT id FROM photos WHERE persona_id NOT IN (SELECT id FROM personas)',
    'souvenirs sans persona' => 'SELECT id FROM user_memories WHERE persona_id IS NOT NULL AND persona_id NOT IN (SELECT id FROM personas)',
    'messages sans conversation' => 'SELECT id FROM messages WHERE conversation_id NOT IN (SELECT id FROM conversations)',
    'conversations sans persona' => 'SELECT id FROM conversations WHERE persona_id NOT IN (SELECT id FROM personas)',
];

foreach ($checks as $label => $sql) {
    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $report[$label] = count($ids);
    $total += count($ids);
    if ($ids && $apply) {
        $table = match (true) {
            str_starts_with($label, 'prompts')        => 'prompts',
            str_starts_with($label, 'photos')         => 'photos',
            str_starts_with($label, 'souvenirs')      => 'user_memories',
            str_starts_with($label, 'messages')       => 'messages',
            str_starts_with($label, 'conversations')  => 'conversations',
        };
        $in = implode(',', array_map('intval', $ids));
        $pdo->exec("DELETE FROM {$table} WHERE id IN ({$in})");
    }
}

// fichiers média non référencés
$dir = (string) config('uploads');
$ref = [];
foreach ($pdo->query('SELECT file FROM photos') as $r) {
    if (($r['file'] ?? '') !== '') {
        $ref[] = $r['file'] . '.webp';
        $ref[] = $r['file'] . '.thumb.webp';
    }
}
foreach ($pdo->query("SELECT avatar FROM personas WHERE avatar <> ''") as $r) {
    $ref[] = $r['avatar'] . '.webp';
    $ref[] = $r['avatar'] . '.thumb.webp';
}
$files  = is_dir($dir) ? scandir($dir) : [];
$orphan = [];
foreach ($files as $f) {
    if ($f === '.' || $f === '..') {
        continue;
    }
    if (!in_array($f, $ref, true) && preg_match('/^p[a-f0-9]{16}(\.thumb)?\.webp$/', $f)) {
        $orphan[] = $f;
    }
}
$report['fichiers média orphelins'] = count($orphan);
$total += count($orphan);
if ($apply) {
    foreach ($orphan as $f) {
        @unlink($dir . '/' . $f);
    }
}

// temporaires de sauvegarde
$tmp = dirname($dir) . '/tmp';
$tmpFiles = 0;
if (is_dir($tmp)) {
    foreach (scandir($tmp) ?: [] as $f) {
        if (str_ends_with($f, '.zip')) {
            $tmpFiles++;
            if ($apply) {
                @unlink($tmp . '/' . $f);
            }
        }
    }
}
$report['archives temporaires'] = $tmpFiles;
$total += $tmpFiles;

foreach ($report as $label => $n) {
    echo str_pad($label, 34, '.') . ' ' . $n . "\n";
}

if ($apply) {
    $pdo->exec('VACUUM');
    echo "\n" . ($total > 0 ? "Nettoyé : {$total} élément(s)." : 'Rien à nettoyer.') . "\n";
} else {
    echo "\n" . ($total > 0 ? "{$total} élément(s) à supprimer — relance avec --apply." : 'Base et fichiers propres.') . "\n";
}
