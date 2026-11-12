<?php
declare(strict_types=1);

// Vérifie la tâche planifiée sur la copie temporaire et son faux modèle.
[$script, $appDir] = $argv;
require $appDir . '/src/bootstrap.php';

$pdo = db();
$pdo->exec('UPDATE conversations SET auto_followup = 0');
$pdo->exec("INSERT INTO conversations (persona_id, title, memory_enabled)
            SELECT id, 'Relance de test', 0 FROM personas ORDER BY id LIMIT 1");
$cid = (int) $pdo->lastInsertId();
facts_add((int) chat_conv_effective($cid)['persona_id'], 'Connaît Lyon', 'fait', 'confirme', $cid);
$insert = $pdo->prepare("INSERT INTO messages (conversation_id, role, content, created_at)
                         VALUES (?, ?, ?, datetime('now', ?))");
$expected = [];
for ($i = 1; $i <= 7; $i++) {
    $role = $i % 2 ? 'user' : 'assistant';
    $content = 'Échange sur Lyon ' . $i;
    $insert->execute([$cid, $role, $content, '-8 hours']);
    $insert->execute([$cid, 'memory', 'Notification interne', '+0 hours']);
    $expected[] = ['role' => $role, 'content' => $content];
}
$pdo->prepare("UPDATE conversations SET summary = 'Sujet : ancienne destination' WHERE id = ?")->execute([$cid]);
set_setting('chat_followup_enabled', '1');
set_setting('chat_followup_from', '0');
set_setting('chat_followup_to', '24');
set_setting('chat_followup_idle_hours', '6');
set_setting('chat_followup_min_gap_hours', '12');

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($appDir . '/bin/followup.php');
passthru($command, $status);
if ($status !== 0) {
    exit($status);
}
passthru($command, $status);
if ($status !== 0) {
    exit($status);
}

$query = $pdo->prepare("SELECT request FROM llm_logs WHERE conversation_id = ? AND purpose = 'followup'");
$query->execute([$cid]);
$requests = $query->fetchAll(PDO::FETCH_COLUMN);
$request = json_decode($requests[0] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
$system = $request[0]['content'] ?? '';
$conversation = chat_conv_effective($cid);
if (count($requests) !== 1 || array_slice($request, 1, -1) !== array_slice($expected, -6)
    || !str_contains($system, 'Dernier sujet : « Échange sur Lyon 7 »')
    || str_contains($system, 'Notification interne') || str_contains($system, 'ancienne destination')
    || str_contains($system, 'mémoire longue') || (int) $conversation['unread'] !== 1
    || count(fetch_messages($cid, 0, 0, true)) !== 8) {
    throw new RuntimeException('La relance doit utiliser les six derniers échanges, rester cohérente et être enregistrée une seule fois comme non lue.');
}
echo "OK : relance fondée sur les six derniers échanges et enregistrée une seule fois.\n";
