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
        "/(souviens[- ]?toi|rappelle[- ]?toi|retiens|m[ée]morise|note (bien )?que|n['’]oublie pas|garde en m[ée]moire"
        // anglais (« do you remember… » est une question, pas une demande)
        . "|(?<!do you )\\bremember (that|this|me)\\b|\\bkeep (it )?in mind\\b|\\bdon['’]?t forget\\b|\\bdo not forget\\b"
        . "|\\bnever forget\\b|\\bmake a note\\b|\\btake note\\b|\\bnote that\\b|\\bmemori[sz]e\\b|\\bwrite (it|this) down\\b"
        // espagnol
        . "|\\brecuerda (que|esto|esta|este)\\b|\\bacu[ée]rdate\\b|\\bno (te )?olvides\\b|\\bapunta (que|esto)\\b"
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
    $freq = setting('facts_frequency', 'each');
    $timing = setting('facts_timing', 'now');
    $model = setting('facts_model', 'chat');
    return [
        'frequency' => in_array($freq, ['each', 'every', 'explicit', 'off'], true) ? $freq : 'each',
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
    foreach ($messages as $m) {
        if (!in_array((string) $m['role'], ['user', 'assistant'], true)) {
            continue;
        }
        $who = $m['role'] === 'user' ? 'Utilisateur' : 'Personnage';
        $sources[] = $m;
        $transcript .= '[' . count($sources) . '] ' . $who . ' : ' . mb_substr(trim((string) $m['content']), 0, 1200) . "\n\n";
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
            . "Formule-le tel qu'il devra être rappelé plus tard.";
    }
    $system .= "\nEn plus de memories et forget, ajoute le tableau story à l'objet JSON. "
        . "Dans story uniquement, mémorise les événements concrets de la vie du persona, ses projets en cours et les moments partagés "
        . "qui comptent pour la suite. Garde seulement ce qui a été clairement raconté par le persona ou confirmé par lui "
        . "dans un message numéroté de l'échange. Ignore les salutations, les scènes hypothétiques, les plaisanteries et les simples suggestions. "
        . "Écris à la troisième personne et distingue un projet d'un fait accompli. Ne transforme pas ces éléments en faits sur l'utilisateur. "
        . 'Chaque entrée de story a la forme {"source":2,"category":"fait|projet|decision","content":"phrase courte","replaces":null}. '
        . "source désigne le numéro du message du persona qui établit le souvenir. replaces désigne l'identifiant d'un souvenir story "
        . "du même persona que la nouvelle information actualise. Omet les faits que tu as déjà sous une autre forme.";

    $user = "Mémoires existantes :\n" . ($knownLines !== '' ? $knownLines : '(aucune)')
        . "\nDernier message de l'utilisateur (le plus important) :\n" . ($lastUser !== '' ? $lastUser : '(aucun)')
        . "\nÉchange récent :\n" . $transcript;

    $cfg  = facts_llm_config($cid);
    $remote = !empty($cfg['_remote']);
    unset($cfg['_remote']);
    $msgs = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    $opts = [
        'purpose'          => 'facts',
        'temperature'      => 0.2,
        'max_tokens'       => 900,
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

    return db_tx(static function (PDO $pdo) use ($cid, $personaId, $json, $sources, $known, $cursor): array {
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
            return ['ok' => true, 'added' => [], 'updated' => 0, 'removed' => 0];
        }
        $result = facts_save_extraction($cid, $personaId, $json, $sources, $known);
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
    foreach ($current as $k) {
        $norm = facts_norm((string) $k['content']);
        if ($k['subject'] === 'story') {
            $storySeen[$norm] = (int) $k['id'];
        } else {
            $seen[$norm] = (int) $k['id'];
        }
    }

    $items = $json['memories'] ?? [];
    if (is_array($items)) {
        foreach (array_slice($items, 0, 8) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $content = trim((string) ($it['content'] ?? ''));
            if (mb_strlen($content) < 6) {
                continue;
            }
            $norm = facts_norm($content);
            $cat  = (string) ($it['category'] ?? 'fait');
            $conf = (string) ($it['confidence'] ?? 'confirme');

            $replaces = is_scalar($it['replaces'] ?? null) ? (int) $it['replaces'] : 0;
            if ($replaces > 0 && isset($knownIds[$replaces])) {
                facts_update($replaces, $content, $cat, $conf);
                $updated++;
                continue;
            }
            if (isset($seen[$norm])) {
                continue; // déjà connu
            }
            $id = facts_add($personaId, $content, $cat, $conf, $cid);
            if ($id > 0) {
                $seen[$norm] = $id;
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
    foreach (is_array($story) ? array_slice($story, 0, 5) : [] as $item) {
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
        $category = is_string($item['category'] ?? null) ? $item['category'] : 'fait';
        $replace = filter_var($item['replaces'] ?? null, FILTER_VALIDATE_INT);
        $sourceId = (int) $source['id'];
        if ($replace && isset($storyIds[$replace])) {
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
        $added[] = $content;
    }

    return ['ok' => true, 'added' => $added, 'updated' => $updated, 'removed' => $removed];
}
