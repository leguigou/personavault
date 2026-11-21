<?php
declare(strict_types=1);

/* Mémoire longue : faits sur l'utilisateur et continuité du persona, avec recherche FTS5. */

/** Catégories de souvenirs : code => libellé dans la langue de l'interface. */
function facts_categories(): array
{
    return [
        'preference' => t('labels.fact_category.preference'),
        'fait'       => t('labels.fact_category.fait'),
        'projet'     => t('labels.fact_category.projet'),
        'contrainte' => t('labels.fact_category.contrainte'),
        'decision'   => t('labels.fact_category.decision'),
    ];
}

/** Libellés des catégories envoyés au modèle : toujours en français, comme les consignes. */
function facts_categories_llm(): array
{
    return [
        'preference' => 'Préférence',
        'fait'       => 'Fait',
        'projet'     => 'Projet',
        'contrainte' => 'Contrainte',
        'decision'   => 'Décision',
    ];
}

function facts_confidences(): array
{
    return [
        'confirme'   => t('labels.fact_confidence.confirme'),
        'hypothese'  => t('labels.fact_confidence.hypothese'),
        'temporaire' => t('labels.fact_confidence.temporaire'),
    ];
}

/**
 * L'utilisateur demande-t-il explicitement de mémoriser quelque chose ?
 * « retiens que… », « remember that… », « recuerda que… » : français, anglais et espagnol,
 * quelle que soit la langue de l'interface.
 */
function facts_explicit_request(string $message): bool
{
    return (bool) preg_match(
        // français
        "/(souviens[- ]?(toi|t['’]en)|rappelle[- ]?(toi|t['’]en)|retiens|m[ée]morise|note (bien )?que|n['’]oublie (pas|jamais)|garde ([çc]a |le |cela )?en (m[ée]moire|t[êe]te)"
        . "|\\b(pourras|devras|dois|vas|faut) (te |t['’]en )souvenir\\b|\\btu (te |t['’]en )souviendras\\b"
        . "|\\bque tu (te |t['’]en )souviennes\\b|\\bpromets?[- ]moi\\b|\\btu me (le )?promets\\b|\\bje te (le )?promets\\b"
        // anglais (« do you remember… » est une question, pas une demande)
        . "|(?<!do you )\\bremember (that|this|me)\\b|\\bkeep (it )?in mind\\b|\\bdon['’]?t forget\\b|\\bdo not forget\\b"
        . "|\\bpromise me\\b|\\bi promise( you)?\\b|\\byou(['’]ll| will) remember\\b"
        . "|\\bnever forget\\b|\\bmake a note\\b|\\btake note\\b|\\bnote that\\b|\\bmemori[sz]e\\b|\\bwrite (it|this) down\\b"
        // espagnol
        . "|\\brecuerda (que|esto|esta|este)\\b|\\bacu[ée]rdate\\b|\\bno (te )?olvides\\b|\\bapunta (que|esto)\\b"
        . "|\\bpr[oó]meteme\\b|\\bme lo prometes\\b|\\bte (lo )?prometo\\b|\\bte acordar[aá]s\\b"
        . "|\\banota (que|esto)\\b|\\bten en cuenta\\b|\\bmemoriza\\b|\\bguarda en (la |tu )?memoria\\b|\\btoma nota\\b)/iu",
        $message
    );
}

function facts_list(?int $personaId = null, string $q = '', int $limit = 200): array
{
    $sql    = 'SELECT * FROM user_memories WHERE 1 = 1';
    $params = [];

    if ($personaId !== null) {
        $sql .= ' AND (persona_id = ? OR persona_id IS NULL)';
        $params[] = $personaId;
    }
    if ($q !== '') {
        $sql .= ' AND content LIKE ?';
        $params[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, $limit);

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Souvenirs durables partagés par TOUTES les conversations d'un persona (+ les globaux). */
function chat_persona_memories(int $personaId, int $limit = 20): array
{
    $stmt = db()->prepare(
        'SELECT id, category, content, confidence, persona_id, subject
         FROM user_memories
         WHERE persona_id = ? OR persona_id IS NULL
         ORDER BY updated_at DESC LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$personaId]);
    return $stmt->fetchAll();
}

function facts_add(
    int $personaId, string $content, string $category = 'fait', string $confidence = 'confirme',
    ?int $conversationId = null, ?int $storyMessageId = null
): int
{
    $content = trim($content);
    if ($content === '') {
        return 0;
    }
    $stmt = db()->prepare('INSERT INTO user_memories (persona_id, category, content, confidence, source_conversation_id, subject, source_message_id)
                           VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([
        $personaId > 0 ? $personaId : null,
        array_key_exists($category, facts_categories()) ? $category : 'fait',
        mb_substr($content, 0, 400),
        array_key_exists($confidence, facts_confidences()) ? $confidence : 'confirme',
        $conversationId,
        $storyMessageId === null ? 'user' : 'story',
        $storyMessageId,
    ]);
    return (int) db()->lastInsertId();
}

function facts_update(int $id, string $content, string $category, string $confidence): void
{
    $content = trim($content);
    if ($content === '') {
        return;
    }
    db()->prepare('UPDATE user_memories SET content = ?, category = ?, confidence = ?, updated_at = datetime("now") WHERE id = ?')
        ->execute([
            mb_substr($content, 0, 400),
            array_key_exists($category, facts_categories()) ? $category : 'fait',
            array_key_exists($confidence, facts_confidences()) ? $confidence : 'confirme',
            $id,
        ]);
}

function facts_delete(int $id): void
{
    db()->prepare('DELETE FROM user_memories WHERE id = ?')->execute([$id]);
}

/** Normalise pour comparer deux souvenirs. */
function facts_norm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/**
 * Mots porteurs de sens d'un souvenir, pour repérer deux formulations du même fait.
 * Les négations et les nombres sont gardés à part : « aime le jazz » ≠ « n'aime pas le jazz », « 30 ans » ≠ « 31 ans ».
 * @param string[] $ignore mots à ignorer en plus (prénom du persona…)
 * @return array{words:array<string,true>, neg:array<string,true>, num:array<string,true>}
 */
function facts_signature(string $s, array $ignore = []): array
{
    static $stop = ['les', 'des', 'une', 'est', 'que', 'qui', 'dans', 'avec', 'pour', 'sur', 'par', 'son', 'ses',
        'aux', 'elle', 'lui', 'leur', 'cette', 'tout', 'très', 'tres', 'être', 'etre', 'avoir', 'été', 'ete', 'aussi',
        'utilisateur', 'utilisatrice', 'personnage', 'persona', 'user', 'character', 'the', 'and', 'with', 'for', 'has',
        'was', 'his', 'her', 'their', 'that', 'usuario', 'usuaria', 'personaje', 'los', 'las', 'con', 'por', 'del'];
    static $negations = ['pas', 'plus', 'jamais', 'aucun', 'aucune', 'rien', 'personne', 'not', 'never', 'nunca', 'nada', 'ningún', 'ninguna'];

    $out = ['words' => [], 'neg' => [], 'num' => []];
    foreach (explode(' ', facts_norm($s)) as $w) {
        if ($w === '') {
            continue;
        }
        if (preg_match('/\p{N}/u', $w)) {
            $out['num'][$w] = true;
        } elseif (in_array($w, $negations, true) || $w === 'no' || $w === 'ne' || $w === 'n') {
            $out['neg'][$w === 'n' ? 'ne' : $w] = true;
        } elseif (mb_strlen($w) >= 3 && !in_array($w, $stop, true) && !in_array($w, $ignore, true)) {
            $out['words'][$w] = true;
        }
    }
    return $out;
}

/**
 * Deux souvenirs disent-ils la même chose ? Vrai quand presque tous les mots du plus court
 * se retrouvent dans l'autre (« Guillaume vit à Toulon » / « L'utilisateur s'appelle Guillaume et vit à Toulon »).
 */
function facts_similar(array $a, array $b): bool
{
    // une négation d'un côté seulement : faits opposés, pas un doublon
    if ((bool) $a['neg'] !== (bool) $b['neg']) {
        return false;
    }
    if ($a['num'] || $b['num']) {
        $na = array_keys($a['num']);
        $nb = array_keys($b['num']);
        sort($na);
        sort($nb);
        if ($na !== $nb) {
            return false;
        }
    }
    $min = min(count($a['words']), count($b['words']));
    if ($min < 2) {
        // un seul mot utile (« Eva est célibataire ») : doublon seulement si c'est exactement le même
        return $min === 1 && count($a['words']) === count($b['words']) && !array_diff_key($a['words'], $b['words']);
    }
    return count(array_intersect_key($a['words'], $b['words'])) / $min >= 0.8;
}

/** Mots du nom du persona, ignorés dans la comparaison (« Eva a promis… » = « Le personnage a promis… »). */
function facts_persona_words(int $personaId): array
{
    $st = db()->prepare('SELECT name FROM personas WHERE id = ?');
    $st->execute([$personaId]);
    return array_values(array_filter(explode(' ', facts_norm((string) $st->fetchColumn()))));
}

/**
 * Souvenir déjà présent sous une autre forme ?
 * @param array<int, array{sig:array, len:int}> $pool souvenirs existants du même sujet, par id
 * @return array{0:int, 1:bool} [id du souvenir proche ou 0, la nouvelle formulation est-elle plus complète]
 */
function facts_find_similar(array $pool, array $sig): array
{
    foreach ($pool as $id => $entry) {
        if (facts_similar($entry['sig'], $sig)) {
            return [(int) $id, count($sig['words']) > $entry['len']];
        }
    }
    return [0, false];
}

/**
 * Souvenirs pertinents pour un message (FTS5), sinon les plus récents.
 * @return array<int, array>
 */
function facts_relevant(?int $personaId, string $query, int $limit = 5, string $subject = 'user'): array
{
    // mots vides (français, anglais, espagnol) ; les mots de moins de 4 lettres sont ignorés de toute façon
    $stop = ['avec', 'pour', 'dans', 'mais', 'tout', 'tous', 'elle', 'nous', 'vous', 'ils', 'elles',
        'suis', 'est', 'sont', 'avoir', 'fait', 'faire', 'plus', 'moins', 'bien', 'tres', 'très',
        'quoi', 'comment', 'pourquoi', 'quand', 'cette', 'cela', 'celui', 'alors', 'aussi', 'chez',
        'mon', 'ton', 'son', 'mes', 'tes', 'ses', 'les', 'des', 'une', 'que', 'qui', 'pas', 'sur',
        'donc', 'comme', 'peut', 'veux', 'veut', 'dire', 'oui', 'non', 'salut', 'bonjour', 'coucou',
        // anglais
        'about', 'also', 'been', 'does', 'doing', 'from', 'have', 'hello', 'here', 'just', 'know',
        'like', 'make', 'more', 'much', 'only', 'really', 'some', 'tell', 'than', 'that', 'thanks',
        'their', 'them', 'then', 'there', 'they', 'thing', 'think', 'this', 'very', 'want', 'were',
        'what', 'when', 'where', 'which', 'will', 'with', 'would', 'your', 'yours', 'could', 'should',
        'remember', 'forget',
        // espagnol
        'ahora', 'algo', 'bueno', 'como', 'cómo', 'cuando', 'cuándo', 'desde', 'donde', 'dónde', 'ella',
        'ellas', 'ellos', 'esta', 'está', 'estas', 'estás', 'este', 'esto', 'estoy', 'gracias', 'hacer',
        'hola', 'mucho', 'muy', 'nada', 'nosotros', 'para', 'pero', 'porque', 'puedo', 'quiero', 'sobre',
        'también', 'tambien', 'tengo', 'tiene', 'todo', 'todos', 'vale', 'recuerda', 'olvides'];

    $words = preg_split('/[^a-z0-9àâäéèêëîïôöùûüçáíóúñ]+/u', mb_strtolower($query)) ?: [];
    $terms = [];
    foreach ($words as $w) {
        if (mb_strlen($w) < 4 || in_array($w, $stop, true)) {
            continue;
        }
        $terms[$w] = true;
        if (count($terms) >= 8) {
            break;
        }
    }

    $rows = [];
    if ($terms) {
        $match = implode(' OR ', array_map(fn ($t) => '"' . str_replace('"', '', $t) . '"', array_keys($terms)));
        try {
            $stmt = db()->prepare(
                'SELECT m.* FROM user_memories_fts f
                 JOIN user_memories m ON m.id = f.rowid
                 WHERE user_memories_fts MATCH ?
                   AND m.subject = ? AND (m.persona_id = ? OR (m.persona_id IS NULL AND m.subject = "user"))
                 ORDER BY rank LIMIT ' . max(1, $limit)
            );
            $stmt->execute([$match, $subject, $personaId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    if (!$rows) {
        $stmt = db()->prepare(
            'SELECT * FROM user_memories WHERE subject = ? AND (persona_id = ? OR (persona_id IS NULL AND subject = "user"))
             ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$subject, $personaId]);
        $rows = $stmt->fetchAll();
    }

    return $rows;
}

function facts_format(array $rows): string
{
    if (!$rows) {
        return '';
    }
    $cats = facts_categories_llm(); // texte envoyé au modèle : en français
    $lines = [];
    foreach ($rows as $r) {
        $cat = $cats[(string) $r['category']] ?? 'Fait';
        $lines[] = '- [' . $cat . '] ' . $r['content'];
    }
    return implode("\n", $lines);
}

/** Contexte distinct pour éviter de prendre la vie fictive du persona pour des faits sur l'utilisateur. */
function facts_context(array $rows): string
{
    $groups = ['user' => [], 'story' => []];
    foreach ($rows as $row) {
        $groups[($row['subject'] ?? 'user') === 'story' ? 'story' : 'user'][] = $row;
    }
    $parts = [];
    if ($groups['user']) {
        $parts[] = "Ce que tu sais déjà de l'utilisateur (mémoire longue, à utiliser sans le réciter) :\n" . facts_format($groups['user']);
    }
    if ($groups['story']) {
        $parts[] = "Souvenirs de ta propre histoire et de vos moments partagés. Respecte la fiche du persona et ne suppose pas qu'un projet s'est réalisé :\n"
            . facts_format($groups['story']);
    }
    return implode("\n\n", $parts);
}

/**
 * Extrait les faits durables des derniers messages et met la mémoire à jour.
 * @return array{ok:bool, added:array, updated:int, removed:int, error?:string}
 */
/**
 * Réglages globaux de l'extraction des souvenirs.
 * - frequency : each (après chaque échange) | every (tous les N messages) | explicit (sur demande) | off
 * - every     : N, nombre de nouveaux messages avant une extraction (mode every)
 * - timing    : now (dès la réponse) | idle (quand la discussion est calme depuis idle s)
 * - model     : chat (modèle de discussion, local) | remote (modèle des fiches, distant)
 */
function facts_settings(): array
{
    // par défaut tous les 6 messages : un passage sur 3 échanges trie mieux qu'un passage par réponse
    $freq = setting('facts_frequency', 'every');
    $timing = setting('facts_timing', 'now');
    $model = setting('facts_model', 'chat');
    return [
        'frequency' => in_array($freq, ['each', 'every', 'explicit', 'off'], true) ? $freq : 'every',
        'every'     => max(2, min(30, (int) setting('facts_every', '6'))),
        'timing'    => in_array($timing, ['now', 'idle'], true) ? $timing : 'now',
        'idle'      => max(10, min(600, (int) setting('facts_idle_seconds', '45'))),
        'model'     => in_array($model, ['chat', 'remote'], true) ? $model : 'chat',
    ];
}

/** Configuration du modèle qui extrait les souvenirs (distant seulement s'il est configuré). */
function facts_llm_config(int $cid = 0): array
{
    if (facts_settings()['model'] === 'remote' && llm_ready()) {
        return llm_config() + ['_remote' => true];
    }
    // local : le modèle de la conversation (déjà chargé), pas le modèle global
    return $cid > 0 ? chat_llm_config_for(chat_conv_effective($cid) ?? []) : chat_llm_config();
}

/**
 * Faut-il lancer une extraction maintenant ?
 * @return string '' si oui, sinon la raison du report
 */
function facts_should_run(int $newMessages, bool $explicit, bool $force = false): string
{
    $s = facts_settings();
    if ($force) {
        return $newMessages >= 1 ? '' : t('labels.facts_skip.no_new');
    }
    if ($s['frequency'] === 'off') {
        return t('labels.facts_skip.off');
    }
    if ($explicit) {
        return $newMessages >= 1 ? '' : t('labels.facts_skip.no_new');
    }
    return match ($s['frequency']) {
        'explicit' => t('labels.facts_skip.explicit_only'),
        'every'    => $newMessages >= $s['every'] ? '' : t('labels.facts_skip.waiting', ['count' => $newMessages, 'every' => $s['every']]),
        default    => $newMessages >= 2 ? '' : t('labels.facts_skip.not_enough'),
    };
}

function facts_extract(int $cid, int $personaId, array $messages, int $cursor, bool $explicit = false): array
{
    $transcript = '';
    $sources = [];
    $now = chat_now();
    foreach ($messages as $m) {
        if (!in_array((string) $m['role'], ['user', 'assistant'], true)) {
            continue;
        }
        $who = $m['role'] === 'user' ? 'Utilisateur' : 'Personnage';
        $sources[] = $m;
        $day = (new DateTimeImmutable((string) $m['created_at'], new DateTimeZone('UTC')))->setTimezone($now->getTimezone());
        $transcript .= '[' . count($sources) . '] (' . $day->format('Y-m-d') . ') '
            . $who . ' : ' . mb_substr(trim((string) $m['content']), 0, 1200) . "\n\n";
    }
    if (trim($transcript) === '') {
        return ['ok' => true, 'added' => [], 'updated' => 0, 'removed' => 0];
    }

    $known = facts_list($personaId, '', 40);
    $knownLines = '';
    foreach ($known as $k) {
        $knownLines .= '#' . $k['id'] . ' [' . $k['subject'] . '/' . $k['category'] . '] ' . $k['content'] . "\n";
    }

    $lastUser = '';
    foreach (array_reverse($messages) as $m) {
        if ((string) $m['role'] === 'user') {
            $lastUser = mb_substr(trim((string) $m['content']), 0, 800);
            break;
        }
    }

    $system = engine_prompt('facts');
    if ($explicit) {
        $system .= " L'UTILISATEUR DEMANDE EXPLICITEMENT DE MÉMORISER quelque chose (souviens-toi, retiens, "
            . "note…) : tu DOIS enregistrer ce fait, même s'il ne parle pas de lui, même s'il paraît anodin. "
            . "Formule-le tel qu'il devra être rappelé plus tard. S'il s'agit d'une promesse que le persona a faite, "
            . "enregistre-la dans story (voir ci-dessous).";
    }
    $system .= "\nEn plus de memories et forget, ajoute le tableau story à l'objet JSON. "
        . "Dans story uniquement, garde ce qui définit durablement le persona et votre histoire commune, et qui servira "
        . "encore dans une autre conversation : son passé et ses relations (ex, famille, célibat…), ce qu'il dit chercher, "
        . "aimer ou refuser, les moments marquants partagés avec l'utilisateur, ses promesses, et les événements futurs "
        . "précis (voyage, tournage, rendez-vous…). Pour un événement futur, écris une date absolue calculée à partir de "
        . "la date du jour (« fin novembre 2026 », jamais « fin du mois », « demain » ou « ce week-end »). "
        . "INTERDIT dans story : l'activité du moment ou de la journée (travailler, être au bureau, gérer ses réseaux, "
        . "monter des vidéos, être fatigué… l'emploi du temps s'en charge déjà), les traits évidents d'après son métier "
        . "ou sa fiche, les salutations, les scènes hypothétiques, les plaisanteries, les simples suggestions, "
        . "et tout ce qui figure déjà dans les mémoires existantes, même formulé autrement. "
        . "Garde seulement ce qui a été clairement raconté ou confirmé par le persona dans un message numéroté. "
        . "Écris à la troisième personne et distingue un projet d'un fait accompli. Ne transforme pas ces éléments en faits sur l'utilisateur. "
        . "Au plus 2 entrées story par analyse, souvent aucune. "
        . 'Chaque entrée de story a la forme {"source":2,"category":"fait|projet|decision","content":"phrase courte","replaces":null}. '
        . "source désigne le numéro du message du persona qui établit le souvenir. replaces désigne l'identifiant d'un souvenir story "
        . "du même persona que la nouvelle information actualise ou précise. "
        . "Garde AUSSI dans story les promesses et engagements que le persona prend envers l'utilisateur (« promis », "
        . "« je te promets », « je ne te mentirai jamais »…), y compris quand l'utilisateur les lui a demandés : category "
        . "\"decision\", par exemple « Le persona a promis à l'utilisateur de toujours lui dire la vérité » (avec son prénom "
        . "à la place de « Le persona »). Les promesses de l'utilisateur au persona vont dans memories (category \"decision\").";

    $topics = chat_topics_for_analysis($cid);
    $system .= "\nAjoute open_topics et closed_topics au même objet JSON, avec des tableaux vides par défaut. "
        . 'open_topics contient au plus trois sujets de CETTE discussion à reprendre plus tard, sous la forme '
        . '{"source":2,"content":"demander comment s’est passé le rendez-vous","due_on":"2026-11-23"}. '
        . "Garde seulement un engagement de revenir sur un sujet, un résultat attendu ou une question reportée "
        . "explicitement, avec un message source numéroté. Ignore les salutations, projets vagues et questions courantes. "
        . "Si le sujet est déjà suivi, repris, annulé ou réglé dans l'échange, ne l'ajoute pas. "
        . "Calcule la date à partir du jour indiqué sur le message source (par exemple « demain »), ou utilise null si aucune date n'a été donnée. "
        . "Dans closed_topics, renvoie les identifiants des sujets encore ouverts qui ont déjà été repris ou annulés dans les messages numérotés.";

    $user = 'Date du jour : ' . $now->format('Y-m-d') . "\n"
        . "Mémoires existantes :\n" . ($knownLines !== '' ? $knownLines : '(aucune)')
        . "\nSujets déjà suivis (closed=1 signifie clos) :\n" . json_encode($topics, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        . "\nDernier message de l'utilisateur (le plus important) :\n" . ($lastUser !== '' ? $lastUser : '(aucun)')
        . "\nÉchange récent :\n" . $transcript;

    $cfg  = facts_llm_config($cid);
    $remote = !empty($cfg['_remote']);
    unset($cfg['_remote']);
    $msgs = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    $opts = [
        'purpose'          => 'facts',
        'temperature'      => 0.2,
        'max_tokens'       => 1200,
        'timeout'          => 120,
        // le réglage « réflexion » concerne le modèle local de discussion
        'reasoning_effort' => $remote ? '' : setting('chat_reasoning_effort', 'none'),
    ];

    try {
        try {
            $res = llm_request($msgs, $cfg, $opts + ['json' => true]);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'response_format')) {
                $res = llm_request($msgs, $cfg, $opts + ['json' => false]);
            } else {
                throw $e;
            }
        }
        $json = llm_json($res['content']);
    } catch (Throwable $e) {
        return ['ok' => false, 'added' => [], 'updated' => 0, 'removed' => 0, 'error' => $e->getMessage()];
    }

    return db_tx(static function (PDO $pdo) use ($cid, $personaId, $json, $sources, $known, $topics, $cursor): array {
        $check = $pdo->prepare('SELECT 1 FROM conversations WHERE id = ? AND facts_cursor = ? AND memory_enabled = 1 AND facts_enabled = 1');
        $check->execute([$cid, $cursor]);
        $unchanged = (bool) $check->fetchColumn();
        // Pendant l'appel au modèle, l'utilisateur peut continuer à écrire ou modifier une réponse.
        $check = $pdo->prepare('SELECT content, version_id FROM messages WHERE conversation_id = ? AND id = ?');
        foreach ($sources as $source) {
            if (!$unchanged) {
                break;
            }
            $check->execute([$cid, $source['id']]);
            $row = $check->fetch();
            $unchanged = $row && $row['content'] === $source['content'] && $row['version_id'] === $source['version_id'];
        }
        if (!$unchanged) {
            // la conversation a bougé pendant l'appel : l'analyse sera refaite à la prochaine réponse
            return ['ok' => true, 'added' => [], 'updated' => 0, 'removed' => 0, 'stale' => true];
        }
        $result = facts_save_extraction($cid, $personaId, $json, $sources, $known);
        chat_topics_save_analysis($pdo, $cid, $json, $sources, $topics);
        $pdo->prepare('UPDATE conversations SET facts_cursor = ? WHERE id = ?')
            ->execute([(int) end($sources)['id'], $cid]);
        return $result;
    });
}

function facts_save_extraction(int $cid, int $personaId, array $json, array $sources, array $known): array
{
    $added   = [];
    $updated = 0;
    $removed = 0;

    $seen = [];
    // seuls les souvenirs MONTRÉS au modèle (ce persona + globaux) peuvent être modifiés
    // ou oubliés : un ID inventé (ou injecté dans la conversation) est ignoré
    $knownIds = [];
    $storyIds = [];
    $storySeen = [];
    $current = array_column(facts_list($personaId, '', 200), null, 'id');
    foreach ($known as $k) {
        $id = (int) $k['id'];
        if (!isset($current[$id]) || $current[$id]['content'] !== $k['content'] || $current[$id]['updated_at'] !== $k['updated_at']) {
            continue;
        }
        if ($k['subject'] === 'story' && (int) $k['persona_id'] === $personaId) {
            $storyIds[$id] = true;
        } elseif ($k['subject'] === 'user') {
            $knownIds[$id] = true;
        }
    }
    // repérage des doublons reformulés : le prénom du persona ne compte pas dans la comparaison
    $ignore = facts_persona_words($personaId);
    $userPool = [];
    $storyPool = [];
    foreach ($current as $k) {
        $norm = facts_norm((string) $k['content']);
        $sig = facts_signature((string) $k['content'], $ignore);
        if ($k['subject'] === 'story') {
            if ((int) $k['persona_id'] === $personaId) {
                $storySeen[$norm] = (int) $k['id'];
                $storyPool[(int) $k['id']] = ['sig' => $sig, 'len' => count($sig['words'])];
            }
        } else {
            $seen[$norm] = (int) $k['id'];
            $userPool[(int) $k['id']] = ['sig' => $sig, 'len' => count($sig['words'])];
        }
    }

    $items = $json['memories'] ?? [];
    if (is_array($items)) {
        foreach (array_slice($items, 0, 5) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $content = trim((string) ($it['content'] ?? ''));
            if (mb_strlen($content) < 6) {
                continue;
            }
            // un fait sur l'utilisateur vient de SES messages : ce que le persona dit de lui ne compte pas
            $sourceNo = filter_var($it['source'] ?? null, FILTER_VALIDATE_INT);
            if ($sourceNo && ($sources[$sourceNo - 1]['role'] ?? '') === 'assistant') {
                continue;
            }
            $norm = facts_norm($content);
            $sig  = facts_signature($content, $ignore);
            $cat  = (string) ($it['category'] ?? 'fait');
            $conf = (string) ($it['confidence'] ?? 'confirme');

            $replaces = is_scalar($it['replaces'] ?? null) ? (int) $it['replaces'] : 0;
            if ($replaces > 0 && isset($knownIds[$replaces])) {
                facts_update($replaces, $content, $cat, $conf);
                $userPool[$replaces] = ['sig' => $sig, 'len' => count($sig['words'])];
                $updated++;
                continue;
            }
            if (isset($seen[$norm])) {
                continue; // déjà connu
            }
            [$near, $richer] = facts_find_similar($userPool, $sig);
            if ($near > 0) {
                // déjà connu sous une autre forme : on garde la formulation la plus complète
                if ($richer && isset($knownIds[$near])) {
                    facts_update($near, $content, $cat, $conf);
                    $userPool[$near] = ['sig' => $sig, 'len' => count($sig['words'])];
                    $seen[$norm] = $near;
                    $updated++;
                }
                continue;
            }
            $id = facts_add($personaId, $content, $cat, $conf, $cid);
            if ($id > 0) {
                $seen[$norm] = $id;
                $userPool[$id] = ['sig' => $sig, 'len' => count($sig['words'])];
                $added[] = $content;
            }
        }
    }

    $forget = $json['forget'] ?? [];
    if (is_array($forget)) {
        foreach (array_slice($forget, 0, 8) as $fid) {
            $fid = is_scalar($fid) ? (int) $fid : 0;
            if ($fid > 0 && isset($knownIds[$fid])) {
                unset($knownIds[$fid]);
                facts_delete($fid);
                $removed++;
            }
        }
    }

    $story = $json['story'] ?? [];
    foreach (is_array($story) ? array_slice($story, 0, 2) : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sourceNo = filter_var($item['source'] ?? null, FILTER_VALIDATE_INT);
        $source = $sourceNo > 0 ? ($sources[$sourceNo - 1] ?? null) : null;
        $content = is_string($item['content'] ?? null) ? trim($item['content']) : '';
        if (($source['role'] ?? '') !== 'assistant' || !empty($source['reaction_reply']) || mb_strlen($content) < 6) {
            continue;
        }
        $norm = facts_norm($content);
        if (isset($storySeen[$norm])) {
            continue;
        }
        $sig = facts_signature($content, $ignore);
        $category = is_string($item['category'] ?? null) ? $item['category'] : 'fait';
        $replace = filter_var($item['replaces'] ?? null, FILTER_VALIDATE_INT);
        $sourceId = (int) $source['id'];
        if (!($replace && isset($storyIds[$replace]))) {
            [$near, $richer] = facts_find_similar($storyPool, $sig);
            if ($near > 0 && !($richer && isset($storyIds[$near]))) {
                continue; // déjà dans l'histoire du persona, sous une autre forme
            }
            $replace = $near ?: $replace;
        }
        if ($replace && isset($storyIds[$replace])) {
            $storyPool[$replace] = ['sig' => $sig, 'len' => count($sig['words'])];
            facts_update($replace, $content, $category, 'confirme');
            db()->prepare('UPDATE user_memories SET source_conversation_id = ?, source_message_id = ? WHERE id = ?')
                ->execute([$cid, $sourceId, $replace]);
            $storySeen[$norm] = $replace;
            unset($storyIds[$replace]);
            $updated++;
            continue;
        }
        $id = facts_add($personaId, $content, $category, 'confirme', $cid, $sourceId);
        $storySeen[$norm] = $id;
        $storyPool[$id] = ['sig' => $sig, 'len' => count($sig['words'])];
        $added[] = $content;
    }

    return ['ok' => true, 'added' => $added, 'updated' => $updated, 'removed' => $removed];
}

/**
 * Analyse des messages pas encore lus par la mémoire (route /chat/{id}/remember).
 * Laisse une trace dans la conversation (rôle « memory », jamais renvoyé au modèle) : ce qui
 * a été retenu, ou, si l'utilisateur l'avait explicitement demandé, pourquoi rien ne l'a été.
 */
function facts_remember(int $cid, array $conv, array $persona, bool $force = false): array
{
    $pid = (int) $persona['id'];
    $cursor = (int) ($conv['facts_cursor'] ?? 0);
    $st = db()->prepare("SELECT * FROM messages WHERE conversation_id = ? AND id > ?
                             AND godmode = 0 AND role IN ('user', 'assistant') ORDER BY id ASC");
    $st->execute([$cid, $cursor]);
    $recent = $st->fetchAll();

    // demande explicite : « souviens-toi », « promets-moi », « remember that »… (voir facts_explicit_request),
    // dans n'importe quel message pas encore analysé : si l'analyse a sauté (connexion coupée), la demande
    // n'est pas perdue quand l'utilisateur a écrit autre chose entre-temps
    $explicit = false;
    foreach ($recent as $m) {
        if ((string) $m['role'] === 'user' && facts_explicit_request((string) $m['content'])) {
            $explicit = true;
            break;
        }
    }

    $trace = static function (string $label) use ($cid): array {
        db()->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, "memory", ?)')->execute([$cid, $label]);
        return ['id' => (int) db()->lastInsertId(), 'role' => 'memory', 'content' => $label];
    };
    $failed = static fn (string $reason): array => $trace(t('labels.chat.memory_failed', ['reason' => $reason]));
    // demande explicite impossible : on le dit une seule fois, puis la demande est considérée comme lue
    $refuse = static function (string $why) use ($explicit, $recent, $cid, $pid, $failed): array {
        $out = ['ok' => true, 'added' => [], 'skipped' => $why, 'memories' => chat_persona_memories($pid)];
        if ($explicit) {
            $out['memory_msg'] = $failed($why);
            db()->prepare('UPDATE conversations SET facts_cursor = ? WHERE id = ?')->execute([(int) end($recent)['id'], $cid]);
        }
        return $out;
    };

    if ((int) $conv['facts_enabled'] !== 1 || (int) $conv['memory_enabled'] !== 1) {
        return $refuse(t('labels.facts_skip.disabled_here'));
    }
    // fréquence choisie dans les réglages (chaque échange, tous les N messages, sur demande…)
    $why = facts_should_run(count($recent), $explicit, $force);
    if ($why !== '') {
        return $refuse($why);
    }

    try {
        // jusqu'à 30 messages : en mode « tous les N », rien n'est sauté
        $res = facts_extract($cid, $pid, array_slice($recent, -30), $cursor, $explicit);
    } catch (Throwable $e) {
        if ($explicit) {
            $failed(t('labels.facts_fail.error', ['error' => mb_substr(public_error($e), 0, 160)]));
        }
        throw $e;
    }
    $res['memories'] = chat_persona_memories($pid);

    $added = $res['added'] ?? [];
    if ($added) {
        $res['memory_msg'] = $trace(implode(' · ', $added));
    } elseif ($explicit && empty($res['stale']) && (int) ($res['updated'] ?? 0) === 0) {
        // demande explicite sans résultat : on le dit plutôt que de rester muet
        $res['memory_msg'] = $failed(empty($res['ok'])
            ? t('labels.facts_fail.error', ['error' => mb_substr((string) ($res['error'] ?? ''), 0, 160)])
            : t('labels.facts_fail.nothing'));
    }
    return $res;
}

