<?php
/**
 * Empreinte psychologique d'un persona (src/psy.php) : personnalité attendue d'après la fiche,
 * observée d'après ses réponses, conclusion. Le modèle distant (Réglages › IA) pose les questions
 * et juge ; le persona répond avec son modèle de discussion. Rien n'est écrit dans les discussions.
 *
 *   php bin/persona-psy.php lea-2                       grille « personnalité », affiche le résultat
 *   php bin/persona-psy.php lea-2 --grid=caractere      grilles : personnalite, caractere, relation, ton
 *   php bin/persona-psy.php lea-2 --custom="Jaloux,Romantique : cherche l'idéal,Sarcastique"
 *   php bin/persona-psy.php lea-2 --job    tâche lancée par la page (progression + résultat gardé)
 */
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$ref = '';
$job = false;
$gridKey = 'personnalite';
$custom = '';
foreach (array_slice($argv, 1) as $a) {
    if ($a === '--job') {
        $job = true;
    } elseif (str_starts_with($a, '--grid=')) {
        $gridKey = substr($a, 7);
    } elseif (str_starts_with($a, '--custom=')) {
        $gridKey = 'custom';
        $custom = str_replace(',', "\n", substr($a, 9));
    } elseif ($ref === '') {
        $ref = $a;
    }
}
if ($ref === '') {
    fwrite(STDERR, "Usage : php bin/persona-psy.php <slug|id> [--job]\n");
    exit(2);
}

try {
    $persona = harness_persona($ref);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
$slug = (string) $persona['slug'];
$started = time();

try {
    // lancé par la page : la grille choisie est dans le fichier de la tâche
    $spec = $job ? (psy_job_get($slug) ?? []) : [];
    $grid = psy_grid((string) ($spec['grid'] ?? $gridKey), (array) ($spec['custom'] ?? psy_parse_custom($custom)));
    $result = psy_run($persona, harness_prompts((int) $persona['id']), $grid, static function (string $step, int $done, int $total) use ($job, $slug, $started): void {
        if ($job) {
            psy_job_put($slug, ['state' => 'running', 'step' => $step, 'done' => $done, 'total' => $total, 'started' => $started, 'beat' => time(),
                'grid' => $grid['key'], 'grid_name' => $grid['name']]);
        } else {
            fwrite(STDERR, "  {$done}/{$total} {$step}\n");
        }
    });
    $runId = psy_save_run((int) $persona['id'], $result);
    if ($job) {
        psy_job_put($slug, ['state' => 'done', 'run_id' => $runId, 'started' => $started, 'finished' => time()]);
        exit(0);
    }
} catch (Throwable $e) {
    if ($job) {
        psy_job_put($slug, ['state' => 'error', 'error' => public_error($e), 'started' => $started, 'finished' => time()]);
        exit(1);
    }
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}

$labels = $result['labels'];
echo "\nEMPREINTE PSYCHOLOGIQUE ({$result['grid_name']}) — {$persona['name']} · fidélité {$result['fidelity']}/100\n";
echo "local : {$result['local_model']} · juge : {$result['remote_model']}\n\n";
foreach (array_keys($labels) as $k) {
    printf("  %-14s attendu %3d   observé %3d   %+4d\n", $labels[$k], $result['expected'][$k]['score'], $result['observed'][$k]['score'], $result['gaps'][$k]);
}
echo "\n", $result['conclusion'], "\n";
foreach ($result['corrections'] as $c) {
    echo "  → {$c}\n";
}
