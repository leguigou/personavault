<?php
declare(strict_types=1);

/*
 * Essai de discussion entre deux personas (Réglages › Essai de discussion, bin/duo-test.php).
 * Une réplique à la fois : l'appelant garde l'échange et le renvoie à chaque tour.
 * Rien n'est écrit dans les conversations.
 */

const DUO_MIN_TURNS_BEFORE_END = 4;
const DUO_HISTORY_LINES = 16;
const DUO_MAX_TURNS = 40;
const DUO_LINE_MAX = 1200;
const DUO_TEXT_MAX = 300;

/** Un persona et ses prompts, ou null. */
function duo_speaker(int|string $idOrSlug, string $goal = ''): ?array
{
    $stmt = db()->prepare(is_int($idOrSlug) ? 'SELECT * FROM personas WHERE id = ?' : 'SELECT * FROM personas WHERE slug = ?');
    $stmt->execute([$idOrSlug]);
    $persona = $stmt->fetch();
    if (!$persona) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM prompts WHERE persona_id = ? ORDER BY id');
    $stmt->execute([(int) $persona['id']]);
    return ['persona' => $persona, 'prompts' => $stmt->fetchAll(), 'goal' => mb_substr(trim($goal), 0, DUO_TEXT_MAX)];
}

/**
 * Modèle de l'essai : celui demandé, sinon celui déjà chargé dans LM Studio (pas de
 * déchargement entre les deux voix), sinon le modèle de base des Réglages.
 */
function duo_llm_config(string $model = ''): array
{
    $cfg = chat_llm_config();
    if (trim($model) !== '') {
        $cfg['model'] = trim($model);
        return $cfg;
    }
    if (($cfg['provider'] ?? 'lmstudio') === 'lmstudio') {
        $st = lms_status();
        if (!empty($st['loaded'])) {
            $cfg['model'] = (string) ($st['loaded'][0]['key'] ?: $st['loaded'][0]['id']);
        }
    }
    return $cfg;
}

/**
 * Répliques reçues de l'extérieur (navigateur), remises en forme : alternance depuis
 * le premier persona, textes bornés.
 * @return array<int, array{speaker:int, text:string, ms:int}>
 */
function duo_lines_from_input(mixed $raw): array
{
    $lines = [];
    foreach (is_array($raw) ? array_slice($raw, 0, DUO_MAX_TURNS) : [] as $i => $line) {
        $text = is_array($line) && is_string($line['text'] ?? null) ? trim($line['text']) : '';
        if ($text === '') {
            break;
        }
        $lines[] = ['speaker' => $i % 2, 'text' => mb_substr($text, 0, DUO_LINE_MAX), 'ms' => max(0, (int) ($line['ms'] ?? 0))];
    }
    return $lines;
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
    $text = trim(preg_replace('/\[\s*FIN\s*\]/iu', '', $text) ?? $text);
    $text = preg_replace('/^\**' . preg_quote($name, '/') . '\**\s*:\s*/u', '', $text) ?? $text;
    $cut = preg_split('/\n+\s*\**' . preg_quote($otherName, '/') . '\**\s*:/u', $text);
    $text = trim((string) ($cut[0] ?? $text));
    return [trim($text, " \t\n\r\"«»"), $ended];
}

/**
 * Réplique suivante de l'échange.
 * @param array{0: array, 1: array} $speakers
 * @return array{speaker:int, text:string, ended:bool, ms:int}
 * @throws RuntimeException modèle injoignable ou réponse vide
 */
function duo_turn(array $speakers, array $lines, string $topic, array $cfg): array
{
    $who = count($lines) % 2;
    $me = $speakers[$who];
    $other = $speakers[1 - $who];

    llm_log_context(['purpose' => 'duo']);
    $t0 = microtime(true);
    $res = llm_request(duo_messages($me, $other, $who, $lines, $topic), $cfg, [
        'temperature'      => (float) ($me['persona']['temperature'] ?? 0.85),
        'max_tokens'       => max(60, min(220, (int) ($me['persona']['max_tokens'] ?? 400))),
        'timeout'          => 180,
        'reasoning_effort' => setting('chat_reasoning_effort', 'none'),
    ]);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    [$text, $ended] = duo_clean((string) $res['content'], (string) $me['persona']['name'], (string) $other['persona']['name']);
    if ($text === '') {
        throw new RuntimeException(t('error.llm.empty'));
    }
    return ['speaker' => $who, 'text' => $text, 'ended' => $ended && count($lines) + 1 >= DUO_MIN_TURNS_BEFORE_END, 'ms' => $ms];
}

/** Proportion de mots communs entre deux ensembles (0 à 1). */
function duo_overlap(array $a, array $b): float
{
    if (!$a || !$b) {
        return 0.0;
    }
    return count(array_intersect($a, $b)) / max(1, count(array_unique(array_merge($a, $b))));
}

/**
 * Indicateurs simples, pour orienter la lecture (pas un jugement de qualité).
 * @return array{per: array<int, array>, voice_overlap: int}
 */
function duo_metrics(array $lines, array $names): array
{
    $agree = '/\b(tu as raison|vous avez raison|je suis d.accord|t.as raison|exactement|totalement|complètement d.accord|bonne idée|carrément'
        . '|you.re right|i agree|exactly|good idea|tienes razón|estoy de acuerdo|exacto|buena idea)\b/iu';
    $per = [];
    $vocab = [[], []];
    foreach ([0, 1] as $who) {
        $own = array_values(array_filter($lines, static fn (array $l): bool => $l['speaker'] === $who));
        $words = 0;
        $repeats = 0;
        $agreements = 0;
        $seen = [];
        foreach ($own as $l) {
            $w = text_words($l['text'], 4);
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
            'avg_s'      => $own ? round(array_sum(array_column($own, 'ms')) / count($own) / 1000, 1) : 0,
        ];
    }
    return ['per' => $per, 'voice_overlap' => (int) round(duo_overlap(array_unique($vocab[0]), array_unique($vocab[1])) * 100)];
}

/** Points de la grille de lecture. */
function duo_checklist(): array
{
    return [t('duo.check.voices'), t('duo.check.disagree'), t('duo.check.new'), t('duo.check.roles'),
        t('duo.check.sheets'), t('duo.check.end')];
}

/** Rapport Markdown : réglages, échange, indicateurs et grille de lecture. */
function duo_report(array $info, array $names, array $lines): string
{
    $md = '# ' . t('duo.report.title', ['a' => $names[0], 'b' => $names[1]]) . "\n\n"
        . '- ' . t('duo.report.date') . ' : ' . date('Y-m-d H:i') . "\n"
        . '- ' . t('duo.report.model') . ' : ' . ($info['model'] ?? '') . "\n"
        . '- ' . t('duo.report.topic') . ' : ' . (($info['topic'] ?? '') !== '' ? $info['topic'] : t('duo.report.free')) . "\n"
        . '- ' . t('duo.report.turns', ['asked' => (int) ($info['turns'] ?? 0), 'got' => count($lines)]) . "\n";
    foreach ([0, 1] as $who) {
        if (($info['goals'][$who] ?? '') !== '') {
            $md .= '- ' . t('duo.report.goal', ['name' => $names[$who]]) . ' : ' . $info['goals'][$who] . "\n";
        }
    }
    if (($info['end'] ?? '') !== '') {
        $md .= '- ' . t('duo.report.end') . ' : ' . $info['end'] . "\n";
    }
    if (($info['error'] ?? '') !== '') {
        $md .= '- ' . t('duo.report.error') . ' : ' . $info['error'] . "\n";
    }

    $md .= "\n## " . t('duo.report.exchange') . "\n\n";
    foreach ($lines as $l) {
        $md .= '**' . $names[$l['speaker']] . '** : ' . $l['text'] . "\n\n";
    }

    $m = duo_metrics($lines, $names);
    $md .= '## ' . t('duo.metrics.title') . "\n\n"
        . '| | ' . implode(' | ', [t('duo.metrics.lines'), t('duo.metrics.words'), t('duo.metrics.repeats'),
            t('duo.metrics.agreements'), t('duo.metrics.time')]) . " |\n|---|---|---|---|---|---|\n";
    foreach ($m['per'] as $p) {
        $md .= "| {$p['name']} | {$p['lines']} | {$p['avg_words']} | {$p['repeats']} | {$p['agreements']} | {$p['avg_s']} s |\n";
    }
    $md .= "\n" . t('duo.metrics.voice', ['n' => $m['voice_overlap']]) . "\n\n" . t('duo.metrics.help') . "\n\n"
        . '## ' . t('duo.check.title') . "\n\n";
    foreach (duo_checklist() as $item) {
        $md .= "- [ ] {$item}\n";
    }
    return $md;
}
