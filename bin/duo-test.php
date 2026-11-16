<?php
/**
 * Essai de discussion entre deux personas, en ligne de commande (même moteur que
 * Réglages › Essai de discussion : src/duo.php). Rien n'est enregistré dans les
 * conversations : le résultat est écrit dans un fichier Markdown (storage/duo-tests/).
 *
 * Usage :
 *   php bin/duo-test.php <slug-a> <slug-b> [options]
 *
 * Options :
 *   --sujet="…"         sujet de départ (défaut : discussion libre)
 *   --tours=12          nombre total de répliques, 2 à 40 (défaut 12)
 *   --objectif-a="…"    ce que veut le premier persona dans l'échange (facultatif)
 *   --objectif-b="…"    ce que veut le second persona (facultatif)
 *   --modele=…          modèle à utiliser (défaut : celui déjà chargé dans LM Studio,
 *                       sinon le modèle de base des Réglages)
 *   --sortie=chemin.md  fichier de résultat
 *
 * Seule trace dans la base : le journal des 20 derniers appels au modèle (Réglages › Journal).
 */
require __DIR__ . '/../src/bootstrap.php';

$args = ['slugs' => [], 'topic' => '', 'turns' => 12, 'goal_a' => '', 'goal_b' => '', 'model' => '', 'out' => ''];
$map = ['sujet' => 'topic', 'objectif-a' => 'goal_a', 'objectif-b' => 'goal_b', 'modele' => 'model', 'sortie' => 'out'];
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/su', $arg, $m)) {
        if ($m[1] === 'tours') {
            $args['turns'] = max(2, min(DUO_MAX_TURNS, (int) $m[2]));
        } elseif (isset($map[$m[1]])) {
            $args[$map[$m[1]]] = trim($m[2]);
        } else {
            fwrite(STDERR, "Option inconnue ignorée : --{$m[1]}\n");
        }
    } elseif (!str_starts_with($arg, '--')) {
        $args['slugs'][] = $arg;
    }
}
if (count($args['slugs']) !== 2 || $args['slugs'][0] === $args['slugs'][1]) {
    fwrite(STDERR, "Usage : php bin/duo-test.php <slug-a> <slug-b> [--sujet=\"…\"] [--tours=12] "
        . "[--objectif-a=\"…\"] [--objectif-b=\"…\"] [--modele=…] [--sortie=fichier.md]\n");
    exit(1);
}

$speakers = [];
foreach ($args['slugs'] as $i => $slug) {
    $speaker = duo_speaker($slug, $i === 0 ? $args['goal_a'] : $args['goal_b']);
    if (!$speaker) {
        fwrite(STDERR, "Persona introuvable : {$slug} (le slug est visible dans l'adresse de sa fiche).\n");
        exit(1);
    }
    $speakers[] = $speaker;
}

$cfg = duo_llm_config($args['model']);
if ((string) $cfg['model'] === '') {
    fwrite(STDERR, "Aucun modèle de discussion configuré (Réglages → Intelligence) : utilisez --modele=…\n");
    exit(1);
}

$names = [(string) $speakers[0]['persona']['name'], (string) $speakers[1]['persona']['name']];
$topic = mb_substr($args['topic'], 0, DUO_TEXT_MAX);
echo "Essai : {$names[0]} et {$names[1]} · {$args['turns']} répliques · modèle {$cfg['model']}\n";
echo 'Sujet : ' . ($topic !== '' ? $topic : 'discussion libre') . "\n\n";

$lines = [];
$end = '';
$error = '';
while (count($lines) < $args['turns']) {
    try {
        $turn = duo_turn($speakers, $lines, $topic, $cfg);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        echo 'Échec de la réplique ' . (count($lines) + 1) . " : {$error}\n";
        break;
    }
    $lines[] = ['speaker' => $turn['speaker'], 'text' => $turn['text'], 'ms' => $turn['ms']];
    echo $names[$turn['speaker']] . ' : ' . $turn['text'] . "\n";
    if ($turn['ended']) {
        $end = t('duo.end.closed', ['name' => $names[$turn['speaker']]]);
        break;
    }
}

$report = duo_report([
    'model' => $cfg['model'], 'topic' => $topic, 'turns' => $args['turns'],
    'goals' => [$speakers[0]['goal'], $speakers[1]['goal']], 'end' => $end, 'error' => $error,
], $names, $lines);
$out = $args['out'] !== '' ? $args['out']
    : PV_ROOT . '/storage/duo-tests/' . date('Ymd-His') . '-' . $args['slugs'][0] . '-' . $args['slugs'][1] . '.md';
is_dir(dirname($out)) || @mkdir(dirname($out), 0775, true);
if (file_put_contents($out, $report) === false) {
    fwrite(STDERR, "Impossible d'écrire {$out}\n");
    exit(1);
}
echo "\nRésultat : {$out}\n";
exit($lines ? 0 : 1);
