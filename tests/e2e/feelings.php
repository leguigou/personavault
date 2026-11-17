<?php
declare(strict_types=1);

// Humeur et ressentis du persona dans une conversation (src/feelings.php), avec le faux modèle.
[$script, $appDir] = $argv;
require $appDir . '/src/bootstrap.php';

$pdo = db();
$pid = (int) $pdo->query("SELECT id FROM personas WHERE slug = 'hugo-bernard'")->fetchColumn();
$pdo->prepare("INSERT INTO conversations (persona_id, title) VALUES (?, 'Ressentis de test')")->execute([$pid]);
$cid = (int) $pdo->lastInsertId();
$insert = $pdo->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)');
for ($i = 1; $i <= 8; $i++) {
    $insert->execute([$cid, $i % 2 ? 'user' : 'assistant', $i % 2 ? 'Tu es très charmant ce soir.' : 'Merci, ça me touche.']);
}
$persona = $pdo->query("SELECT * FROM personas WHERE id = $pid")->fetch();

if (!feel_due($cid) || feel_context($cid, $persona) !== '') {
    throw new RuntimeException('Quatre réponses sans analyse : une analyse est due, et rien n’est encore transmis.');
}
feel_analyze($cid);
$f = feel_get($cid, $persona);
$notices = $pdo->query("SELECT content FROM messages WHERE conversation_id = $cid AND role = 'notice'")->fetchAll(PDO::FETCH_COLUMN);
if ($f['emotion'] !== 'gene' || $f['mood'] !== 'troublée' || $f['friendship'] !== 6 || $f['love'] !== 10 || feel_due($cid)
    || !in_array('Hugo Bernard est maintenant troublée', $notices, true)) {
    throw new RuntimeException('L’analyse doit mettre à jour humeur et ressentis par pas bornés et le noter dans le fil : ' . json_encode($f));
}
feel_set($cid, $persona, ['anger' => '65', 'emotion' => 'colere', 'intensity' => '80']);
$context = feel_context($cid, $persona);
if (!str_contains($context, 'colère (forte)') || !str_contains($context, 'phrases courtes et sèches') || !str_contains($context, 'lui en veux')) {
    throw new RuntimeException('Le réglage doit arriver dans le contexte avec un comportement concret : ' . $context);
}
// une autre conversation avec le même persona n'est pas touchée
$pdo->prepare("INSERT INTO conversations (persona_id, title) VALUES (?, 'Autre')")->execute([$pid]);
$other = (int) $pdo->lastInsertId();
if (feel_get($other, $persona)['intensity'] !== 0 || feel_context($other, $persona) !== '') {
    throw new RuntimeException('Chaque conversation garde sa propre humeur.');
}
// fiche indiquant moins de 18 ans : pas de sentiment amoureux envers l'utilisateur
$minor = $persona;
$minor['meta'] = json_encode([['k' => 'Âge', 'v' => '16 ans']]);
feel_set($cid, $minor, ['love' => '70']);
if (!feel_persona_minor($minor) || feel_persona_minor($persona) || feel_get($cid, $minor)['love'] !== 0
    || !str_contains(feel_context($cid, $minor), 'Aucune séduction ni romance')) {
    throw new RuntimeException('Une fiche de moins de 18 ans ne doit pas recevoir de sentiment amoureux.');
}
echo "OK : humeur et ressentis par conversation (analyse, réglage, contexte, garde-fou d’âge).\n";
