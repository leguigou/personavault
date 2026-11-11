<?php
declare(strict_types=1);

/*
 * Journal des échanges avec les modèles (Réglages › Journal) : les 20 derniers appels,
 * avec ce qui est parti (messages, réglages) et ce qui est revenu (réponse ou erreur).
 * La clé d'API n'y entre jamais ; les images sont remplacées par leur taille.
 */

const LLM_LOG_KEEP = 20;

/** Contexte de l'appel en cours (usage, conversation), lu par llm_log_write(). */
function llm_log_context(?array $set = null): array
{
    static $ctx = [];
    if ($set !== null) {
        $ctx = $set + $ctx;
    }
    return $ctx;
}

/** Libellés des usages (clé → texte affiché). */
function llm_log_purposes(): array
{
    return [
        'chat'      => t('logs.purpose.chat'),
        'fallback'  => t('logs.purpose.fallback'),
        'memory'    => t('logs.purpose.memory'),
        'facts'     => t('logs.purpose.facts'),
        'title'     => t('logs.purpose.title'),
        'followup'  => t('logs.purpose.followup'),
        'ai_prompt' => t('logs.purpose.ai_prompt'),
        'ai_fill'   => t('logs.purpose.ai_fill'),
        'directive' => t('logs.purpose.directive'),
        'test'      => t('logs.purpose.test'),
        'other'     => t('logs.purpose.other'),
    ];
}

/** Copie lisible des messages : images remplacées par « [image ~N Ko] ». */
function llm_log_messages(array $messages): array
{
    $out = [];
    foreach ($messages as $m) {
        $content = $m['content'] ?? '';
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $p) {
                if (($p['type'] ?? '') === 'image_url') {
                    $url     = (string) ($p['image_url']['url'] ?? '');
                    $parts[] = '[image ~' . max(1, (int) round(strlen($url) * 0.75 / 1024)) . ' Ko]';
                } else {
                    $parts[] = (string) ($p['text'] ?? '');
                }
            }
            $content = implode("\n", $parts);
        }
        $out[] = ['role' => (string) ($m['role'] ?? ''), 'content' => mb_substr((string) $content, 0, 40000)];
    }
    return $out;
}

/**
 * Enregistre un appel. $r : status (ok|error|aborted), code, ms, response, error,
 * prompt_tokens, completion_tokens, model (réellement utilisé).
 * N'échoue jamais : le journal ne doit pas casser une réponse.
 */
function llm_log_write(array $body, string $baseUrl, array $options, array $r): void
{
    try {
        $ctx     = llm_log_context();
        $purpose = (string) ($options['purpose'] ?? $ctx['purpose'] ?? 'other');
        $params  = $body;
        unset($params['messages']);
        $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?? '');
        $port = parse_url($baseUrl, PHP_URL_PORT);

        $pdo = db();
        $pdo->prepare('INSERT INTO llm_logs (purpose, conversation_id, endpoint, model, status, http_code, ms,
                                             prompt_tokens, completion_tokens, params, request, response, error)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $purpose,
                (int) ($ctx['cid'] ?? 0) ?: null,
                $host . ($port ? ':' . $port : ''),
                (string) ($r['model'] ?? $body['model'] ?? ''),
                (string) ($r['status'] ?? 'ok'),
                (int) ($r['code'] ?? 0),
                (int) ($r['ms'] ?? 0),
                (int) ($r['prompt_tokens'] ?? 0),
                (int) ($r['completion_tokens'] ?? 0),
                json_encode($params, JSON_UNESCAPED_UNICODE),
                json_encode(llm_log_messages((array) ($body['messages'] ?? [])), JSON_UNESCAPED_UNICODE),
                mb_substr((string) ($r['response'] ?? ''), 0, 40000),
                mb_substr((string) ($r['error'] ?? ''), 0, 2000),
            ]);
        $pdo->exec('DELETE FROM llm_logs WHERE id NOT IN (SELECT id FROM llm_logs ORDER BY id DESC LIMIT ' . LLM_LOG_KEEP . ')');
    } catch (Throwable $e) {
        // journal facultatif
    }
}

/** Les derniers appels, du plus récent au plus ancien. */
function llm_log_list(): array
{
    $rows = db()->query('SELECT l.*, c.title AS conv_title FROM llm_logs l
                         LEFT JOIN conversations c ON c.id = l.conversation_id
                         ORDER BY l.id DESC LIMIT ' . LLM_LOG_KEEP)->fetchAll();
    foreach ($rows as &$row) {
        $row['request'] = json_decode((string) $row['request'], true) ?: [];
        $row['params']  = json_decode((string) $row['params'], true) ?: [];
    }
    return $rows;
}
