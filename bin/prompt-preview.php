<?php
/**
 * Affiche le prompt système exact qui serait envoyé pour un message donné.
 * Usage : php bin/prompt-preview.php <id_conversation> "message de l'utilisateur"
 */
require __DIR__ . '/../src/bootstrap.php';

$cid = (int) ($argv[1] ?? 0);
$msg = (string) ($argv[2] ?? 'Bonjour');

$stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
$stmt->execute([$cid]);
$conv = $stmt->fetch();
if (!$conv) {
    fwrite(STDERR, "Conversation {$cid} introuvable.\n");
    exit(1);
}

$stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
$stmt->execute([$conv['persona_id']]);
$persona = $stmt->fetch();
if (!$persona) {
    fwrite(STDERR, "Persona introuvable.\n");
    exit(1);
}

$stmt = db()->prepare('SELECT * FROM prompts WHERE persona_id = ?');
$stmt->execute([$persona['id']]);
$prompts = $stmt->fetchAll();

$conv  = chat_with_persona($conv, $persona);
$built = chat_build_messages($persona, $prompts, $conv, fetch_context_messages($cid, $conv), $msg);

echo 'conversation #' . $cid . ' — persona « ' . $persona['name'] . " »\n";
echo 'messages envoyés : ' . count($built['messages']) . ' (historique retenu : ' . $built['kept'] . ")\n";
echo str_repeat('-', 72) . "\n";
echo $built['messages'][0]['content'] . "\n";
echo str_repeat('-', 72) . "\n";
echo "dernier message (contexte variable + message) :\n";
$last = end($built['messages']);
echo (is_array($last['content']) ? (string) ($last['content'][0]['text'] ?? '') : (string) $last['content']) . "\n";
