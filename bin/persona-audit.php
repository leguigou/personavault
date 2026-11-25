<?php
declare(strict_types=1);

/*
 * Audit de la bibliothèque : repère les fiches qui ne peuvent pas tenir un rôle, SANS appeler
 * de modèle (donc instantané, gratuit, et sans risque pour la base).
 *
 *   php bin/persona-audit.php             toutes les fiches
 *   php bin/persona-audit.php --risk      seulement celles à corriger
 *   php bin/persona-audit.php --json      sortie machine
 *
 * Les alertes viennent de ce qu'on a mesuré sur le harnais : une fiche courte glisse vers
 * l'assistant, une fenêtre trop petite coupe le prompt système sans que rien ne le signale.
 */

require __DIR__ . '/../src/bootstrap.php';

/** En dessous, la fiche n'a pas assez de matière pour tenir une personnalité. */
const AUDIT_SHORT_PROMPT = 1500;
/** En dessous, la fenêtre coupe le prompt système d'une fiche un peu fournie. */
const AUDIT_SMALL_WINDOW = 16384;

$args = $argv;
array_shift($args);
$json = in_array('--json', $args, true);
$riskOnly = in_array('--risk', $args, true);

$rows = db()->query('SELECT * FROM personas ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$report = [];
$flagged = 0;

foreach ($rows as $persona) {
    $pid = (int) $persona['id'];
    $prompts = harness_prompts($pid);
    $conv = harness_conv($persona);

    $system = '';
    foreach ($prompts as $p) {
        if (($p['kind'] ?? '') === 'system') {
            $system .= (string) $p['body'];
        }
    }

    $audit = [];
    chat_build_messages($persona, $prompts, $conv, [], 'Bonjour', null, false, false, '', null, null, [], $audit);
    $need  = (int) ($audit['need'] ?? 0);
    $limit = (int) ($audit['limit'] ?? 0);
    $cut   = trim((string) ($audit['omitted'] ?? '')) !== '';

    $meta = json_decode((string) $persona['meta'], true);
    $metaCount = is_array($meta) ? count($meta) : 0;
    $directive = trim((string) $persona['directive']);
    $window = (int) ($persona['context_window'] ?? 8192);
    $alerts = [];

    if ($cut) {
        $alerts[] = 'prompt coupé';
    }
    if (mb_strlen($system) < AUDIT_SHORT_PROMPT && $metaCount < 5) {
        // un prompt court est compensé par des attributs fournis : ne pas crier au loup
        // (Eva, Amy et Elena sont à 3,75 avec un prompt de 1 300-1 400 car. et 7 à 9 attributs)
        $alerts[] = 'prompt court';
    }
    if ($directive === '') {
        $alerts[] = 'aucune consigne de jeu';
    }
    if ($window < AUDIT_SMALL_WINDOW) {
        $alerts[] = 'fenêtre ' . $window;
    }
    if ($metaCount === 0) {
        $alerts[] = 'aucun attribut';
    }
    if ($prompts === []) {
        $alerts[] = 'aucun prompt';
    }

    if ($alerts !== []) {
        $flagged++;
    }
    $report[] = [
        'id'        => $pid,
        'slug'      => (string) $persona['slug'],
        'name'      => (string) $persona['name'],
        'prompt'    => mb_strlen($system),
        'need'      => $need,
        'limit'     => $limit,
        'marge'     => $limit - $need,
        'meta'      => $metaCount,
        'directive' => mb_strlen($directive),
        'window'    => $window,
        'prompts'   => count($prompts),
        'alerts'    => $alerts,
    ];
}

// les fiches les plus en difficulté d'abord
usort($report, static function (array $a, array $b): int {
    $c = count($b['alerts']) <=> count($a['alerts']);
    return $c !== 0 ? $c : $a['prompt'] <=> $b['prompt'];
});

if ($json) {
    echo json_encode(['total' => count($report), 'flagged' => $flagged, 'personas' => $report],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit($flagged > 0 ? 1 : 0);
}

echo "\n════════════════════════════════════════════════════════════════════════\n";
echo ' AUDIT DE LA BIBLIOTHÈQUE — ', count($report), " fiche(s)\n";
echo "════════════════════════════════════════════════════════════════════════\n\n";
printf("%-4s %-22s %6s %7s %5s %5s %6s %s\n", 'id', 'fiche', 'prompt', 'marge', 'attr', 'dir', 'fen.', 'alertes');
echo str_repeat('-', 88), "\n";

foreach ($report as $r) {
    if ($riskOnly && $r['alerts'] === []) {
        continue;
    }
    printf("%-4d %-22s %6d %7s %5d %5d %6d %s\n",
        $r['id'],
        mb_substr($r['name'], 0, 21),
        $r['prompt'],
        ($r['marge'] >= 0 ? '+' : '') . $r['marge'],
        $r['meta'],
        $r['directive'],
        $r['window'],
        $r['alerts'] === [] ? '—' : implode(', ', $r['alerts'])
    );
}

echo "\n", str_repeat('─', 72), "\n";
echo $flagged, ' fiche(s) à corriger sur ', count($report), ".\n";
if ($flagged > 0) {
    echo "Le plus rapide : ouvrir la fiche et lancer « Tester la voix » ; pour une fiche\n";
    echo "« prompt court » ou « sans consigne », l'assistant (Compléter) fait le gros du travail.\n";
    echo "Détail chiffré : php bin/persona-audit.php --risk\n";
}
echo "════════════════════════════════════════════════════════════════════════\n";

exit($flagged > 0 ? 1 : 0);
