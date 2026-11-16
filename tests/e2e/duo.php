<?php
declare(strict_types=1);

// Vérifie l'essai de discussion entre deux personas sur la copie temporaire et son faux modèle.
[$script, $appDir] = $argv;
require $appDir . '/src/bootstrap.php';

$pdo = db();
$messagesBefore = (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$logsBefore = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM llm_logs")->fetchColumn();
$out = $appDir . '/storage/duo-test.md';
$command = implode(' ', array_map('escapeshellarg', [
    PHP_BINARY, $appDir . '/bin/duo-test.php', 'camille-lefebvre', 'hugo-bernard',
    '--sujet=Partir vivre à la campagne', '--tours=5', '--objectif-b=Défendre la ville', '--sortie=' . $out,
]));
exec($command . ' 2>&1', $output, $status);
if ($status !== 0) {
    throw new RuntimeException("L'essai de discussion a échoué :\n" . implode("\n", $output));
}

$report = (string) file_get_contents($out);
$requests = $pdo->prepare('SELECT request FROM llm_logs WHERE id > ? ORDER BY id');
$requests->execute([$logsBefore]);
$requests = array_map(static fn ($r) => json_decode((string) $r, true, 512, JSON_THROW_ON_ERROR), $requests->fetchAll(PDO::FETCH_COLUMN));

$alternates = true;
foreach ($requests as $request) {
    $roles = array_column(array_slice($request, 1), 'role');
    foreach ($roles as $i => $role) {
        $alternates = $alternates && $role === ($i % 2 === 0 ? 'user' : 'assistant');
    }
    $alternates = $alternates && end($roles) === 'user';
}
$second = $requests[1][0]['content'] ?? '';
if (count($requests) !== 5 || !$alternates
    || substr_count($report, '**Camille Lefebvre** : ') !== 3 || substr_count($report, '**Hugo Bernard** : ') !== 2
    || !str_contains($requests[0][0]['content'] ?? '', 'Tu es Camille.')
    || !str_contains($requests[0][0]['content'] ?? '', 'tu discutes par messagerie avec Hugo Bernard')
    || !str_contains($second, 'Ton objectif dans cet échange') || !str_contains($second, 'Défendre la ville')
    || str_contains($second, 'Tu es Camille.') || str_contains($second, 'interlocuteur')
    || (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() !== $messagesBefore) {
    throw new RuntimeException("L'essai doit alterner les deux voix, chacune avec sa fiche, sans rien écrire dans les conversations.");
}
echo "OK : essai de discussion à deux voix, alterné, sans trace dans les conversations.\n";

// Sauvegarde : les discussions entre personas, humeurs et ressentis font l'aller-retour.
$session = $pdo->query("SELECT * FROM duo_sessions WHERE status = 'done' ORDER BY id LIMIT 1")->fetch();
$bondBefore = $session ? $pdo->query('SELECT friendship FROM persona_bonds WHERE friendship <> 0 AND persona_id = ' . (int) $session['persona_a'])->fetch() : false;
if (!$session || !$bondBefore) {
    throw new RuntimeException('Le parcours doit laisser une discussion terminée et des ressentis à sauvegarder.');
}
$pdo->exec("UPDATE duo_sessions SET status = 'running' WHERE id = " . (int) $session['id']);
$maxSession = (int) $pdo->query('SELECT MAX(id) FROM duo_sessions')->fetchColumn();
$zip = backup_create_zip(true);
$restored = backup_import_zip($zip['path']);
@unlink($zip['path']);
$copy = $pdo->prepare("SELECT s.*, (SELECT COUNT(*) FROM duo_lines l WHERE l.session_id = s.id AND l.kind = 'line') AS n
                         FROM duo_sessions s WHERE s.id > ? AND s.topic = ? ORDER BY s.id LIMIT 1");
$copy->execute([$maxSession, $session['topic']]);
$copy = $copy->fetch();
$bonds = $copy ? $pdo->query('SELECT friendship FROM persona_bonds WHERE persona_id = ' . (int) $copy['persona_a'])->fetchAll(PDO::FETCH_COLUMN) : [];
$moods = $copy ? (int) $pdo->query('SELECT COUNT(*) FROM persona_moods WHERE persona_id IN (' . (int) $copy['persona_a'] . ', '
    . (int) $copy['persona_b'] . ')')->fetchColumn() : 0;
if ($restored['errors'] || !$copy || (int) $copy['n'] !== 5 || $copy['status'] !== 'paused' || !in_array((int) $bondBefore['friendship'], array_map('intval', $bonds), true) || $moods < 2) {
    throw new RuntimeException('La sauvegarde doit restaurer discussions (en pause), humeurs et ressentis : ' . json_encode($restored['errors'], JSON_UNESCAPED_UNICODE));
}
echo "OK : discussions entre personas, humeurs et ressentis restaurés depuis une sauvegarde.\n";

// Ressentis : l'amour peut devenir négatif (rejet), borné à -100, et se dit en mots.
$ids = $pdo->query("SELECT id FROM personas WHERE slug IN ('ines-morel', 'hugo-bernard') ORDER BY slug")->fetchAll(PDO::FETCH_COLUMN);
$pdo->prepare('DELETE FROM persona_bonds WHERE persona_id = ? AND other_id = ?')->execute([$ids[0], $ids[1]]);
duo_bond_apply((int) $ids[0], (int) $ids[1], ['amour' => -25], 'Il la trouve froide');
$bond = duo_bond_apply((int) $ids[0], (int) $ids[1], ['amour' => -8, 'amitie' => 4], '');
$text = duo_feelings_text(duo_mood((int) $ids[0]), $bond, 'Inès');
if ($bond['love'] !== -18 || $bond['friendship'] !== 4 || !str_contains($text, 'pas du tout attiré') || duo_gauge_colors()['love']['neg'] === '') {
    throw new RuntimeException('L’amour doit pouvoir devenir négatif (rejet), par pas bornés, et être transmis en mots.');
}
echo "OK : ressentis de -100 à 100 (rejet compris), transmis en mots.\n";
