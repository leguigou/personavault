<?php
declare(strict_types=1);

/* Moteur de discussion avec les personas (LM Studio par défaut, repli DeepSeek). */

function chat_llm_config(): array
{
    $cfg = [
        'provider' => setting('chat_llm_provider', 'lmstudio'),
        'base_url' => rtrim(setting('chat_llm_base_url', 'http://localhost:1234/v1'), '/'),
        'model'    => setting('chat_llm_model', ''),
        'key'      => setting('chat_llm_api_key', ''),
    ];

    // pas de modèle choisi : on prend un modèle de complétion, en préférant les plus solides
    if ($cfg['model'] === '') {
        $models   = chat_models($cfg['base_url'], $cfg['key']);
        $preferred = [
            'qwen/qwen3.5-9b', 'qwen3.5-9b',
            'google/gemma-4-12b', 'gemma-4-12b',
            'google/gemma-4-e4b', 'gemma-4-e4b',
        ];
        $pick = '';
        foreach ($preferred as $want) {
            if (in_array($want, $models, true)) {
                $pick = $want;
                break;
            }
        }
        if ($pick === '') {
            foreach ($models as $id) {
                if (!str_contains(strtolower($id), 'embed')) {
                    $pick = $id;
                    break;
                }
            }
        }
        if ($pick !== '') {
            $cfg['model'] = $pick;
            set_setting('chat_llm_model', $pick);
        }
    }

    return $cfg;
}

function chat_llm_ready(): bool
{
    $c = chat_llm_config();
    return $c['base_url'] !== '' && $c['model'] !== '';
}

/** Liste des modèles disponibles sur un serveur compatible OpenAI. */
function chat_models(?string $baseUrl = null, ?string $key = null): array
{
    $cfg  = chat_llm_config_raw();
    $base = rtrim($baseUrl ?? $cfg['base_url'], '/');
    $auth = $key ?? $cfg['key'];
    if ($base === '') {
        return [];
    }

    $ch = curl_init($base . '/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => $auth !== '' ? ['Authorization: Bearer ' . $auth] : [],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return [];
    }
    $data = json_decode((string) $raw, true);
    $out  = [];
    foreach (($data['data'] ?? []) as $m) {
        if (isset($m['id'])) {
            $out[] = (string) $m['id'];
        }
    }
    return $out;
}

/** Config brute, sans résolution automatique du modèle (évite la récursion). */
function chat_llm_config_raw(): array
{
    return [
        'provider' => setting('chat_llm_provider', 'lmstudio'),
        'base_url' => rtrim(setting('chat_llm_base_url', 'http://localhost:1234/v1'), '/'),
        'model'    => setting('chat_llm_model', ''),
        'key'      => setting('chat_llm_api_key', ''),
    ];
}

/**
 * Messages d'une conversation, du plus ancien au plus récent.
 * $limit = 0 → tout ; sinon on prend les $limit plus récents (avant $beforeId si fourni).
 */
function fetch_messages(int $conversationId, int $limit = 0, int $beforeId = 0): array
{
    $sql    = 'SELECT * FROM messages WHERE conversation_id = ?';
    $params = [$conversationId];
    if ($beforeId > 0) {
        $sql .= ' AND id < ?';
        $params[] = $beforeId;
    }
    if ($limit > 0) {
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return array_reverse($stmt->fetchAll());
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}


/* --------------------------------------------------------------- mémoire */

function chat_memory_threshold(): int
{
    return max(600, (int) setting('chat_memory_threshold', '3000'));
}

function chat_memory_target(): int
{
    return max(300, (int) setting('chat_memory_target', '1600'));
}

/** Nombre de messages déjà résumés renvoyés tout de même au modèle, pour la continuité. */
const CHAT_SUMMARY_OVERLAP = 2;

/** Le résumé de la conversation remplace-t-il les anciens messages ? */
function chat_uses_summary(array $conv): bool
{
    return (int) ($conv['memory_enabled'] ?? 1) === 1
        && (int) ($conv['last_compressed_id'] ?? 0) > 0
        && trim((string) ($conv['summary'] ?? '')) !== '';
}

/**
 * Messages réellement envoyés au modèle.
 * Mémoire active : uniquement ceux qui ne sont pas encore dans le résumé, plus les
 * CHAT_SUMMARY_OVERLAP derniers messages résumés (raccord). Sans mémoire : tout
 * l'historique (le budget de tokens coupe ensuite les plus anciens).
 */
function fetch_context_messages(int $cid, array $conv): array
{
    if (!chat_uses_summary($conv)) {
        return fetch_messages($cid);
    }
    $cursor = (int) $conv['last_compressed_id'];
    $st = db()->prepare("SELECT id FROM messages WHERE conversation_id = ? AND id <= ?
                         AND role IN ('user', 'assistant') ORDER BY id DESC LIMIT " . CHAT_SUMMARY_OVERLAP);
    $st->execute([$cid, $cursor]);
    $ids  = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $from = $ids ? min($ids) : $cursor + 1;

    $st = db()->prepare('SELECT * FROM messages WHERE conversation_id = ? AND id >= ? ORDER BY id ASC');
    $st->execute([$cid, $from]);
    return $st->fetchAll();
}

/** Tokens de la partie fixe du prompt (consignes, fiche, résumé exclu) + marge pour le contexte variable. */
function chat_base_tokens(array $conv): int
{
    $st = db()->prepare('SELECT * FROM personas WHERE id = ?');
    $st->execute([(int) ($conv['persona_id'] ?? 0)]);
    $persona = $st->fetch();
    if (!$persona) {
        return 300;
    }
    $prompts = fetch_prompts((int) $persona['id']);
    $system  = chat_system_prompt($persona, $prompts, ['summary' => ''] + $conv, []);
    // heure, souvenirs pertinents, rappels : environ 250 tokens selon les cas
    return chat_estimate_tokens($system) + 250;
}

/**
 * Statistiques de contexte d'une conversation.
 * - recent_tokens  : messages pas encore résumés (déclenche la compression au-delà du seuil)
 * - context_tokens : estimation de ce qui part réellement au modèle (anneau, pied de page)
 */
function chat_memory_stats(int $cid, array $conv): array
{
    $cursor = (int) ($conv['last_compressed_id'] ?? 0);

    // comptages en SQL : pas besoin de charger tout l'historique
    $st = db()->prepare("SELECT COUNT(*) AS n,
                                SUM(CASE WHEN id > ? THEN 1 ELSE 0 END) AS un,
                                SUM(CASE WHEN id > ? THEN LENGTH(content) ELSE 0 END) AS chars
                           FROM messages WHERE conversation_id = ? AND role IN ('user', 'assistant')");
    $st->execute([$cursor, $cursor, $cid]);
    $row          = $st->fetch() ?: [];
    $total        = (int) ($row['n'] ?? 0);
    $unsummarized = (int) ($row['un'] ?? 0);
    $recentTokens = (int) ceil(((int) ($row['chars'] ?? 0)) / 3.6) + $unsummarized;

    $useSummary    = chat_uses_summary($conv);
    $summaryTokens = chat_estimate_tokens((string) ($conv['summary'] ?? ''));
    $window        = max(2048, (int) ($conv['context_window'] ?? 8192));
    $budget        = $window - (int) ($conv['max_tokens'] ?? 400) - 512;
    $base          = chat_base_tokens($conv) + ($useSummary ? $summaryTokens : 0);

    // historique effectivement envoyé (même règle que chat_build_messages)
    $history = 0;
    foreach (array_reverse(fetch_context_messages($cid, $conv)) as $m) {
        if (!in_array((string) $m['role'], ['user', 'assistant'], true)) {
            continue;
        }
        $cost = chat_estimate_tokens((string) $m['content']) + 4;
        if ($base + $history + $cost > $budget && $history > 0) {
            break;
        }
        $history += $cost;
    }
    $sent = $base + $history;

    return [
        'messages'        => $total,
        'unsummarized'    => $unsummarized,
        'summary_tokens'  => $useSummary ? $summaryTokens : 0,
        'recent_tokens'   => $recentTokens,
        'base_tokens'     => $base,
        'context_tokens'  => $sent,
        'window'          => $window,
        'percent'         => (int) round(min(100, ($sent / $window) * 100)),
        // position du repère « compression » sur l'anneau
        'trigger_tokens'  => $base + chat_memory_threshold(),
        'compressions'    => (int) ($conv['compressions'] ?? 0),
        'cursor'          => $cursor,
    ];
}

/** Texte compact du résumé, prêt à être injecté dans le prompt système. */
function chat_summary_text(array $j): string
{
    $lines = [];
    $topic = trim((string) ($j['current_topic'] ?? ''));
    $goal  = trim((string) ($j['conversation_goal'] ?? ''));
    if ($topic !== '') {
        $lines[] = 'Sujet : ' . $topic;
    }
    if ($goal !== '') {
        $lines[] = 'Objectif : ' . $goal;
    }
    foreach ([
        'important_facts' => 'Faits',
        'decisions'       => 'Décisions',
        'open_questions'  => 'Questions ouvertes',
    ] as $key => $label) {
        $items = $j[$key] ?? [];
        if (!is_array($items)) {
            continue;
        }
        $clean = [];
        foreach ($items as $it) {
            $it = trim((string) (is_scalar($it) ? $it : ''));
            if ($it !== '') {
                $clean[] = '- ' . mb_substr($it, 0, 220);
            }
            if (count($clean) >= 8) {
                break;
            }
        }
        if ($clean) {
            $lines[] = $label . " :\n" . implode("\n", $clean);
        }
    }
    $ctx = trim((string) ($j['context_summary'] ?? ''));
    if ($ctx !== '') {
        $lines[] = 'Contexte : ' . mb_substr($ctx, 0, 700);
    }

    return trim(implode("\n", $lines));
}

/**
 * Construit la requête de compression : UNIQUEMENT les messages de la conversation
 * (user / assistant). Le prompt système du persona n'y entre jamais, pas plus que
 * les notices techniques ou tout autre rôle.
 *
 * @return array{system:string, user:string, messages:int, skipped:int}
 */
function chat_compress_payload(array $toSummarize, array $conv): array
{
    $transcript = '';
    $kept       = 0;
    $skipped    = 0;

    foreach ($toSummarize as $m) {
        $role = (string) ($m['role'] ?? '');
        if ($role !== 'user' && $role !== 'assistant') {
            $skipped++;
            continue;
        }
        $who = $role === 'user' ? 'Utilisateur' : 'Personnage';
        $transcript .= $who . ' : ' . mb_substr(trim((string) $m['content']), 0, 1400) . "\n\n";
        $kept++;
    }

    $system = engine_prompt('memory');

    $user = "Résumé précédent :\n" . (trim((string) $conv['summary']) !== '' ? $conv['summary'] : '(aucun)')
        . "\n\nNouveaux messages :\n" . $transcript;

    return ['system' => $system, 'user' => $user, 'messages' => $kept, 'skipped' => $skipped];
}

/**
 * Compresse les anciens messages dans le résumé (jamais deux fois les mêmes).
 * @return array{ok:bool, compressed:int, reason?:string, summary?:string}
 */
function chat_compress(int $cid, array $conv, bool $force = false): array
{
    if ((int) ($conv['memory_enabled'] ?? 1) !== 1) {
        return ['ok' => true, 'compressed' => 0, 'reason' => 'mémoire désactivée'];
    }

    $lastCompressed = (int) ($conv['last_compressed_id'] ?? 0);
    $st = db()->prepare("SELECT * FROM messages WHERE conversation_id = ? AND id > ?
                         AND role IN ('user', 'assistant') ORDER BY id ASC");
    $st->execute([$cid, $lastCompressed]);
    $pending = $st->fetchAll();

    if (count($pending) < 6) {
        return ['ok' => true, 'compressed' => 0, 'reason' => 'rien à résumer'];
    }

    $pendingTokens = 0;
    foreach ($pending as $m) {
        $pendingTokens += chat_estimate_tokens((string) $m['content']);
    }
    if (!$force && $pendingTokens < chat_memory_threshold()) {
        return ['ok' => true, 'compressed' => 0, 'reason' => 'sous le seuil'];
    }

    // on garde les messages les plus récents (au moins 4) dans la mémoire courte
    $target = chat_memory_target();
    $keep   = [];
    $acc    = 0;
    foreach (array_reverse($pending) as $m) {
        $t = chat_estimate_tokens((string) $m['content']);
        if ($acc + $t > $target && count($keep) >= 4) {
            break;
        }
        $acc += $t;
        array_unshift($keep, $m);
    }

    $toSummarize = array_slice($pending, 0, count($pending) - count($keep));
    if (!$toSummarize) {
        return ['ok' => true, 'compressed' => 0, 'reason' => 'rien à résumer'];
    }

    $payload = chat_compress_payload($toSummarize, $conv);
    $msgs    = [
        ['role' => 'system', 'content' => $payload['system']],
        ['role' => 'user', 'content' => $payload['user']],
    ];

    $cfg  = chat_llm_config();
    $opts = [
        'temperature'      => 0.2,
        'max_tokens'       => 1200,
        'timeout'          => 180,
        'reasoning_effort' => setting('chat_reasoning_effort', 'none'),
    ];

    try {
        $res = llm_request($msgs, $cfg, $opts + ['json' => true]);
    } catch (Throwable $e) {
        // certains serveurs (LM Studio) refusent response_format=json_object
        if (str_contains($e->getMessage(), 'response_format')) {
            $res = llm_request($msgs, $cfg, $opts + ['json' => false]);
        } else {
            throw $e;
        }
    }

    try {
        $json = llm_json($res['content']);
    } catch (Throwable $e) {
        return [
            'ok'         => false,
            'compressed' => 0,
            'reason'     => 'réponse du modèle illisible (' . mb_substr(trim((string) $res['content']), 0, 120) . ')',
        ];
    }

    $text = chat_summary_text($json);
    if ($text === '') {
        return ['ok' => false, 'compressed' => 0, 'reason' => 'résumé vide, mémoire précédente conservée'];
    }
    if (chat_estimate_tokens($text) > 1100) {
        $text = mb_substr($text, 0, 3600);
    }

    $cursor = (int) end($toSummarize)['id'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE conversations SET summary = ?, last_compressed_id = ?, compressions = compressions + 1, updated_at = datetime("now") WHERE id = ?');
        $stmt->execute([$text, $cursor, $cid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'compressed' => 0, 'reason' => 'écriture impossible : ' . public_error($e)];
    }

    return ['ok' => true, 'compressed' => count($toSummarize), 'summary' => $text];
}


/** Anneau de progression du contexte (vert → orange → rouge), avec 2 indicateurs annexes. */
function chat_ring(array $m, int $size = 40): string
{
    $pct = max(0, min(100, (int) ($m['percent'] ?? 0)));
    $color = $pct >= 80 ? '#f87171' : ($pct >= 50 ? '#fbbf24' : '#34d399');

    $r  = 15.5;
    $c  = 2 * M_PI * $r;
    $fillLen = $c * $pct / 100;

    $window = max(1, (int) ($m['window'] ?? 8192));
    $thrPct = max(0, min(100, (int) round(((int) ($m['trigger_tokens'] ?? chat_memory_threshold())) / $window * 100)));
    $angle  = -90 + 360 * $thrPct / 100;
    $rad    = deg2rad($angle);

    $tx1 = 18 + 17.2 * cos($rad);
    $ty1 = 18 + 17.2 * sin($rad);
    $tx2 = 18 + 20.0 * cos($rad);
    $ty2 = 18 + 20.0 * sin($rad);

    $memPct = 0;
    if (!empty($m['context_tokens'])) {
        $memPct = max(0, min(100, (int) round(($m['summary_tokens'] ?? 0) / $window * 100)));
    }
    $rIn = 11.5;
    $cIn = 2 * M_PI * $rIn;
    $memLen = $cIn * $memPct / 100;

    $title = 'Contexte ' . number_format((float) ($m['context_tokens'] ?? 0), 0, ',', ' ')
        . ' / ' . number_format((float) $window, 0, ',', ' ') . ' tokens (' . $pct . ' %)'
        . ' · résumé ' . number_format((float) ($m['summary_tokens'] ?? 0), 0, ',', ' ') . ' tokens'
        . ' · ' . (int) ($m['compressions'] ?? 0) . ' compression(s)'
        . ' · résumé auto au-delà de ' . number_format((float) chat_memory_threshold(), 0, ',', ' ') . ' tokens non résumés';

    $fs = $size <= 44 ? 11 : 13;

    return '<svg class="ring" viewBox="0 0 36 36" width="' . $size . '" height="' . $size . '" role="img" aria-label="' . e($title) . '">'
        . '<title>' . e($title) . '</title>'
        . '<circle cx="18" cy="18" r="' . $r . '" fill="none" stroke="rgba(255,255,255,.10)" stroke-width="3"/>'
        . ($memPct > 0
            ? '<circle class="ring-mem" cx="18" cy="18" r="' . $rIn . '" fill="none" stroke="#38bdf8" stroke-width="1.8"'
              . ' stroke-dasharray="' . round($memLen, 2) . ' ' . round($cIn, 2) . '"'
              . ' stroke-linecap="round" transform="rotate(-90 18 18)" opacity=".9"/>'
            : '')
        . '<circle class="ring-fill" cx="18" cy="18" r="' . $r . '" fill="none" stroke="' . $color . '" stroke-width="3"'
        . ' stroke-dasharray="' . round($fillLen, 2) . ' ' . round($c, 2) . '"'
        . ' stroke-linecap="round" transform="rotate(-90 18 18)"/>'
        . '<line class="ring-tick" x1="' . round($tx1, 2) . '" y1="' . round($ty1, 2) . '"'
        . ' x2="' . round($tx2, 2) . '" y2="' . round($ty2, 2) . '" stroke="#ffffff" stroke-width="1.4"'
        . ' stroke-linecap="round" opacity=".75"/>'
        . '<text class="ring-val" x="18" y="' . (18 + $fs * 0.36) . '" text-anchor="middle"'
        . ' font-size="' . $fs . '" font-family="inherit" font-weight="600" fill="' . $color . '">' . $pct . '</text>'
        . '</svg>';
}


/* ------------------------------------------------- gestion du serveur local */

/** Racine du serveur (sans le /v1 de l'API OpenAI). */
function lms_root(): string
{
    $base = chat_llm_config_raw()['base_url'];
    return preg_replace('#/v\d+/?$#', '', $base) ?: $base;
}

/** Modèles actuellement chargés en mémoire (API native v1, repli v0). */
function lms_loaded_models(): array
{
    $root = lms_root();
    $out  = [];

    $ch = curl_init($root . '/api/v1/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw !== false && $code === 200) {
        $data = json_decode((string) $raw, true);
        foreach (($data['models'] ?? []) as $m) {
            if (empty($m['loaded_instances'])) {
                continue;
            }
            foreach ($m['loaded_instances'] as $inst) {
                $id = is_array($inst) ? (string) ($inst['id'] ?? '') : (string) $inst;
                if ($id !== '') {
                    $out[$id] = ['id' => $id, 'model' => (string) ($m['key'] ?? $id)];
                }
            }
        }
        return array_values($out);
    }

    // repli : ancienne API v0
    $ch = curl_init($root . '/api/v0/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw2 = curl_exec($ch);
    curl_close($ch);
    $data2 = json_decode((string) $raw2, true);
    foreach (($data2['data'] ?? []) as $m) {
        if (($m['state'] ?? '') === 'loaded') {
            $out[(string) $m['id']] = ['id' => (string) $m['id'], 'model' => (string) $m['id']];
        }
    }
    return array_values($out);
}

/**
 * Demande le chargement d'un modèle en tâche de fond (la requête bloque jusqu'à la fin
 * du chargement : on la détache pour pouvoir afficher la progression par polling).
 */
function lms_load_async(string $model, int $contextLength = 8192): bool
{
    if ($model === '') {
        return false;
    }
    $root = lms_root();
    $log  = dirname(__DIR__) . '/storage/lms-load.log';
    $body = json_encode([
        'model'          => $model,
        'context_length' => max(2048, $contextLength),
    ], JSON_UNESCAPED_UNICODE);

    $cmd = sprintf(
        'nohup curl -s -m 300 -X POST %s -H %s -d %s > %s 2>&1 &',
        escapeshellarg($root . '/api/v1/models/load'),
        escapeshellarg('Content-Type: application/json'),
        escapeshellarg((string) $body),
        escapeshellarg($log)
    );

    @unlink($log);
    exec($cmd);
    return true;
}

function lms_unload(string $instanceId): bool
{
    $ch = curl_init(lms_root() . '/api/v1/models/unload');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode(['instance_id' => $instanceId]),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code < 200 || $code >= 300) {
        return false;
    }
    $data = json_decode((string) $raw, true);
    return !isset($data['error']);
}

/**
 * Vide la mémoire du serveur local (tous les modèles chargés).
 * @return array<int,string> instances déchargées
 */
function lms_purge(): array
{
    $done = [];
    foreach (lms_loaded_models() as $m) {
        if (lms_unload($m['id'])) {
            $done[] = $m['id'];
        }
    }
    return $done;
}

/* ---------------------------------------------------------------- favoris */

/** Modèles recommandés pour la discussion (testés sur la machine de Guillaume). */
function chat_favorite_defaults(): array
{
    return [
        'qwen/qwen3.5-9b'        => 'Polyvalent, rapide, bon français — désactiver la réflexion',
        'google/gemma-4-12b'     => 'Meilleure qualité rédactionnelle, plus lent à charger',
        'qwen/qwen3-vl-8b'       => 'Très rapide, comprend aussi les images',
        'mistralai/ministral-3-3b' => 'Léger (3B), répond vite, style plus simple',
    ];
}

function chat_favorites(): array
{
    $raw = setting('chat_favorite_models', '');
    if ($raw === '') {
        $defaults = array_keys(chat_favorite_defaults());
        set_setting('chat_favorite_models', json_encode($defaults));
        return $defaults;
    }
    $list = json_decode($raw, true);
    return is_array($list) ? array_values(array_map('strval', $list)) : [];
}

/** Trie les modèles : favoris d'abord (préfixés d'une étoile). */
function chat_models_ordered(array $models): array
{
    // les modèles d'embedding n'ont rien à faire dans un sélecteur de discussion
    $models = array_values(array_filter($models, fn ($m) => !str_contains(strtolower($m), 'embed')));
    $favs = chat_favorites();
    $out  = [];
    foreach ($favs as $f) {
        if (in_array($f, $models, true)) {
            $out[] = ['id' => $f, 'fav' => true];
        }
    }
    foreach ($models as $m) {
        if (!in_array($m, $favs, true)) {
            $out[] = ['id' => $m, 'fav' => false];
        }
    }
    return $out;
}

/* ------------------------------------------------------------- contexte */

function chat_estimate_tokens(string $text): int
{
    // estimation conservatrice (~3.6 caractères par token en français)
    return (int) ceil(mb_strlen($text) / 3.6) + 1;
}

/** Prompt système = prompts « système » de la fiche + fiche + consigne de session. */
function chat_system_prompt(array $persona, array $personaPrompts, array $conv, array $facts = []): string
{
    $parts = [];
    $system = '';
    foreach ($personaPrompts as $p) {
        if (($p['kind'] ?? '') === 'system') {
            $system .= trim((string) $p['body']) . "\n\n";
        }
    }

    if ($system !== '') {
        $parts[] = trim($system);
    } else {
        $parts[] = 'Tu incarnes ' . $persona['name'] . ". Reste dans le personnage et réponds de façon naturelle.";
    }

    $fiche = ['Nom' => $persona['name']];
    if (trim((string) $persona['tagline']) !== '') {
        $fiche['Accroche'] = $persona['tagline'];
    }
    if (trim((string) $persona['description']) !== '') {
        $fiche['Description'] = $persona['description'];
    }
    foreach (json_list($persona['meta'] ?? '[]') as $row) {
        if (is_array($row) && !empty($row['k'])) {
            $fiche[(string) $row['k']] = (string) ($row['v'] ?? '');
        }
    }

    $lines = [];
    foreach ($fiche as $k => $v) {
        if (trim((string) $v) === '') {
            continue;
        }
        $lines[] = $k . ' : ' . $v;
    }
    if ($lines) {
        $parts[] = "Fiche du personnage (contexte factuel, ne la récite pas) :\n" . implode("\n", $lines);
    }

    $directive = trim((string) ($conv['directive'] ?? ''));
    if ($directive !== '') {
        $parts[] = 'Consigne de jeu : ' . $directive;
    }

    // le résumé ne sert que si la mémoire est active (sinon l'historique complet est envoyé)
    $summary = (int) ($conv['memory_enabled'] ?? 1) === 1 ? trim((string) ($conv['summary'] ?? '')) : '';
    if ($summary !== '') {
        $parts[] = "Mémoire de la conversation (résumé des échanges plus anciens) :\n" . $summary;
    }

    $factsText = facts_format($facts);
    if ($factsText !== '') {
        $parts[] = "Ce que tu sais déjà de l'utilisateur (mémoire longue, à utiliser sans le réciter) :\n" . $factsText;
    }

    $parts[] = "Règles : reste strictement dans le personnage, ne dis jamais que tu es une IA ou un modèle. "
        . "Écris comme dans une messagerie : réponses courtes (2 à 4 phrases), pas de listes, pas de mise en forme markdown. "
        . "Langue : celle de l'utilisateur. N'invente pas de faits sur l'utilisateur et ne contredis jamais la fiche "
        . "(ville, âge, métier, apparence) : si un détail manque, reste vague plutôt que d'inventer. "
        . "Le dernier message peut commencer par un bloc <contexte>…</contexte> : ce sont des informations "
        . "pour toi seul (heure, souvenirs), invisibles pour l'utilisateur. Utilise-les sans jamais citer ce bloc.";

    return implode("\n\n", $parts);
}

/**
 * Construit les messages à envoyer au modèle, en respectant le budget de tokens.
 * @return array{messages: array, kept: int, dropped: int, tokens: int}
 */
/** Échappe les jokers SQL pour un LIKE … ESCAPE '\'. */
function chat_like_escape(string $s): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

/**
 * Requête FTS5 sûre à partir d'un texte libre : chaque mot entre guillemets (aucun
 * opérateur interprété), en préfixe (« lisb » trouve « Lisbonne »), reliés par AND ou OR.
 * Accents ignorés grâce au tokenizer (remove_diacritics).
 */
function fts_query(array $words, string $op = 'AND'): string
{
    $terms = [];
    foreach ($words as $w) {
        $w = trim(str_replace('"', '', (string) $w));
        if ($w !== '') {
            $terms[] = '"' . $w . '"*';
        }
    }
    return implode($op === 'OR' ? ' OR ' : ' AND ', $terms);
}

/** Mots d'un texte (lettres et chiffres), en minuscules, sans doublon. */
function text_words(string $text, int $minLen = 2, array $stop = []): array
{
    $out = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $w) {
        if (mb_strlen($w) >= $minLen && !in_array($w, $stop, true) && !in_array($w, $out, true)) {
            $out[] = $w;
        }
    }
    return $out;
}

/** Normalise une ligne de résultat de recherche. */
function chat_search_row(array $r): array
{
    return [
        'id'              => (int) $r['id'],
        'conversation_id' => (int) $r['conversation_id'],
        'title'           => (string) $r['title'],
        'role'            => (string) $r['role'],
        'content'         => (string) $r['content'],
        'created_at'      => (string) $r['created_at'],
    ];
}

/**
 * Recherche dans TOUTES les conversations d'un persona (hors conversation courante).
 * Index plein texte (tous les mots, en préfixe), du plus récent au plus ancien ;
 * repli sur LIKE si l'index n'est pas disponible.
 * @return array<int, array{id:int, conversation_id:int, title:string, role:string, content:string, created_at:string}>
 */
function chat_persona_search(int $personaId, string $query, int $limit = 8, int $excludeCid = 0): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $words = array_slice(text_words($query, 1), 0, 8);
    $limit = max(1, $limit);

    if ($words) {
        try {
            $sql = "SELECT m.id, m.conversation_id, m.role, m.content, m.created_at, c.title
                      FROM messages_fts f
                      JOIN messages m ON m.id = f.rowid
                      JOIN conversations c ON c.id = m.conversation_id
                     WHERE messages_fts MATCH ? AND c.persona_id = ? AND m.role IN ('user', 'assistant')"
                . ($excludeCid > 0 ? ' AND c.id <> ?' : '')
                . ' ORDER BY m.id DESC LIMIT ' . $limit;
            $params = [fts_query($words, 'AND'), $personaId];
            if ($excludeCid > 0) {
                $params[] = $excludeCid;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return array_map('chat_search_row', $stmt->fetchAll());
        } catch (Throwable $e) {
            error_log('PersonaVault recherche FTS : ' . $e->getMessage());
        }
    }

    // repli : recherche de sous-chaîne (lente sur un gros historique)
    $sql    = "SELECT m.id, m.conversation_id, m.role, m.content, m.created_at, c.title
               FROM messages m JOIN conversations c ON c.id = m.conversation_id
               WHERE c.persona_id = ? AND m.role IN ('user', 'assistant')";
    $params = [$personaId];
    if ($excludeCid > 0) {
        $sql .= ' AND c.id <> ?';
        $params[] = $excludeCid;
    }
    $sql .= " AND m.content LIKE ? ESCAPE '\\' ORDER BY m.id DESC LIMIT " . $limit;
    $params[] = '%' . chat_like_escape($query) . '%';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('chat_search_row', $stmt->fetchAll());
}

/** L'utilisateur fait-il référence à un échange passé ? */
function chat_has_recall_cue(string $message): bool
{
    return (bool) preg_match(
        "/(souviens|rappelle|m[ée]morise|la derni[eè]re fois|l['’]autre (jour|fois)|on avait dit|"
        . "comme (pr[ée]vu|convenu)|dont on a parl|pr[ée]c[ée]demment|tu te rappelles|on s['’][ée]tait dit)/iu",
        $message
    );
}

/**
 * Extraits pertinents tirés des AUTRES conversations du même persona.
 * @return array<int, array>
 */
function chat_recall_snippets(int $personaId, string $userMessage, int $excludeCid, int $limit = 3): array
{
    if (!chat_has_recall_cue($userMessage)) {
        return [];
    }

    $stop = ['alors', 'aussi', 'autre', 'avant', 'avec', 'avoir', 'beaucoup', 'bien', 'cette', 'comme',
        'comment', 'dans', 'depuis', 'derniere', 'dernière', 'elle', 'elles', 'encore', 'enfin', 'entre',
        'etait', 'était', 'faire', 'fois', 'jour', 'jours', 'juste', 'mais', 'meme', 'même', 'moins',
        'notre', 'nous', 'parle', 'parler', 'pour', 'pourquoi', 'quand', 'quelque', 'rappelle',
        'souviens', 'souvenir', 'toujours', 'toute', 'toutes', 'trouve', 'votre', 'vous'];

    $kw = array_slice(text_words($userMessage, 5, $stop), 0, 6);
    if (!$kw) {
        return [];
    }
    $limit = max(1, $limit);

    // index plein texte : n'importe lequel des mots-clés, les plus PERTINENTS d'abord (bm25)
    try {
        $stmt = db()->prepare(
            "SELECT m.id, m.conversation_id, m.role, m.content, m.created_at, c.title
               FROM messages_fts f
               JOIN messages m ON m.id = f.rowid
               JOIN conversations c ON c.id = m.conversation_id
              WHERE messages_fts MATCH ? AND c.persona_id = ? AND m.role IN ('user', 'assistant') AND c.id <> ?
              ORDER BY bm25(messages_fts) LIMIT " . $limit
        );
        $stmt->execute([fts_query($kw, 'OR'), $personaId, $excludeCid]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('PersonaVault rappel FTS : ' . $e->getMessage());
    }

    // repli : sous-chaînes, les plus récentes d'abord
    $sql    = "SELECT m.id, m.conversation_id, m.role, m.content, m.created_at, c.title
               FROM messages m JOIN conversations c ON c.id = m.conversation_id
               WHERE c.persona_id = ? AND m.role IN ('user', 'assistant') AND c.id <> ?";
    $params = [$personaId, $excludeCid];
    $like   = [];
    foreach (array_slice($kw, 0, 4) as $w) {
        $like[]   = "m.content LIKE ? ESCAPE '\\'";
        $params[] = '%' . chat_like_escape($w) . '%';
    }
    $sql .= ' AND (' . implode(' OR ', $like) . ') ORDER BY m.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Heure locale de l'application (fuseau configurable, Europe/Paris par défaut). */
function chat_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone((string) config('timezone', 'Europe/Paris')));
}

/** « il y a 3 jours », « il y a 2 h », « à l'instant »… */
function chat_gap_label(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 90) {
        return "à l'instant";
    }
    $minutes = (int) round($seconds / 60);
    if ($minutes < 60) {
        return 'il y a ' . $minutes . ' min';
    }
    $hours = (int) round($minutes / 60);
    if ($hours < 24) {
        return 'il y a ' . $hours . ' h';
    }
    $days = (int) round($hours / 24);
    return 'il y a ' . $days . ' jour' . ($days > 1 ? 's' : '');
}

/** Durée nue : « 2 jours », « 7 h », « 35 min ». */
function chat_gap_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 3600) {
        return max(1, (int) round($seconds / 60)) . ' min';
    }
    if ($seconds < 86400) {
        return (int) round($seconds / 3600) . ' h';
    }
    $days = (int) round($seconds / 86400);
    return $days . ' jour' . ($days > 1 ? 's' : '');
}

/**
 * Bloc de contexte temporel injecté dans le prompt système :
 * date/heure réelle, silence écoulé, et rappel du dernier sujet si la pause est longue.
 */
function chat_time_context(array $conv, array $history): string
{
    $now    = chat_now();
    $jours  = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois   = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
        'septembre', 'octobre', 'novembre', 'décembre'];

    $lines = [];
    $lines[] = 'Contexte réel : nous sommes ' . $jours[(int) $now->format('w')] . ' '
        . (int) $now->format('j') . ' ' . $mois[(int) $now->format('n') - 1] . ', '
        . $now->format('H\hi') . '.';

    $last = $history ? end($history) : null;
    $gap  = 0;
    if ($last && !empty($last['created_at'])) {
        $ts  = strtotime((string) $last['created_at'] . ' UTC');
        $gap = $ts ? max(0, $now->getTimestamp() - $ts) : 0;
        $lines[] = 'Dernier message de cette conversation : ' . chat_gap_label($gap) . '.';
    }

    // reprise après une vraie pause : on rappelle le dernier sujet, sans le réciter
    if ($gap >= 6 * 3600) {
        $topic = '';
        $summary = trim((string) ($conv['summary'] ?? ''));
        if ($summary !== '') {
            foreach (preg_split('/\R/', $summary) ?: [] as $line) {
                if (stripos($line, 'sujet') === 0) {
                    $topic = trim(preg_replace('/^sujet\s*:\s*/iu', '', $line) ?? '');
                    break;
                }
            }
            if ($topic === '') {
                $topic = mb_substr($summary, 0, 160);
            }
        }
        if ($topic === '' && $last) {
            $topic = mb_substr(trim((string) $last['content']), 0, 160);
        }
        if ($topic !== '') {
            $lines[] = 'La conversation reprend après une pause de ' . chat_gap_duration($gap) . '. '
                . 'Dernier sujet : « ' . $topic . ' ». Tu peux y faire allusion en une phrase '
                . 'si c\'est naturel, sans le réciter ni dire que tu « reprends » la conversation.';
        }
    }

    $lines[] = 'Reste cohérent avec cette heure : la nuit tu peux être endormi, surpris ou à moitié '
        . 'réveillé ; en journée tu peux être au travail, pressé ou disponible. Ne demande pas l\'heure.';

    return implode("\n", $lines);
}

/**
 * Propose une « consigne de jeu » à partir du prompt système du persona.
 * @return array{directive:string, temperature:float}
 */
function persona_ai_directive(array $persona, array $prompts): array
{
    $sys = '';
    foreach ($prompts as $p) {
        if ((string) ($p['kind'] ?? '') === 'system') {
            $sys .= trim((string) $p['body']) . "\n\n";
        }
    }
    if (trim($sys) === '') {
        $sys = trim((string) ($persona['description'] ?? ''));
    }
    if (trim($sys) === '') {
        throw new RuntimeException('Aucun prompt système à analyser pour ce persona.');
    }

    $fiche = ['Nom' => (string) $persona['name']];
    if (trim((string) $persona['tagline']) !== '') {
        $fiche['Accroche'] = (string) $persona['tagline'];
    }
    foreach (json_list($persona['meta'] ?? '[]') as $row) {
        if (is_array($row) && !empty($row['k'])) {
            $fiche[(string) $row['k']] = (string) ($row['v'] ?? '');
        }
    }
    $ficheTxt = '';
    foreach ($fiche as $k => $v) {
        $ficheTxt .= $k . ' : ' . mb_substr(trim((string) $v), 0, 160) . "\n";
    }

    $system = "Tu écris la « consigne de jeu » d'un personnage de chat : les règles de style que le modèle "
        . "doit suivre pour que ses messages sonnent comme une vraie personne qui écrit sur son téléphone. "
        . "À partir de la fiche et du prompt système fournis, écris 3 à 4 phrases impératives, en français, "
        . "qui précisent : le registre et le ton, la longueur des messages (nombre de phrases), "
        . "les habitudes de langage (abréviations, ponctuation relâchée, majuscules), l'usage des emojis "
        . "(lesquels, à quelle fréquence), un ou deux tics de langage concrets, et ce que le personnage "
        . "ne fait jamais (pas de listes, pas de ton d'assistant, pas de questions en rafale…). "
        . "Ne résume pas le personnage, ne décris pas son physique, ne répète pas le prompt système : "
        . "uniquement des règles de forme, utilisables telles quelles. 420 caractères maximum, sans markdown. "
        . "Propose aussi une température de génération entre 0.6 et 1.1 (plus le personnage est expressif, "
        . "plus elle est haute). Réponds uniquement par un JSON : {\"directive\":\"...\",\"temperature\":0.9}.";

    $user = "Fiche :\n" . $ficheTxt . "\nPrompt système du personnage :\n" . mb_substr($sys, 0, 4000);

    $cfg = llm_config();
    if ($cfg['key'] === '' || $cfg['model'] === '') {
        throw new RuntimeException('Aucun modèle configuré dans les réglages IA.');
    }

    $res = llm_request(
        [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
        $cfg,
        ['temperature' => 0.4, 'max_tokens' => 420, 'timeout' => 120, 'json' => true]
    );

    $json = llm_json((string) $res['content']);
    $dir  = trim((string) ($json['directive'] ?? ''));
    if ($dir === '') {
        throw new RuntimeException('Réponse du modèle vide.');
    }
    $temp = (float) ($json['temperature'] ?? 0.85);

    return [
        'directive'   => mb_substr($dir, 0, 600),
        'temperature' => max(0.5, min(1.2, $temp)),
    ];
}

/**
 * Points de coupe d'une réponse en plusieurs bulles.
 * Chaque bulle vise $maxWords mots maximum, se termine sur une fin de phrase et
 * évite les bulles trop courtes (on préfère dépasser un peu que couper après 3 mots).
 * @return array<int,int> décalages en OCTETS dans le texte d'origine
 */
function chat_split_cuts(string $text, int $maxWords = 50, int $maxBubbles = 2): array
{
    $text       = trim($text);
    $maxBubbles = max(1, min(8, $maxBubbles));
    $maxWords   = max(8, $maxWords);
    if ($text === '' || $maxBubbles < 2) {
        return [];
    }

    $cuts   = [];
    $offset = 0;
    $len    = strlen($text);

    while (count($cuts) < $maxBubbles - 1 && $offset < $len) {
        $rest = substr($text, $offset);

        preg_match_all('/\S+/u', $rest, $mw, PREG_OFFSET_CAPTURE);
        preg_match_all('/[.!?…]+(?=\s|$)|\R/u', $rest, $mb, PREG_OFFSET_CAPTURE);
        $wordCount = count($mw[0]);
        if ($wordCount <= $maxWords || !$mb[0]) {
            break;
        }

        // position de chaque fin de phrase, en nombre de mots
        $boundaries = [];
        $wi = 0;
        foreach ($mb[0] as $b) {
            $pos = $b[1];
            while ($wi < $wordCount && $mw[0][$wi][1] < $pos) {
                $wi++;
            }
            $boundaries[] = ['byte' => $pos + strlen((string) $b[0]), 'words' => $wi];
        }

        $chosen = null;
        foreach ($boundaries as $b) {
            if ($b['words'] <= $maxWords) {
                $chosen = $b; // dernière coupure sous la limite
            }
        }
        if ($chosen && $chosen['words'] < 6) {
            $chosen = null; // bulle minuscule : on accepte de dépasser un peu
        }
        if (!$chosen) {
            foreach ($boundaries as $b) {
                if ($b['words'] > $maxWords) {
                    $chosen = $b;
                    break;
                }
            }
        }
        if (!$chosen) {
            $chosen = end($boundaries) ?: null;
        }
        if (!$chosen) {
            break;
        }

        $cut = (int) $chosen['byte'];
        while ($cut < strlen($rest) && strpbrk($rest[$cut], " \t\r\n") !== false) {
            $cut++;
        }
        if ($cut <= 0 || $cut >= strlen($rest)) {
            break;
        }
        if (trim(substr($rest, $cut)) === '') {
            break;
        }

        $offset += $cut;
        $cuts[]  = $offset;
    }

    return $cuts;
}

/** Mêmes coupes, exprimées en points de code (stockage / rendu PHP). */
function chat_split_points(string $text, int $maxWords = 50, int $maxBubbles = 2): array
{
    return array_map(
        static fn (int $bytes): int => mb_strlen(substr($text, 0, $bytes)),
        chat_split_cuts($text, $maxWords, $maxBubbles)
    );
}

/**
 * Les réglages IA (modèle, température, tokens, consigne) appartiennent au persona :
 * on les fusionne dans la conversation avant de construire la requête.
 */
function chat_with_persona(array $conv, array $persona): array
{
    $directive     = trim((string) ($persona['directive'] ?? ''));
    $convDirective = trim((string) ($conv['directive'] ?? ''));
    if ($convDirective !== '') {
        $directive = trim($directive . "\n" . $convDirective);
    }

    $conv['model']          = (string) (($persona['model'] ?? '') !== '' ? $persona['model'] : ($conv['model'] ?? ''));
    $conv['temperature']    = (float) ($persona['temperature'] ?? 0.85);
    $conv['max_tokens']     = (int) ($persona['max_tokens'] ?? 400);
    $conv['context_window'] = (int) ($persona['context_window'] ?? 8192);
    $conv['directive']      = $directive;

    return $conv;
}

/** Le modèle sait-il voir les images ? (type « vlm » côté LM Studio) */
function chat_model_vision(string $model): bool
{
    if ($model === '') {
        return false;
    }
    $st = lms_status();
    return ($st['types'][$model] ?? '') === 'vlm';
}

/**
 * Titre court généré à partir des premiers échanges (si le titre est encore automatique).
 */
function chat_auto_title(int $cid): ?string
{
    $stmt = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $stmt->execute([$cid]);
    $conv = $stmt->fetch();
    if (!$conv || (int) ($conv['title_auto'] ?? 1) !== 1) {
        return null;
    }

    $stmt = db()->prepare("SELECT role, content FROM messages WHERE conversation_id = ? AND role IN ('user','assistant') ORDER BY id ASC LIMIT 4");
    $stmt->execute([$cid]);
    $msgs = $stmt->fetchAll();
    if (count($msgs) < 2) {
        return null;
    }

    $txt = '';
    foreach ($msgs as $m) {
        $txt .= ((string) $m['role'] === 'user' ? 'Utilisateur' : 'Personnage') . ' : '
            . mb_substr(trim((string) $m['content']), 0, 300) . "\n";
    }

    $system = "Tu donnes un titre très court à une conversation, comme dans une messagerie. "
        . "3 à 5 mots maximum, en français, sans guillemets ni ponctuation finale, sans le mot « discussion ». "
        . "Décris le sujet concret de l'échange. Réponds uniquement par le titre.";

    try {
        $res = llm_request(
            [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $txt]],
            llm_config(),
            ['temperature' => 0.3, 'max_tokens' => 24, 'timeout' => 25]
        );
    } catch (Throwable $e) {
        return null;
    }

    $title = trim(str_replace(['"', '«', '»', '“', '”'], '', (string) $res['content']));
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    $title = rtrim($title, ".!?:;");
    if ($title === '' || mb_strlen($title) > 60) {
        return null;
    }

    db()->prepare('UPDATE conversations SET title = ?, updated_at = updated_at WHERE id = ?')->execute([$title, $cid]);
    return $title;
}

function chat_build_messages(array $persona, array $personaPrompts, array $conv, array $history, string $userMessage, ?string $imageDataUrl = null): array
{
    // 1) Partie STABLE en tête : consignes, fiche, consigne de jeu, résumé.
    //    Elle ne change qu'à la compression : LM Studio / llama.cpp réutilisent alors
    //    leur cache (début du prompt identique) et ne retraitent que la fin.
    $system = chat_system_prompt($persona, $personaPrompts, $conv, []);

    // 2) Partie VARIABLE (heure, souvenirs, rappels) : jointe au DERNIER message,
    //    pour ne pas invalider le cache de tout ce qui précède.
    $factsOn = (int) ($conv['memory_enabled'] ?? 1) === 1 && (int) ($conv['facts_enabled'] ?? 1) === 1;
    $context = [chat_time_context($conv, $history)];

    $facts     = $factsOn ? facts_relevant((int) $persona['id'], $userMessage, 5) : [];
    $factsText = facts_format($facts);
    if ($factsText !== '') {
        $context[] = "Ce que tu sais déjà de l'utilisateur (mémoire longue, à utiliser sans le réciter) :\n" . $factsText;
    }

    // rappel d'échanges d'autres conversations avec le même persona
    try {
        $recall = $factsOn ? chat_recall_snippets((int) $persona['id'], $userMessage, (int) $conv['id'], 3) : [];
    } catch (Throwable $e) {
        $recall = [];
    }
    if ($recall) {
        $lines = [];
        foreach ($recall as $r) {
            $who = (string) $r['role'] === 'user' ? 'Utilisateur' : 'Toi';
            $lines[] = '- (' . fr_date((string) $r['created_at']) . ', autre conversation) ' . $who . ' : '
                . mb_substr(trim((string) $r['content']), 0, 320);
        }
        $context[] = "Extraits d'autres conversations que tu as eues avec cette personne "
            . "(sers-t'en comme de souvenirs, ne les cite pas mot pour mot, ne les mentionne pas comme "
            . "des « extraits ») :\n" . implode("\n", $lines);
    }
    $contextBlock = "<contexte>\n" . implode("\n\n", $context) . "\n</contexte>";

    $limit  = max(2048, (int) ($conv['context_window'] ?? 8192));
    $budget = $limit - (int) ($conv['max_tokens'] ?? 400) - 512;
    $used   = chat_estimate_tokens($system) + chat_estimate_tokens($contextBlock) + chat_estimate_tokens($userMessage);

    $kept = [];
    $dropped = 0;
    foreach (array_reverse($history) as $msg) {
        $role = (string) $msg['role'];
        if ($role !== 'user' && $role !== 'assistant') {
            continue;
        }
        $cost = chat_estimate_tokens((string) $msg['content']) + 4;
        if ($used + $cost > $budget && $kept) {
            $dropped = (int) $msg['id'];
            break;
        }
        $used += $cost;
        array_unshift($kept, ['role' => $role, 'content' => (string) $msg['content']]);
    }

    $messages = [['role' => 'system', 'content' => $system]];
    $messages = array_merge($messages, $kept);

    $text = $userMessage !== '' ? $userMessage : ($imageDataUrl ? 'Regarde cette photo.' : '');
    $text = $contextBlock . "\n\n" . $text;

    if ($imageDataUrl !== null && $imageDataUrl !== '') {
        // message multimodal : texte + image (modèles « vlm »)
        $messages[] = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $text],
            ['type' => 'image_url', 'image_url' => ['url' => $imageDataUrl]],
        ]];
    } else {
        $messages[] = ['role' => 'user', 'content' => $text];
    }

    return [
        'messages' => $messages,
        'kept'     => count($kept),
        'dropped'  => $dropped,
        'tokens'   => $used,
    ];
}

/** État du serveur LM Studio : joignable ? quel(s) modèle(s) chargé(s) ? */
function lms_status(): array
{
    $root = lms_root();
    $ch = curl_init($root . '/api/v1/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        return ['reachable' => false, 'loaded' => [], 'available' => [], 'types' => [], 'total' => 0];
    }

    $data      = json_decode((string) $raw, true);
    $loaded    = [];
    $available = [];
    $types     = [];
    $total     = 0;
    foreach (($data['models'] ?? []) as $m) {
        $total++;
        if (!empty($m['key'])) {
            $available[] = (string) $m['key'];
            $types[(string) $m['key']] = (string) ($m['type'] ?? '');
        }
        foreach (($m['loaded_instances'] ?? []) as $inst) {
            $id = is_array($inst) ? (string) ($inst['id'] ?? '') : (string) $inst;
            if ($id !== '') {
                $loaded[] = [
                    'id'  => $id,
                    'key' => (string) ($m['key'] ?? $id),
                    'ctx' => (int) ($m['loaded_context_length'] ?? 0),
                ];
            }
        }
    }

    // l'API v1 déclare « llm » pour des modèles qui voient très bien les images
    // (gemma 4 par ex.) : on complète avec la modalité déclarée par l'API v0.
    $ch = curl_init($root . '/api/v0/models');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2]);
    $raw0 = curl_exec($ch);
    curl_close($ch);
    if ($raw0 !== false) {
        $d0 = json_decode((string) $raw0, true);
        foreach (($d0['data'] ?? []) as $m0) {
            $id0 = (string) ($m0['id'] ?? '');
            if ($id0 !== '' && isset($m0['type'])) {
                $types[$id0] = (string) $m0['type'];
            }
        }
    }

    return ['reachable' => true, 'loaded' => $loaded, 'available' => $available, 'types' => $types, 'total' => $total];
}

/**
 * Réponse en streaming.
 * mode "auto"     : LM Studio uniquement — lève LlmUnavailable (kind: down|not_loaded|error) sans basculer.
 * mode "fallback" : force le fournisseur de secours (DeepSeek), aussi en streaming.
 */
function chat_reply_stream(array $messages, array $conv, callable $onDelta, string $mode = 'auto', ?callable $shouldAbort = null): array
{
    $opts = [
        'temperature'      => (float) ($conv['temperature'] ?? 0.85),
        'max_tokens'       => (int) ($conv['max_tokens'] ?? 400),
        'timeout'          => 300,
        'reasoning_effort' => setting('chat_reasoning_effort', 'none'),
        'should_abort'     => $shouldAbort,
    ];

    if ($mode === 'fallback') {
        $fb = llm_config();
        if (!$fb['key'] || $fb['model'] === '') {
            throw new LlmUnavailable('Aucun modèle de secours configuré (réglages → IA).', 'no_fallback');
        }
        $o = $opts;
        unset($o['reasoning_effort']);
        try {
            $res = llm_stream($messages, $fb, $o, $onDelta);
        } catch (Throwable $e) {
            throw new LlmUnavailable($e->getMessage(), 'fallback_error');
        }
        $res['provider'] = 'fallback';
        $res['notice']   = 'Réponse générée par ' . $fb['model'] . ' (' . $fb['provider'] . '), LM Studio non utilisé.';
        return $res;
    }

    $cfg = chat_llm_config();
    if ($cfg['model'] === '') {
        throw new LlmUnavailable('Aucun modèle de discussion sélectionné.', 'no_model');
    }

    // le serveur répond-il seulement ? (LM Studio lancé ou pas)
    $st = lms_status();
    if (!$st['reachable']) {
        throw new LlmUnavailable('LM Studio ne répond pas : le serveur n’est pas lancé ou l’adresse est fausse.', 'down');
    }

    $partial = false;
    $emit    = function (string $d) use ($onDelta, &$partial): void {
        if ($d !== '') {
            $partial = true;
            $onDelta($d);
        }
    };

    try {
        try {
            $res = llm_stream($messages, $cfg, $opts, $emit);
        } catch (Throwable $first) {
            if ($partial) {
                throw $first;
            }
            if (!empty($opts['reasoning_effort']) && str_contains($first->getMessage(), '(400)')) {
                $opts['reasoning_effort'] = '';
                $res = llm_stream($messages, $cfg, $opts, $emit);
            } else {
                throw $first;
            }
        }
    } catch (Throwable $e) {
        $msg  = $e->getMessage();
        $kind = 'error';
        if (stripos($msg, 'Connexion impossible') !== false || stripos($msg, 'timed out') !== false) {
            $kind = 'down';
        } elseif (
            (stripos($msg, 'model') !== false || stripos($msg, 'modèle') !== false)
            && (stripos($msg, 'charg') !== false || stripos($msg, 'load') !== false || stripos($msg, 'not found') !== false)
        ) {
            $kind = 'not_loaded';
        }
        throw new LlmUnavailable($msg, $kind);
    }

    $res['provider'] = 'chat';
    $res['notice']   = '';
    return $res;
}
