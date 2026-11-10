<?php
/**
 * Relance spontanée : le persona écrit de lui-même après un long silence.
 *
 * Usage : php bin/followup.php [--dry] [--verbose]
 *   --dry     affiche ce qui serait envoyé, sans rien enregistrer
 *   --verbose détaille les conversations examinées
 *
 * À lancer par cron (toutes les 30 min). Ne fait rien si LM Studio est éteint,
 * si l'heure est hors plage, ou si la conversation a déjà été relancée récemment.
 */
require __DIR__ . '/../src/bootstrap.php';

$dry     = in_array('--dry', $argv ?? [], true);
$verbose = $dry || in_array('--verbose', $argv ?? [], true);
$say     = static function (string $msg): void {
    echo date('H:i:s') . '  ' . $msg . PHP_EOL;
};

// un seul passage à la fois : si le précédent génère encore (LM Studio lent), on s'efface
is_dir(PV_ROOT . '/storage') || @mkdir(PV_ROOT . '/storage', 0775, true);
$lock = fopen(PV_ROOT . '/storage/followup.lock', 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    $say('passage précédent encore en cours — rien à faire');
    exit(0);
}

/**
 * Battement de cœur : les Réglages affichent le dernier passage et son résultat,
 * ce qui permet de voir que la tâche planifiée tourne bien (rien en simulation).
 */
$beat = static function (string $reason, int $sent = 0) use ($dry): void {
    if ($dry) {
        return;
    }
    set_setting('followup_last_run', gmdate('Y-m-d H:i:s'));
    set_setting('followup_last_result', json_encode(['reason' => $reason, 'sent' => $sent]));
};

if (setting('chat_followup_enabled', '1') !== '1') {
    $verbose && $say('relances spontanées désactivées');
    $beat('disabled');
    exit(0);
}

$now  = chat_now();
$hour = (int) $now->format('G');
$from = (int) setting('chat_followup_from', '8');
$to   = (int) setting('chat_followup_to', '23');
if ($hour < $from || $hour >= $to) {
    $verbose && $say("hors plage horaire ({$hour} h, autorisé {$from}–{$to} h)");
    $beat('off_hours');
    exit(0);
}

$idle   = max(1, (int) setting('chat_followup_idle_hours', '6'));
$minGap = max(1, (int) setting('chat_followup_min_gap_hours', '12'));

$st = lms_status();
if (!$st['reachable']) {
    $say('LM Studio injoignable — aucune relance envoyée');
    $beat('llm_down');
    exit(0);
}

$convs = db()->query('SELECT * FROM conversations WHERE auto_followup = 1 ORDER BY id')->fetchAll();
$sent  = 0;

foreach ($convs as $conv) {
    $cid = (int) $conv['id'];

    $stmt = db()->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$cid]);
    $last = $stmt->fetch();
    if (!$last) {
        $verbose && $say("#{$cid} : aucun message");
        continue;
    }

    $lastTs = strtotime((string) $last['created_at'] . ' UTC') ?: 0;
    $gap    = $now->getTimestamp() - $lastTs;
    if ($gap < $idle * 3600) {
        $verbose && $say("#{$cid} : silence de " . chat_gap_duration($gap) . " (seuil {$idle} h)");
        continue;
    }

    if ((string) $conv['last_followup_at'] !== '') {
        $lf = strtotime((string) $conv['last_followup_at'] . ' UTC') ?: 0;
        if ($now->getTimestamp() - $lf < $minGap * 3600) {
            $verbose && $say("#{$cid} : relance déjà faite il y a moins de {$minGap} h");
            continue;
        }
    }

    $stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
    $stmt->execute([(int) $conv['persona_id']]);
    $persona = $stmt->fetch();
    if (!$persona) {
        continue;
    }

    $stmt = db()->prepare('SELECT * FROM prompts WHERE persona_id = ?');
    $stmt->execute([(int) $persona['id']]);
    $prompts = $stmt->fetchAll();

    $conv    = chat_with_persona($conv, $persona);
    $history = fetch_messages($cid);
    $facts   = facts_relevant((int) $persona['id'], (string) $last['content'], 5);

    $system = chat_system_prompt($persona, $prompts, $conv, $facts)
        . "\n\n" . chat_time_context($conv, $history)
        . "\n\n" . str_replace(
            ['{persona}', '{gap}'],
            [(string) $persona['name'], chat_gap_duration($gap)],
            engine_prompt('followup')
        );

    $messages = [['role' => 'system', 'content' => $system]];
    foreach (array_slice($history, -6) as $m) {
        if (in_array((string) $m['role'], ['user', 'assistant'], true)) {
            $messages[] = ['role' => (string) $m['role'], 'content' => (string) $m['content']];
        }
    }
    $messages[] = ['role' => 'user', 'content' => '[le téléphone vibre : écris ton message spontané maintenant]'];

    try {
        $res = llm_request($messages, chat_llm_config_for($conv), [
            'temperature'      => max(0.7, (float) ($conv['temperature'] ?? 0.85)),
            'max_tokens'       => 140,
            'timeout'          => 120,
            'reasoning_effort' => setting('chat_reasoning_effort', 'none'),
        ]);
    } catch (Throwable $e) {
        $say("#{$cid} : échec génération (" . $e->getMessage() . ')');
        continue;
    }

    $text = trim((string) $res['content']);
    if ($text === '') {
        $say("#{$cid} : réponse vide");
        continue;
    }
    if (mb_strlen($text) > 320) {
        $text = mb_substr($text, 0, 320) . '…';
    }

    if ($dry) {
        $say("#{$cid} ({$persona['name']}) APRÈS " . chat_gap_duration($gap) . ' : ' . $text);
        $sent++;
        continue;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare('INSERT INTO messages (conversation_id, role, content, model) VALUES (?, "assistant", ?, ?)');
        $ins->execute([$cid, $text, (string) ($res['model'] ?? '')]);
        $pdo->prepare('UPDATE conversations SET last_followup_at = datetime("now"), unread = 1, updated_at = datetime("now") WHERE id = ?')
            ->execute([$cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $say("#{$cid} : écriture impossible (" . $e->getMessage() . ')');
        continue;
    }

    $say("#{$cid} ({$persona['name']}) relance envoyée après " . chat_gap_duration($gap) . ' : ' . mb_substr($text, 0, 90));
    $sent++;
}

$beat('done', $sent);
$say(plural($sent, 'relance', 'relances') . ($dry ? ($sent >= 2 ? ' simulées' : ' simulée') : ($sent >= 2 ? ' envoyées' : ' envoyée')));
