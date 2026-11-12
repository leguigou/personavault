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
    'history'     => 200,
    'family'      => 200,
    'friends'     => 200,
    'goals'       => 200,
    'personality' => 200,
    'voice'       => 600,
    'relation'    => 500,
    'rules'       => 600,
    'source'      => 200, // personnage existant : « Nom — Œuvre »
    'source_url'  => 300, // page de référence consultée (http/https seulement)
];

const ASSIST_BACKGROUND_FIELDS = ['history', 'family', 'friends', 'goals', 'personality'];

/** Fiche en cours nettoyée (types, longueurs, listes bornées). */
function assist_clean(array $d): array
{
    $out = [];
    foreach (ASSIST_TEXT_FIELDS as $k => $max) {
        $v       = $d[$k] ?? '';
        $v       = is_scalar($v) ? trim((string) $v) : '';
        $out[$k] = mb_substr($v, 0, $max);
        if (mb_strlen($v) > $max && in_array($k, ASSIST_BACKGROUND_FIELDS, true)) {
            $short = mb_substr($v, 0, $max - 1);
            $space = mb_strrpos($short, ' ');
            $out[$k] = rtrim($space === false ? $short : mb_substr($short, 0, $space), ',;:') . '…';
        }
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
    if ($out['source_url'] !== '' && !preg_match('~^https?://[^\s"<>]+$~i', $out['source_url'])) {
        $out['source_url'] = '';
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
    $meta = json_list($persona['meta'] ?? '[]');
    $profile = [];
    foreach ($meta as $row) {
        if (is_array($row) && is_string($row['k'] ?? null)) {
            $profile[mb_strtolower(trim($row['k']))] = $row['v'] ?? '';
        }
    }
    $fields = [];
    foreach (assist_profile_labels() as $key => $label) {
        $fields[$key] = $profile[mb_strtolower($label)] ?? '';
    }
    return assist_clean([
        'name'        => $persona['name'] ?? '',
        'tagline'     => $persona['tagline'] ?? '',
        'description' => $persona['description'] ?? '',
        'tags'        => json_list($persona['tags'] ?? '[]'),
        'meta'        => $meta,
    ] + $fields);
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
        ['key' => 'life', 'label' => t('assist.item.life'), 'done' => $d['history'] !== '' && ($d['family'] !== '' || $d['friends'] !== '') && $d['goals'] !== ''],
        ['key' => 'character', 'label' => t('assist.item.character'), 'done' => $d['personality'] !== '' && $d['voice'] !== ''],
        ['key' => 'looks', 'label' => t('assist.item.looks'), 'done' => isset($kinds['image']) || mb_strlen($d['looks']) >= 30],
        ['key' => 'rules', 'label' => t('assist.item.rules'), 'done' => $d['rules'] !== ''],
    ];
    $percent = (int) round(count(array_filter($items, static fn ($i) => $i['done'])) / count($items) * 100);
    $items[] = ['key' => 'system', 'label' => t('assist.item.system'), 'done' => isset($kinds['system']), 'auto' => !isset($kinds['system'])];
    $items[] = ['key' => 'image', 'label' => t('assist.item.image'), 'done' => isset($kinds['image']), 'auto' => !isset($kinds['image'])];
    return ['items' => $items, 'percent' => $percent];
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
function assist_turn(array $draft, array $history, string $action, string $answer, ?array $persona = null, array $prompts = [], ?array $pendingCanon = null): array
{
    $draft = assist_clean($draft);
    $isNew = $persona === null;
    // Les formulations directes ouvrent la relecture sans refaire analyser le brouillon.
    if ($action === 'reply' && $pendingCanon === null && preg_match(
        '~^(?:(?:ok|oui|c[’\x27]est bon)[,.! ]+)?(?:je valide(?: (?:la|cette) fiche)?'
        . '|(?:tu peux |on peut |je veux )?(?:valider?|enregistrer?|cr[ée]er?|appliquer?|relire) (?:la|cette) fiche'
        . '|(?:i approve|save|review|create) (?:the|this) (?:profile|card)'
        . '|(?:apruebo|guardar|revisar|crear) (?:la|esta) ficha)[.! ]*$~iu',
        trim($answer)
    )) {
        return [
            'draft' => $draft, 'reply' => '', 'question' => '', 'choices' => [],
            'progress' => assist_progress($draft, $prompts), 'review' => true,
        ];
    }

    $system = <<<TXT
Tu es un assistant chaleureux et efficace qui aide à créer la fiche d'un PERSONA : un personnage récurrent pour la génération d'images et pour un agent conversationnel qui l'incarnera.
Tu tiens à jour la FICHE EN COURS (JSON) et tu fais avancer la conversation, une question à la fois.

Champs de la fiche :
- name : prénom (et nom) ; tagline : accroche d'une ligne (90 caractères max) ; description : 2 ou 3 phrases pour situer le personnage, sans portrait détaillé ;
- tags : 3 à 6 mots-clés en minuscules ; meta : attributs clé/valeur courts (Âge, Métier, Ville, Origine, Taille…) ;
- looks : apparence physique PRÉCISE pour qu'on la reconnaisse sur les images (visage, yeux, cheveux, peau, silhouette, style vestimentaire, signes distinctifs) ;
- style : rendu visuel voulu (photo réaliste, manga, illustration, 3D…) ;
- history : un événement marquant et sa conséquence aujourd'hui ; family : proches et liens familiaux ; friends : amis, alliés ou personnes qu'il fréquente, avec la nature des liens ; goals : un désir actuel et ce qui peut l'en empêcher ; personality : traits, valeurs et une tension qui le rend humain. Une phrase courte par champ, de moins de 170 caractères. Place ces éléments dans leurs champs dédiés.
- voice : façon de parler (ton, registre, tutoiement, longueur des réponses, tics) ;
- relation : relation avec l'utilisateur et contexte des échanges ; rules : limites et règles de conduite (ce qu'elle ne fait jamais).

À chaque tour :
1. Intègre la dernière réponse de l'utilisateur dans la fiche. Il peut corriger quelque chose : applique la correction. Ne supprime rien d'autre.
2. Si ACTION = suggest : l'utilisateur manque d'inspiration. INVENTE toi-même une réponse riche, originale et cohérente avec le reste à ta DERNIÈRE question, intègre-la, et dis en une phrase ce que tu as choisi.
3. Choisis la prochaine question : la plus utile pour ce qui MANQUE encore. Après l'identité, explore ce que le personnage a vécu, qui compte pour lui et ce qu'il veut, puis sa façon de réagir, de parler et ses détails visuels. Une seule question, courte, naturelle, en tutoyant. Accepte qu'un personnage n'ait pas de famille proche ou d'amis si c'est un choix de l'utilisateur.
4. Propose 3 réponses possibles (« choices »), courtes (6 mots max), variées et adaptées à CE persona.
5. Quand tout l'essentiel est rempli, ne pose plus de question obligatoire : propose plutôt un détail optionnel et dis que la fiche peut être créée.
6. PERSONNAGE EXISTANT : si l'utilisateur veut un persona tiré d'un personnage qui existe déjà (roman, film, série, BD, manga, anime, jeu vidéo, conte, mythologie, figure historique…), renseigne "canon" : {"name": "nom usuel du personnage", "work": "œuvre ou univers d'origine"}. Sinon "canon": null. Un simple « dans le style de » ou « inspiré de » sans personnage précis n'est pas un personnage existant.
7. Si plusieurs adaptations ou incarnations plausibles changent sensiblement le personnage et que l'utilisateur n'a pas choisi, ajoute à "canon" un tableau "version_choices" contenant 2 ou 3 versions précises et distinctes. Si une version est déjà indiquée ou qu'une seule est pertinente, omets ce tableau.
8. Si la fiche en cours contient une source, respecte la version choisie et laisse les faits biographiques incertains ouverts, sauf si l'utilisateur demande une adaptation libre.
9. Si la dernière réponse demande explicitement de valider, créer, appliquer ou relire la fiche, mets "review": true, avec "question": "" et "choices": []. Applique d'abord les éventuelles corrections de cette réponse. Un simple oui à une question de contenu, une hésitation ou une validation partielle ne suffit pas : mets alors "review": false.
Réponds en français. Ne mets jamais d'informations sur l'utilisateur réel dans la fiche.

Réponds UNIQUEMENT avec un objet JSON :
{"draft": {la fiche complète à jour, mêmes champs}, "reply": "une phrase : ce que tu as compris ou inventé (vide au tout premier message)", "question": "ta question suivante", "choices": ["…", "…", "…"], "canon": null, "review": false}
TXT;

    $lines = '';
    foreach (array_slice($history, -12) as $h) {
        $who = ($h['role'] ?? '') === 'user' ? 'Utilisateur' : 'Assistant';
        $lines .= $who . ' : ' . mb_substr(trim((string) ($h['content'] ?? '')), 0, 600) . "\n";
    }
    if ($pendingCanon !== null && in_array($action, ['reply', 'suggest'], true)) {
        $res = assist_canon_fill($draft, $pendingCanon, $lines, $answer, $persona, $prompts);
        if ($res !== null) {
            return $res;
        }
    }

    $user = ($isNew ? "MODE : création d'un nouveau persona.\n" : "MODE : compléter une fiche existante. Pose des questions uniquement sur ce qui MANQUE dans la fiche ou les prompts. Si un prompt image existe, ne redemande pas l'apparence.\n" . assist_existing_context($persona, $prompts) . "\n")
        . "FICHE EN COURS\n" . json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
        . "CONVERSATION\n" . ($lines !== '' ? $lines : "(début)\n")
        . "\nACTION : " . $action . "\n"
        . ($action === 'reply' ? "RÉPONSE DE L'UTILISATEUR : " . mb_substr(trim($answer), 0, 2000) . "\n" : '');

    $j = assist_llm_json($system, $user, 2200, $action === 'suggest' ? 0.95 : 0.7);

    // personnage existant (nouveau, ou différent de celui déjà retrouvé) : on va chercher
    // ses informations et on remplit toute la fiche d'un coup
    $canon = assist_canon_ref($j['canon'] ?? null);
    if ($canon && $action === 'reply' && $canon !== $pendingCanon && !assist_same_canon($draft, $canon)) {
        $versions = array_values(array_unique(assist_choices($j['canon']['version_choices'] ?? [])));
        if (count($versions) >= 2) {
            return [
                'draft' => $draft,
                'reply' => '',
                'question' => t('assist.canon.choose_version', ['name' => $canon['name']]),
                'choices' => $versions,
                'progress' => assist_progress($draft, $prompts),
                'source' => null,
                'pending_canon' => $canon,
            ];
        }
        $res = assist_canon_fill($draft, $canon, $lines, $answer, $persona, $prompts);
        if ($res !== null) {
            return $res;
        }
    }

    $newDraft = is_array($j['draft'] ?? null) ? $j['draft'] : [];
    $merged   = assist_merge($draft, $newDraft);
    $review   = $action === 'reply' && $pendingCanon === null && ($j['review'] ?? false) === true;
    $choices  = assist_choices($j['choices'] ?? []);
    $question = mb_substr(trim((string) ($j['question'] ?? '')), 0, 400);
    if ($question === '' && !$review) {
        throw new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
    }

    return [
        'draft'    => $merged,
        'reply'    => mb_substr(trim((string) ($j['reply'] ?? '')), 0, 400),
        'question' => $review ? '' : $question,
        'choices'  => $review ? [] : $choices,
        'progress' => assist_progress($merged, $prompts),
        'source'   => null,
        'review'   => $review,
    ];
}

/** Choix proposés par le modèle : 3 au plus, courts. */
function assist_choices(mixed $raw): array
{
    $choices = [];
    foreach ((array) $raw as $c) {
        if (is_scalar($c) && trim((string) $c) !== '' && count($choices) < 3) {
            $choices[] = mb_substr(trim((string) $c), 0, 60);
        }
    }
    return $choices;
}

/** Personnage existant signalé par le modèle : {name, work} nettoyé, ou null. */
function assist_canon_ref(mixed $c): ?array
{
    if (!is_array($c) || !is_scalar($c['name'] ?? null)) {
        return null;
    }
    $name = mb_substr(trim((string) $c['name']), 0, 80);
    $work = is_scalar($c['work'] ?? null) ? mb_substr(trim((string) $c['work']), 0, 100) : '';
    return $name === '' ? null : ['name' => $name, 'work' => $work];
}

/** Libellé « Nom — Œuvre » d'un personnage existant. */
function assist_canon_label(array $canon): string
{
    return mb_substr($canon['name'] . ($canon['work'] !== '' ? ' — ' . $canon['work'] : ''), 0, 200);
}

/** Ce personnage a-t-il déjà été retrouvé pour cette fiche ? */
function assist_same_canon(array $draft, array $canon): bool
{
    $src = mb_strtolower(trim((string) ($draft['source'] ?? '')));
    return $src !== '' && str_contains($src, mb_strtolower($canon['name']));
}

/**
 * Adresse de l'API de recherche (MediaWiki) pour une langue, ou '' si la recherche
 * en ligne est désactivée (config « assist_lookup_url » vide).
 */
function assist_lookup_endpoint(string $lang): string
{
    $tpl = (string) config('assist_lookup_url', 'https://{lang}.wikipedia.org/w/api.php');
    return $tpl === '' ? '' : str_replace('{lang}', $lang, $tpl);
}

/** GET JSON court (recherche en ligne). Null en cas d'échec : la recherche est facultative. */
function assist_http_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: PersonaVault/1.0 (self-hosted persona library)'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code < 200 || $code >= 300) {
        return null;
    }
    $j = json_decode((string) $raw, true);
    return is_array($j) ? $j : null;
}

/**
 * Cherche la page de référence d'un personnage existant (Wikipédia par défaut), dans la
 * langue de l'interface puis en anglais.
 * @return array{title:string, url:string, text:string, lang:string}|null
 */
function assist_lookup(array $canon): ?array
{
    $langs = array_values(array_unique([locale(), 'en']));
    $words = array_filter(preg_split('/[\s\-]+/u', mb_strtolower($canon['name'])) ?: [], static fn ($w) => mb_strlen($w) >= 3);
    foreach ($langs as $lang) {
        $api = assist_lookup_endpoint($lang);
        if ($api === '') {
            return null;
        }
        $q   = trim($canon['name'] . ' ' . $canon['work']);
        $res = assist_http_json($api . '?' . http_build_query([
            'action' => 'query', 'list' => 'search', 'srsearch' => $q, 'srlimit' => 5,
            'format' => 'json', 'formatversion' => 2, 'utf8' => 1,
        ]));
        $hits = is_array($res['query']['search'] ?? null) ? $res['query']['search'] : [];
        // Préférer un titre qui contient le nom du personnage.
        $best = null;
        $bestScore = -1;
        foreach ($hits as $h) {
            $title = is_scalar($h['title'] ?? null) ? (string) $h['title'] : '';
            if ($title === '') {
                continue;
            }
            $score = 0;
            foreach ($words as $w) {
                $score += str_contains(mb_strtolower($title), $w) ? 1 : 0;
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$title, $score];
            }
        }
        if ($best === null || ($words && $bestScore === 0)) {
            continue;
        }
        $page = assist_http_json($api . '?' . http_build_query([
            'action' => 'query', 'prop' => 'extracts|info', 'inprop' => 'url', 'explaintext' => 1,
            'exsectionformat' => 'plain', 'redirects' => 1, 'titles' => $best,
            'format' => 'json', 'formatversion' => 2, 'utf8' => 1,
        ]));
        $p    = $page['query']['pages'][0] ?? null;
        $text = is_array($p) && is_scalar($p['extract'] ?? null) ? trim((string) $p['extract']) : '';
        if (mb_strlen($text) < 200) {
            continue;
        }
        $text = (string) preg_replace("/\n{3,}/", "\n\n", $text);
        $url  = is_scalar($p['fullurl'] ?? null) ? (string) $p['fullurl'] : '';
        return [
            'title' => mb_substr(is_scalar($p['title'] ?? null) ? (string) $p['title'] : $best, 0, 120),
            'url'   => preg_match('~^https?://~i', $url) ? mb_substr($url, 0, 300) : '',
            'text'  => mb_substr($text, 0, 9000),
            'lang'  => $lang,
        ];
    }
    return null;
}

/**
 * Remplit TOUTE la fiche d'après un personnage existant (référence en ligne si trouvée,
 * sinon connaissances du modèle). Null si le modèle ne connaît pas ce personnage :
 * la conversation reprend alors normalement.
 */
function assist_canon_fill(array $draft, array $canon, string $lines, string $answer, ?array $persona, array $prompts): ?array
{
    $ref   = assist_lookup($canon);
    $label = assist_canon_label($canon);

    $system = <<<TXT
Tu remplis la fiche d'un PERSONA tiré d'un personnage EXISTANT : {$label}.
Remplis TOUS les champs, fidèlement à l'œuvre, à partir de la RÉFÉRENCE (si elle est fournie) et de tes connaissances. Ne contredis jamais l'œuvre ; quand elle ne dit rien (façon de parler précise, limites…), déduis-le de façon cohérente avec le personnage. Si l'utilisateur a précisé une version (livre, film, série, époque de l'histoire), suis-la.
Si une version a été précisée, inscris-la dans les attributs. Si l'utilisateur te laisse choisir, prends une version de référence et indique laquelle. Si la dernière demande désigne un autre personnage, réponds {"found": false}.
- name : nom tel que dans l'œuvre ; tagline : qui il ou elle est, en une ligne (90 caractères max) ; description : 2 ou 3 phrases pour situer son rôle et le moment choisi, sans portrait détaillé ;
- tags : 3 à 6 mots-clés en minuscules ; meta : 4 à 8 attributs courts (Œuvre, Créateur ou auteur, Âge, Origine, Rôle ou métier, Affiliations…) ;
- looks : apparence canonique PRÉCISE pour les images (visage, yeux, cheveux, silhouette, tenue emblématique, signes distinctifs) ; style : rendu visuel cohérent avec l'œuvre ;
- history : événement marquant déjà vécu à cette époque ; family : liens familiaux ; friends : amis ou alliés ; goals : ce qu'il veut à ce moment de l'œuvre ; personality : traits, valeurs et contradictions. Une phrase courte par champ, de moins de 170 caractères. Garde les faits incertains vides, sauf demande explicite d'adaptation libre ;
- voice : façon de parler, expressions et tics typiques ;
- relation : par défaut, l'utilisateur est quelqu'un qu'il ou elle rencontre dans son univers ; rules : rester dans le personnage, ne pas connaître ce qui est hors de son univers, limites cohérentes avec lui ou elle.
Si tu ne connais pas ce personnage et que la référence ne le décrit pas, n'invente rien : réponds {"found": false}.
Sinon, pose UNE question d'ajustement courte en tutoyant (version de l'œuvre, moment de l'histoire, relation avec l'utilisateur…) avec 3 réponses possibles de 6 mots max.
Réponds en français, UNIQUEMENT avec un objet JSON :
{"found": true, "draft": {la fiche complète}, "reply": "une phrase : ce que tu as retrouvé et rempli", "question": "…", "choices": ["…", "…", "…"]}
TXT;
    $user = ($persona ? "MODE : compléter une fiche existante (ne rien contredire).\n" . assist_existing_context($persona, $prompts) . "\n" : '')
        . "FICHE EN COURS\n" . json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
        . "CONVERSATION\n" . ($lines !== '' ? $lines : "(début)\n")
        . "DERNIÈRE DEMANDE DE L'UTILISATEUR : " . mb_substr(trim($answer), 0, 2000) . "\n\n"
        . ($ref
            ? "RÉFÉRENCE (" . $ref['title'] . ", " . $ref['url'] . ")\n" . $ref['text'] . "\n"
            : "RÉFÉRENCE : aucune trouvée en ligne, utilise tes connaissances.\n");

    $j = assist_llm_json($system, $user, 2600, 0.6);
    if (empty($j['found']) || !is_array($j['draft'] ?? null)) {
        return null;
    }
    $new               = $j['draft'];
    $new['source']     = $label;
    $new['source_url'] = $ref['url'] ?? '';
    $merged            = assist_merge($draft, $new);
    $question          = mb_substr(trim((string) ($j['question'] ?? '')), 0, 400);
    if ($question === '') {
        $question = t('assist.canon.question');
    }
    return [
        'draft'    => $merged,
        'reply'    => mb_substr(trim((string) ($j['reply'] ?? '')), 0, 400),
        'question' => $question,
        'choices'  => assist_choices($j['choices'] ?? []),
        'progress' => assist_progress($merged, $prompts),
        'source'   => $ref
            ? ['label' => t('assist.canon.found', ['title' => $ref['title']]), 'url' => $ref['url']]
            : ['label' => t('assist.canon.memory', ['name' => $canon['name']]), 'url' => ''],
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
1. Complète les champs vides de façon cohérente avec la fiche. La description situe le personnage en 2 ou 3 phrases. Le portrait détaillé va dans looks pour préparer l'image. Réserve meta aux faits courts comme l'âge, le métier ou la ville. Dans history, family, friends, goals et personality, donne des éléments précis qui peuvent se manifester en conversation, en 200 caractères maximum par champ. Préserve les choix explicites, y compris l'absence de proches. Complète aussi name, tagline, tags, style, voice, relation et rules.
2. Écris "system_prompt" en français, à la 2e personne (« Tu es … »). La description et les attributs sont déjà transmis au modèle de discussion. Concentre le prompt sur ce qui guide le jeu : comment le personnage parle, se comporte avec l'interlocuteur et réagit à un imprévu, une contradiction ou un sujet qui le touche. Fais sentir ses motivations et ses relations quand elles deviennent pertinentes. Donne 2 ou 3 courtes répliques illustrant des réactions différentes. Garde les limites utiles, sans répéter le portrait ni réciter la biographie.
3. Écris "image_prompt" en anglais, à partir de looks et style. Décris le sujet, les traits distinctifs, la tenue, l'expression et les éléments de cadrage, de lumière ou de décor utiles à l'image. Utilise des détails visibles plutôt que de raconter l'histoire ou de lister des traits de caractère abstraits.
Réponds UNIQUEMENT avec un objet JSON :
{"draft": {la fiche complète}, "system_prompt": "…", "image_prompt": "…"}
TXT;
    if ($draft['source'] !== '') {
        $system .= "\nCe persona est un personnage EXISTANT (" . $draft['source'] . ") : respecte la version et l'époque choisies. Laisse vides les faits biographiques incertains, sauf demande explicite d'adaptation libre. Le prompt système commence par « Tu es " . $draft['name'] . ", … » en situant l'œuvre ; le prompt image reprend l'apparence de cette version.";
    }
    $user = ($persona ? assist_existing_context($persona, $prompts) . "\n" : '')
        . "FICHE EN COURS\n" . json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $j = assist_llm_json($system, $user, 3600, $draft['source'] !== '' ? 0.6 : 0.75);
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

/** Champs qui seront enregistrés et que l'utilisateur peut relire ou corriger. */
function assist_review_fields(array $final, ?array $persona, array $prompts): array
{
    $d = $final['draft'];
    $attributeLabels = assist_profile_labels();
    $fields = array_intersect_key($d, array_flip(['name', 'tagline', 'description']) + $attributeLabels);
    $fields['tags'] = implode(', ', $d['tags']);
    $dedicated = [];
    foreach ($attributeLabels as $key => $label) {
        if ($d[$key] !== '') {
            $dedicated[] = mb_strtolower($label);
        }
    }
    $reviewMeta = array_filter($d['meta'], static fn (array $row): bool => !in_array(mb_strtolower($row['k']), $dedicated, true));
    $fields['meta'] = meta_to_text($reviewMeta);
    $fields['system_prompt'] = $final['system_prompt'];
    $fields['image_prompt'] = $final['image_prompt'];
    if ($d['source'] === '' && $d['source_url'] === '') {
        unset($fields['source'], $fields['source_url']);
    }
    if ($persona === null) {
        return $fields;
    }

    unset($fields['name']);
    foreach (['tagline', 'description'] as $key) {
        if (trim((string) $persona[$key]) !== '') {
            unset($fields[$key]);
        }
    }
    $existing = array_map(static fn (array $row): string => mb_strtolower((string) ($row['k'] ?? '')), array_filter(json_list($persona['meta']), 'is_array'));
    $fields['tags'] = implode(', ', array_diff($d['tags'], json_list($persona['tags'])));
    $fields['meta'] = meta_to_text(array_filter($reviewMeta, static fn (array $row): bool => !in_array(mb_strtolower($row['k']), $existing, true)));
    foreach ($prompts as $prompt) {
        unset($fields[$prompt['kind'] . '_prompt']);
    }
    foreach ($attributeLabels as $key => $label) {
        if (in_array(mb_strtolower($label), $existing, true)) {
            unset($fields[$key]);
        }
    }
    return $fields;
}

/** Convertit la relecture en fiche à enregistrer, sans nouvel appel au modèle. */
function assist_review_final(array $fields, ?array $persona): array
{
    $fields = array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $fields);
    $draft = assist_clean(array_replace($fields, [
        'name' => $persona['name'] ?? $fields['name'] ?? '',
        'tags' => parse_tags($fields['tags'] ?? ''),
        'meta' => parse_meta($fields['meta'] ?? ''),
    ]));
    if ($draft['name'] === '') {
        throw new RuntimeException(t('assist.error.no_name'));
    }
    return [
        'draft' => $draft,
        'system_prompt' => mb_substr($fields['system_prompt'] ?? '', 0, 12000),
        'image_prompt' => mb_substr($fields['image_prompt'] ?? '', 0, 4000),
    ];
}

/** Champs dédiés qui sont enregistrés comme attributs et lus dans le chat. */
function assist_profile_labels(): array
{
    return [
        'history' => t('js.assist.f.history'), 'family' => t('js.assist.f.family'),
        'friends' => t('js.assist.f.friends'), 'goals' => t('js.assist.f.goals'),
        'personality' => t('js.assist.f.personality'), 'style' => t('assist.meta.style'),
        'source' => t('assist.meta.source'), 'source_url' => t('assist.meta.source_url'),
    ];
}

/** Attributs factuels et champs dédiés du persona. */
function assist_meta_rows(array $d): array
{
    $rows = $d['meta'];
    $known = array_map(static fn ($r) => mb_strtolower($r['k']), $rows);
    foreach (assist_profile_labels() as $key => $k) {
        $v = $d[$key];
        if ($v !== '' && !in_array(mb_strtolower($k), $known, true) && count($rows) < 20) {
            $rows[] = ['k' => $k, 'v' => mb_substr($v, 0, 200)];
        }
    }
    return $rows;
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
                $d['description'],
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
    $tagline = (string) $persona['tagline'];
    $desc    = (string) $persona['description'];
    if (trim($tagline) === '' && $d['tagline'] !== '') {
        $tagline   = $d['tagline'];
        $changes[] = t('labels.ai_field.tagline');
    }
    if (trim($desc) === '' && $d['description'] !== '') {
        $desc      = $d['description'];
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
