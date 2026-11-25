<?php
declare(strict_types=1);

/** Retrouve les paragraphes dans le bloc d'origine par position, même s'ils se répètent. */
function persona_prompt_audit_rows(array $prompts, array $systemAudit): array
{
    $rows = [];
    $offset = 0;
    foreach ($prompts as $prompt) {
        $body = trim((string) $prompt['body']);
        if ($prompt['kind'] !== 'system' || $body === '') {
            continue;
        }
        // chat_persona_identity() place les corps système, dans le même ordre, en tête du bloc.
        $start = strpos($systemAudit['original'], $body, $offset);
        if ($start === false) {
            throw new LogicException('Le prompt système enregistré manque dans le bloc à auditer.');
        }
        $offset = $start + strlen($body);
        $paragraphs = preg_split('/\R(?:[ \t]*\R)+/u', $body, -1, PREG_SPLIT_OFFSET_CAPTURE) ?: [[$body, 0]];
        $omissions = [];
        foreach ($paragraphs as $index => [$paragraph, $position]) {
            $position += $start + strlen($paragraph) - strlen(ltrim($paragraph));
            $paragraph = trim($paragraph);
            if ($systemAudit['omitted'] === null) {
                continue;
            }
            [$cutStart, $cutEnd] = $systemAudit['omitted'];
            $from = max($position, $cutStart);
            $to = min($position + strlen($paragraph), $cutEnd);
            if ($from < $to) {
                $omissions[] = [
                    'number' => $index + 1,
                    'partial' => $to - $from < strlen($paragraph),
                    'text' => substr($paragraph, $from - $position, $to - $from),
                ];
            }
        }
        $rows[] = ['title' => $prompt['title'], 'count' => count($paragraphs), 'omissions' => $omissions];
    }
    return $rows;
}

function persona_prompt_audit(array $persona, array $prompts, array $conversation, string $message, ?array $modelStatus = null): array
{
    $conv = chat_with_persona($conversation + ['id' => 0], $persona);
    $configuredWindow = max(1024, $conv['context_window']);
    $cfg = chat_llm_config_raw();
    $model = $conv['model'] ?: $cfg['model'];
    $loadedWindow = null;
    if ($cfg['provider'] === 'lmstudio' && $model !== '') {
        $modelStatus ??= lms_status();
        $loadedWindow = chat_loaded_model_context($model, $modelStatus['loaded'] ?? []);
        $conv = chat_apply_loaded_context($conv, $loadedWindow ?? 0);
    }

    $cid = (int) $conv['id'];
    $history = $cid > 0 ? fetch_context_messages($cid, $conv) : [];
    $systemAudit = [];
    $built = chat_build_messages(
        $persona, $prompts, $conv, $history, $message,
        canReact: $cid === 0 || chat_can_react($cid),
        addressOverride: relationship_memory_on($conv) ? relationship_direct_address($message) : '',
        systemAudit: $systemAudit,
    );

    $margin = $systemAudit['limit'] - $systemAudit['need'];

    return [
        'limit' => $systemAudit['limit'],
        'need' => $systemAudit['need'],
        'margin' => $margin,
        'suggestion' => persona_prompt_audit_window($persona, $conv, $systemAudit, $configuredWindow, $loadedWindow),
        'configuredWindow' => $configuredWindow,
        'effectiveWindow' => max(1024, $conv['context_window']),
        'loadedWindow' => $loadedWindow,
        'model' => $model,
        'rows' => persona_prompt_audit_rows($prompts, $systemAudit),
        'otherPrompts' => array_filter($prompts, static fn (array $p): bool => $p['kind'] !== 'system'),
        'system' => $built['messages'][0]['content'],
    ];
}

/**
 * Fenêtre de contexte qui ferait tenir le bloc système : la plus petite possible et une
 * confortable (marge d'environ un huitième), arrondies au pas de 1024 du formulaire.
 * null quand le prompt tient déjà ou quand même la fenêtre maximale ne suffit pas.
 */
function persona_prompt_audit_window(array $persona, array $conv, array $systemAudit, int $configuredWindow, ?int $loadedWindow): ?array
{
    if ($systemAudit['limit'] >= $systemAudit['need']) {
        return null;
    }
    $needSystem = (int) $systemAudit['needSystem'];
    // Les règles du personnage peuvent avoir été écartées faute de place : leur coût réel
    // doit tout de même être réservé pour la fenêtre proposée.
    $rulesCost = max((int) $systemAudit['rulesCost'], chat_estimate_tokens("\n\n" . chat_character_rules($persona, $conv)));
    $textCost = (int) $systemAudit['textCost'];
    $webRule = (string) $systemAudit['webRule'];
    $minimum = null;
    $window = null;
    for ($candidate = 2048; $candidate <= 131072; $candidate += 1024) {
        $probe = $conv;
        $probe['context_window'] = $candidate;
        $room = chat_system_limits($probe, $textCost, $webRule, $rulesCost)['system'];
        if ($minimum === null && $room >= $needSystem) {
            $minimum = $candidate;
        }
        if ($minimum !== null && $room - $needSystem >= max(192, intdiv($needSystem, 8))) {
            $window = $candidate;
            break;
        }
    }
    return [
        'minimum' => $minimum,
        'window' => $window ?? $minimum,
        'shortfall' => (int) $systemAudit['need'] - (int) $systemAudit['limit'],
        'configured' => $configuredWindow,
        'reload' => $loadedWindow !== null && ($window ?? $minimum ?? 0) > $loadedWindow,
    ];
}
