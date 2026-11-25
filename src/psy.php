<?php
declare(strict_types=1);

/*
 * Empreinte psychologique d'un persona : la personnalité ATTENDUE (lue dans la fiche) comparée
 * à la personnalité OBSERVÉE (dans ses réponses), sur 8 traits, en radar.
 *
 *   1. le modèle distant (Réglages › IA, ex. DeepSeek) note les traits d'après la fiche ;
 *   2. il écrit 16 messages de mise en situation (2 par trait) ;
 *   3. le persona y répond avec son modèle de discussion (local), dans une conversation factice :
 *      aucune trace dans les discussions ;
 *   4. le modèle distant note les traits d'après les SEULES réponses (sans voir la fiche) ;
 *   5. il compare et conclut, avec des corrections concrètes.
 * Tourne en arrière-plan (bin/persona-psy.php) ; les résultats sont gardés (persona_psy_runs).
 */

const PSY_TRAITS = ['empathie', 'sociabilite', 'humour', 'confiance', 'independance', 'sensibilite', 'impulsivite', 'loyaute'];
const PSY_PER_TRAIT = 2;
const PSY_KEEP_RUNS = 20;

/** Définition de chaque trait, envoyée aux modèles (en français, comme les consignes). */
function psy_trait_definitions(): array
{
    return [
        'empathie'     => 'perçoit ce que ressent l’autre et en tient compte',
        'sociabilite'  => 'recherche le contact, s’ouvre facilement, aime la compagnie',
        'humour'       => 'plaisante, ironise, dédramatise',
        'confiance'    => 'assurance, aplomb, croit en ce qu’il dit et en lui-même',
        'independance' => 'décide seul, tient à sa liberté, ne cherche pas l’approbation',
        'sensibilite'  => 'se laisse toucher, vulnérabilité émotionnelle visible',
        'impulsivite'  => 'réagit vite et sans filtre, agit avant de réfléchir',
        'loyaute'      => 'fidèle à ses proches et à sa parole, défend les siens',
    ];
}

/** Libellés affichés (langue de l'interface), dans l'ordre du radar. */
function psy_trait_labels(): array
{
    return [
        'empathie'     => t('psy.trait.empathie'),
        'sociabilite'  => t('psy.trait.sociabilite'),
        'humour'       => t('psy.trait.humour'),
        'confiance'    => t('psy.trait.confiance'),
        'independance' => t('psy.trait.independance'),
        'sensibilite'  => t('psy.trait.sensibilite'),
        'impulsivite'  => t('psy.trait.impulsivite'),
        'loyaute'      => t('psy.trait.loyaute'),
    ];
}

/** Messages de secours si le générateur en fournit trop peu : le test ne doit jamais échouer pour ça. */
const PSY_FALLBACK = [
    'empathie'     => ['J’ai passé une journée horrible, je crois que je vais tout lâcher…', 'Ma grand-mère est à l’hôpital depuis hier.'],
    'sociabilite'  => ['Il y a une soirée chez des amis ce soir, tu viens avec moi ?', 'Tu préfères un dîner à deux ou une grosse fête ?'],
    'humour'       => ['Je viens de renverser mon café sur mon clavier en pleine réunion.', 'Raconte-moi un truc qui t’a fait rire récemment.'],
    'confiance'    => ['Je ne suis pas sûr que tu aies raison sur ce que tu m’as dit hier.', 'Tu te sens capable de parler devant cent personnes ?'],
    'independance' => ['Je préférerais que tu me demandes mon avis avant de décider tes week-ends.', 'Tu ferais un tour du monde toute seule ?'],
    'sensibilite'  => ['Franchement, ce que tu as dit hier m’a un peu vexé.', 'Tu pleures devant les films ?'],
    'impulsivite'  => ['On part demain matin en week-end, sans rien prévoir ?', 'Quelqu’un vient de te doubler dans la file d’attente. Tu fais quoi ?'],
    'loyaute'      => ['J’ai entendu ton meilleur ami dire du mal de toi.', 'Si je te demandais de garder un secret qui te dérange, tu le garderais ?'],
];

/** Fiche résumée pour le modèle distant : ce que le persona est censé être. */
function psy_fiche_text(array $persona, array $prompts, array $conv): string
{
    $txt = 'Nom : ' . $persona['name'] . "\n";
    foreach (json_list($persona['meta'] ?? '[]') as $row) {
        if (is_array($row) && isset($row['k'])) {
            $txt .= $row['k'] . ' : ' . mb_substr((string) ($row['v'] ?? ''), 0, 200) . "\n";
        }
    }
    if (trim((string) $persona['description']) !== '') {
        $txt .= 'Description : ' . mb_substr((string) $persona['description'], 0, 800) . "\n";
    }
    foreach ($prompts as $p) {
        if (($p['kind'] ?? '') === 'system') {
            $txt .= "Prompt système :\n" . mb_substr(trim((string) $p['body']), 0, 6000) . "\n";
        }
    }
    if (trim((string) ($conv['directive'] ?? '')) !== '') {
        $txt .= 'Consigne de jeu (forme des messages) : ' . mb_substr((string) $conv['directive'], 0, 1500) . "\n";
    }
    return $txt;
}

function psy_traits_spec(): string
{
    $lines = [];
    foreach (psy_trait_definitions() as $k => $def) {
        $lines[] = "- {$k} : {$def}";
    }
    return implode("\n", $lines);
}

/** Notes 0-100 nettoyées : chaque trait présent, borné, avec sa justification. */
function psy_clean_scores(mixed $raw): array
{
    $out = [];
    $raw = is_array($raw) ? $raw : [];
    foreach (PSY_TRAITS as $k) {
        $item = $raw[$k] ?? null;
        $score = is_array($item) ? ($item['score'] ?? null) : $item;
        $out[$k] = [
            'score'  => is_numeric($score) ? max(0, min(100, (int) round((float) $score))) : 50,
            'raison' => is_array($item) && is_string($item['raison'] ?? null) ? mb_substr(trim($item['raison']), 0, 300) : '',
        ];
    }
    return $out;
}

/** 1. Personnalité attendue, d'après la fiche. */
function psy_expected(string $fiche): array
{
    $system = "Tu es psychologue. Tu lis la FICHE d'un personnage de fiction et tu estimes, pour chaque trait, le niveau "
        . "que la fiche lui donne, de 0 (absent) à 100 (très marqué), 50 = moyen. Appuie-toi sur ce que la fiche dit ou montre ; "
        . "si elle ne dit rien d'un trait, mets 50 et la raison « non précisé ».\nTraits :\n" . psy_traits_spec()
        . "\nRéponds UNIQUEMENT avec un objet JSON : {\"traits\":{\"empathie\":{\"score\":N,\"raison\":\"phrase courte tirée de la fiche\"},…}}";
    $j = assist_llm_json($system, "FICHE\n" . $fiche, 1500, 0.2);
    return psy_clean_scores($j['traits'] ?? $j);
}

/** 2. Messages de mise en situation, PSY_PER_TRAIT par trait (complétés par les messages de secours). */
function psy_questions(array $persona): array
{
    $system = "Tu prépares un test de personnalité pour un personnage de fiction avec qui l'on discute par messagerie. "
        . 'Écris ' . PSY_PER_TRAIT . ' messages PAR TRAIT, que l’interlocuteur pourrait lui envoyer, en français, en le tutoyant. '
        . "Chaque message est une situation concrète et naturelle qui pousse le personnage à révéler le trait par sa façon de réagir. "
        . "Ne nomme jamais le trait, ne demande pas « es-tu… ? », ne parle pas d'IA ni de modèle, pas de contenu sexuel. Une ou deux phrases.\n"
        . "Traits :\n" . psy_traits_spec()
        . "\nRéponds UNIQUEMENT avec un objet JSON : {\"questions\":[{\"trait\":\"empathie\",\"message\":\"…\"},…]}";
    $who = 'Personnage : ' . $persona['name'];
    foreach (array_slice(json_list($persona['meta'] ?? '[]'), 0, 6) as $row) {
        if (is_array($row) && isset($row['k'])) {
            $who .= "\n" . $row['k'] . ' : ' . mb_substr((string) ($row['v'] ?? ''), 0, 120);
        }
    }
    try {
        $j = assist_llm_json($system, $who, 2000, 0.7);
    } catch (Throwable $e) {
        $j = [];
    }
    $byTrait = array_fill_keys(PSY_TRAITS, []);
    foreach ((array) ($j['questions'] ?? []) as $q) {
        $trait = is_array($q) ? (string) ($q['trait'] ?? '') : '';
        $msg = is_array($q) && is_string($q['message'] ?? null) ? trim($q['message']) : '';
        if (isset($byTrait[$trait]) && $msg !== '' && count($byTrait[$trait]) < PSY_PER_TRAIT) {
            $byTrait[$trait][] = mb_substr($msg, 0, 400);
        }
    }
    $out = [];
    foreach ($byTrait as $trait => $msgs) {
        foreach (PSY_FALLBACK[$trait] as $fallback) {
            if (count($msgs) >= PSY_PER_TRAIT) {
                break;
            }
            $msgs[] = $fallback;
        }
        foreach ($msgs as $m) {
            $out[] = ['trait' => $trait, 'message' => $m];
        }
    }
    return $out;
}

/** 4. Personnalité observée, d'après les SEULES réponses (le modèle ne voit pas la fiche). */
function psy_observed(string $name, array $qa): array
{
    $transcript = '';
    foreach ($qa as $i => $x) {
        $transcript .= '[' . ($i + 1) . "] Interlocuteur : " . $x['message'] . "\n" . $name . ' : ' . ($x['answer'] !== '' ? $x['answer'] : '(pas de réponse)') . "\n\n";
    }
    $system = "Tu es psychologue. Tu observes UNIQUEMENT les réponses d'un personnage à une série de messages. "
        . "Pour chaque trait, note de 0 à 100 le niveau qu'il MONTRE dans ses réponses (50 = moyen). Toutes les réponses comptent, "
        . "pas seulement celles qui visent le trait. Une réponse vide, d'assistant ou hors personnage n'apporte aucun indice. "
        . "Justifie en citant le numéro d'une réponse.\nTraits :\n" . psy_traits_spec()
        . "\nRéponds UNIQUEMENT avec un objet JSON : {\"traits\":{\"empathie\":{\"score\":N,\"raison\":\"phrase courte avec [numéro]\"},…}}";
    $j = assist_llm_json($system, "ÉCHANGES\n" . $transcript, 1800, 0.2);
    return psy_clean_scores($j['traits'] ?? $j);
}

/** Écart moyen (points) et fidélité (100 − écart moyen) entre attendu et observé. */
function psy_fidelity(array $expected, array $observed): array
{
    $gaps = [];
    foreach (PSY_TRAITS as $k) {
        $gaps[$k] = (int) $observed[$k]['score'] - (int) $expected[$k]['score'];
    }
    $mean = array_sum(array_map('abs', $gaps)) / count($gaps);
    return ['gaps' => $gaps, 'fidelity' => (int) round(100 - $mean)];
}

/** 5. Conclusion : les écarts qui comptent et comment les corriger. */
function psy_conclusion(string $fiche, array $expected, array $observed, array $gaps): array
{
    $rows = [];
    foreach (PSY_TRAITS as $k) {
        $rows[] = "{$k} : attendu {$expected[$k]['score']}, observé {$observed[$k]['score']} (écart " . sprintf('%+d', $gaps[$k])
            . ") — observé : {$observed[$k]['raison']}";
    }
    $system = "Tu compares la personnalité ATTENDUE d'un personnage (d'après sa fiche) et la personnalité OBSERVÉE dans ses réponses. "
        . "Écris une conclusion de 2 à 4 phrases, en français, centrée sur les écarts de plus de 15 points (le reste est du bruit). "
        . "Puis propose jusqu'à 3 corrections concrètes de la fiche, une phrase chacune : la psychologie et l'histoire vont dans le "
        . "PROMPT SYSTÈME, la forme des messages dans la CONSIGNE DE JEU. Si les profils concordent, dis-le simplement, sans correction forcée.\n"
        . 'Réponds UNIQUEMENT avec un objet JSON : {"conclusion":"…","corrections":["…"]}';
    $j = assist_llm_json($system, "FICHE\n" . mb_substr($fiche, 0, 5000) . "\n\nCOMPARAISON\n" . implode("\n", $rows), 1200, 0.3);
    return [
        'conclusion'  => is_string($j['conclusion'] ?? null) ? mb_substr(trim($j['conclusion']), 0, 1500) : '',
        'corrections' => array_values(array_slice(array_filter(array_map(
            static fn ($c) => is_string($c) ? mb_substr(trim($c), 0, 400) : '', (array) ($j['corrections'] ?? [])
        )), 0, 3)),
    ];
}

/**
 * Test complet. $onProgress(étape, fait, total) à chaque pas.
 * @return array résultat enregistrable (attendu, observé, échanges, écarts, conclusion)
 */
function psy_run(array $persona, array $prompts, ?callable $onProgress = null): array
{
    $conv = harness_conv($persona);
    $fiche = psy_fiche_text($persona, $prompts, $conv);
    $local = chat_llm_config_for($conv);
    $total = 4 + count(PSY_TRAITS) * PSY_PER_TRAIT;
    $done = 0;
    $tick = static function (string $step) use (&$done, &$total, $onProgress): void {
        if ($onProgress) {
            $onProgress($step, $done, $total);
        }
        $done++;
    };

    $tick('attendu');
    $expected = psy_expected($fiche);
    $tick('questions');
    $questions = psy_questions($persona);
    $total = 4 + count($questions);

    $qa = [];
    foreach ($questions as $i => $q) {
        $tick('reponse ' . ($i + 1) . '/' . count($questions));
        $res = harness_ask($persona, $prompts, $conv, [], $q['message'], $local);
        $qa[] = $q + ['answer' => $res['text'], 'error' => $res['error']];
    }
    if (!array_filter(array_column($qa, 'answer'))) {
        throw new RuntimeException(t('psy.error.no_answer', ['error' => (string) ($qa[0]['error'] ?? '')]));
    }

    $tick('observe');
    $observed = psy_observed((string) $persona['name'], $qa);
    $fid = psy_fidelity($expected, $observed);
    $tick('conclusion');
    $conclusion = psy_conclusion($fiche, $expected, $observed, $fid['gaps']);

    return [
        'expected'     => $expected,
        'observed'     => $observed,
        'gaps'         => $fid['gaps'],
        'fidelity'     => $fid['fidelity'],
        'qa'           => $qa,
        'conclusion'   => $conclusion['conclusion'],
        'corrections'  => $conclusion['corrections'],
        'local_model'  => (string) ($local['model'] ?? ''),
        'remote_model' => (string) (llm_config()['model'] ?? ''),
    ];
}

/* ---------------------------------------------------------------- résultats gardés */

function psy_save_run(int $pid, array $result): int
{
    $pdo = db();
    $pdo->prepare('INSERT INTO persona_psy_runs (persona_id, local_model, remote_model, fidelity, result) VALUES (?, ?, ?, ?, ?)')
        ->execute([$pid, $result['local_model'], $result['remote_model'], (int) $result['fidelity'], json_encode($result, JSON_UNESCAPED_UNICODE)]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('DELETE FROM persona_psy_runs WHERE persona_id = ? AND id NOT IN
        (SELECT id FROM persona_psy_runs WHERE persona_id = ? ORDER BY id DESC LIMIT ' . PSY_KEEP_RUNS . ')')->execute([$pid, $pid]);
    return $id;
}

/** Tests passés d'un persona, du plus récent au plus ancien (sans le détail). */
function psy_runs(int $pid): array
{
    $st = db()->prepare('SELECT id, created_at, local_model, remote_model, fidelity FROM persona_psy_runs WHERE persona_id = ? ORDER BY id DESC');
    $st->execute([$pid]);
    return $st->fetchAll();
}

function psy_run_get(int $pid, int $id): ?array
{
    $st = db()->prepare('SELECT * FROM persona_psy_runs WHERE persona_id = ? AND id = ?');
    $st->execute([$pid, $id]);
    $row = $st->fetch();
    if (!$row) {
        return null;
    }
    $result = json_decode((string) $row['result'], true);
    return is_array($result) ? $result + ['id' => (int) $row['id'], 'created_at' => (string) $row['created_at']] : null;
}

/* ---------------------------------------------------------------- tâche en arrière-plan */

function psy_job_path(string $slug): string
{
    $dir = PV_ROOT . '/storage/tmp/harness';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . $slug . '.psy.json';
}

function psy_job_get(string $slug): ?array
{
    $file = psy_job_path($slug);
    $job = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    return is_array($job) ? $job : null;
}

function psy_job_put(string $slug, array $data): void
{
    $file = psy_job_path($slug);
    $tmp = $file . '.' . getmypid() . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
    @rename($tmp, $file);
}

/** Un test est-il en cours (et vivant : signe de vie depuis moins de 10 min) ? */
function psy_job_running(?array $job): bool
{
    return $job !== null && ($job['state'] ?? '') === 'running' && (int) ($job['beat'] ?? $job['started'] ?? 0) > time() - 600;
}

/** Les deux modèles sont-ils configurés (distant avec clé, local) ? Sans appel réseau. */
function psy_ready(): bool
{
    return harness_config_ready('remote') && harness_config_ready('local');
}

function psy_spawn(string $slug): bool
{
    if (!duo_can_spawn()) {
        return false;
    }
    $shell = @is_executable('/bin/bash') ? '/bin/bash' : '/bin/sh';
    // ferme les descripteurs hérités du serveur (sinon le port d'écoute reste pris)
    $script = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-" 2>/dev/null; done; '
        . 'exec ' . escapeshellarg(duo_php_cli()) . ' ' . escapeshellarg(PV_ROOT . '/bin/persona-psy.php') . ' '
        . escapeshellarg($slug) . ' --job';
    @exec('nohup ' . $shell . ' -c ' . escapeshellarg($script) . ' < /dev/null > /dev/null 2>&1 &', $out, $code);
    return $code === 0;
}

/* ---------------------------------------------------------------- radar */

/**
 * Radar SVG : attendu en pointillés, observé en surface pleine. Couleurs par classes CSS
 * (thèmes clair et sombre) ; libellés dans la langue de l'interface.
 */
function psy_radar_svg(array $expected, array $observed): string
{
    $labels = psy_trait_labels();
    $n = count(PSY_TRAITS);
    $cx = 260.0;
    $cy = 210.0;
    $r = 150.0;
    $point = static function (int $i, float $value) use ($n, $cx, $cy, $r): array {
        $angle = -M_PI / 2 + 2 * M_PI * $i / $n;
        return [$cx + cos($angle) * $r * $value / 100, $cy + sin($angle) * $r * $value / 100];
    };
    $poly = static function (array $scores) use ($point): string {
        $pts = [];
        foreach (PSY_TRAITS as $i => $k) {
            [$x, $y] = $point($i, (float) ($scores[$k]['score'] ?? 0));
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        return implode(' ', $pts);
    };
    $svg = '<svg class="psy-radar" viewBox="0 0 520 430" role="img" aria-label="' . e(t('psy.radar_aria')) . '">';
    foreach ([20, 40, 60, 80, 100] as $ring) {
        $pts = [];
        foreach (PSY_TRAITS as $i => $_) {
            [$x, $y] = $point($i, $ring);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $svg .= '<polygon class="psy-ring" points="' . implode(' ', $pts) . '"/>';
    }
    foreach (PSY_TRAITS as $i => $k) {
        [$x, $y] = $point($i, 100);
        $svg .= '<line class="psy-spoke" x1="' . $cx . '" y1="' . $cy . '" x2="' . round($x, 1) . '" y2="' . round($y, 1) . '"/>';
        [$lx, $ly] = $point($i, 118);
        $anchor = abs($lx - $cx) < 5 ? 'middle' : ($lx > $cx ? 'start' : 'end');
        $svg .= '<text class="psy-label" x="' . round($lx, 1) . '" y="' . round($ly + 5, 1) . '" text-anchor="' . $anchor . '">' . e($labels[$k]) . '</text>';
    }
    $svg .= '<polygon class="psy-observed" points="' . $poly($observed) . '"/>';
    $svg .= '<polygon class="psy-expected" points="' . $poly($expected) . '"/>';
    foreach (PSY_TRAITS as $i => $k) {
        [$x, $y] = $point($i, (float) $observed[$k]['score']);
        $svg .= '<circle class="psy-dot" cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4.5"><title>'
            . e($labels[$k] . ' : ' . $observed[$k]['score'] . ' / ' . $expected[$k]['score']) . '</title></circle>';
    }
    return $svg . '</svg>';
}
