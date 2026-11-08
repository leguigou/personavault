<?php
declare(strict_types=1);

/*
 * Fiche : complétion et consigne de jeu proposées par l’IA (AJAX).
 * Inclus par src/routes/persona.php ($persona, $pid, $slug, $action).
 */

/* --- complétion IA (AJAX) --- */
/* --- proposer une consigne de jeu à partir du prompt système --- */
if ($action === 'ai-directive' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    if (!llm_ready()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.ai.configure_settings')]);
        exit;
    }
    try {
        $proposal = persona_ai_directive($persona, fetch_prompts($pid));
        echo json_encode(['ok' => true] + $proposal, JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'ai-fill' && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check_json();
    session_write_close(); // route longue / AJAX : ne bloque pas les autres requêtes
    if (!llm_ready()) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => t('error.ai.configure_gear')]);
        exit;
    }
    try {
        $prompts = fetch_prompts($pid);
        if (!$prompts) {
            throw new RuntimeException(t('error.ai.need_prompt'));
        }

        $ai = llm_complete_persona($persona, $prompts);

        $changes = [];
        $tagline = trim((string) $persona['tagline']);
        $desc    = trim((string) $persona['description']);

        $aiTagline = trim((string) ($ai['tagline'] ?? ''));
        if ($tagline === '' && $aiTagline !== '') {
            $tagline = mb_substr($aiTagline, 0, 120);
            $changes[] = t('labels.ai_field.tagline');
        }
        $aiDesc = trim((string) ($ai['description'] ?? ''));
        if ($desc === '' && $aiDesc !== '') {
            $desc = mb_substr($aiDesc, 0, 1200);
            $changes[] = t('labels.ai_field.description');
        }

        $tags = json_list($persona['tags']);
        if (isset($ai['tags']) && is_array($ai['tags'])) {
            foreach ($ai['tags'] as $t) {
                if (!is_scalar($t)) {
                    continue;
                }
                $t = mb_strtolower(trim(ltrim(trim((string) $t), '#')));
                $t = mb_substr(preg_replace('/\s+/u', '-', $t) ?? $t, 0, 32);
                if ($t === '' || in_array($t, $tags, true) || count($tags) >= 12) {
                    continue;
                }
                $tags[] = $t;
                $changes[] = t('labels.ai_field.tags');
            }
        }

        $meta  = json_list($persona['meta']);
        $known = [];
        foreach ($meta as $row) {
            if (is_array($row) && isset($row['k'])) {
                $known[mb_strtolower(trim((string) $row['k']))] = true;
            }
        }
        if (isset($ai['meta']) && is_array($ai['meta'])) {
            foreach ($ai['meta'] as $row) {
                if (!is_array($row) || !isset($row['k']) || count($meta) >= 20) {
                    continue;
                }
                $k = trim((string) $row['k']);
                $k = mb_substr($k, 0, 40);
                $lower = mb_strtolower($k);
                if ($k === '' || isset($known[$lower])) {
                    continue;
                }
                $v = is_scalar($row['v'] ?? null) ? trim((string) $row['v']) : '';
                $meta[] = ['k' => $k, 'v' => mb_substr($v, 0, 200)];
                $known[$lower] = true;
                $changes[] = t('labels.ai_field.meta');
            }
        }

        if (!$changes) {
            echo json_encode(['ok' => true, 'changed' => [], 'message' => t('labels.ai.nothing_to_fill')]);
            exit;
        }

        $stmt = db()->prepare('UPDATE personas SET tagline=?, description=?, tags=?, meta=?, updated_at=datetime("now") WHERE id=?');
        $stmt->execute([
            $tagline,
            $desc,
            json_encode($tags, JSON_UNESCAPED_UNICODE),
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            $pid,
        ]);

        $changes = array_values(array_unique($changes));
        echo json_encode([
            'ok'      => true,
            'changed' => $changes,
            'message' => t('labels.ai.filled', ['fields' => implode(t('labels.list_separator'), $changes)]),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
