<?php
declare(strict_types=1);

/*
 * Réglages : modèles, discussion, apparence, prompts internes, tests.
 */

/* ---------------------------------------------------------------- réglages */

/* --- journal des échanges avec les modèles (20 derniers) --- */
if ($path === '/settings/logs' && $method === 'GET') {
    render('llm_logs', [
        'title' => t('logs.title'),
        'rows'  => llm_log_list(),
    ]);
    exit;
}

if ($path === '/settings/logs/clear' && $method === 'POST') {
    csrf_check();
    db()->exec('DELETE FROM llm_logs');
    flash('ok', t('logs.cleared'));
    redirect('/settings/logs');
}

/* --- discussions entre deux personas (menées en arrière-plan, rien dans les conversations) --- */
if ($path === '/settings/duo' && $method === 'GET') {
    render('duo', [
        'title'    => t('duo.title'),
        'personas' => db()->query('SELECT id, name, tagline, avatar FROM personas WHERE archived = 0 ORDER BY name COLLATE NOCASE')->fetchAll(),
        'sessions' => db()->query("SELECT s.*, a.name AS name_a, a.avatar AS avatar_a, b.name AS name_b, b.avatar AS avatar_b,
                (SELECT COUNT(*) FROM duo_lines l WHERE l.session_id = s.id AND l.kind = 'line') AS lines
              FROM duo_sessions s JOIN personas a ON a.id = s.persona_a JOIN personas b ON b.id = s.persona_b
             ORDER BY s.id DESC LIMIT 30")->fetchAll(),
    ]);
    exit;
}

if ($path === '/settings/duo' && $method === 'POST') {
    csrf_check();
    $a = (int) ($_POST['a'] ?? 0);
    $b = (int) ($_POST['b'] ?? 0);
    if ($a <= 0 || $b <= 0 || $a === $b || !duo_speaker($a) || !duo_speaker($b)) {
        flash('error', t('duo.error.personas'));
        redirect('/settings/duo');
    }
    $id = duo_session_create($a, $b, (string) ($_POST['topic'] ?? ''), (string) ($_POST['goal_a'] ?? ''),
        (string) ($_POST['goal_b'] ?? ''), (int) ($_POST['turns'] ?? 12), (string) ($_POST['scene'] ?? ''));
    duo_session_update($id, ['heartbeat_at' => time()]);
    duo_spawn($id);
    redirect('/settings/duo/' . $id);
}

if (preg_match('#^/settings/duo/(\d+)(?:/(state|control|direction|direction-clear|feelings|tick|report|delete))?$#', $path, $m)) {
    $duoId = (int) $m[1];
    $action = $m[2] ?? '';
    $s = duo_session($duoId);
    if (!$s) {
        if ($action === '' || $action === 'report') {
            http_response_code(404);
            render('notfound', ['title' => t('notfound.title')]);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => t('duo.error.missing')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $names = static function (array $s): array {
        $st = db()->prepare('SELECT id, name, avatar, tagline FROM personas WHERE id IN (?, ?)');
        $st->execute([(int) $s['persona_a'], (int) $s['persona_b']]);
        $rows = array_column($st->fetchAll(), null, 'id');
        return [$rows[(int) $s['persona_a']] ?? ['name' => '?', 'avatar' => '', 'tagline' => ''],
            $rows[(int) $s['persona_b']] ?? ['name' => '?', 'avatar' => '', 'tagline' => '']];
    };

    if ($action === '' && $method === 'GET') {
        $drive = !duo_kick($s);
        render('duo_session', [
            'title'   => t('duo.session_title'),
            'session' => $s,
            'people'  => $names($s),
            'state'   => duo_state(duo_session($duoId) ?? $s) + ['drive' => $drive || !duo_can_spawn()],
        ]);
        exit;
    }

    if ($action === 'report' && $method === 'GET') {
        $people = $names($s);
        $pn = [(string) $people[0]['name'], (string) $people[1]['name']];
        $feelings = [];
        foreach ([0, 1] as $w) {
            $pid = (int) $s[$w === 0 ? 'persona_a' : 'persona_b'];
            $oid = (int) $s[$w === 0 ? 'persona_b' : 'persona_a'];
            $mood = duo_mood($pid);
            $bond = duo_bond($pid, $oid);
            $feelings[] = t('duo.feelings.line', [
                'name' => $pn[$w], 'other' => $pn[1 - $w],
                'mood' => $mood['label'] . ($mood['mood'] !== '' ? ' (' . $mood['mood'] . ')' : ''),
                'friendship' => $bond['friendship'], 'love' => $bond['love'], 'anger' => $bond['anger'],
            ]);
        }
        $md = duo_report([
            'model' => (string) $s['model'], 'topic' => (string) $s['topic'], 'turns' => (int) $s['target'],
            'goals' => [(string) $s['goal_a'], (string) $s['goal_b']], 'end' => (string) $s['end_reason'],
            'error' => (string) $s['error'], 'feelings' => $feelings,
        ], $pn, duo_session_lines($duoId));
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="discussion-' . $duoId . '.md"');
        echo $md;
        exit;
    }

    if ($action === 'delete' && $method === 'POST') {
        csrf_check();
        duo_control($duoId, 'stop');
        db()->prepare('DELETE FROM duo_sessions WHERE id = ?')->execute([$duoId]);
        flash('ok', t('duo.deleted'));
        redirect('/settings/duo');
    }

    header('Content-Type: application/json; charset=utf-8');
    if ($action === 'state' && $method === 'GET') {
        session_write_close();
        $drive = !duo_kick($s);
        echo json_encode(duo_state(duo_session($duoId) ?? $s, (int) ($_GET['after'] ?? 0)) + ['drive' => $drive || !duo_can_spawn()],
            JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (in_array($action, ['control', 'direction', 'direction-clear', 'feelings', 'tick'], true) && $method === 'POST') {
        csrf_check_json();
        session_write_close();
        if ($action === 'control') {
            $op = (string) ($_POST['op'] ?? '');
            $wasRunning = $s['status'] === 'running';
            $s = duo_control($duoId, $op);
            if (!$s) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => t('duo.error.action')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!$wasRunning && $s['status'] === 'running') { // relancée : un worker reprend l'échange
                duo_session_update($duoId, ['heartbeat_at' => time()]);
                duo_spawn($duoId);
            }
        } elseif ($action === 'direction') {
            $text = trim((string) ($_POST['text'] ?? ''));
            if ($text === '') {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => t('duo.error.direction')], JSON_UNESCAPED_UNICODE);
                exit;
            }
            duo_direction($duoId, $text, (int) ($_POST['target'] ?? -1));
        } elseif ($action === 'feelings') {
            $values = array_intersect_key($_POST, array_flip(['friendship', 'love', 'anger', 'emotion', 'intensity']));
            duo_feelings_set($s, (int) ($_POST['who'] ?? 0), $values);
        } elseif ($action === 'direction-clear') {
            duo_direction_clear($duoId, (int) ($_POST['target'] ?? -1));
        } else {
            // le serveur ne peut pas lancer de processus : le navigateur mène l'échange
            @set_time_limit(400);
            duo_step($duoId);
        }
        echo json_encode(duo_state(duo_session($duoId) ?? $s, (int) ($_POST['after'] ?? 0)) + ['drive' => !duo_can_spawn()],
            JSON_UNESCAPED_UNICODE);
        exit;
    }
}

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
    flash('ok', t('flash.settings.prompts_saved'));
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
    $tabbar = (string) ($_POST['ui_tabbar'] ?? 'float');
    set_setting('ui_tabbar', in_array($tabbar, ['float', 'pill', 'classic'], true) ? $tabbar : 'float');
    $accent = (string) ($_POST['ui_accent'] ?? 'violet');
    set_setting('ui_accent', in_array($accent, UI_ACCENTS, true) ? $accent : 'violet');
    $mode = (string) ($_POST['ui_mode'] ?? 'dark');
    set_setting('ui_mode', in_array($mode, UI_MODES, true) ? $mode : 'dark');
    $chatList = (string) ($_POST['ui_chat_list'] ?? setting('ui_chat_list', 'threads'));
    set_setting('ui_chat_list', $chatList === 'contacts' ? 'contacts' : 'threads');
    set_setting('ui_avatars', !empty($_POST['ui_avatars']) ? '1' : '0');
    // langue de l'interface : « auto » (navigateur) ou une langue installée dans lang/
    $lang = (string) ($_POST['ui_lang'] ?? 'auto');
    set_setting('ui_lang', $lang === 'auto' || isset(i18n_available()[$lang]) ? $lang : 'auto');
    i18n_set_locale(null); // le message de confirmation part déjà dans la nouvelle langue

    flash('ok', t('settings.look.saved'));
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
            flash('error', t('error.settings.url_scheme'));
            redirect('/settings');
        }
        if ($model === '') {
            flash('error', t('flash.settings.model_missing'));
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
        flash('ok', t('flash.settings.saved'));
        redirect('/settings');
    }

    $chatCfg    = chat_llm_config_raw();
    $chatModels = chat_models($chatCfg['base_url'], $chatCfg['key']); // un seul appel réseau
    $llmCfg     = llm_config();
    $keyPreviews = [
        'llm' => secret_preview($llmCfg['key']),
        'chat' => secret_preview($chatCfg['key']),
        'klipy' => secret_preview(setting('klipy_api_key')),
        'brave' => secret_preview(chat_web_search_key()),
    ];
    unset($llmCfg['key'], $chatCfg['key']);
    render('settings', [
        'title'      => t('nav.settings'),
        'llm'        => $llmCfg,
        'keyPreviews' => $keyPreviews,
        'searchMcp' => chat_web_mcp_config(),
        'chat'       => $chatCfg,
        'chatModels'     => $chatModels,
        'chatModelList'  => chat_models_ordered($chatModels),
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
    $klipyKey = trim((string) ($_POST['klipy_api_key'] ?? ''));
    if (isset($_POST['klipy_api_key_clear'])) {
        set_setting('klipy_api_key', '');
    } elseif ($klipyKey !== '') {
        set_setting('klipy_api_key', $klipyKey);
    }

    if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
        flash('error', t('error.settings.url_scheme'));
        redirect('/settings');
    }

    $previousModel = chat_llm_config_raw()['model'];
    if (isset($_POST['chat_user_profile']) && is_string($_POST['chat_user_profile'])) {
        $profile = mb_substr(trim($_POST['chat_user_profile']), 0, CHAT_USER_PROFILE_MAX);
        db_tx(static function () use ($profile): void {
            set_setting('chat_user_profile', $profile);
            set_setting('chat_user_profile_enabled', ($_POST['chat_user_profile_enabled'] ?? '') === '1' ? '1' : '0');
        });
    }
    set_setting('chat_llm_provider', trim((string) ($_POST['chat_provider'] ?? 'lmstudio')));
    set_setting('chat_memory_threshold', (string) max(600, min(100000, (int) ($_POST['chat_memory_threshold'] ?? 3000))));
    set_setting('chat_memory_target', (string) max(300, min(50000, (int) ($_POST['chat_memory_target'] ?? 1600))));
    $effort = (string) ($_POST['chat_reasoning_effort'] ?? 'none');
    set_setting('chat_reasoning_effort', in_array($effort, ['none', 'low', 'medium', 'high'], true) ? $effort : 'none');
    set_setting('chat_split_enabled', !empty($_POST['chat_split_enabled']) ? '1' : '0');
    set_setting('chat_split_words', (string) max(15, min(120, (int) ($_POST['chat_split_words'] ?? 50))));
    $splitPosition = $_POST['chat_split_position'] ?? setting('chat_split_position', 'before');
    set_setting('chat_split_position', $splitPosition === 'after' ? 'after' : 'before');
    set_setting('chat_split_max_bubbles', (string) max(1, min(8, (int) ($_POST['chat_split_max_bubbles'] ?? 2))));
    $dmin = max(0.5, min(CHAT_SPLIT_DELAY_MAX, (float) ($_POST['chat_split_delay_min'] ?? 1)));
    $dmax = max(0.5, min(CHAT_SPLIT_DELAY_MAX, (float) ($_POST['chat_split_delay_max'] ?? 2)));
    if ($dmax < $dmin) {
        [$dmin, $dmax] = [$dmax, $dmin];
    }
    set_setting('chat_split_delay_min', (string) $dmin);
    set_setting('chat_split_delay_max', (string) $dmax);

    set_setting('chat_followup_enabled', !empty($_POST['chat_followup_enabled']) ? '1' : '0');
    set_setting('chat_followup_idle_hours', (string) max(1, min(72, (int) ($_POST['chat_followup_idle_hours'] ?? 6))));
    set_setting('chat_followup_min_gap_hours', (string) max(1, min(168, (int) ($_POST['chat_followup_min_gap_hours'] ?? 12))));
    set_setting('chat_attention_chance', (string) max(0, min(100, (int) ($_POST['chat_attention_chance'] ?? 20))));
    set_setting('chat_followup_max_streak', (string) max(1, min(5, (int) ($_POST['chat_followup_max_streak'] ?? 2))));
    set_setting('chat_followup_from', (string) max(0, min(23, (int) ($_POST['chat_followup_from'] ?? 8))));
    set_setting('chat_followup_to', (string) max(1, min(24, (int) ($_POST['chat_followup_to'] ?? 23))));

    // recherche d'informations (internet via SearXNG, Wikipédia, météo) : seulement si le bloc était dans le formulaire
    if (!empty($_POST['web_settings_present'])) {
        set_setting('web_enabled', !empty($_POST['web_enabled']) ? '1' : '0');
        $searx = rtrim(trim((string) ($_POST['web_searxng_url'] ?? '')), '/');
        if ($searx !== '' && (!preg_match('~\Ahttps?://~i', $searx) || !filter_var($searx, FILTER_VALIDATE_URL))) {
            flash('error', t('error.settings.url_scheme'));
            $searx = setting('web_searxng_url', '');
        }
        set_setting('web_searxng_url', mb_substr($searx, 0, 300));
        set_setting('web_wikipedia', !empty($_POST['web_wikipedia']) ? '1' : '0');
        set_setting('web_weather', !empty($_POST['web_weather']) ? '1' : '0');
    }

    // souvenirs durables : quand et avec quel modèle les extraire
    $ff = (string) ($_POST['facts_frequency'] ?? 'every');
    set_setting('facts_frequency', in_array($ff, ['each', 'every', 'explicit', 'off'], true) ? $ff : 'every');
    set_setting('facts_every', (string) max(2, min(30, (int) ($_POST['facts_every'] ?? 6))));
    $ft = (string) ($_POST['facts_timing'] ?? 'now');
    set_setting('facts_timing', in_array($ft, ['now', 'idle'], true) ? $ft : 'now');
    set_setting('facts_idle_seconds', (string) max(10, min(600, (int) ($_POST['facts_idle_seconds'] ?? 45))));
    $fm = (string) ($_POST['facts_model'] ?? 'chat');
    set_setting('facts_model', in_array($fm, ['chat', 'remote'], true) ? $fm : 'chat');

    // un seul modèle en mémoire dans LM Studio (les autres déchargés à chaque changement)
    set_setting('lms_single_model', !empty($_POST['lms_single_model']) ? '1' : '0');
    // déchargement après inactivité : 0 = jamais, sinon de 30 min à 8 h par paliers de 30 min
    $idle = (int) ($_POST['lms_idle_unload_minutes'] ?? 0);
    set_setting('lms_idle_unload_minutes', (string) (int) (round(max(0, min(480, $idle)) / 30) * 30));

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
    flash('ok', t('flash.settings.chat_saved')
        . ($purged ? ' ' . t('flash.settings.server_purged', ['models' => t('labels.count.models_unloaded', count($purged))]) : ''));
    redirect('/settings#chat');
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

if ($path === '/settings/klipy/test' && $method === 'POST') {
    csrf_check_json();
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $input = $_POST['klipy_api_key'] ?? '';
    $key = is_string($input) ? trim($input) : '';
    $key = $key !== '' ? $key : setting('klipy_api_key');
    $ok = false;
    if ($key === '' || strlen($key) > 255) {
        $message = t('settings.chat.klipy_test_missing');
    } else {
        try {
            $ok = klipy_search_media('gif', 'celebrate', $key, [], true) !== '';
            $message = $ok ? t('settings.chat.klipy_test_ok') : t('settings.chat.klipy_test_empty');
        } catch (RuntimeException $error) {
            $message = $error->getMessage(); // erreurs traduites par le connecteur, sans URL ni corps distant
        }
    }
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method === 'POST' && in_array($path, ['/settings/test', '/settings/chat/test', '/settings/brave/test', '/settings/mcp/test', '/settings/searxng/test'], true)) {
    csrf_check_json();
    session_write_close();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $field = static fn (string $name): string => is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
    try {
        if ($path === '/settings/searxng/test') {
            // adresse saisie (pas encore enregistrée), sinon celle des réglages
            $base = rtrim($field('web_searxng_url'), '/') ?: chat_web_searxng_url();
            if ($base === '') {
                throw new RuntimeException(t('settings.web.searxng_missing'));
            }
            if (!preg_match('~\Ahttps?://~i', $base) || !filter_var($base, FILTER_VALIDATE_URL)) {
                throw new RuntimeException(t('error.settings.url_scheme'));
            }
            try {
                $found = chat_web_searxng_results('Tour Eiffel Paris', $base, null, true);
            } catch (RuntimeException $e) {
                // 403 : SearXNG répond, mais le format JSON n'est pas autorisé dans son settings.yml
                throw $e->getCode() === 403 ? new RuntimeException(t('settings.web.searxng_json')) : $e;
            }
            if (!$found) {
                throw new RuntimeException(t('settings.connection.search_empty'));
            }
            echo json_encode(['ok' => true, 'message' => t('settings.connection.search_ok')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($path === '/settings/brave/test' || $path === '/settings/mcp/test') {
            $query = 'Tour Eiffel Paris site officiel';
            if ($path === '/settings/brave/test') {
                $key = chat_web_search_key();
                if ($key === '') {
                    throw new RuntimeException(t('error.llm.no_key'));
                }
                $results = chat_web_results($query, $key, null, true);
            } else {
                $cfg = chat_llm_config();
                $integration = chat_web_mcp_config();
                if ($cfg['key'] === '') {
                    throw new RuntimeException(t('error.llm.no_key'));
                }
                if ($cfg['model'] === '') {
                    throw new RuntimeException(t('error.llm.no_model'));
                }
                if ($integration === null) {
                    throw new RuntimeException(t('settings.connection.mcp_missing'));
                }
                $results = chat_web_lmstudio_results($query, $cfg, $integration['id'], $integration['tool'], null, true);
            }
            if (!$results) {
                throw new RuntimeException(t('settings.connection.search_empty'));
            }
            echo json_encode(['ok' => true, 'message' => t('settings.connection.search_ok')], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $isChat = $path === '/settings/chat/test';
        $prefix = $isChat ? 'chat_' : '';
        $stored = $isChat ? chat_llm_config_raw() : llm_config();
        $baseUrl = rtrim($field($prefix . 'base_url'), '/');
        $model = $field($prefix . 'model');
        $key = $field($prefix . 'api_key');
        if (!preg_match('#^https?://#i', $baseUrl) || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException(t('error.settings.url_scheme'));
        }
        if ($key === '' && $stored['key'] !== '' && $baseUrl !== rtrim($stored['base_url'], '/')) {
            throw new RuntimeException(t('error.settings.test_other_url'));
        }
        $key = $key !== '' ? $key : $stored['key'];
        if (!$isChat && $key === '') {
            throw new RuntimeException(t('error.llm.no_key'));
        }
        if ($model === '') {
            throw new RuntimeException(t('error.llm.no_model'));
        }
        $ms = llm_test_connection($baseUrl, $model, $key);
        echo json_encode(['ok' => true, 'ms' => $ms, 'modele' => $model], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        http_response_code(400);
        $status = $e->getCode();
        $message = $e->getMessage() ?: service_connection_error($status);
        echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
