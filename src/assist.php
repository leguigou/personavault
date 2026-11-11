<?php
declare(strict_types=1);

/*
 * Assistant de création de persona : une conversation où l'IA (modèle des Réglages › IA)
 * tient à jour une fiche en cours, pose UNE question à la fois avec des réponses toutes
 * prêtes, peut inventer (« Suggérer pour moi »), puis rédige la fiche complète avec son
 * prompt système et son prompt image.
 * Sur une fiche existante, le même assistant repère ce qui manque et ne remplit que les
 * champs vides : rien de ce qui existe n'est réécrit.
 */

/** Champs texte de la fiche en cours (clé → longueur max). */
const ASSIST_TEXT_FIELDS = [
    'name'        => 80,
    'tagline'     => 120,
    'description' => 1200,
    'looks'       => 900,
    'style'       => 200,
    'personality' => 900,
    'voice'       => 600,
    'relation'    => 500,
    'rules'       => 600,
];

/** Fiche en cours nettoyée (types, longueurs, listes bornées). */
function assist_clean(array $d): array
{
    $out = [];
    foreach (ASSIST_TEXT_FIELDS as $k => $max) {
        $v       = $d[$k] ?? '';
        $out[$k] = is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }
    $tags = [];
    foreach ((array) ($d['tags'] ?? []) as $t) {
        if (!is_scalar($t)) {
            continue;
        }
        $t = mb_strtolower(trim(ltrim(trim((string) $t), '#')));
        $t = mb_substr(preg_replace('/\s+/u', '-', $t) ?? $t, 0, 32);
        if ($t !== '' && !in_array($t, $tags, true) && count($tags) < 8) {
            $tags[] = $t;
        }
    }
    $out['tags'] = $tags;
    $meta = [];
    $seen = [];
    foreach ((array) ($d['meta'] ?? []) as $row) {
        if (!is_array($row) || !isset($row['k']) || !is_scalar($row['k'])) {
            continue;
        }
        $k = mb_substr(trim((string) $row['k']), 0, 40);
        $v = is_scalar($row['v'] ?? null) ? mb_substr(trim((string) $row['v']), 0, 200) : '';
        if ($k === '' || $v === '' || isset($seen[mb_strtolower($k)]) || count($meta) >= 12) {
            continue;
        }
        $seen[mb_strtolower($k)] = true;
        $meta[] = ['k' => $k, 'v' => $v];
    }
    $out['meta'] = $meta;
    return $out;
}

/**
 * Fusion : la nouvelle version de la fiche remplace l'ancienne champ par champ, sauf
 * quand elle laisse un champ vide (le modèle oublie parfois ce qu'il ne touche pas).
 */
function assist_merge(array $old, array $new): array
{
    $old = assist_clean($old);
    $new = assist_clean($new);
    foreach (ASSIST_TEXT_FIELDS as $k => $_) {
        if ($new[$k] === '') {
            $new[$k] = $old[$k];
        }
    }
    $new['tags'] = array_slice(array_values(array_unique(array_merge($old['tags'], $new['tags']))), 0, 8);
    $known       = [];
    foreach ($new['meta'] as $row) {
        $known[mb_strtolower($row['k'])] = true;
    }
    foreach ($old['meta'] as $row) {
        if (!isset($known[mb_strtolower($row['k'])]) && count($new['meta']) < 12) {
            $new['meta'][] = $row;
        }
    }
    return $new;
}

/** Fiche en cours tirée d'un persona existant (pour l'assistant sur une fiche). */
function assist_from_persona(array $persona): array
{
    return assist_clean([
        'name'        => $persona['name'] ?? '',
        'tagline'     => $persona['tagline'] ?? '',
        'description' => $persona['description'] ?? '',
        'tags'        => json_list($persona['tags'] ?? '[]'),
        'meta'        => json_list($persona['meta'] ?? '[]'),
    ]);
}

/**
 * Avancement de la fiche : éléments cochés et pourcentage.
 * Les prompts système et image sont rédigés à la création (ou existent déjà sur la fiche).
 * @return array{items: list<array{key:string,label:string,done:bool,auto?:bool}>, percent:int}
 */
function assist_progress(array $d, array $prompts = []): array
{
    $d     = assist_clean($d);
    $kinds = array_count_values(array_map(static fn ($p) => (string) $p['kind'], $prompts));
    $items = [
        ['key' => 'ident', 'label' => t('assist.item.ident'), 'done' => $d['name'] !== '' && $d['tagline'] !== ''],
        ['key' => 'desc', 'label' => t('assist.item.desc'), 'done' => mb_strlen($d['description']) >= 40],
        ['key' => 'facts', 'label' => t('assist.item.facts'), 'done' => count($d['meta']) >= 3],
        ['key' => 'looks', 'label' => t('assist.item.looks'), 'done' => mb_strlen($d['looks']) >= 30],
        ['key' => 'character', 'label' => t('assist.item.character'), 'done' => $d['personality'] !== '' && $d['voice'] !== ''],
        ['key' => 'rules', 'label' => t('assist.item.rules'), 'done' => $d['rules'] !== ''],
    ];
    $done = count(array_filter($items, static fn ($i) => $i['done']));
    $items[] = ['key' => 'system', 'label' => t('assist.item.system'), 'done' => isset($kinds['system']), 'auto' => !isset($kinds['system'])];
    $items[] = ['key' => 'image', 'label' => t('assist.item.image'), 'done' => isset($kinds['image']), 'auto' => !isset($kinds['image'])];
    return ['items' => $items, 'percent' => (int) round($done / 6 * 100)];
}

/** Contexte d'une fiche existante pour le modèle (prompts résumés). */
function assist_existing_context(?array $persona, array $prompts): string
{
    if (!$persona) {
        return '';
    }
    $txt = '';
    foreach ($prompts as $p) {
        $chunk = '[' . strtoupper((string) $p['kind']) . ($p['title'] !== '' ? ' — ' . $p['title'] : '') . "]\n" . mb_substr(trim((string) $p['body']), 0, 2500) . "\n\n";
        if (mb_strlen($txt) + mb_strlen($chunk) > 9000) {
            break;
        }
        $txt .= $chunk;
    }
    return "FICHE EXISTANTE (ne rien contredire ; seules les informations MANQUANTES t'intéressent)\n"
        . json_encode(assist_from_persona($persona), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\nPROMPTS EXISTANTS\n" . ($txt !== '' ? $txt : "(aucun)\n");
}

/** Appel JSON au modèle des Réglages › IA (repli sans response_format si refusé). */
function assist_llm_json(string $system, string $user, int $maxTokens, float $temperature): array
{
    $cfg = llm_config();
    if ($cfg['key'] === '') {
        throw new RuntimeException(t('error.llm.no_key'));
    }
    llm_log_context(['purpose' => 'assist']);
    $msgs = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    $opts = ['temperature' => $temperature, 'max_tokens' => $maxTokens, 'timeout' => 150, 'json' => true, 'purpose' => 'assist'];
    try {
        $res = llm_request($msgs, $cfg, $opts);
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'response_format')) {
            throw $e;
        }
        $opts['json'] = false;
        $res = llm_request($msgs, $cfg, $opts);
    }
    $out = trim((string) preg_replace('~<think>.*?</think>~s', '', (string) $res['content']));
    return llm_json($out);
}

/**
 * Un tour de conversation.
 * $action : start (premier message, rien encore), reply (réponse de l'utilisateur),
 *           suggest (l'IA invente la réponse à sa dernière question).
 * $history : [{role: assistant|user, content}] (les derniers échanges).
 * @return array{draft:array, reply:string, question:string, choices:list<string>, progress:array}
 */
function assist_turn(array $draft, array $history, string $action, string $answer, ?array $persona = null, array $prompts = []): array
{
    $draft = assist_clean($draft);
    $isNew = $persona === null;

    $system = <<<TXT
Tu es un assistant chaleureux et efficace qui aide à créer la fiche d'un PERSONA : un personnage récurrent pour la génération d'images et pour un agent conversationnel qui l'incarnera.
Tu tiens à jour la FICHE EN COURS (JSON) et tu fais avancer la conversation, une question à la fois.

Champs de la fiche :
- name : prénom (et nom) ; tagline : accroche d'une ligne (90 caractères max) ; description : 2 à 4 phrases ;
- tags : 3 à 6 mots-clés en minuscules ; meta : attributs clé/valeur courts (Âge, Métier, Ville, Origine, Taille…) ;
- looks : apparence physique PRÉCISE pour qu'on la reconnaisse sur les images (visage, yeux, cheveux, peau, silhouette, style vestimentaire, signes distinctifs) ;
- style : rendu visuel voulu (photo réaliste, manga, illustration, 3D…) ;
- personality : caractère, valeurs, passions, histoire ; voice : façon de parler (ton, registre, tutoiement, longueur des réponses, tics) ;
- relation : relation avec l'utilisateur et contexte des échanges ; rules : limites et règles de conduite (ce qu'elle ne fait jamais).

À chaque tour :
1. Intègre la dernière réponse de l'utilisateur dans la fiche. Il peut corriger quelque chose : applique la correction. Ne supprime rien d'autre.
2. Si ACTION = suggest : l'utilisateur manque d'inspiration. INVENTE toi-même une réponse riche, originale et cohérente avec le reste à ta DERNIÈRE question, intègre-la, et dis en une phrase ce que tu as choisi.
3. Choisis la prochaine question : la plus utile pour ce qui MANQUE encore (ordre conseillé : idée générale → prénom et âge → métier et lieu de vie → apparence → style visuel → personnalité → façon de parler → relation avec l'utilisateur → limites). Une seule question, courte, naturelle, en tutoyant.
4. Propose 3 réponses possibles (« choices »), courtes (6 mots max), variées et adaptées à CE persona.
5. Quand tout l'essentiel est rempli, ne pose plus de question obligatoire : propose plutôt un détail optionnel et dis que la fiche peut être créée.
Réponds en français. Ne mets jamais d'informations sur l'utilisateur réel dans la fiche.

Réponds UNIQUEMENT avec un objet JSON :
{"draft": {la fiche complète à jour, mêmes champs}, "reply": "une phrase : ce que tu as compris ou inventé (vide au tout premier message)", "question": "ta question suivante", "choices": ["…", "…", "…"]}
TXT;

    $lines = '';
    foreach (array_slice($history, -12) as $h) {
        $who = ($h['role'] ?? '') === 'user' ? 'Utilisateur' : 'Assistant';
        $lines .= $who . ' : ' . mb_substr(trim((string) ($h['content'] ?? '')), 0, 600) . "\n";
    }
    $user = ($isNew ? "MODE : création d'un nouveau persona.\n" : "MODE : compléter une fiche existante. Pose des questions uniquement sur ce qui MANQUE (champs vides, apparence peu précise, pas de prompt système ou d'image…).\n" . assist_existing_context($persona, $prompts) . "\n")
        . "FICHE EN COURS\n" . json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
        . "CONVERSATION\n" . ($lines !== '' ? $lines : "(début)\n")
        . "\nACTION : " . $action . "\n"
        . ($action === 'reply' ? "RÉPONSE DE L'UTILISATEUR : " . mb_substr(trim($answer), 0, 2000) . "\n" : '');

    $j        = assist_llm_json($system, $user, 1600, $action === 'suggest' ? 0.95 : 0.7);
    $newDraft = is_array($j['draft'] ?? null) ? $j['draft'] : [];
    $merged   = assist_merge($draft, $newDraft);
    $choices  = [];
    foreach ((array) ($j['choices'] ?? []) as $c) {
        if (is_scalar($c) && trim((string) $c) !== '' && count($choices) < 3) {
            $choices[] = mb_substr(trim((string) $c), 0, 60);
        }
    }
    $question = mb_substr(trim((string) ($j['question'] ?? '')), 0, 400);
    if ($question === '') {
        throw new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
    }

    return [
        'draft'    => $merged,
        'reply'    => mb_substr(trim((string) ($j['reply'] ?? '')), 0, 400),
        'question' => $question,
        'choices'  => $choices,
        'progress' => assist_progress($merged, $prompts),
    ];
}

/**
 * Rédaction finale : complète ce qui manque (en inventant de façon cohérente) et écrit
 * le prompt système et le prompt image.
 * @return array{draft:array, system_prompt:string, image_prompt:string}
 */
function assist_finalize(array $draft, ?array $persona = null, array $prompts = []): array
{
    $draft = assist_clean($draft);
    $system = <<<TXT
Tu finalises la fiche d'un PERSONA à partir de la FICHE EN COURS.
1. Complète les champs vides de façon cohérente et originale (n'en contredis aucun) : name, tagline (90 caractères max), description (2 à 4 phrases), tags (3 à 6, minuscules), meta (4 à 6 attributs : Âge, Métier, Ville…), looks, style, personality, voice, relation, rules.
2. Écris "system_prompt" : le prompt système de l'agent qui incarne ce persona, en français, à la 2e personne (« Tu es … »), structuré en paragraphes courts : identité, apparence, personnalité, façon de parler (avec 2 ou 3 exemples de répliques), relation avec l'utilisateur, règles et limites. Il doit permettre de jouer le personnage de façon cohérente et vivante.
3. Écris "image_prompt" : un prompt de génération d'image, en anglais, pour un portrait reconnaissable de ce persona dans son style (photo réaliste avec objectif, lumière et grain, ou le style d'illustration voulu) : segments séparés par des virgules, sujet et visage d'abord.
Réponds UNIQUEMENT avec un objet JSON :
{"draft": {la fiche complète}, "system_prompt": "…", "image_prompt": "…"}
TXT;
    $user = ($persona ? assist_existing_context($persona, $prompts) . "\n" : '')
        . "FICHE EN COURS\n" . json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $j = assist_llm_json($system, $user, 3000, 0.75);
    $final = assist_merge($draft, is_array($j['draft'] ?? null) ? $j['draft'] : []);
    // ce que l'utilisateur a déjà donné prime toujours
    foreach (ASSIST_TEXT_FIELDS as $k => $_) {
        if ($draft[$k] !== '') {
            $final[$k] = $draft[$k];
        }
    }
    if ($final['name'] === '') {
        throw new RuntimeException(t('assist.error.no_name'));
    }
    return [
        'draft'         => $final,
        'system_prompt' => mb_substr(trim((string) ($j['system_prompt'] ?? '')), 0, 12000),
        'image_prompt'  => mb_substr(trim((string) ($j['image_prompt'] ?? '')), 0, 4000),
    ];
}

/** Meta de la fiche : attributs + apparence, style… rangés en clé/valeur lisibles. */
function assist_meta_rows(array $d): array
{
    $rows = $d['meta'];
    $extra = [
        t('assist.meta.style') => $d['style'],
    ];
    $known = array_map(static fn ($r) => mb_strtolower($r['k']), $rows);
    foreach ($extra as $k => $v) {
        if ($v !== '' && !in_array(mb_strtolower($k), $known, true) && count($rows) < 20) {
            $rows[] = ['k' => $k, 'v' => mb_substr($v, 0, 200)];
        }
    }
    return $rows;
}

/** Description complète : texte + apparence, pour que la fiche soit utile d'emblée. */
function assist_description(array $d): string
{
    $desc = $d['description'];
    if ($d['looks'] !== '' && !str_contains($desc, $d['looks'])) {
        $desc = trim($desc . "\n\n" . t('assist.desc.looks') . ' ' . $d['looks']);
    }
    return mb_substr($desc, 0, 2000);
}

/** Crée le persona et ses deux prompts. Renvoie le slug. */
function assist_create(array $final): string
{
    $d    = $final['draft'];
    $slug = unique_slug($d['name']);
    $pdo  = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                $slug,
                $d['name'],
                $d['tagline'],
                assist_description($d),
                '',
                json_encode($d['tags'], JSON_UNESCAPED_UNICODE),
                json_encode(assist_meta_rows($d), JSON_UNESCAPED_UNICODE),
            ]);
        $pid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO prompts (persona_id, title, kind, body) VALUES (?,?,?,?)');
        if ($final['system_prompt'] !== '') {
            $ins->execute([$pid, t('assist.prompt.system_title'), 'system', $final['system_prompt']]);
        }
        if ($final['image_prompt'] !== '') {
            $ins->execute([$pid, t('assist.prompt.image_title'), 'image', $final['image_prompt']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $slug;
}

/**
 * Applique le résultat à une fiche existante SANS RIEN ÉCRASER : champs vides seulement,
 * attributs et tags ajoutés, prompts créés seulement s'il n'y en a pas de ce type.
 * @return list<string> libellés de ce qui a été ajouté
 */
function assist_apply(array $persona, array $prompts, array $final): array
{
    $d       = $final['draft'];
    $changes = [];
    $tagline = trim((string) $persona['tagline']);
    $desc    = trim((string) $persona['description']);
    if ($tagline === '' && $d['tagline'] !== '') {
        $tagline   = $d['tagline'];
        $changes[] = t('labels.ai_field.tagline');
    }
    if ($desc === '' && ($d['description'] !== '' || $d['looks'] !== '')) {
        $desc      = assist_description($d);
        $changes[] = t('labels.ai_field.description');
    }
    $tags = json_list($persona['tags']);
    foreach ($d['tags'] as $tg) {
        if (!in_array($tg, $tags, true) && count($tags) < 12) {
            $tags[]    = $tg;
            $changes[] = t('labels.ai_field.tags');
        }
    }
    $meta  = json_list($persona['meta']);
    $known = [];
    foreach ($meta as $r) {
        if (is_array($r) && isset($r['k'])) {
            $known[mb_strtolower((string) $r['k'])] = true;
        }
    }
    foreach (assist_meta_rows($d) as $r) {
        if (!isset($known[mb_strtolower($r['k'])]) && count($meta) < 20) {
            $meta[]    = $r;
            $known[mb_strtolower($r['k'])] = true;
            $changes[] = t('labels.ai_field.meta');
        }
    }
    // apparence : dans la description si elle était vide, sinon en attribut (si absent)
    if (!in_array(t('labels.ai_field.description'), $changes, true) && $d['looks'] !== ''
        && !isset($known[mb_strtolower(t('assist.meta.looks'))]) && count($meta) < 20) {
        $meta[]    = ['k' => t('assist.meta.looks'), 'v' => mb_substr($d['looks'], 0, 200)];
        $changes[] = t('labels.ai_field.meta');
    }
    $kinds = array_map(static fn ($p) => (string) $p['kind'], $prompts);
    $pdo   = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE personas SET tagline=?, description=?, tags=?, meta=?, updated_at=datetime("now") WHERE id=?')
            ->execute([$tagline, $desc, json_encode($tags, JSON_UNESCAPED_UNICODE), json_encode($meta, JSON_UNESCAPED_UNICODE), (int) $persona['id']]);
        $ins = $pdo->prepare('INSERT INTO prompts (persona_id, title, kind, body) VALUES (?,?,?,?)');
        if (!in_array('system', $kinds, true) && $final['system_prompt'] !== '') {
            $ins->execute([(int) $persona['id'], t('assist.prompt.system_title'), 'system', $final['system_prompt']]);
            $changes[] = t('assist.item.system');
        }
        if (!in_array('image', $kinds, true) && $final['image_prompt'] !== '') {
            $ins->execute([(int) $persona['id'], t('assist.prompt.image_title'), 'image', $final['image_prompt']]);
            $changes[] = t('assist.item.image');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return array_values(array_unique($changes));
}
