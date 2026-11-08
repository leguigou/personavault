<?php
declare(strict_types=1);

/*
 * Réglages : modèles, discussion, apparence, prompts internes, tests.
 */

/* ---------------------------------------------------------------- réglages */

if ($path === '/settings/prompts' && $method === 'POST') {
    csrf_check();
    $defaults = engine_prompt_defaults();
    foreach (['memory', 'facts', 'followup'] as $key) {
        if (!empty($_POST['reset_' . $key])) {
            set_setting('prompt_' . $key, '');
            continue;
        }
        $value = trim((string) ($_POST['prompt_' . $key] ?? ''));
        // valeur identique au défaut → on n'enregistre rien (retour au défaut)
        set_setting('prompt_' . $key, $value === trim($defaults[$key]) ? '' : $value);
    }
    flash('ok', 'Prompts enregistrés.');
    redirect('/settings#prompts');
}

if ($path === '/settings/ui' && $method === 'POST') {
    csrf_check();

    $font = (float) ($_POST['ui_font_size'] ?? 14.5);
    $font = max(11.0, min(22.0, $font));

    $bubble = (string) ($_POST['ui_bubble_style'] ?? 'bulle');
    if (!in_array($bubble, ['bulle', 'plat', 'carte'], true)) {
        $bubble = 'bulle';
    }
    $density = (string) ($_POST['ui_density'] ?? 'confort');
    if (!in_array($density, ['confort', 'compact'], true)) {
        $density = 'confort';
    }
    $width = (string) ($_POST['ui_width'] ?? 'normal');
    if (!in_array($width, ['normal', 'large'], true)) {
        $width = 'normal';
    }

    set_setting('ui_font_size', (string) $font);
    set_setting('ui_bubble_style', $bubble);
    set_setting('ui_density', $density);
    set_setting('ui_width', $width);
    set_setting('ui_avatars', !empty($_POST['ui_avatars']) ? '1' : '0');

    flash('ok', 'Apparence enregistrée.');
    redirect('/settings#look');
}

if ($path === '/settings') {
    if ($method === 'POST') {
        csrf_check();
        $provider = trim((string) ($_POST['provider'] ?? 'deepseek'));
        $baseUrl  = trim((string) ($_POST['base_url'] ?? ''));
        $model    = trim((string) ($_POST['model'] ?? ''));
        $apiKey   = trim((string) ($_POST['api_key'] ?? ''));

        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            flash('error', 'L’URL doit commencer par http:// ou https://');
            redirect('/settings');
        }
        if ($model === '') {
            flash('error', 'Il manque le nom du modèle.');
            redirect('/settings');
        }

        set_setting('llm_provider', $provider);
        set_setting('llm_base_url', rtrim($baseUrl, '/'));
        set_setting('llm_model', $model);
        if (($apiKey === '' && !empty($_POST['clear_key']))) {
            set_setting('llm_api_key', '');
        } elseif ($apiKey !== '') {
            set_setting('llm_api_key', $apiKey);
        }
        flash('ok', 'Réglages enregistrés.');
        redirect('/settings');
    }

    $chatCfg    = chat_llm_config_raw();
    $chatModels = chat_models($chatCfg['base_url'], $chatCfg['key']); // un seul appel réseau
    $llmCfg     = llm_config();
    render('settings', [
        'title'      => 'Réglages',
        'llm'        => $llmCfg,
        'hasKey'     => $llmCfg['key'] !== '',
        'chat'       => $chatCfg,
        'chatModels'     => $chatModels,
        'chatModelList'  => chat_models_ordered($chatModels),
        'chatHasKey' => $chatCfg['key'] !== '',
        'chatReasoning' => setting('chat_reasoning_effort', 'none'),
        'style'         => ui_style(),
        'backupStats'   => [
            'personas'      => (int) db()->query('SELECT COUNT(*) FROM personas')->fetchColumn(),
            'photos'        => (int) db()->query('SELECT COUNT(*) FROM photos')->fetchColumn(),
            'prompts'       => (int) db()->query('SELECT COUNT(*) FROM prompts')->fetchColumn(),
            'conversations' => (int) db()->query('SELECT COUNT(*) FROM conversations')->fetchColumn(),
        ],
    ]);
    exit;
}

if ($path === '/settings/chat' && $method === 'POST') {
    csrf_check();
    $baseUrl = trim((string) ($_POST['chat_base_url'] ?? ''));
    $model   = trim((string) ($_POST['chat_model'] ?? ''));
    $key     = trim((string) ($_POST['chat_api_key'] ?? ''));

    if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
        flash('error', 'L’URL doit commencer par http:// ou https://');
        redirect('/settings');
    }

    $previousModel = chat_llm_config_raw()['model'];
    set_setting('chat_llm_provider', trim((string) ($_POST['chat_provider'] ?? 'lmstudio')));
    set_setting('chat_memory_threshold', (string) max(600, min(100000, (int) ($_POST['chat_memory_threshold'] ?? 3000))));
    set_setting('chat_memory_target', (string) max(300, min(50000, (int) ($_POST['chat_memory_target'] ?? 1600))));
    $effort = (string) ($_POST['chat_reasoning_effort'] ?? 'none');
    set_setting('chat_reasoning_effort', in_array($effort, ['none', 'low', 'medium', 'high'], true) ? $effort : 'none');
    set_setting('chat_split_enabled', !empty($_POST['chat_split_enabled']) ? '1' : '0');
    set_setting('chat_split_words', (string) max(15, min(120, (int) ($_POST['chat_split_words'] ?? 50))));
    set_setting('chat_split_max_bubbles', (string) max(1, min(8, (int) ($_POST['chat_split_max_bubbles'] ?? 2))));
    $dmin = max(0.5, min(3.0, (float) ($_POST['chat_split_delay_min'] ?? 1)));
    $dmax = max(0.5, min(4.0, (float) ($_POST['chat_split_delay_max'] ?? 2)));
    if ($dmax < $dmin) {
        [$dmin, $dmax] = [$dmax, $dmin];
    }
    set_setting('chat_split_delay_min', (string) $dmin);
    set_setting('chat_split_delay_max', (string) $dmax);

    set_setting('chat_followup_enabled', !empty($_POST['chat_followup_enabled']) ? '1' : '0');
    set_setting('chat_followup_idle_hours', (string) max(1, min(72, (int) ($_POST['chat_followup_idle_hours'] ?? 6))));
    set_setting('chat_followup_from', (string) max(0, min(23, (int) ($_POST['chat_followup_from'] ?? 8))));
    set_setting('chat_followup_to', (string) max(1, min(24, (int) ($_POST['chat_followup_to'] ?? 23))));

    // souvenirs durables : quand et avec quel modèle les extraire
    $ff = (string) ($_POST['facts_frequency'] ?? 'each');
    set_setting('facts_frequency', in_array($ff, ['each', 'every', 'explicit', 'off'], true) ? $ff : 'each');
    set_setting('facts_every', (string) max(2, min(30, (int) ($_POST['facts_every'] ?? 6))));
    $ft = (string) ($_POST['facts_timing'] ?? 'now');
    set_setting('facts_timing', in_array($ft, ['now', 'idle'], true) ? $ft : 'now');
    set_setting('facts_idle_seconds', (string) max(10, min(600, (int) ($_POST['facts_idle_seconds'] ?? 45))));
    $fm = (string) ($_POST['facts_model'] ?? 'chat');
    set_setting('facts_model', in_array($fm, ['chat', 'remote'], true) ? $fm : 'chat');

    // titres automatiques des discussions
    $tm = (string) ($_POST['chat_title_model'] ?? 'remote');
    set_setting('chat_title_model', in_array($tm, ['remote', 'chat', 'off'], true) ? $tm : 'remote');

    set_setting('chat_llm_base_url', rtrim($baseUrl, '/'));
    set_setting('chat_llm_model', $model);
    if ($key !== '') {
        set_setting('chat_llm_api_key', $key);
    } elseif (!empty($_POST['chat_clear_key'])) {
        set_setting('chat_llm_api_key', '');
    }
    $purged = [];
    if ($model !== '' && $model !== $previousModel) {
        // on libère la VRAM avant de charger le nouveau modèle
        $purged = lms_purge();
    }
    flash('ok', 'Réglages de discussion enregistrés.'
        . ($purged ? ' Mémoire du serveur purgée (' . plural(count($purged), 'modèle déchargé', 'modèles déchargés') . ').' : ''));
    redirect('/settings');
}

if ($path === '/settings/purge' && $method === 'POST') {
    csrf_check_json();
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $before = lms_loaded_models();
        $done   = lms_purge();
        echo json_encode([
            'ok'       => true,
            'before'   => array_map(fn ($m) => $m['id'], $before),
            'unloaded' => $done,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($path === '/settings/test' && $method === 'POST') {
    csrf_check_json();
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    $stored  = llm_config();
    $key     = trim((string) ($_POST['api_key'] ?? ''));
    $baseUrl = rtrim(trim((string) ($_POST['base_url'] ?? '')), '/');
    // la clé ENREGISTRÉE n'est envoyée qu'à l'adresse enregistrée : tester une autre
    // adresse exige de saisir la clé (évite de l'exfiltrer vers un serveur quelconque)
    $sameUrl = $baseUrl !== '' && strcasecmp($baseUrl, rtrim($stored['base_url'], '/')) === 0;
    $cfg     = [
        'base_url' => $baseUrl,
        'model'    => trim((string) ($_POST['model'] ?? '')),
        'key'      => $key !== '' ? $key : ($sameUrl ? $stored['key'] : ''),
    ];
    try {
        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new RuntimeException('L’URL doit commencer par http:// ou https://');
        }
        if ($cfg['key'] === '' && $key === '' && !$sameUrl && $stored['key'] !== '') {
            throw new RuntimeException('Adresse différente de celle enregistrée : saisis la clé API pour la tester.');
        }
        $t0  = microtime(true);
        $out = llm_chat([
            ['role' => 'system', 'content' => 'Tu réponds uniquement par le mot OK.'],
            ['role' => 'user', 'content' => 'ping'],
        ], false, 30, $cfg);
        echo json_encode([
            'ok'      => true,
            'ms'      => (int) round((microtime(true) - $t0) * 1000),
            'modele'  => $cfg['model'],
            'reponse' => mb_substr(trim($out), 0, 40),
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => public_error($e)], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
