<?php
/**
 * Essai de discussion entre deux personas, pour juger la qualité avant de développer
 * les discussions de groupe. Rien n'est enregistré dans les conversations : le
 * résultat est écrit dans un fichier Markdown (storage/duo-tests/ par défaut).
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

const DUO_MIN_TURNS_BEFORE_END = 4;
const DUO_HISTORY_LINES = 16;

$args = duo_args(array_slice($argv ?? [], 1));
if (count($args['slugs']) !== 2 || $args['slugs'][0] === $args['slugs'][1]) {
    fwrite(STDERR, "Usage : php bin/duo-test.php <slug-a> <slug-b> [--sujet=\"…\"] [--tours=12] "
        . "[--objectif-a=\"…\"] [--objectif-b=\"…\"] [--modele=…] [--sortie=fichier.md]\n");
    exit(1);
}

$speakers = [];
foreach ($args['slugs'] as $i => $slug) {
    $stmt = db()->prepare('SELECT * FROM personas WHERE slug = ?');
    $stmt->execute([$slug]);
    $persona = $stmt->fetch();
    if (!$persona) {
        fwrite(STDERR, "Persona introuvable : {$slug} (le slug est visible dans l'adresse de sa fiche).\n");
        exit(1);
    }
    $stmt = db()->prepare('SELECT * FROM prompts WHERE persona_id = ? ORDER BY id');
    $stmt->execute([(int) $persona['id']]);
    $speakers[] = [
        'persona' => $persona,
        'prompts' => $stmt->fetchAll(),
        'goal'    => $i === 0 ? $args['goal_a'] : $args['goal_b'],
    ];
}

// un seul modèle pour les deux voix : sinon LM Studio déchargerait et rechargerait à chaque tour
$cfg = chat_llm_config();
if ($args['model'] !== '') {
    $cfg['model'] = $args['model'];
} else {
    $st = lms_status();
    if (!empty($st['loaded'])) {
        $cfg['model'] = (string) ($st['loaded'][0]['key'] ?: $st['loaded'][0]['id']);
    }
}
if ((string) $cfg['model'] === '') {
    fwrite(STDERR, "Aucun modèle de discussion configuré (Réglages → Intelligence) : utilisez --modele=…\n");
    exit(1);
}

$names = [(string) $speakers[0]['persona']['name'], (string) $speakers[1]['persona']['name']];
$topic = $args['topic'];
echo "Essai : {$names[0]} et {$names[1]} · {$args['turns']} répliques · modèle {$cfg['model']}\n";
echo 'Sujet : ' . ($topic !== '' ? $topic : 'discussion libre') . "\n\n";

$lines = [];     // [speaker => 0|1, text => string, ms => int]
$endReason = '';
$error = '';
for ($turn = 0; $turn < $args['turns']; $turn++) {
    $who = $turn % 2;
    $me = $speakers[$who];
    $other = $speakers[1 - $who];
    $messages = duo_messages($me, $other, $who, $lines, $topic);

    try {
        llm_log_context(['purpose' => 'other']);
        $t0 = microtime(true);
        $res = llm_request($messages, $cfg, [
            'temperature'      => (float) ($me['persona']['temperature'] ?? 0.85),
            'max_tokens'       => max(60, min(220, (int) ($me['persona']['max_tokens'] ?? 400))),
            'timeout'          => 180,
            'reasoning_effort' => setting('chat_reasoning_effort', 'none'),
        ]);
        $ms = (int) round((microtime(true) - $t0) * 1000);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        echo "Échec de la réplique " . ($turn + 1) . " : {$error}\n";
        break;
    }

    [$text, $ended] = duo_clean((string) $res['content'], $names[$who], $names[1 - $who]);
    if ($text === '') {
        $error = 'réponse vide';
        echo "Réplique " . ($turn + 1) . " vide : essai arrêté.\n";
        break;
    }
    $lines[] = ['speaker' => $who, 'text' => $text, 'ms' => $ms];
    echo $names[$who] . ' : ' . $text . "\n";

    if ($ended && count($lines) >= DUO_MIN_TURNS_BEFORE_END) {
        $endReason = $names[$who] . ' a clos la discussion';
        break;
    }
}

$report = duo_report($args, $cfg, $names, $speakers, $lines, $endReason, $error);
$out = $args['out'] !== '' ? $args['out']
    : PV_ROOT . '/storage/duo-tests/' . date('Ymd-His') . '-' . $args['slugs'][0] . '-' . $args['slugs'][1] . '.md';
is_dir(dirname($out)) || @mkdir(dirname($out), 0775, true);
if (file_put_contents($out, $report) === false) {
    fwrite(STDERR, "Impossible d'écrire {$out}\n");
    exit(1);
}
echo "\nRésultat : {$out}\n";
exit($lines ? 0 : 1);


/** Lecture des arguments (slugs, puis options --nom=valeur). */
function duo_args(array $argv): array
{
    $a = ['slugs' => [], 'topic' => '', 'turns' => 12, 'goal_a' => '', 'goal_b' => '', 'model' => '', 'out' => ''];
    $map = ['sujet' => 'topic', 'objectif-a' => 'goal_a', 'objectif-b' => 'goal_b', 'modele' => 'model', 'sortie' => 'out'];
    foreach ($argv as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/su', $arg, $m)) {
            if ($m[1] === 'tours') {
                $a['turns'] = max(2, min(40, (int) $m[2]));
            } elseif (isset($map[$m[1]])) {
                $a[$map[$m[1]]] = trim($m[2]);
            } else {
                fwrite(STDERR, "Option inconnue ignorée : --{$m[1]}\n");
            }
        } elseif (!str_starts_with($arg, '--')) {
            $a['slugs'][] = $arg;
        }
    }
    return $a;
}

/** Consigne propre à l'échange entre personas, vue depuis celui qui parle. */
function duo_rules(array $me, array $other, string $topic): string
{
    $name = (string) $me['persona']['name'];
    $otherName = (string) $other['persona']['name'];
    $otherTag = trim((string) $other['persona']['tagline']);

    $text = "Situation : tu discutes par messagerie avec {$otherName}" . ($otherTag !== '' ? " ({$otherTag})" : '')
        . ". L'utilisateur n'intervient pas : tu ne parles qu'à {$otherName}.";
    if ($topic !== '') {
        $text .= "\nSujet de départ : {$topic}";
    }
    if ($me['goal'] !== '') {
        $text .= "\nCe que tu veux dans cet échange : {$me['goal']}";
    }
    return $text . "\n\nRègles de l'échange :\n"
        . "- Écris uniquement ta propre réplique, en tant que {$name}, à la première personne. "
        . "N'écris jamais les répliques de {$otherName}, ni de narration.\n"
        . "- 1 à 3 phrases, style messagerie, sans markdown ni liste.\n"
        . "- Garde ta personnalité et ta façon de parler, même si {$otherName} parle autrement.\n"
        . "- Tu as le droit d'être en désaccord, de taquiner, de t'agacer ou de changer de sujet : "
        . "ne te contente pas d'approuver.\n"
        . "- Fais avancer l'échange : à chaque message, une idée, une anecdote, un avis ou une question nouvelle.\n"
        . "- Si la discussion est vraiment terminée pour toi, ajoute [FIN] à la toute fin de ton message.\n"
        . "- Ne dis jamais que tu es une IA.";
}

/**
 * Messages envoyés au modèle pour la réplique de $me : ses répliques en « assistant »,
 * celles de l'autre en « user » (alternance stricte, exigée par certains modèles).
 */
function duo_messages(array $me, array $other, int $who, array $lines, string $topic): array
{
    $system = implode("\n\n", chat_persona_identity($me['persona'], $me['prompts'], (string) ($me['persona']['directive'] ?? '')))
        . "\n\n" . duo_rules($me, $other, $topic);

    $own = array_values(array_filter($lines, static fn (array $l): bool => $l['speaker'] === $who));
    if (count($own) >= 2) {
        $recent = array_map(static fn (array $l): string => '- « ' . mb_substr($l['text'], 0, 200) . ' »', array_slice($own, -3));
        $system .= "\n\nTes dernières répliques :\n" . implode("\n", $recent)
            . "\nNe les répète pas et n'en reprends pas les formulations.";
    }

    $messages = [['role' => 'system', 'content' => $system]];
    $recentLines = array_slice($lines, -DUO_HISTORY_LINES);
    // l'historique doit commencer par une réplique de l'autre : signal de départ au premier
    // message, ou de reprise quand le début de l'échange a été coupé
    if (!$recentLines || $recentLines[0]['speaker'] === $who) {
        $messages[] = ['role' => 'user', 'content' => count($lines) > count($recentLines)
            ? '[La discussion avec ' . $other['persona']['name'] . ' continue.]'
            : '[' . $other['persona']['name'] . ' est en ligne. Écris ton message'
                . ($topic !== '' ? ' pour lancer la discussion sur : ' . $topic : ' pour lancer la discussion') . '.]'];
    }
    foreach ($recentLines as $l) {
        $messages[] = ['role' => $l['speaker'] === $who ? 'assistant' : 'user', 'content' => $l['text']];
    }
    return $messages;
}

/**
 * Nettoie une réplique : signal de fin, nom en tête, répliques de l'autre écrites à sa place.
 * @return array{0: string, 1: bool} texte, fin demandée
 */
function duo_clean(string $text, string $name, string $otherName): array
{
    $ended = (bool) preg_match('/\[\s*FIN\s*\]/iu', $text);
    $text = preg_replace('/\[\s*FIN\s*\]/iu', '', $text) ?? $text;
    $text = trim($text);
    // « Marco : … » en tête
    $text = preg_replace('/^\**' . preg_quote($name, '/') . '\**\s*:\s*/u', '', $text) ?? $text;
    // le modèle enchaîne parfois avec la réplique de l'autre : on coupe
    $cut = preg_split('/\n+\s*\**' . preg_quote($otherName, '/') . '\**\s*:/u', $text);
    $text = trim((string) ($cut[0] ?? $text));
    return [trim($text, " \t\n\r\"«»"), $ended];
}

/** Mots porteurs de sens (4 lettres et plus). */
function duo_words(string $text): array
{
    return text_words($text, 4);
}

/** Proportion de mots communs entre deux ensembles (0 à 1). */
function duo_overlap(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    $common = count(array_intersect($a, $b));
    return $common / max(1, count(array_unique(array_merge($a, $b))));
}

/** Indicateurs simples, pour orienter la lecture (pas un jugement de qualité). */
function duo_metrics(array $lines, array $names): array
{
    $agree = '/\b(tu as raison|vous avez raison|je suis d.accord|t.as raison|exactement|totalement|complètement d.accord|bonne idée|carrément)\b/iu';
    $per = [];
    $vocab = [[], []];
    foreach ([0, 1] as $who) {
        $own = array_values(array_filter($lines, static fn (array $l): bool => $l['speaker'] === $who));
        $words = 0;
        $repeats = 0;
        $agreements = 0;
        $seen = [];
        foreach ($own as $l) {
            $w = duo_words($l['text']);
            $words += count(preg_split('/\s+/u', trim($l['text'])) ?: []);
            foreach ($seen as $prev) {
                if (duo_overlap($w, $prev) >= 0.5) {
                    $repeats++;
                    break;
                }
            }
            $seen[] = $w;
            $vocab[$who] = array_merge($vocab[$who], $w);
            $agreements += preg_match($agree, $l['text']) ? 1 : 0;
        }
        $per[$who] = [
            'name'       => $names[$who],
            'lines'      => count($own),
            'avg_words'  => $own ? round($words / count($own), 1) : 0,
            'repeats'    => $repeats,
            'agreements' => $agreements,
            'avg_ms'     => $own ? (int) round(array_sum(array_column($own, 'ms')) / count($own)) : 0,
        ];
    }
    return ['per' => $per, 'voice_overlap' => round(duo_overlap(array_unique($vocab[0]), array_unique($vocab[1])) * 100)];
}

/** Rapport Markdown : réglages, échange, indicateurs et grille de lecture. */
function duo_report(array $args, array $cfg, array $names, array $speakers, array $lines, string $endReason, string $error): string
{
    $md = "# Essai de discussion : {$names[0]} et {$names[1]}\n\n"
        . '- Date : ' . date('Y-m-d H:i') . "\n"
        . '- Modèle : ' . $cfg['model'] . "\n"
        . '- Sujet : ' . ($args['topic'] !== '' ? $args['topic'] : 'discussion libre') . "\n"
        . '- Répliques demandées : ' . $args['turns'] . ', obtenues : ' . count($lines) . "\n";
    foreach ([0, 1] as $who) {
        if ($speakers[$who]['goal'] !== '') {
            $md .= "- Objectif de {$names[$who]} : {$speakers[$who]['goal']}\n";
        }
    }
    if ($endReason !== '') {
        $md .= "- Fin : {$endReason}\n";
    }
    if ($error !== '') {
        $md .= "- Arrêt sur erreur : {$error}\n";
    }

    $md .= "\n## Échange\n\n";
    foreach ($lines as $l) {
        $md .= '**' . $names[$l['speaker']] . '** : ' . $l['text'] . "\n\n";
    }

    $m = duo_metrics($lines, $names);
    $md .= "## Indicateurs (à prendre comme des pistes)\n\n"
        . "| | Répliques | Mots en moyenne | Redites probables | Approbations | Temps moyen |\n|---|---|---|---|---|---|\n";
    foreach ($m['per'] as $p) {
        $md .= "| {$p['name']} | {$p['lines']} | {$p['avg_words']} | {$p['repeats']} | {$p['agreements']} | "
            . round($p['avg_ms'] / 1000, 1) . " s |\n";
    }
    $md .= "\nVocabulaire commun aux deux voix : {$m['voice_overlap']} % "
        . "(plus c'est haut, plus les deux personas parlent pareil).\n\n"
        . "« Redites probables » : répliques qui reprennent au moins la moitié des mots d'une réplique précédente du même persona. "
        . "« Approbations » : répliques contenant « tu as raison », « d'accord », « exactement »…\n\n"
        . "## Grille de lecture\n\n"
        . "- [ ] Les deux voix restent reconnaissables sans lire les noms\n"
        . "- [ ] Il y a de vrais désaccords, pas seulement des approbations\n"
        . "- [ ] Chaque réplique apporte quelque chose de nouveau\n"
        . "- [ ] Aucun persona n'écrit à la place de l'autre ni ne s'adresse à l'utilisateur\n"
        . "- [ ] L'échange reste cohérent avec les fiches (métier, âge, ville)\n"
        . "- [ ] La fin (s'il y en a une) arrive à un moment naturel\n";
    return $md;
}
