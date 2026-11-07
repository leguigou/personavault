<?php
/**
 * Affiche exactement ce que la compression enverrait au modèle pour une conversation.
 * Usage : php bin/compress-preview.php <id_conversation> [nb_messages]
 */
require __DIR__ . '/../src/bootstrap.php';

$cid   = (int) ($argv[1] ?? 0);
$limit = max(1, (int) ($argv[2] ?? 6));

$stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
$stmt->execute([$cid]);
$conv = $stmt->fetch();
if (!$conv) {
    fwrite(STDERR, "Conversation {$cid} introuvable.\n");
    exit(1);
}

$all = fetch_messages($cid);
$roles = [];
foreach ($all as $m) {
    $roles[(string) $m['role']] = ($roles[(string) $m['role']] ?? 0) + 1;
}

// on simule un lot de messages non encore résumés
$pending = array_values(array_filter($all, static fn (array $m): bool => (int) $m['id'] > (int) ($conv['last_compressed_id'] ?? 0)));
if (!$pending) {
    $pending = array_slice($all, 0, $limit);
}
$pending = array_slice($pending, 0, $limit);

$payload = chat_compress_payload($pending, $conv);

echo "conversation #{$cid} — « " . $conv['title'] . " »\n";
echo "rôles en base : " . json_encode($roles, JSON_UNESCAPED_UNICODE) . "\n";
echo "messages envoyés à la compression : {$payload['messages']}"
   . " (ignorés : {$payload['skipped']})\n";
echo str_repeat('-', 72) . "\n";
echo "SYSTEM (consigne fixe du moteur de mémoire) :\n" . $payload['system'] . "\n";
echo str_repeat('-', 72) . "\n";
echo "USER (ce qui est réellement résumé) :\n" . $payload['user'] . "\n";
