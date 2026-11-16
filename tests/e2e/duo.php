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
    || !str_contains($second, 'Ce que tu veux dans cet échange : Défendre la ville')
    || str_contains($second, 'Tu es Camille.') || str_contains($second, 'interlocuteur')
    || (int) $pdo->query('SELECT COUNT(*) FROM messages')->fetchColumn() !== $messagesBefore) {
    throw new RuntimeException("L'essai doit alterner les deux voix, chacune avec sa fiche, sans rien écrire dans les conversations.");
}
echo "OK : essai de discussion à deux voix, alterné, sans trace dans les conversations.\n";
