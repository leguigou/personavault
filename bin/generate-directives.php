<?php
/**
 * Génère une consigne de jeu pour les personas qui n'en ont pas,
 * à partir de leur prompt système.
 *
 * Usage :
 *   php bin/generate-directives.php --dry          (aperçu, rien n'est écrit)
 *   php bin/generate-directives.php --apply        (écrit en base)
 *   php bin/generate-directives.php --apply --force (réécrit même celles déjà remplies)
 *   php bin/generate-directives.php --apply --only=lisa
 */
require __DIR__ . '/../src/bootstrap.php';

$dry   = in_array('--dry', $argv ?? [], true) && !in_array('--apply', $argv ?? [], true);
$force = in_array('--force', $argv ?? [], true);
$only  = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
}

if (!llm_ready()) {
    fwrite(STDERR, "Aucun LLM configuré (Réglages → Intelligence).\n");
    exit(1);
}

$rows = db()->query('SELECT * FROM personas WHERE archived = 0 ORDER BY id')->fetchAll();
$done = 0;
$skipped = 0;
$errors = 0;

foreach ($rows as $persona) {
    if ($only !== null && $persona['slug'] !== $only) {
        continue;
    }
    if (!$force && trim((string) $persona['directive']) !== '') {
        $skipped++;
        continue;
    }

    $prompts = fetch_prompts((int) $persona['id']);
    $hasSystem = false;
    foreach ($prompts as $p) {
        if ((string) ($p['kind'] ?? '') === 'system' && trim((string) $p['body']) !== '') {
            $hasSystem = true;
            break;
        }
    }
    if (!$hasSystem) {
        echo '- ' . $persona['name'] . " : aucun prompt système, ignoré\n";
        $skipped++;
        continue;
    }

    try {
        $proposal = persona_ai_directive($persona, $prompts);
    } catch (Throwable $e) {
        echo '! ' . $persona['name'] . ' : ' . $e->getMessage() . "\n";
        $errors++;
        usleep(600000);
        continue;
    }

    echo '= ' . $persona['name'] . ' (temp ' . number_format($proposal['temperature'], 2) . ")\n";
    echo '  ' . $proposal['directive'] . "\n";

    if (!$dry) {
        db()->prepare('UPDATE personas SET directive = ?, temperature = ?, updated_at = datetime("now") WHERE id = ?')
            ->execute([$proposal['directive'], $proposal['temperature'], (int) $persona['id']]);
    }

    $done++;
    usleep(600000);
}

echo "\n" . ($dry ? 'Aperçu' : 'Écrit') . ' : ' . $done . ' · ignorés : ' . $skipped . ' · erreurs : ' . $errors . "\n";
