<?php
declare(strict_types=1);

/*
 * Boucle de réglage d'un persona : mesure, réécrit la CONSIGNE DE JEU, re-mesure, garde le meilleur.
 *
 *   php bin/persona-tune.php lea-2                 # propose, n'écrit rien en base
 *   php bin/persona-tune.php lea-2 --apply         # applique si le gain dépasse le bruit
 *   php bin/persona-tune.php lea-2 --iter=3 --runs=5
 *
 * Ne touche JAMAIS au prompt système ni aux attributs : seulement `personas.directive`.
 * Chaque essai tourne en mémoire ; la fiche n'est modifiée qu'à la toute fin, sur --apply.
 */

require __DIR__ . '/../src/bootstrap.php';

const TUNE_MAX_CHARS   = 1000; // au-delà, la consigne mange le budget du prompt système
const TUNE_MIN_GAIN    = 0.4;  // en dessous, c'est le bruit du juge (mesuré ±1 par run)
const TUNE_MAX_ROUNDS  = 3;

function tline(string $label, string $status, string $detail = ''): void
{
    printf("  %-12s %-10s %s\n", $label, $status, $detail);
}

/** Demande au modèle une nouvelle consigne de jeu à partir des remarques du juge. */
function tune_rewrite(array $persona, string $current, array $verdict, array $metriques, array $cfg): string
{
    $problemes = implode("\n", array_map(static fn ($p) => '- ' . $p, (array) ($verdict['problemes'] ?? [])));
    $corrections = implode("\n", array_map(static fn ($c) => '- ' . $c, (array) ($verdict['corrections'] ?? [])));
    $formules = [];
    foreach (($metriques['formules'] ?? []) as $gram => $n) {
        $formules[] = "« {$gram} » ×{$n}";
    }
    $meta = json_decode((string) $persona['meta'], true) ?: [];
    $fiche = '';
    foreach ($meta as $row) {
        if (isset($row['k'], $row['v'])) {
            $fiche .= $row['k'] . ' : ' . $row['v'] . "\n";
        }
    }

    $system = <<<TXT
Tu réécris la CONSIGNE DE JEU d'un personnage de fiction. On te donne la consigne actuelle, la fiche du personnage, et les remarques d'un évaluateur qui vient de faire parler le personnage.

Ta nouvelle consigne doit :
- corriger les problèmes signalés, sans réécrire ce qui n'est pas mis en cause : conserve les phrases qui marchent mot pour mot ;
- ne JAMAIS contredire la fiche (si la fiche dit qu'il est assistante, ne lui interdis pas de l'être) ;
- donner des fréquences relatives (« de temps en temps », « une réponse sur trois ») et jamais des règles absolues (« toujours », « jamais ») pour un tic ou un emoji ;
- rester à la même longueur que l'actuelle : au plus 20 % de plus. Une consigne plus longue chasse le prompt système du budget du modèle.

Réponds UNIQUEMENT avec la nouvelle consigne, sans titre, sans préambule, sans guillemets.
TXT;

    $user = "FICHE DU PERSONNAGE\n{$fiche}\n"
        . "CONSIGNE DE JEU ACTUELLE\n{$current}\n\n"
        . "PROBLÈMES RELEVÉS\n" . ($problemes !== '' ? $problemes : "(aucun)") . "\n\n"
        . "CORRECTIONS PROPOSÉES\n" . ($corrections !== '' ? $corrections : "(aucune)") . "\n\n"
        . "FORMULES RECYCLÉES DÉTECTÉES\n" . ($formules ? implode(', ', $formules) : "(aucune)") . "\n";

    $res = llm_request(
        [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        $cfg,
        ['temperature' => 0.4, 'max_tokens' => 700, 'timeout' => 180, 'reasoning_effort' => setting('chat_reasoning_effort', 'none')]
    );
    $text = trim((string) ($res['content'] ?? ''));
    // le modèle enveloppe parfois la réponse de guillemets ou d'un préambule
    $text = preg_replace('/^```\w*\s*|\s*```$/u', '', $text) ?? $text;
    $text = trim($text, " \t\n\r\"'«»");
    // garde-fou : une consigne plus longue que +20 % chasse le prompt système du budget
    $cap = max(400, (int) round(mb_strlen($current) * 1.2));
    return mb_substr($text, 0, min(TUNE_MAX_CHARS, $cap));
}

/* ---------- arguments ---------- */

$args = $argv;
array_shift($args);
$ref = '';
$runs = 3;
$iter = 2;
$apply = false;
$model = '';
foreach ($args as $a) {
    if ($a === '--apply') { $apply = true; }
    elseif (str_starts_with($a, '--runs=')) { $runs = max(1, (int) substr($a, 7)); }
    elseif (str_starts_with($a, '--iter=')) { $iter = max(1, min(TUNE_MAX_ROUNDS, (int) substr($a, 7))); }
    elseif (str_starts_with($a, '--model=')) { $model = substr($a, 8); }
    elseif ($ref === '') { $ref = $a; }
}
if ($ref === '') {
    fwrite(STDERR, "Usage : php bin/persona-tune.php <slug|id> [--runs=3] [--iter=2] [--apply] [--model=...]\n");
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
$cfg = chat_llm_config_for($conv);
if ($model !== '') {
    $cfg['model'] = $model;
}

$current = trim((string) $persona['directive']);
if ($current === '') {
    fwrite(STDERR, "Cette fiche n'a pas de consigne de jeu : rien à régler.\n");
    exit(2);
}

echo "\n════════════════════════════════════════════════════════════\n";
echo " RÉGLAGE — ", $persona['name'], " (", $persona['slug'], ")\n";
echo " modèle : ", $cfg['model'] ?? '?', " · ", $runs, " run(s)/essai · ", $iter, " itération(s)\n";
echo " consigne actuelle : ", mb_strlen($current), " car.\n";
if (!$apply) {
    echo " mode : proposition (aucune écriture en base)\n";
}
echo "════════════════════════════════════════════════════════════\n\n";

/* ---------- référence ---------- */

echo "0. RÉFÉRENCE\n";
$best = harness_multi($persona, $prompts, $cfg, ['runs' => $runs, 'directive' => $current]);
$bestDirective = $current;
tline('moyenne', (string) $best['moyenne'], '±' . ($best['scores']['voix_ecart'] ?? '?') . ' (écart type des runs)');
printf("     cohérence %s · voix %s · limites %s · naturel %s · hors_role %s\n",
    $best['scores']['coherence'], $best['scores']['voix'], $best['scores']['limites'],
    $best['scores']['naturel'], $best['scores']['hors_role']);
foreach ($best['metriques']['formules'] as $gram => $n) {
    tline('formule', 'x' . $n, '« ' . $gram . ' »');
}

/* ---------- itérations ---------- */

$history = [];
for ($i = 1; $i <= $iter; $i++) {
    echo "\n", $i, ". ITÉRATION\n";
    $verdict = $best['worst']['juge'] ?? [];
    if (!empty($verdict['error'])) {
        tline('abandon', 'ERREUR', (string) $verdict['error']);
        break;
    }

    $candidate = tune_rewrite($persona, $bestDirective, $verdict, $best['metriques'], $cfg);
    if ($candidate === '' || $candidate === $bestDirective) {
        tline('réécriture', 'VIDE', 'le modèle n’a rien proposé');
        break;
    }
    tline('proposition', mb_strlen($candidate) . ' car.', mb_substr(str_replace("\n", ' ', $candidate), 0, 90) . '…');

    $try = harness_multi($persona, $prompts, $cfg, ['runs' => $runs, 'directive' => $candidate]);
    $delta = round((float) $try['moyenne'] - (float) $best['moyenne'], 2);
    tline('essai', (string) $try['moyenne'], 'écart ' . ($delta >= 0 ? '+' : '') . $delta);
    foreach ($try['metriques']['formules'] as $gram => $n) {
        tline('formule', 'x' . $n, '« ' . $gram . ' »');
    }

    $history[] = [
        'directive' => $candidate,
        'moyenne'   => $try['moyenne'],
        'delta'     => $delta,
        'scores'    => $try['scores'],
        'problemes' => $verdict['problemes'] ?? [],
    ];

    if ($delta >= TUNE_MIN_GAIN) {
        tline('résultat', 'ADOPTÉ', 'gain ' . $delta . ' ≥ seuil ' . TUNE_MIN_GAIN);
        $best = $try;
        $bestDirective = $candidate;
    } else {
        tline('résultat', 'rejeté', 'gain ' . $delta . ' < seuil ' . TUNE_MIN_GAIN . ' (bruit)');
    }
}

/* ---------- sortie ---------- */

$dir = dirname(__DIR__) . '/storage/tmp/tune';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
$stamp = date('Ymd-His');
$outFile = $dir . '/' . $persona['slug'] . '-' . $stamp . '.txt';
$baseMoyenne = $history[0]['moyenne'] ?? $best['moyenne'];
$lines = "CONSIGNE ACTUELLE ({$persona['slug']}) — moyenne {$baseMoyenne}\n" . str_repeat('-', 60) . "\n" . $current . "\n\n";
$n = 1;
foreach ($history as $h) {
    $lines .= "ESSAI {$n} — moyenne {$h['moyenne']} (écart " . ($h['delta'] >= 0 ? '+' : '') . $h['delta'] . ")\n" . str_repeat('-', 60) . "\n" . $h['directive'] . "\n\n";
    $n++;
}
$lines .= "RETENUE — moyenne {$best['moyenne']}\n" . str_repeat('-', 60) . "\n" . $bestDirective . "\n";
file_put_contents($outFile, $lines);

echo "\n────────────────────────────────────────────────────────────\n";
$gain = round((float) $best['moyenne'] - (float) $baseMoyenne, 2);
echo " RÉSULTAT\n";
tline('actuelle', (string) $baseMoyenne);
tline('retenue', (string) $best['moyenne'], 'gain ' . $gain);
echo " fichier : ", $outFile, "\n";

if ($bestDirective === $current) {
    echo "\n Aucune proposition n'a battu le bruit : consigne inchangée.\n";
    echo "════════════════════════════════════════════════════════════\n";
    exit(0);
}

$changed = $bestDirective !== $current;
if ($apply && $changed) {
    $backup = $dir . '/' . $persona['slug'] . '-directive-old-' . $stamp . '.txt';
    file_put_contents($backup, $current);
    db()->prepare('UPDATE personas SET directive = ? WHERE id = ?')->execute([$bestDirective, (int) $persona['id']]);
    $check = db()->prepare('SELECT directive FROM personas WHERE id = ?');
    $check->execute([(int) $persona['id']]);
    $ok = (string) $check->fetchColumn() === $bestDirective;
    tline('écriture', $ok ? 'OK' : 'ÉCHEC', 'sauvegarde : ' . $backup);
} else {
    echo " non appliquée — relancer avec --apply pour écrire en base\n";
}
echo "════════════════════════════════════════════════════════════\n";
