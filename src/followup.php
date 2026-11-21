<?php
declare(strict_types=1);

/*
 * Messages spontanés (bin/followup.php) : quel genre de message le persona envoie.
 *
 * Sans indication, le modèle prolonge le dernier sujet. Chaque réveil reçoit donc un type
 * imposé, choisi au hasard (avec des poids) parmi ceux qui n'ont pas servi aux 3 derniers
 * réveils de la conversation : prendre des nouvelles de l'utilisateur à partir d'un souvenir
 * sur lui, une anecdote de sa journée, un souvenir partagé, une proposition, son humeur,
 * une découverte, ou plus rarement un rebond sur le dernier sujet (le seul type où le
 * « dernier sujet » est rappelé).
 */

const FOLLOWUP_MEMORY = 3; // types récents à éviter

/**
 * Types de réveil : poids du tirage et consigne (« {fact} », « {date} » remplacés).
 * @return array<string, array{weight:int, needs:string, text:string}>
 */
function followup_kinds(): array
{
    return [
        'nouvelles'   => ['weight' => 3, 'needs' => 'user',
            'text' => "Cette fois, prends de ses nouvelles à propos de ce qu'il ou elle t'a confié ({date}) : « {fact} ». "
                . "Pose une question naturelle, comme quelqu'un qui s'en souvient, sans recopier la phrase."],
        'anecdote'    => ['weight' => 3, 'needs' => '',
            'text' => 'Cette fois, raconte un petit moment de ta journée, cohérent avec ta fiche (ton métier, ton quotidien, '
                . 'ton entourage) : un détail concret, pas une généralité.'],
        'souvenir'    => ['weight' => 2, 'needs' => 'story',
            'text' => 'Cette fois, évoque ce moment de votre histoire, comme une pensée qui te revient : « {fact} ».'],
        'proposition' => ['weight' => 2, 'needs' => '',
            'text' => 'Cette fois, propose quelque chose : une sortie, une activité, un projet ou un petit jeu, '
                . "en lien avec ce que tu sais de lui ou d'elle."],
        'humeur'      => ['weight' => 2, 'needs' => '',
            'text' => 'Cette fois, partage ton humeur du moment et ce qui la provoque, en une phrase sincère.'],
        'decouverte'  => ['weight' => 2, 'needs' => '',
            'text' => "Cette fois, partage une découverte qui t'a fait penser à lui ou à elle : une chanson, un lieu, "
                . "un film, une photo que tu viens de prendre, une info."],
        'rebond'      => ['weight' => 1, 'needs' => '',
            'text' => 'Cette fois, reviens sur le dernier sujet de votre conversation, avec une idée ou une question nouvelle.'],
    ];
}

/**
 * Un souvenir pour le réveil : sur l'utilisateur ($subject 'user') ou sur votre histoire
 * ('story'). Parmi les 8 plus récents, au hasard, en écartant ceux que les 3 derniers
 * réveils ont déjà évoqués.
 */
function followup_fact(int $cid, int $pid, string $subject): ?array
{
    $stmt = db()->prepare("SELECT * FROM user_memories WHERE subject = ? AND confidence <> 'hypothese'
        AND (persona_id = ? OR (persona_id IS NULL AND subject = 'user')) ORDER BY updated_at DESC, id DESC LIMIT 8");
    $stmt->execute([$subject, $pid]);
    $facts = $stmt->fetchAll();
    $stmt = db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND spontaneous = 1 ORDER BY id DESC LIMIT 3");
    $stmt->execute([$cid]);
    $recent = array_map(static fn ($c): array => text_words((string) $c, 4), $stmt->fetchAll(PDO::FETCH_COLUMN));
    $facts = array_values(array_filter($facts, static function (array $f) use ($recent): bool {
        foreach ($recent as $words) {
            if (duo_overlap(text_words((string) $f['content'], 4), $words) >= 0.3) {
                return false;
            }
        }
        return true;
    }));
    return $facts ? $facts[random_int(0, count($facts) - 1)] : null;
}

/**
 * Type du prochain réveil et sa consigne.
 * Réglage caché chat_followup_force_kind : impose un type (banc de tests).
 * @return array{kind:string, text:string, topic:bool}
 */
function followup_pick(array $conv, int $pid): array
{
    $kinds = followup_kinds();
    $cid = (int) $conv['id'];
    $recent = array_slice(array_filter(explode(',', (string) ($conv['followup_kinds'] ?? ''))), -FOLLOWUP_MEMORY);
    $material = ['user' => followup_fact($cid, $pid, 'user'), 'story' => followup_fact($cid, $pid, 'story')];

    $forced = setting('chat_followup_force_kind', '');
    $pool = [];
    foreach ($kinds as $key => $k) {
        if (($k['needs'] === '' || $material[$k['needs']]) && !in_array($key, $recent, true)) {
            $pool[$key] = $k['weight'];
        }
    }
    if (isset($kinds[$forced])) {
        $kind = $forced;
    } elseif (!$pool) {
        $kind = 'anecdote';
    } else {
        $roll = random_int(1, array_sum($pool));
        foreach ($pool as $kind => $weight) {
            if (($roll -= $weight) <= 0) {
                break;
            }
        }
    }

    $fact = $kinds[$kind]['needs'] !== '' ? $material[$kinds[$kind]['needs']] : null;
    $text = $kinds[$kind]['text'];
    if ($fact) {
        $text = str_replace(['{fact}', '{date}'], [trim((string) $fact['content']), chat_llm_date((string) $fact['updated_at'])], $text);
    } elseif ($kinds[$kind]['needs'] !== '') {
        $kind = 'anecdote'; // type imposé sans souvenir disponible
        $text = $kinds['anecdote']['text'];
    }
    if ($kind !== 'rebond') {
        $text .= ' Ne reprends pas le dernier sujet de la conversation.';
    }
    return ['kind' => $kind, 'text' => $text, 'topic' => $kind === 'rebond'];
}

/** Retient le type utilisé (les FOLLOWUP_MEMORY derniers). */
function followup_remember(int $cid, string $previous, string $kind): void
{
    $kinds = array_slice([...array_filter(explode(',', $previous)), $kind], -FOLLOWUP_MEMORY);
    db()->prepare('UPDATE conversations SET followup_kinds = ? WHERE id = ?')->execute([implode(',', $kinds), $cid]);
}

/**
 * Modèle des messages spontanés, sans rien décharger ni charger : celui déjà en mémoire dans
 * LM Studio s'il y en a un, sinon le modèle de base des Réglages. Le modèle propre au persona
 * ou à la conversation est ignoré : sinon chaque relance ferait décharger puis recharger.
 * null si aucun modèle n'est configuré.
 */
function followup_model_config(array $status): ?array
{
    $cfg = chat_llm_config();
    if (!empty($status['loaded'])) {
        $cfg['model'] = (string) ($status['loaded'][0]['key'] ?: $status['loaded'][0]['id']);
    }
    return (string) $cfg['model'] !== '' ? $cfg : null;
}

/**
 * Génère et enregistre un message spontané. $pick : type imposé (sinon tiré par followup_pick).
 * Le message n'est enregistré que si la conversation n'a pas bougé pendant la génération.
 * @return array{status:string, kind:string, text:string, error:string}
 *         status : sent, dry (simulation), skip (conversation changée), asleep (dort d'après
 *         son planning) ou error
 */
function followup_send(array $conv, array $persona, array $history, int $gap, array $cfg, ?array $pick = null, bool $dry = false): array
{
    $cid  = (int) $conv['id'];
    $last = end($history);
    $stmt = db()->prepare('SELECT * FROM prompts WHERE persona_id = ?');
    $stmt->execute([(int) $persona['id']]);
    $prompts = $stmt->fetchAll();

    // emploi du temps (src/planning.php) : pas de message spontané pendant son sommeil
    $now = chat_now();
    $scene = chat_scene_now($conv, $now);
    $planState = planning_now(planning_body($prompts), (int) $persona['id'], $scene);
    if ($planState !== null && $planState['sleeping']) {
        return ['status' => 'asleep', 'kind' => (string) ($pick['kind'] ?? ''), 'text' => '', 'error' => ''];
    }
    $planning = planning_context($prompts, (int) $persona['id'], $scene);

    $conv = chat_with_persona($conv, $persona);
    // genre de message imposé (nouvelles, anecdote, souvenir, proposition…), différent des
    // derniers réveils : sans lui, le modèle prolonge le dernier sujet
    $dueTopic = $pick === null ? chat_topic_due($conv, $now) : null;
    $pick ??= $dueTopic === null ? followup_pick($conv, (int) $persona['id']) : [
        'kind' => 'sujet',
        'text' => chat_topic_context($dueTopic),
        'topic' => true,
    ];
    $out = ['status' => 'error', 'kind' => $pick['kind'], 'text' => '', 'error' => ''];
    $factsOn = (int) $conv['memory_enabled'] === 1 && (int) $conv['facts_enabled'] === 1;
    // souvenirs liés au dernier message seulement pour un rebond ; sinon les plus récents
    $factsQuery = $pick['topic'] && $last ? chat_history_text($last) : '';
    $facts = $factsOn ? array_merge(
        facts_relevant((int) $persona['id'], $factsQuery),
        facts_relevant((int) $persona['id'], $factsQuery, 3, 'story')
    ) : [];
    $feelings = feel_context($cid, $persona);

    $system = chat_system_prompt($persona, $prompts, $conv, $facts)
        . "\n\n" . chat_time_context($conv, $history, true, $pick['topic'], $now)
        . ($planning !== '' ? "\n\n" . $planning : '')
        . ($feelings !== '' ? "\n\n" . $feelings : '')
        . "\n\n" . str_replace(
            ['{persona}', '{gap}'],
            [(string) $persona['name'], chat_gap_duration($gap)],
            engine_prompt('followup')
        )
        . "\n\n" . $pick['text']
        . followup_no_repeat($cid);

    $messages = [['role' => 'system', 'content' => $system]];
    $lastUserMessage = '';
    foreach ($history as $m) {
        $content = trim(chat_history_text($m) . ($m['image'] !== '' ? ' [photo jointe]' : ''));
        $messages[] = ['role' => (string) $m['role'], 'content' => $content];
        if ($m['role'] === 'user') {
            $lastUserMessage = $content;
        }
    }
    $messages[] = ['role' => 'user', 'content' => '[le téléphone vibre : écris ton message spontané maintenant]'];

    try {
        llm_log_context(['cid' => $cid]);
        $res = llm_request($messages, $cfg, [
            'purpose'          => 'followup',
            'temperature'      => max(0.7, (float) ($conv['temperature'] ?? 0.85)),
            'max_tokens'       => $pick['kind'] === 'attention' ? 90 : 140,
            'timeout'          => 120,
            'reasoning_effort' => setting('chat_reasoning_effort', 'none'),
        ]);
    } catch (Throwable $e) {
        $out['error'] = 'échec génération (' . $e->getMessage() . ')';
        return $out;
    }

    $text = trim((string) $res['content']);
    if ($text === '') {
        $out['error'] = 'réponse vide';
        return $out;
    }
    if (mb_strlen($text) > 320) {
        $text = mb_substr($text, 0, 320) . '…';
    }
    $out['text'] = $text;
    if ($dry) {
        $out['status'] = 'dry';
        return $out;
    }

    $chatLock = chat_try_lock($cid);
    if ($chatLock === false) {
        $out['status'] = 'skip';
        return $out;
    }
    try {
        // Les échanges récents ou le réglage peuvent avoir changé pendant la génération.
        $stmt = db()->prepare("SELECT klipy_formats FROM conversations WHERE id = ? AND auto_followup = 1
                                AND NOT EXISTS (SELECT 1 FROM settings WHERE key = 'chat_followup_enabled' AND value <> '1')");
        $stmt->execute([$cid]);
        $formats = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($formats === false || fetch_messages($cid, CHAT_MIN_RECENT, 0, true) !== $history) {
            $out['status'] = 'skip';
            return $out;
        }
        $conv['klipy_formats'] = (int) $formats;
        $klipyUrl = $pick['kind'] === 'attention' ? ''
            : chat_spontaneous_klipy($cid, $persona, $conv, $lastUserMessage, $text, modelConfig: $cfg);
        $sent = db_tx(static function (PDO $pdo) use ($cid, $text, $res, $klipyUrl, $pick, $conv, $dueTopic, $now): bool {
            if ($dueTopic !== null) {
                $check = $pdo->prepare('SELECT id, memory_enabled, facts_enabled FROM conversations WHERE id = ?');
                $check->execute([$cid]);
                $current = $check->fetch();
                if (!$current || chat_topic_due($current, $now) !== $dueTopic || !chat_topic_close($pdo, $cid, (int) $dueTopic['id'])) {
                    return false;
                }
            }
            $pdo->prepare('INSERT INTO messages (conversation_id, role, content, model, klipy_url, spontaneous) VALUES (?, "assistant", ?, ?, ?, 1)')
                ->execute([$cid, $text, (string) ($res['model'] ?? ''), $klipyUrl]);
            if ($pick['kind'] === 'attention') {
                // une petite attention ne repousse pas la relance habituelle après un long silence
                $pdo->prepare('UPDATE conversations SET unread = 1, updated_at = datetime("now") WHERE id = ?')->execute([$cid]);
            } else {
                $pdo->prepare('UPDATE conversations SET last_followup_at = datetime("now"), unread = 1, updated_at = datetime("now") WHERE id = ?')
                    ->execute([$cid]);
                followup_remember($cid, (string) ($conv['followup_kinds'] ?? ''), $pick['kind']);
            }
            return true;
        });
        $out['status'] = $sent ? 'sent' : 'skip';
    } catch (Throwable $e) {
        $out['error'] = 'écriture impossible (' . $e->getMessage() . ')';
    } finally {
        fclose($chatLock);
    }
    return $out;
}

/**
 * Rappel des dernières relances de la conversation, pour que la suivante ne les répète pas
 * ('' s'il n'y en a pas encore).
 */
function followup_no_repeat(int $cid): string
{
    $stmt = db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND role = 'assistant' AND spontaneous = 1
                           ORDER BY id DESC LIMIT 3");
    $stmt->execute([$cid]);
    $previous = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $content) {
        $content = trim(preg_replace('/\s+/u', ' ', (string) $content) ?? '');
        if ($content !== '') {
            $previous[] = '- « ' . mb_substr($content, 0, 200) . ' »';
        }
    }
    if (!$previous) {
        return '';
    }
    return "\n\nTes derniers messages spontanés dans cette conversation, du plus récent au plus ancien :\n"
        . implode("\n", $previous)
        . "\nN'en reprends ni le sujet ni les formulations. Parle d'autre chose, ou raconte une suite nouvelle "
        . "de ce qui s'y passait.";
}

/*
 * Petite attention : quelques minutes après une réponse du persona, il arrive qu'il renvoie
 * de lui-même un petit mot, comme une pensée après coup. Probabilité réglable (Réglages ›
 * Chat › Messages spontanés, chat_attention_chance en %). Le tirage a lieu après la réponse ;
 * la tâche planifiée (bin/followup.php, toutes les 5 min) envoie le mot une fois l'heure
 * prévue passée, soit 3 à 8 min après la réponse. Annulée si l'utilisateur a écrit entre-temps.
 */

const ATTENTION_DELAY = 180; // secondes minimum entre la réponse et la petite attention

/** Consigne du type « attention ». */
function attention_pick(): array
{
    return [
        'kind'  => 'attention',
        'text'  => "Cette fois, juste une petite attention, quelques minutes après votre échange : une seule phrase courte "
            . "et chaleureuse, comme une pensée après coup (un mot gentil, un détail qui t'est revenu de ce que vous "
            . "vous êtes dit, une petite taquinerie ou un emoji). Ne pose pas de nouvelle question et ne relance pas la discussion.",
        'topic' => false,
    ];
}

/** Après une réponse du persona : tire au sort une petite attention et la programme. */
function attention_maybe(int $cid): bool
{
    $chance = max(0, min(100, (int) setting('chat_attention_chance', '20')));
    if ($chance === 0 || setting('chat_followup_enabled', '1') !== '1' || random_int(1, 100) > $chance) {
        return false;
    }
    $stmt = db()->prepare("UPDATE conversations SET attention_due = datetime('now', ?), attention_msg = ?
                           WHERE id = ? AND auto_followup = 1");
    $stmt->execute(['+' . ATTENTION_DELAY . ' seconds', attention_last_id($cid), $cid]);
    return $stmt->rowCount() > 0;
}

/**
 * Petites attentions arrivées à échéance (appelé par la tâche planifiée). Chacune n'est
 * tentée qu'une fois : sans les conditions réunies, elle est abandonnée.
 * @return array<int,string> résultat par conversation (voir attention_run)
 */
function attention_due_run(bool $dry = false): array
{
    $rows = db()->query("SELECT id, attention_msg FROM conversations
                         WHERE attention_due <> '' AND attention_due <= datetime('now') ORDER BY attention_due")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $cid = (int) $row['id'];
        if ($dry) {
            $out[$cid] = 'due';
            continue;
        }
        // on réserve d'abord : deux passages simultanés n'envoient qu'un mot
        $stmt = db()->prepare("UPDATE conversations SET attention_due = '' WHERE id = ? AND attention_due <> ''");
        $stmt->execute([$cid]);
        if ($stmt->rowCount() === 0) {
            continue;
        }
        $out[$cid] = attention_run($cid, (int) $row['attention_msg']);
    }
    return $out;
}
/**
 * Envoie la petite attention si la conversation n'a pas bougé depuis $lastId (le dernier
 * message au moment du tirage) : ce doit toujours être la réponse du persona, pas un message
 * spontané, et l'heure doit être dans la plage des relances.
 * @return string résultat (sent, moved, off, off_hours, llm_down, ou une erreur)
 */
function attention_run(int $cid, int $lastId): string
{
    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ? AND auto_followup = 1');
    $stmt->execute([$cid]);
    $conv = $stmt->fetch();
    if (!$conv || setting('chat_followup_enabled', '1') !== '1') {
        return 'off';
    }
    $history = fetch_messages($cid, CHAT_MIN_RECENT, 0, true);
    $last = end($history);
    if (!$last || (int) $last['id'] !== $lastId || $last['role'] !== 'assistant' || (int) $last['spontaneous'] === 1) {
        return 'moved';
    }
    $now  = chat_now();
    $hour = (int) $now->format('G');
    if ($hour < (int) setting('chat_followup_from', '8') || $hour >= (int) setting('chat_followup_to', '23')) {
        return 'off_hours';
    }
    $st = lms_status();
    $cfg = $st['reachable'] ? followup_model_config($st) : null;
    if ($cfg === null) {
        return 'llm_down';
    }
    $stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
    $stmt->execute([(int) $conv['persona_id']]);
    $persona = $stmt->fetch();
    if (!$persona) {
        return 'off';
    }
    $gap = max(60, $now->getTimestamp() - (strtotime((string) $last['created_at'] . ' UTC') ?: 0));
    $r = followup_send($conv, $persona, $history, $gap, $cfg, attention_pick());
    return $r['status'] === 'sent' ? 'sent' : ($r['error'] ?: $r['status']);
}

/** Dernier message (utilisateur ou persona) de la conversation, 0 s'il n'y en a pas. */
function attention_last_id(int $cid): int
{
    $stmt = db()->prepare("SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ? AND role IN ('user', 'assistant')");
    $stmt->execute([$cid]);
    return (int) $stmt->fetchColumn();
}
