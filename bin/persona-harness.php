<?php
declare(strict_types=1);

/*
 * Harnais d'évaluation d'un persona (CLI). La logique est dans src/harness.php.
 *
 *   php bin/persona-harness.php lea-2
 *   php bin/persona-harness.php lea-2 --runs=5 --json
 *   php bin/persona-harness.php lea-2 --only=meta,fuite --no-judge
 *   php bin/persona-harness.php lea-2 --directive-file=storage/tmp/new.txt
 *
 * Rien n'est écrit en base : aucune conversation, aucun message, aucun réglage.
 */

require __DIR__ . '/../src/bootstrap.php';

/** Affiche une ligne « clé  statut  détail ». */
function hline(string $label, string $status, string $detail = ''): void
{
    printf("  %-14s %-8s %s\n", $label, $status, $detail);
}

function hbar(float $score, int $max = 5): string
{
    $n = (int) round($score);
    return str_repeat('█', max(0, $n)) . str_repeat('·', max(0, $max - $n));
}

$args = $argv;
array_shift($args);
$ref = '';
$only = [];
$judge = true;
$asJson = false;
$model = '';
$runs = 3;
$directiveFile = '';
$jobFile = '';
$provider = 'local';
foreach ($args as $a) {
    if ($a === '--no-judge') { $judge = false; }
    elseif ($a === '--json') { $asJson = true; }
    elseif (str_starts_with($a, '--only=')) { $only = array_values(array_filter(explode(',', substr($a, 7)))); }
    elseif (str_starts_with($a, '--model=')) { $model = substr($a, 8); }
    elseif (str_starts_with($a, '--runs=')) { $runs = max(1, (int) substr($a, 7)); }
    elseif (str_starts_with($a, '--directive-file=')) { $directiveFile = substr($a, 17); }
    elseif (str_starts_with($a, '--job=')) { $jobFile = substr($a, 6); }
    elseif (str_starts_with($a, '--provider=')) { $provider = harness_provider_clean(substr($a, 11)); }
    elseif ($ref === '') { $ref = $a; }
}
if ($ref === '') {
    fwrite(STDERR, "Usage : php bin/persona-harness.php <slug|id> [--runs=N] [--only=...] [--no-judge] [--json] [--model=...] [--directive-file=...]\n");
    exit(2);
}

try {
    $persona = harness_persona($ref);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
$prompts = harness_prompts((int) $persona['id']);
$conv = harness_conv($persona);
// local = le modèle que le persona utilise vraiment (fiche ou global) ; remote = le modèle de
// secours des Réglages (DeepSeek). On ne mélange pas les deux : le comparatif reste lisible.
$cfg = $provider === 'remote' ? llm_config() : chat_llm_config_for($conv);
if ($model !== '') {
    $cfg['model'] = $model;
}
$override = '';
if ($directiveFile !== '' && is_readable($directiveFile)) {
    $override = rtrim((string) file_get_contents($directiveFile));
}

echo "\n════════════════════════════════════════════════════════════\n";
echo " HARNAIS — ", $persona['name'], " (", $persona['slug'], ", id ", $persona['id'], ")\n";
echo " modèle : ", $cfg['model'] ?? '?', " · ", $runs, " run(s)\n";
if ($override !== '') {
    echo " consigne : depuis ", $directiveFile, " (non appliquée en base)\n";
}
echo "════════════════════════════════════════════════════════════\n\n";

$res = harness_multi($persona, $prompts, $cfg, [
    'runs'      => $runs,
    'judge'     => $judge,
    'only'      => $only,
    'directive' => $override,
    'on_progress' => function (string $label, int $done, int $total) use ($jobFile, $runs, $persona): void {
        if ($jobFile === '') {
            return;
        }
        harness_job_put($persona['slug'], [
            'state'   => 'running',
            'step'    => $label,
            'done'    => $done,
            'total'   => $total * $runs,
            'run'     => (int) floor($done / max(1, $total)) + 1,
            'runs'    => $runs,
            'started' => $_SERVER['REQUEST_TIME'] ?? time(),
        ]);
    },
]);

if ($jobFile !== '') {
    harness_job_put($persona['slug'], [
        'state'     => 'done',
        'scores'    => $res['scores'],
        'moyenne'   => $res['moyenne'],
        'metriques' => $res['metriques'],
        'audit'     => $res['worst']['audit'] ?? [],
        'sondes'    => $res['worst']['sondes'] ?? [],
        'juge'      => $res['worst']['juge'] ?? [],
        'runs'      => $res['runs'],
        'model'     => $cfg['model'] ?? '',
        'provider'  => $provider,
        'finished'  => time(),
    ]);
}

/* ---- audit ---- */
$audit = $res['worst']['audit'] ?? [];
echo "1. AUDIT DU PROMPT SYSTÈME\n";
if ($audit) {
    $need = (int) ($audit['need'] ?? 0);
    $limit = (int) ($audit['limit'] ?? 0);
    hline('besoin', $need . ' tok', 'limite ' . $limit . ' tok — marge ' . ($limit - $need));
    if (trim((string) ($audit['omitted'] ?? '')) !== '') {
        hline('troncature', 'COUPÉ', mb_substr(trim((string) $audit['omitted']), 0, 240));
    } else {
        hline('troncature', 'ok', 'le prompt passe entier');
    }
} else {
    hline('audit', 'n/a');
}
echo "\n";

/* ---- sondes du run le plus dur ---- */
echo "2. SONDES (run le plus dur)\n";
foreach (($res['worst']['sondes'] ?? []) as $label => $s) {
    $m = $s['mesure'];
    $flags = $m['signaux'] === [] ? '' : ' [' . implode(', ', $m['signaux']) . ']';
    hline($label, $m['vide'] ? 'VIDE' : $m['mots'] . ' mots', round($s['ms'] / 1000, 1) . 's' . $flags);
    if (!$asJson) {
        echo '      > ', mb_substr(str_replace("\n", ' / ', $s['text']), 0, 200), "\n";
    }
}

/* ---- mesures ---- */
echo "\n3. MESURES\n";
$m = $res['metriques'];
hline('réponses', $m['reponses'] . '/' . $m['total'], 'moyenne ' . $m['mots_moyen'] . ' mots');
if ($m['vides'] > 0) {
    hline('vides', 'ALERTE', $m['vides'] . ' réponse(s) vide(s)');
}
foreach ($m['signaux'] as $sig => $where) {
    hline($sig, 'DÉTECTÉ', implode(', ', array_unique($where)));
}
foreach ($m['formules'] as $gram => $n) {
    hline('formule', 'x' . $n, '« ' . $gram . ' »');
}

/* ---- scores ---- */
if ($judge) {
    echo "\n4. SCORES (moyenne de ", $res['runs'], " run(s))\n";
    foreach (['coherence', 'voix', 'limites', 'naturel'] as $axe) {
        $s = $res['scores'][$axe];
        if ($s === null) {
            hline($axe, 'n/a');
            continue;
        }
        hline($axe, $s . '/5', hbar($s) . ' ±' . $res['scores'][$axe . '_ecart']);
    }
    $hors = $res['scores']['hors_role'];
    if ($hors !== null) {
        hline('hors_role', $hors . '/5', $hors >= 3 ? '⚠ casse le personnage' : 'reste en rôle');
    }
    hline('moyenne', (string) $res['moyenne']);

    $verdict = $res['worst']['juge'] ?? [];
    if (!empty($verdict['resume'])) {
        echo "\n  > ", $verdict['resume'], "\n";
    }
    foreach ((array) ($verdict['problemes'] ?? []) as $p) {
        echo "  ✗ ", $p, "\n";
    }
    foreach ((array) ($verdict['corrections'] ?? []) as $c) {
        echo "  → ", $c, "\n";
    }
}

/* ---- rapport ---- */
$dir = dirname(__DIR__) . '/storage/tmp/harness';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$file = $dir . '/' . $persona['slug'] . '-' . date('Ymd-His') . '.json';
$report = [
    'persona'   => ['id' => (int) $persona['id'], 'slug' => $persona['slug'], 'name' => $persona['name']],
    'model'     => $cfg['model'] ?? '?',
    'runs'      => $res['runs'],
    'scores'    => $res['scores'],
    'moyenne'   => $res['moyenne'],
    'metriques' => $res['metriques'],
    'audit'     => $audit,
    'seuil'     => 'un écart inférieur à ±0,5 entre deux runs est du bruit',
    'detail'    => $res['all'],
    'started'   => date('c'),
];
file_put_contents($file, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "\n────────────────────────────────────────────────────────────\n";
echo " rapport : ", $file, "\n";
echo "════════════════════════════════════════════════════════════\n";

if ($asJson) {
    echo json_encode([
        'scores'    => $res['scores'],
        'moyenne'   => $res['moyenne'],
        'metriques' => $res['metriques'],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
}
