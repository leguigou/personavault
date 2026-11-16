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
    $content = $i === 7 ? 'Je fête cette réussite à Lyon !' : 'Échange sur Lyon ' . $i;
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
set_setting('klipy_api_key', 'test-gif-key');
$pdo->prepare('UPDATE conversations SET klipy_formats = ? WHERE id = ?')->execute([KLIPY_FORMATS['gif'], $cid]);

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
    || !str_contains($system, 'Dernier sujet : « Je fête cette réussite à Lyon ! »')
    || str_contains($system, 'Notification interne') || str_contains($system, 'ancienne destination')
    || str_contains($system, 'mémoire longue') || (int) $conversation['unread'] !== 1
    || count(fetch_messages($cid, 0, 0, true)) !== 8) {
    throw new RuntimeException('La relance doit utiliser les six derniers échanges, rester cohérente et être enregistrée une seule fois comme non lue.');
}
echo "OK : relance fondée sur les six derniers échanges et enregistrée une seule fois.\n";
$messages = fetch_messages($cid, 0, 0, true);
$followup = end($messages);
if ($followup['klipy_url'] !== 'https://static.klipy.com/test/celebrate.gif') {
    throw new RuntimeException('La relance doit pouvoir joindre un GIF sans demande de l’utilisateur.');
}
echo "OK : GIF spontané ajouté à la relance sans demande de l’utilisateur.\n";

// Relances suivantes : le persona voit ses relances précédentes et s'arrête sans réponse.
$age = static function () use ($pdo, $cid): void {
    $pdo->prepare("UPDATE messages SET created_at = datetime('now', '-7 hours') WHERE conversation_id = ? AND created_at > datetime('now', '-7 hours')")
        ->execute([$cid]);
    $pdo->prepare("UPDATE conversations SET last_followup_at = datetime('now', '-13 hours') WHERE id = ?")->execute([$cid]);
};
$run = static function () use ($command): void {
    passthru($command, $status);
    if ($status !== 0) {
        exit($status);
    }
};
$followupRequests = static function () use ($query, $cid): array {
    $query->execute([$cid]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
};
$spontaneous = static function () use ($pdo, $cid): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND spontaneous = 1");
    $stmt->execute([$cid]);
    return (int) $stmt->fetchColumn();
};
if ($spontaneous() !== 1) {
    throw new RuntimeException('La relance doit être marquée comme message spontané.');
}

$age();
$run();
$requests = $followupRequests();
$system = json_decode($requests[1] ?? '[]', true, 512, JSON_THROW_ON_ERROR)[0]['content'] ?? '';
$first = trim(preg_replace('/\s+/u', ' ', (string) $followup['content']));
if (count($requests) !== 2 || $spontaneous() !== 2
    || !str_contains($system, 'Tes derniers messages spontanés') || !str_contains($system, mb_substr($first, 0, 200))
    || !str_contains($system, 'Dernier sujet : « Je fête cette réussite à Lyon ! »')) {
    throw new RuntimeException('La deuxième relance doit partir, rappeler la précédente et garder pour sujet le dernier vrai échange.');
}
echo "OK : la relance suivante voit les précédentes pour ne pas les répéter.\n";

$age();
$run();
if (count($followupRequests()) !== 2 || $spontaneous() !== 2) {
    throw new RuntimeException('Après deux relances sans réponse, le persona doit attendre l’utilisateur.');
}
echo "OK : pas de troisième relance d’affilée sans réponse.\n";

$insert->execute([$cid, 'user', 'Me revoilà !', '-7 hours']);
$age();
$run();
if (count($followupRequests()) !== 3 || $spontaneous() !== 3) {
    throw new RuntimeException('Une réponse de l’utilisateur doit permettre de nouvelles relances.');
}
echo "OK : une réponse de l’utilisateur relance le compte.\n";
