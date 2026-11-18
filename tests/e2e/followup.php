<?php
declare(strict_types=1);

// Vérifie la tâche planifiée sur la copie temporaire et son faux modèle.
[$script, $appDir] = $argv;
require $appDir . '/src/bootstrap.php';

$pdo = db();
$pdo->exec('UPDATE conversations SET auto_followup = 0');
$pdo->exec("INSERT INTO conversations (persona_id, title, memory_enabled)
            SELECT id, 'Relance de test', 0 FROM personas ORDER BY id LIMIT 1");
$cid = (int) $pdo->lastInsertId();
facts_add((int) chat_conv_effective($cid)['persona_id'], 'Connaît Lyon', 'fait', 'confirme', $cid);
$insert = $pdo->prepare("INSERT INTO messages (conversation_id, role, content, created_at)
                         VALUES (?, ?, ?, datetime('now', ?))");
$expected = [];
for ($i = 1; $i <= 7; $i++) {
    $role = $i % 2 ? 'user' : 'assistant';
    $content = $i === 7 ? 'Je fête cette réussite à Lyon !' : 'Échange sur Lyon ' . $i;
    $insert->execute([$cid, $role, $content, '-8 hours']);
    $insert->execute([$cid, 'memory', 'Notification interne', '+0 hours']);
    $expected[] = ['role' => $role, 'content' => $content];
}
$pdo->prepare("UPDATE conversations SET summary = 'Sujet : ancienne destination' WHERE id = ?")->execute([$cid]);
set_setting('chat_followup_enabled', '1');
set_setting('chat_followup_from', '0');
set_setting('chat_followup_to', '24');
set_setting('chat_followup_idle_hours', '6');
set_setting('chat_followup_min_gap_hours', '12');
set_setting('chat_followup_force_kind', 'rebond'); // vérifications du « dernier sujet » ci-dessous
set_setting('klipy_api_key', 'test-gif-key');
$pdo->prepare('UPDATE conversations SET klipy_formats = ? WHERE id = ?')->execute([KLIPY_FORMATS['gif'], $cid]);

$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($appDir . '/bin/followup.php');
passthru($command, $status);
if ($status !== 0) {
    exit($status);
}
passthru($command, $status);
if ($status !== 0) {
    exit($status);
}

$query = $pdo->prepare("SELECT request FROM llm_logs WHERE conversation_id = ? AND purpose = 'followup'");
$query->execute([$cid]);
$requests = $query->fetchAll(PDO::FETCH_COLUMN);
$request = json_decode($requests[0] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
$system = $request[0]['content'] ?? '';
$conversation = chat_conv_effective($cid);
if (count($requests) !== 1 || array_slice($request, 1, -1) !== array_slice($expected, -6)
    || !str_contains($system, 'Dernier sujet : « Je fête cette réussite à Lyon ! »')
    || str_contains($system, 'Notification interne') || str_contains($system, 'ancienne destination')
    || str_contains($system, 'mémoire longue') || (int) $conversation['unread'] !== 1
    || count(fetch_messages($cid, 0, 0, true)) !== 8) {
    throw new RuntimeException('La relance doit utiliser les six derniers échanges, rester cohérente et être enregistrée une seule fois comme non lue.');
}
echo "OK : relance fondée sur les six derniers échanges et enregistrée une seule fois.\n";
$messages = fetch_messages($cid, 0, 0, true);
$followup = end($messages);
if ($followup['klipy_url'] !== 'https://static.klipy.com/test/celebrate.gif') {
    throw new RuntimeException('La relance doit pouvoir joindre un GIF sans demande de l’utilisateur.');
}
echo "OK : GIF spontané ajouté à la relance sans demande de l’utilisateur.\n";

// Relances suivantes : le persona voit ses relances précédentes et s'arrête sans réponse.
$age = static function () use ($pdo, $cid): void {
    $pdo->prepare("UPDATE messages SET created_at = datetime('now', '-7 hours') WHERE conversation_id = ? AND created_at > datetime('now', '-7 hours')")
        ->execute([$cid]);
    $pdo->prepare("UPDATE conversations SET last_followup_at = datetime('now', '-13 hours') WHERE id = ?")->execute([$cid]);
};
$run = static function () use ($command): void {
    passthru($command, $status);
    if ($status !== 0) {
        exit($status);
    }
};
$followupRequests = static function () use ($query, $cid): array {
    $query->execute([$cid]);
    return $query->fetchAll(PDO::FETCH_COLUMN);
};
$spontaneous = static function () use ($pdo, $cid): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND spontaneous = 1");
    $stmt->execute([$cid]);
    return (int) $stmt->fetchColumn();
};
if ($spontaneous() !== 1) {
    throw new RuntimeException('La relance doit être marquée comme message spontané.');
}

$age();
$run();
$requests = $followupRequests();
$system = json_decode($requests[1] ?? '[]', true, 512, JSON_THROW_ON_ERROR)[0]['content'] ?? '';
$first = trim(preg_replace('/\s+/u', ' ', (string) $followup['content']));
if (count($requests) !== 2 || $spontaneous() !== 2
    || !str_contains($system, 'Tes derniers messages spontanés') || !str_contains($system, mb_substr($first, 0, 200))
    || !str_contains($system, 'Dernier sujet : « Je fête cette réussite à Lyon ! »')) {
    throw new RuntimeException('La deuxième relance doit partir, rappeler la précédente et garder pour sujet le dernier vrai échange.');
}
echo "OK : la relance suivante voit les précédentes pour ne pas les répéter.\n";

$age();
$run();
if (count($followupRequests()) !== 2 || $spontaneous() !== 2) {
    throw new RuntimeException('Après deux relances sans réponse, le persona doit attendre l’utilisateur.');
}
echo "OK : pas de troisième relance d’affilée sans réponse.\n";

$insert->execute([$cid, 'user', 'Me revoilà !', '-7 hours']);
$age();
$run();
if (count($followupRequests()) !== 3 || $spontaneous() !== 3) {
    throw new RuntimeException('Une réponse de l’utilisateur doit permettre de nouvelles relances.');
}
echo "OK : une réponse de l’utilisateur relance le compte.\n";

// Type de message imposé : « prendre des nouvelles » à partir d'un souvenir sur l'utilisateur,
// sans rappel du dernier sujet ; le type est retenu pour ne pas revenir aux réveils suivants.
set_setting('chat_followup_force_kind', 'nouvelles');
$age();
$run();
$requests = $followupRequests();
$system = json_decode(end($requests) ?: '[]', true, 512, JSON_THROW_ON_ERROR)[0]['content'] ?? '';
$kinds = (string) $pdo->query("SELECT followup_kinds FROM conversations WHERE id = $cid")->fetchColumn();
if (count($requests) !== 4 || !str_contains($system, 'prends de ses nouvelles') || !preg_match('/t\'a confié \(.+\) : « .+ »/u', $system)
    || str_contains($system, 'Dernier sujet') || !str_ends_with($kinds, 'nouvelles')) {
    throw new RuntimeException('Un réveil « nouvelles » doit partir d’un souvenir sur l’utilisateur, sans le dernier sujet : ' . $kinds);
}
set_setting('chat_followup_force_kind', '');
$conv = chat_conv_effective($cid);
$conv['followup_kinds'] = 'anecdote,proposition,decouverte';
$seen = [];
for ($i = 0; $i < 40; $i++) {
    $seen[followup_pick($conv, (int) $conv['persona_id'])['kind']] = true;
}
if (array_intersect_key($seen, array_flip(['anecdote', 'proposition', 'decouverte'])) || count($seen) < 2) {
    throw new RuntimeException('Les types des 3 derniers réveils ne doivent pas revenir : ' . implode(',', array_keys($seen)));
}
echo "OK : réveils variés (nouvelles de l’utilisateur, types qui tournent, dernier sujet seulement pour un rebond).\n";

// Petite attention quelques minutes après une réponse du persona : programmée après la réponse,
// envoyée par la tâche planifiée une fois l'heure passée, sans repousser la relance habituelle ;
// annulée si l'utilisateur a écrit entre-temps.
set_setting('chat_attention_chance', '100');
$say = $pdo->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)");
$say->execute([$cid, 'user', 'Bonne soirée !']);
$say->execute([$cid, 'assistant', 'Merci, à toi aussi !']);
$before = (string) $pdo->query("SELECT last_followup_at FROM conversations WHERE id = $cid")->fetchColumn();
$runOutput = static function () use ($command): string {
    return (string) shell_exec($command . ' 2>&1');
};
$due = static fn (): string => (string) $pdo->query("SELECT attention_due FROM conversations WHERE id = $cid")->fetchColumn();
$early = attention_maybe($cid) ? $runOutput() : 'non programmée';
$pdo->exec("UPDATE conversations SET attention_due = datetime('now', '-1 minute') WHERE id = $cid");
$sentOut = $runOutput();
$againOut = $runOutput();
$requests = $followupRequests();
$system = json_decode(end($requests) ?: '[]', true, 512, JSON_THROW_ON_ERROR)[0]['content'] ?? '';
$last = $pdo->query("SELECT role, spontaneous FROM messages WHERE conversation_id = $cid ORDER BY id DESC LIMIT 1")->fetch();
$after = (string) $pdo->query("SELECT last_followup_at FROM conversations WHERE id = $cid")->fetchColumn();
if (str_contains($early, 'petite attention') || !str_contains($sentOut, 'petite attention — sent') || str_contains($againOut, 'petite attention')
    || count($requests) !== 5 || !str_contains($system, 'petite attention') || $last['role'] !== 'assistant'
    || (int) $last['spontaneous'] !== 1 || $after !== $before || $due() !== '') {
    throw new RuntimeException("Petite attention : $early / $sentOut / $againOut / " . count($requests));
}
$say->execute([$cid, 'user', 'Encore un mot']);
$say->execute([$cid, 'assistant', 'Je t’écoute.']);
attention_maybe($cid);
$say->execute([$cid, 'user', 'En fait, rien.']);
$pdo->exec("UPDATE conversations SET attention_due = datetime('now', '-1 minute') WHERE id = $cid");
$movedOut = $runOutput();
if (!str_contains($movedOut, 'petite attention — moved') || count($followupRequests()) !== 5) {
    throw new RuntimeException('Petite attention à annuler si l’utilisateur a écrit : ' . $movedOut);
}
set_setting('chat_attention_chance', '20');
echo "OK : petite attention envoyée par la tâche planifiée après une réponse, une seule fois, annulée si l’utilisateur a écrit.\n";

// Planning : pas de relance pendant que le persona dort ; sinon son activité du moment est transmise.
$personaId = (int) chat_conv_effective($cid)['persona_id'];
$pdo->prepare("INSERT INTO prompts (persona_id, kind, title, body) VALUES (?, 'planning', 'Semaine', 'Tous les jours : dort')")->execute([$personaId]);
$planId = (int) $pdo->lastInsertId();
$pdo->exec("UPDATE messages SET created_at = datetime(created_at, '-2 days') WHERE conversation_id = $cid");
$pdo->exec("UPDATE conversations SET last_followup_at = '' WHERE id = $cid");
$count = count($followupRequests());
$runOutput();
if (count($followupRequests()) !== $count) {
    throw new RuntimeException('Pas de relance pendant que le persona dort d’après son planning.');
}
$pdo->prepare("UPDATE prompts SET body = 'Tous les jours : peinture à l’atelier' WHERE id = ?")->execute([$planId]);
$runOutput();
$requests = $followupRequests();
$system = json_decode(end($requests) ?: '[]', true, 512, JSON_THROW_ON_ERROR)[0]['content'] ?? '';
if (count($requests) !== $count + 1 || !str_contains($system, 'En ce moment : peinture à l’atelier.')) {
    throw new RuntimeException('La relance doit connaître l’activité du moment d’après le planning.');
}
$pdo->prepare('DELETE FROM prompts WHERE id = ?')->execute([$planId]);
echo "OK : planning — pas de relance pendant le sommeil, activité du moment transmise.\n";

