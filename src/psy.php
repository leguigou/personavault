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

const PSY_PER_TRAIT = 2;
const PSY_KEEP_RUNS = 20;
const PSY_CUSTOM_MAX = 8;

/**
 * Grilles prêtes à l'emploi : clé de trait → [définition envoyée aux modèles (en français),
 * deux messages de secours si le générateur en fournit trop peu].
 */
const PSY_GRIDS = [
    'personnalite' => [
        'empathie'     => ['perçoit ce que ressent l’autre et en tient compte', ['J’ai passé une journée horrible, je crois que je vais tout lâcher…', 'Ma grand-mère est à l’hôpital depuis hier.']],
        'sociabilite'  => ['recherche le contact, s’ouvre facilement, aime la compagnie', ['Il y a une soirée chez des amis ce soir, tu viens avec moi ?', 'Tu préfères un dîner à deux ou une grosse fête ?']],
        'humour'       => ['plaisante, ironise, dédramatise', ['Je viens de renverser mon café sur mon clavier en pleine réunion.', 'Raconte-moi un truc qui t’a fait rire récemment.']],
        'confiance'    => ['assurance, aplomb, croit en ce qu’il dit et en lui-même', ['Je ne suis pas sûr que tu aies raison sur ce que tu m’as dit hier.', 'Tu te sens capable de parler devant cent personnes ?']],
        'independance' => ['décide seul, tient à sa liberté, ne cherche pas l’approbation', ['Je préférerais que tu me demandes mon avis avant de décider tes week-ends.', 'Tu ferais un tour du monde tout seul ?']],
        'sensibilite'  => ['se laisse toucher, vulnérabilité émotionnelle visible', ['Franchement, ce que tu as dit hier m’a un peu vexé.', 'Tu pleures devant les films ?']],
        'impulsivite'  => ['réagit vite et sans filtre, agit avant de réfléchir', ['On part demain matin en week-end, sans rien prévoir ?', 'Quelqu’un vient de te doubler dans la file d’attente. Tu fais quoi ?']],
        'loyaute'      => ['fidèle à ses proches et à sa parole, défend les siens', ['J’ai entendu ton meilleur ami dire du mal de toi.', 'Si je te demandais de garder un secret qui te dérange, tu le garderais ?']],
    ],
    'caractere' => [
        'enjoue'      => ['de bonne humeur, léger, voit le bon côté des choses', ['Il pleut depuis trois jours, j’en peux plus.', 'Qu’est-ce qui t’a fait plaisir aujourd’hui ?']],
        'grincheux'   => ['ronchon, râle facilement, de mauvaise humeur', ['Il faut qu’on se lève à 6 h demain pour le train.', 'Le serveur a encore oublié ma commande, on attend.']],
        'calme'       => ['posé, garde son sang-froid, ne s’emballe pas', ['Je viens de rater mon train et j’ai un rendez-vous important !', 'Il y a une coupure de courant dans tout l’immeuble.']],
        'anxieux'     => ['s’inquiète facilement, anticipe le pire', ['Je n’ai pas de nouvelles de mon frère depuis hier soir.', 'Demain j’ai un entretien d’embauche, et toi ça t’arrive de stresser ?']],
        'patient'     => ['supporte l’attente et les répétitions sans s’agacer', ['Tu peux me réexpliquer ? Je n’ai toujours pas compris.', 'Je vais être en retard d’une heure, désolé…']],
        'susceptible' => ['se vexe facilement, prend mal les remarques', ['Ta tenue d’hier n’était pas terrible, franchement.', 'Tu exagères un peu parfois, non ?']],
        'chaleureux'  => ['accueillant, bienveillant, met l’autre à l’aise', ['C’est la première fois que je viens dans ta ville.', 'Je ne connais personne ici, je me sens un peu seul.']],
        'rancunier'   => ['garde en mémoire les torts, a du mal à pardonner', ['Ton ami qui t’avait trahi veut se réconcilier.', 'Je suis désolé pour ce que je t’ai dit la semaine dernière.']],
    ],
    'relation' => [
        'affectueux' => ['exprime facilement sa tendresse et son attachement', ['Je pense à toi depuis ce matin.', 'Ça fait du bien de te parler.']],
        'protecteur' => ['veille sur l’autre, veut le mettre à l’abri', ['Je rentre seul à pied ce soir, il est tard.', 'Quelqu’un m’embête au travail en ce moment.']],
        'jaloux'     => ['supporte mal que l’autre accorde de l’attention à d’autres', ['J’ai dîné avec une collègue hier soir, on a bien ri.', 'Mon ex m’a écrit ce matin.']],
        'possessif'  => ['veut garder l’autre pour soi, contrôle son temps', ['Ce week-end je pars avec des amis, sans toi.', 'Je vais être moins disponible ces prochains jours.']],
        'taquin'     => ['aime chambrer, provoquer gentiment', ['J’ai encore perdu mes clés.', 'Je me suis mis au sport ce matin !']],
        'romantique' => ['cherche les gestes tendres, l’idéal amoureux', ['C’est quoi pour toi une soirée parfaite ?', 'Tu crois au coup de foudre ?']],
        'pudique'    => ['réservé sur l’intime, gêné par les sujets personnels', ['Tu me parles de ta dernière histoire d’amour ?', 'Qu’est-ce qui te plaît chez moi ?']],
        'loyal'      => ['fidèle à l’autre et à sa parole, ne trahit pas', ['Promets-moi de ne rien dire à personne de ce que je vais te raconter.', 'On m’a dit que tu avais parlé de moi à quelqu’un.']],
    ],
    'ton' => [
        'drole'        => ['fait rire, a de l’esprit', ['Raconte-moi ta pire honte.', 'Mon chat vient de faire tomber mon téléphone dans la baignoire.']],
        'sarcastique'  => ['ironie mordante, se moque à demi-mot', ['J’ai encore oublié ton anniversaire, je suis le meilleur.', 'Je me suis inscrit à un cours de yoga à 7 h du matin.']],
        'serieux'      => ['parle avec gravité, peu de légèreté', ['Tu penses quoi de ton avenir ?', 'J’ai une décision importante à prendre et j’ai besoin de ton avis.']],
        'direct'       => ['dit les choses franchement, sans détour', ['Sois honnête : tu me trouves comment ?', 'Tu crois que je devrais quitter mon travail ?']],
        'diplomate'    => ['ménage l’autre, arrondit les angles', ['Mon frère et moi on se dispute, qui a raison selon toi ?', 'J’ai écrit un poème, tu veux le lire ? Il est un peu raté.']],
        'timide'       => ['hésitant, se met en retrait, peu à l’aise', ['Parle-moi un peu de toi !', 'Tu viendrais chanter au karaoké avec moi ?']],
        'confiant'     => ['sûr de lui, affirmatif', ['Tu es sûr de toi là ?', 'Qu’est-ce que tu fais mieux que tout le monde ?']],
        'melancolique' => ['nostalgique, une tristesse douce affleure', ['Tu repenses parfois à ton enfance ?', 'Le dimanche soir, ça te fait quoi ?']],
    ],
];

/** Nom affiché d'une grille (langue de l'interface). */
function psy_grid_labels(): array
{
    return [
        'personnalite' => t('psy.grid.personnalite'),
        'caractere'    => t('psy.grid.caractere'),
        'relation'     => t('psy.grid.relation'),
        'ton'          => t('psy.grid.ton'),
        'custom'       => t('psy.grid.custom'),
    ];
}

/** Libellés des traits prêts à l'emploi (tableau explicite : le vérificateur i18n lit chaque clé). */
function psy_builtin_labels(): array
{
    return [
        'empathie' => t('psy.trait.empathie'), 'sociabilite' => t('psy.trait.sociabilite'), 'humour' => t('psy.trait.humour'),
        'confiance' => t('psy.trait.confiance'), 'independance' => t('psy.trait.independance'), 'sensibilite' => t('psy.trait.sensibilite'),
        'impulsivite' => t('psy.trait.impulsivite'), 'loyaute' => t('psy.trait.loyaute'),
        'enjoue' => t('psy.trait.enjoue'), 'grincheux' => t('psy.trait.grincheux'), 'calme' => t('psy.trait.calme'),
        'anxieux' => t('psy.trait.anxieux'), 'patient' => t('psy.trait.patient'), 'susceptible' => t('psy.trait.susceptible'),
        'chaleureux' => t('psy.trait.chaleureux'), 'rancunier' => t('psy.trait.rancunier'),
        'affectueux' => t('psy.trait.affectueux'), 'protecteur' => t('psy.trait.protecteur'), 'jaloux' => t('psy.trait.jaloux'),
        'possessif' => t('psy.trait.possessif'), 'taquin' => t('psy.trait.taquin'), 'romantique' => t('psy.trait.romantique'),
        'pudique' => t('psy.trait.pudique'), 'loyal' => t('psy.trait.loyal'),
        'drole' => t('psy.trait.drole'), 'sarcastique' => t('psy.trait.sarcastique'), 'serieux' => t('psy.trait.serieux'),
        'direct' => t('psy.trait.direct'), 'diplomate' => t('psy.trait.diplomate'), 'timide' => t('psy.trait.timide'),
        'confiant' => t('psy.trait.confiant'), 'melancolique' => t('psy.trait.melancolique'),
    ];
}

/**
 * Lignes de la grille personnalisée : « trait » ou « trait : définition », 3 à 8.
 * @return list<array{label:string, definition:string}>
 */
function psy_parse_custom(string $text): array
{
    $out = [];
    $seen = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        [$label, $def] = array_map('trim', array_pad(explode(':', $line, 2), 2, ''));
        $label = mb_substr(trim($label, " \t-•*"), 0, 40);
        $key = mb_strtolower($label);
        if ($label === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['label' => $label, 'definition' => mb_substr($def, 0, 200)];
        if (count($out) >= PSY_CUSTOM_MAX) {
            break;
        }
    }
    return $out;
}

/**
 * Grille à mesurer : clé, nom, traits (clé → libellé, définition, messages de secours).
 * @param list<array{label:string, definition:string}> $custom
 */
function psy_grid(string $key, array $custom = []): array
{
    if ($key === 'custom') {
        if (count($custom) < 3) {
            throw new RuntimeException(t('psy.custom_too_few'));
        }
        $traits = [];
        foreach (array_values($custom) as $i => $c) {
            $traits['t' . ($i + 1)] = ['label' => $c['label'], 'definition' => $c['definition'] !== '' ? $c['definition'] : $c['label'], 'fallback' => []];
        }
        return ['key' => 'custom', 'name' => psy_grid_labels()['custom'], 'traits' => $traits];
    }
    $key = isset(PSY_GRIDS[$key]) ? $key : 'personnalite';
    $labels = psy_builtin_labels();
    $traits = [];
    foreach (PSY_GRIDS[$key] as $k => [$def, $fallback]) {
        $traits[$k] = ['label' => $labels[$k], 'definition' => $def, 'fallback' => $fallback];
    }
    return ['key' => $key, 'name' => psy_grid_labels()[$key], 'traits' => $traits];
}

/** Clés de trait d'une grille, dans l'ordre du radar. */
function psy_keys(array $grid): array
{
    return array_keys($grid['traits']);
}

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

function psy_traits_spec(array $grid): string
{
    $lines = [];
    foreach ($grid['traits'] as $k => $t) {
        $lines[] = "- {$k} : {$t['label']} — {$t['definition']}";
    }
    return implode("\n", $lines);
}

/** Notes 0-100 nettoyées : chaque trait de la grille présent, borné, avec sa justification. */
function psy_clean_scores(mixed $raw, array $grid): array
{
    $out = [];
    $raw = is_array($raw) ? $raw : [];
    foreach (psy_keys($grid) as $k) {
        $item = $raw[$k] ?? null;
        $score = is_array($item) ? ($item['score'] ?? null) : $item;
        $out[$k] = [
            'score'  => is_numeric($score) ? max(0, min(100, (int) round((float) $score))) : 50,
            'raison' => is_array($item) && is_string($item['raison'] ?? null) ? mb_substr(trim($item['raison']), 0, 300) : '',
        ];
    }
    return $out;
}

/** 1. Profil attendu, d'après la fiche. */
function psy_expected(string $fiche, array $grid): array
{
    $system = "Tu es psychologue. Tu lis la FICHE d'un personnage de fiction et tu estimes, pour chaque trait, le niveau "
        . "que la fiche lui donne, de 0 (absent) à 100 (très marqué), 50 = moyen. Appuie-toi sur ce que la fiche dit ou montre ; "
        . "si elle ne dit rien d'un trait, mets 50 et la raison « non précisé ». Utilise exactement les clés indiquées.\nTraits (clé : nom — définition) :\n"
        . psy_traits_spec($grid)
        . "\nRéponds UNIQUEMENT avec un objet JSON : {\"traits\":{\"clé\":{\"score\":N,\"raison\":\"phrase courte tirée de la fiche\"},…}}";
    $j = assist_llm_json($system, "FICHE\n" . $fiche, 1500, 0.2);
    return psy_clean_scores($j['traits'] ?? $j, $grid);
}

/** 2. Messages de mise en situation, PSY_PER_TRAIT par trait (complétés par les messages de secours de la grille). */
function psy_questions(array $persona, array $grid): array
{
    $system = "Tu prépares un test de caractère pour un personnage de fiction avec qui l'on discute par messagerie. "
        . 'Écris ' . PSY_PER_TRAIT . ' messages PAR TRAIT, que l’interlocuteur pourrait lui envoyer, en français, en le tutoyant. '
        . "Chaque message est une situation concrète et naturelle qui pousse le personnage à révéler le trait par sa façon de réagir. "
        . "Ne nomme jamais le trait, ne demande pas « es-tu… ? », ne parle pas d'IA ni de modèle, pas de contenu sexuel. Une ou deux phrases. "
        . "Utilise exactement les clés indiquées.\nTraits (clé : nom — définition) :\n" . psy_traits_spec($grid)
        . "\nRéponds UNIQUEMENT avec un objet JSON : {\"questions\":[{\"trait\":\"clé\",\"message\":\"…\"},…]}";
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
    $byTrait = array_fill_keys(psy_keys($grid), []);
    foreach ((array) ($j['questions'] ?? []) as $q) {
        $trait = is_array($q) ? (string) ($q['trait'] ?? '') : '';
        $msg = is_array($q) && is_string($q['message'] ?? null) ? trim($q['message']) : '';
        if (isset($byTrait[$trait]) && $msg !== '' && count($byTrait[$trait]) < PSY_PER_TRAIT) {
            $byTrait[$trait][] = mb_substr($msg, 0, 400);
        }
    }
    $out = [];
    foreach ($byTrait as $trait => $msgs) {
        foreach ($grid['traits'][$trait]['fallback'] as $fallback) {
            if (count($msgs) >= PSY_PER_TRAIT) {
                break;
            }
            $msgs[] = $fallback;
        }
        if ($msgs === []) {
            // grille personnalisée : pas de messages de secours, le générateur doit fournir chaque trait
            throw new RuntimeException(t('psy.error.no_questions', ['trait' => $grid['traits'][$trait]['label']]));
        }
        foreach ($msgs as $m) {
            $out[] = ['trait' => $trait, 'message' => $m];
        }
    }
    return $out;
}

/** 4. Profil observé, d'après les SEULES réponses (le modèle ne voit pas la fiche). */
function psy_observed(string $name, array $qa, array $grid): array
{
    $transcript = '';
    foreach ($qa as $i => $x) {
        $transcript .= '[' . ($i + 1) . "] Interlocuteur : " . $x['message'] . "\n" . $name . ' : ' . ($x['answer'] !== '' ? $x['answer'] : '(pas de réponse)') . "\n\n";
    }
    $system = "Tu es psychologue. Tu observes UNIQUEMENT les réponses d'un personnage à une série de messages. "
        . "Pour chaque trait, note de 0 à 100 le niveau qu'il MONTRE dans ses réponses (50 = moyen). Toutes les réponses comptent, "
        . "pas seulement celles qui visent le trait. Une réponse vide, d'assistant ou hors personnage n'apporte aucun indice. "
        . "Justifie en citant le numéro d'une réponse. Utilise exactement les clés indiquées.\nTraits (clé : nom — définition) :\n" . psy_traits_spec($grid)
        . "\nRéponds UNIQUEMENT avec un objet JSON : {\"traits\":{\"clé\":{\"score\":N,\"raison\":\"phrase courte avec [numéro]\"},…}}";
    $j = assist_llm_json($system, "ÉCHANGES\n" . $transcript, 1800, 0.2);
    return psy_clean_scores($j['traits'] ?? $j, $grid);
}

/** Écart (points) par trait et fidélité (100 − écart moyen) entre attendu et observé. */
function psy_fidelity(array $expected, array $observed): array
{
    $gaps = [];
    foreach (array_keys($expected) as $k) {
        $gaps[$k] = (int) ($observed[$k]['score'] ?? 50) - (int) $expected[$k]['score'];
    }
    $mean = $gaps ? array_sum(array_map('abs', $gaps)) / count($gaps) : 0;
    return ['gaps' => $gaps, 'fidelity' => (int) round(100 - $mean)];
}

/** 5. Conclusion : les écarts qui comptent et comment les corriger. */
function psy_conclusion(string $fiche, array $grid, array $expected, array $observed, array $gaps): array
{
    $rows = [];
    foreach ($grid['traits'] as $k => $t) {
        $rows[] = "{$t['label']} : attendu {$expected[$k]['score']}, observé {$observed[$k]['score']} (écart " . sprintf('%+d', $gaps[$k])
            . ") — observé : {$observed[$k]['raison']}";
    }
    $system = "Tu compares le profil ATTENDU d'un personnage (d'après sa fiche) et le profil OBSERVÉ dans ses réponses "
        . "(grille « {$grid['name']} »). Écris une conclusion de 2 à 4 phrases, en français, centrée sur les écarts de plus de 15 points "
        . "(le reste est du bruit). Puis propose jusqu'à 3 corrections concrètes de la fiche, une phrase chacune : la psychologie et "
        . "l'histoire vont dans le PROMPT SYSTÈME, la forme des messages dans la CONSIGNE DE JEU. Si les profils concordent, dis-le "
        . "simplement, sans correction forcée.\n"
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
 * Test complet sur une grille. $onProgress(étape, fait, total) à chaque pas.
 * @return array résultat enregistrable (grille, libellés, attendu, observé, échanges, écarts, conclusion)
 */
function psy_run(array $persona, array $prompts, array $grid, ?callable $onProgress = null): array
{
    $conv = harness_conv($persona);
    $fiche = psy_fiche_text($persona, $prompts, $conv);
    $local = chat_llm_config_for($conv);
    $total = 4 + count($grid['traits']) * PSY_PER_TRAIT;
    $done = 0;
    $tick = static function (string $step) use (&$done, &$total, $onProgress): void {
        if ($onProgress) {
            $onProgress($step, $done, $total);
        }
        $done++;
    };

    $tick('attendu');
    $expected = psy_expected($fiche, $grid);
    $tick('questions');
    $questions = psy_questions($persona, $grid);
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
    $observed = psy_observed((string) $persona['name'], $qa, $grid);
    $fid = psy_fidelity($expected, $observed);
    $tick('conclusion');
    $conclusion = psy_conclusion($fiche, $grid, $expected, $observed, $fid['gaps']);

    return [
        'grid'         => $grid['key'],
        'grid_name'    => $grid['name'],
        'labels'       => array_map(static fn (array $t): string => $t['label'], $grid['traits']),
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

/** Libellés d'un résultat gardé (les premiers tests n'avaient que la grille « personnalité »). */
function psy_result_labels(array $result): array
{
    return is_array($result['labels'] ?? null) ? $result['labels']
        : array_map(static fn (array $t): string => $t['label'], psy_grid('personnalite')['traits']);
}

/* ---------------------------------------------------------------- résultats gardés */

function psy_save_run(int $pid, array $result): int
{
    $pdo = db();
    $pdo->prepare('INSERT INTO persona_psy_runs (persona_id, local_model, remote_model, fidelity, grid, grid_name, result) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$pid, $result['local_model'], $result['remote_model'], (int) $result['fidelity'], (string) ($result['grid'] ?? 'personnalite'),
            (string) ($result['grid_name'] ?? ''), json_encode($result, JSON_UNESCAPED_UNICODE)]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('DELETE FROM persona_psy_runs WHERE persona_id = ? AND id NOT IN
        (SELECT id FROM persona_psy_runs WHERE persona_id = ? ORDER BY id DESC LIMIT ' . PSY_KEEP_RUNS . ')')->execute([$pid, $pid]);
    return $id;
}

/** Tests passés d'un persona, du plus récent au plus ancien (sans le détail). */
function psy_runs(int $pid): array
{
    $st = db()->prepare('SELECT id, created_at, local_model, remote_model, fidelity, grid, grid_name FROM persona_psy_runs WHERE persona_id = ? ORDER BY id DESC');
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
function psy_radar_svg(array $expected, array $observed, array $labels): string
{
    $keys = array_keys($expected);
    $n = max(3, count($keys));
    $cx = 260.0;
    $cy = 210.0;
    $r = 150.0;
    $point = static function (int $i, float $value) use ($n, $cx, $cy, $r): array {
        $angle = -M_PI / 2 + 2 * M_PI * $i / $n;
        return [$cx + cos($angle) * $r * $value / 100, $cy + sin($angle) * $r * $value / 100];
    };
    $poly = static function (array $scores) use ($point, $keys): string {
        $pts = [];
        foreach ($keys as $i => $k) {
            [$x, $y] = $point($i, (float) ($scores[$k]['score'] ?? 0));
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        return implode(' ', $pts);
    };
    $svg = '<svg class="psy-radar" viewBox="0 0 520 430" role="img" aria-label="' . e(t('psy.radar_aria')) . '">';
    foreach ([20, 40, 60, 80, 100] as $ring) {
        $pts = [];
        foreach ($keys as $i => $_) {
            [$x, $y] = $point($i, $ring);
            $pts[] = round($x, 1) . ',' . round($y, 1);
        }
        $svg .= '<polygon class="psy-ring" points="' . implode(' ', $pts) . '"/>';
    }
    foreach ($keys as $i => $k) {
        [$x, $y] = $point($i, 100);
        $svg .= '<line class="psy-spoke" x1="' . $cx . '" y1="' . $cy . '" x2="' . round($x, 1) . '" y2="' . round($y, 1) . '"/>';
        [$lx, $ly] = $point($i, 118);
        $anchor = abs($lx - $cx) < 5 ? 'middle' : ($lx > $cx ? 'start' : 'end');
        $svg .= '<text class="psy-label" x="' . round($lx, 1) . '" y="' . round($ly + 5, 1) . '" text-anchor="' . $anchor . '">' . e((string) ($labels[$k] ?? $k)) . '</text>';
    }
    $svg .= '<polygon class="psy-observed" points="' . $poly($observed) . '"/>';
    $svg .= '<polygon class="psy-expected" points="' . $poly($expected) . '"/>';
    foreach ($keys as $i => $k) {
        [$x, $y] = $point($i, (float) ($observed[$k]['score'] ?? 0));
        $svg .= '<circle class="psy-dot" cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="4.5"><title>'
            . e(($labels[$k] ?? $k) . ' : ' . ($observed[$k]['score'] ?? 0) . ' / ' . $expected[$k]['score']) . '</title></circle>';
    }
    return $svg . '</svg>';
}
