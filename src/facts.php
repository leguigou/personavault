<?php
declare(strict_types=1);

/* Mémoire longue : faits durables sur l'utilisateur, avec recherche FTS5. */

function facts_categories(): array
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
    return ['confirme' => 'Confirmé', 'hypothese' => 'Hypothèse', 'temporaire' => 'Temporaire'];
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
        'SELECT id, category, content, confidence, persona_id
         FROM user_memories
         WHERE persona_id = ? OR persona_id IS NULL
         ORDER BY updated_at DESC LIMIT ' . max(1, $limit)
    );
    $stmt->execute([$personaId]);
    return $stmt->fetchAll();
}

function facts_add(int $personaId, string $content, string $category = 'fait', string $confidence = 'confirme', ?int $conversationId = null): int
{
    $content = trim($content);
    if ($content === '') {
        return 0;
    }
    $stmt = db()->prepare('INSERT INTO user_memories (persona_id, category, content, confidence, source_conversation_id)
                           VALUES (?,?,?,?,?)');
    $stmt->execute([
        $personaId > 0 ? $personaId : null,
        array_key_exists($category, facts_categories()) ? $category : 'fait',
        mb_substr($content, 0, 400),
        array_key_exists($confidence, facts_confidences()) ? $confidence : 'confirme',
        $conversationId,
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
function facts_relevant(?int $personaId, string $query, int $limit = 5): array
{
    $stop = ['avec', 'pour', 'dans', 'mais', 'tout', 'tous', 'elle', 'nous', 'vous', 'ils', 'elles',
        'suis', 'est', 'sont', 'avoir', 'fait', 'faire', 'plus', 'moins', 'bien', 'tres', 'très',
        'quoi', 'comment', 'pourquoi', 'quand', 'cette', 'cela', 'celui', 'alors', 'aussi', 'chez',
        'mon', 'ton', 'son', 'mes', 'tes', 'ses', 'les', 'des', 'une', 'que', 'qui', 'pas', 'sur',
        'donc', 'comme', 'peut', 'veux', 'veut', 'dire', 'oui', 'non', 'salut', 'bonjour', 'coucou'];

    $words = preg_split('/[^a-z0-9àâäéèêëîïôöùûüç]+/u', mb_strtolower($query)) ?: [];
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
                   AND (m.persona_id = ? OR m.persona_id IS NULL)
                 ORDER BY rank LIMIT ' . max(1, $limit)
            );
            $stmt->execute([$match, $personaId]);
            $rows = $stmt->fetchAll();
        } catch (Throwable $e) {
            $rows = [];
        }
    }

    if (!$rows) {
        $stmt = db()->prepare(
            'SELECT * FROM user_memories WHERE persona_id = ? OR persona_id IS NULL
             ORDER BY updated_at DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$personaId]);
        $rows = $stmt->fetchAll();
    }

    return $rows;
}

function facts_format(array $rows): string
{
    if (!$rows) {
        return '';
    }
    $cats = facts_categories();
    $lines = [];
    foreach ($rows as $r) {
        $cat = $cats[(string) $r['category']] ?? 'Fait';
        $lines[] = '- [' . $cat . '] ' . $r['content'];
    }
    return implode("\n", $lines);
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
function facts_llm_config(): array
{
    if (facts_settings()['model'] === 'remote' && llm_ready()) {
        return llm_config() + ['_remote' => true];
    }
    return chat_llm_config();
}

/**
 * Faut-il lancer une extraction maintenant ?
 * @return string '' si oui, sinon la raison du report
 */
function facts_should_run(int $newMessages, bool $explicit, bool $force = false): string
{
    $s = facts_settings();
    if ($force) {
        return $newMessages >= 1 ? '' : 'pas de nouveau message';
    }
    if ($s['frequency'] === 'off') {
        return 'extraction automatique désactivée dans les réglages';
    }
    if ($explicit) {
        return $newMessages >= 1 ? '' : 'pas de nouveau message';
    }
    return match ($s['frequency']) {
        'explicit' => 'extraction seulement sur demande (« retiens que… »)',
        'every'    => $newMessages >= $s['every'] ? '' : 'en attente (' . $newMessages . '/' . $s['every'] . ' messages)',
        default    => $newMessages >= 2 ? '' : 'pas assez de nouveaux messages',
    };
}

function facts_extract(int $cid, int $personaId, array $messages, bool $explicit = false): array
{
    $transcript = '';
    foreach ($messages as $m) {
        if (!in_array((string) $m['role'], ['user', 'assistant'], true)) {
            continue;
        }
        $who = $m['role'] === 'user' ? 'Utilisateur' : 'Personnage';
        $transcript .= $who . ' : ' . mb_substr(trim((string) $m['content']), 0, 1200) . "\n\n";
    }
    if (trim($transcript) === '') {
        return ['ok' => true, 'added' => [], 'updated' => 0, 'removed' => 0];
    }

    $known = facts_list($personaId, '', 40);
    $knownLines = '';
    foreach ($known as $k) {
        $knownLines .= '#' . $k['id'] . ' [' . $k['category'] . '] ' . $k['content'] . "\n";
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

    $user = "Mémoires existantes :\n" . ($knownLines !== '' ? $knownLines : '(aucune)')
        . "\nDernier message de l'utilisateur (le plus important) :\n" . ($lastUser !== '' ? $lastUser : '(aucun)')
        . "\nÉchange récent :\n" . $transcript;

    $cfg  = facts_llm_config();
    $remote = !empty($cfg['_remote']);
    unset($cfg['_remote']);
    $msgs = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]];
    $opts = [
        'temperature'      => 0.2,
        'max_tokens'       => 700,
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

    $added   = [];
    $updated = 0;
    $removed = 0;

    $seen = [];
    // seuls les souvenirs MONTRÉS au modèle (ce persona + globaux) peuvent être modifiés
    // ou oubliés : un ID inventé (ou injecté dans la conversation) est ignoré
    $knownIds = [];
    foreach ($known as $k) {
        $seen[facts_norm((string) $k['content'])] = (int) $k['id'];
        $knownIds[(int) $k['id']] = true;
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

    return ['ok' => true, 'added' => $added, 'updated' => $updated, 'removed' => $removed];
}
