<?php
/**
 * Relance spontanée : le persona écrit de lui-même après un long silence.
 *
 * Usage : php bin/followup.php [--dry] [--verbose]
 *   --dry     affiche ce qui serait envoyé, sans rien enregistrer
 *   --verbose détaille les conversations examinées
 *
 * À lancer par cron (toutes les 5 min). Envoie aussi les petites attentions programmées
 * après une réponse (src/followup.php). Ne fait rien si LM Studio est éteint,
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

// à la fin de chaque passage (même s'il n'y a rien à relancer) : déchargement de la mémoire de
// LM Studio après la durée d'inactivité choisie (les relances ne comptent pas comme activité)
register_shutdown_function(static function () use ($say, $dry): void {
    if ($dry) {
        return;
    }
    try {
        $done = lms_idle_unload_check();
        if ($done) {
            $say('inactivité : mémoire de LM Studio libérée (' . implode(', ', $done) . ')');
        }
    } catch (Throwable $e) {
        $say('déchargement après inactivité impossible : ' . $e->getMessage());
    }
});

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

// discussions entre personas en cours dont le processus s'est arrêté (serveur redémarré…)
try {
    $revived = duo_revive_stale();
    $revived && $say(plural($revived, 'discussion entre personas relancée', 'discussions entre personas relancées'));
} catch (Throwable $e) {
    $say('relance des discussions entre personas impossible : ' . $e->getMessage());
}

// petites attentions programmées après une réponse (elles vérifient elles-mêmes plage horaire et réglages)
try {
    foreach (attention_due_run($dry) as $aCid => $aResult) {
        $say("#{$aCid} : petite attention — {$aResult}");
    }
} catch (Throwable $e) {
    $say('petites attentions impossibles : ' . $e->getMessage());
}

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
// relances d'affilée sans réponse de l'utilisateur : au-delà, on attend qu'il écrive
$maxStreak = max(1, min(5, (int) setting('chat_followup_max_streak', '2')));

$st = lms_status();
if (!$st['reachable']) {
    $say('LM Studio injoignable — aucune relance envoyée');
    $beat('llm_down');
    exit(0);
}

// un seul modèle pour toutes les relances du passage (src/followup.php)
$fuCfg = followup_model_config($st);
if ($fuCfg === null) {
    $say('aucun modèle de discussion configuré — aucune relance envoyée');
    $beat('llm_down');
    exit(0);
}
$verbose && $say('modèle des relances : ' . $fuCfg['model'] . (!empty($st['loaded']) ? ' (déjà chargé)' : ' (modèle de base)'));

$convs = db()->query("SELECT * FROM conversations c WHERE auto_followup = 1
    AND EXISTS (SELECT 1 FROM messages m WHERE m.conversation_id = c.id AND m.role = 'user') ORDER BY id")->fetchAll();
$sent  = 0;

foreach ($convs as $conv) {
    $cid = (int) $conv['id'];

    $history = fetch_messages($cid, CHAT_MIN_RECENT, 0, true);
    $last = end($history);
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

    // sans réponse, les relances finiraient par se répondre à elles-mêmes : on s'arrête
    $stmt = db()->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND role = 'assistant' AND spontaneous = 1
        AND id > (SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ? AND role = 'user')");
    $stmt->execute([$cid, $cid]);
    $streak = (int) $stmt->fetchColumn();
    if ($streak >= $maxStreak) {
        $verbose && $say("#{$cid} : {$streak} relance(s) déjà sans réponse (maximum {$maxStreak})");
        continue;
    }

    $stmt = db()->prepare('SELECT * FROM personas WHERE id = ?');
    $stmt->execute([(int) $conv['persona_id']]);
    $persona = $stmt->fetch();
    if (!$persona) {
        continue;
    }

    $r = followup_send($conv, $persona, $history, $gap, $fuCfg, null, $dry);
    if ($r['status'] === 'dry') {
        $say("#{$cid} ({$persona['name']}) APRÈS " . chat_gap_duration($gap) . " [{$r['kind']}] : " . $r['text']);
        $sent++;
    } elseif ($r['status'] === 'sent') {
        $say("#{$cid} ({$persona['name']}) relance « {$r['kind']} » envoyée après " . chat_gap_duration($gap) . ' : ' . mb_substr($r['text'], 0, 90));
        $sent++;
    } elseif ($r['status'] === 'asleep') {
        $verbose && $say("#{$cid} : {$persona['name']} dort d'après son planning — pas de relance");
    } elseif ($r['error'] !== '') {
        $say("#{$cid} : " . $r['error']);
    }
}

$beat('done', $sent);
$say(plural($sent, 'relance', 'relances') . ($dry ? ($sent >= 2 ? ' simulées' : ' simulée') : ($sent >= 2 ? ' envoyées' : ' envoyée')));
