<?php
/** @var array $llm @var bool $hasKey @var array $chat @var array $chatModels @var bool $chatHasKey */
/*
 * Textes : lang/<langue>/settings.php. Une ligne vide après une balise PHP fermante en fin de ligne
 * est voulue : PHP avale le saut de ligne qui suit cette balise, la ligne vide le rétablit
 * (sortie HTML inchangée).
 */
$providers = [
    'deepseek'   => 'DeepSeek',
    'openai'     => 'OpenAI (ChatGPT)',
    'xai'        => 'xAI (Grok)',
    'gemini'     => 'Google Gemini',
    'mistral'    => 'Mistral AI',
    'openrouter' => 'OpenRouter',
    'lmstudio'   => t('settings.provider.lmstudio'),
    'custom'     => t('settings.provider.custom'),
];
// bouton « i » qui ouvre une explication en fenêtre (contenu : <template data-info-body> en bas de page)
$info = static fn (string $key, string $label): string =>
    '<button type="button" class="info-btn" data-info="' . e($key) . '" aria-label="' . e(t('settings.info_more', ['label' => $label])) . '">i</button>';
$facts = facts_settings();
?>
<a class="back settings-back" href="/">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('nav.library')) ?>

</a>

<section class="form-wrap settings-page">
    <header class="form-head settings-head">
        <div class="settings-title">
            <h1><?= e(t('nav.settings')) ?></h1>
            <!-- déconnexion (mobile) : icône à droite du titre ; sur ordinateur, elle est dans la barre du haut -->
            <form method="post" action="/logout" class="settings-logout" data-confirm="<?= e(t('logout.confirm')) ?>" data-confirm-title="<?= e(t('logout.title')) ?>" data-confirm-ok="<?= e(t('nav.logout')) ?>">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <button class="icon-btn settings-logout-btn" type="submit" aria-label="<?= e(t('nav.logout')) ?>" title="<?= e(t('nav.logout')) ?>">
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
                </button>
            </form>
        </div>
        <p class="form-sub"><?= e(t('settings.subtitle')) ?></p>
    </header>

    <nav class="tabs" data-tabs aria-label="<?= e(t('settings.tabs_label')) ?>">
        <button type="button" class="tab is-on" data-tab="ia"><span class="tab-long"><?= e(t('settings.tab.ia_long')) ?></span><span class="tab-short"><?= e(t('settings.tab.ia_short')) ?></span></button>
        <button type="button" class="tab" data-tab="chat"><span class="tab-long"><?= e(t('settings.tab.chat_long')) ?></span><span class="tab-short"><?= e(t('settings.tab.chat_short')) ?></span></button>
        <button type="button" class="tab" data-tab="look"><?= e(t('settings.tab.look')) ?></button>
        <button type="button" class="tab" data-tab="prompts"><?= e(t('settings.tab.prompts')) ?></button>
        <button type="button" class="tab" data-tab="backup"><?= e(t('settings.tab.backup')) ?></button>
    </nav>

    <div class="tab-panel" data-panel="ia">
    <header class="form-head settings-sub">
        <h2><?= e(t('settings.ia.title')) ?> <?= $info('ia-model', t('settings.info.ia_model_label')) ?></h2>
        <p class="form-sub"><?= e(t('settings.ia.subtitle')) ?></p>
    </header>

    <form class="card-form" method="post" action="/settings" data-settings-form data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="field">
            <span><?= e(t('settings.field.provider')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="provider" class="sel-native">
                    <?php foreach ($providers as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $llm['provider'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small><?= e(t('settings.ia.provider_hint')) ?></small>
        </div>

        <div class="row2">
            <label class="field">
                <span><?= e(t('settings.field.base_url')) ?></span>
                <input class="input" name="base_url" value="<?= e($llm['base_url']) ?>" placeholder="https://api.deepseek.com/v1" spellcheck="false">
            </label>
            <label class="field">
                <span><?= e(t('settings.field.model')) ?></span>
                <input class="input" name="model" value="<?= e($llm['model']) ?>" placeholder="deepseek-chat" spellcheck="false" autocomplete="off" data-model-input>
                <small><?= e(t('settings.ia.model_examples')) ?></small>
            </label>
        </div>

        <label class="field">
            <span><?= e(t('settings.ia.api_key')) ?> <?= $info('api-key', t('settings.info.api_key_label')) ?></span>
            <input class="input" type="password" name="api_key" autocomplete="off" spellcheck="false"
                   placeholder="<?= $hasKey ? e(t('settings.ia.api_key_keep')) : 'sk-…' ?>">
            <small>
                <?php if ($hasKey): ?>
                    <?= e(t('settings.ia.api_key_saved_help')) ?>

                <?php else: ?>
                    <?= e(t('settings.ia.api_key_help')) ?>

                <?php endif; ?>
            </small>
        </label>

        <?php if ($hasKey): ?>
            <label class="check">
                <input type="checkbox" name="clear_key" value="1">
                <span><?= e(t('settings.clear_key')) ?></span>
            </label>
        <?php endif; ?>

        <div class="llm-status" data-llm-status>
            <?php if ($hasKey): ?>
                <span class="dot dot-ok"></span> <?= e(t('settings.ia.status_ready', ['model' => $llm['model']])) ?>
            <?php else: ?>
                <span class="dot"></span> <?= e(t('settings.ia.status_no_key')) ?>

            <?php endif; ?>
        </div>

        <div class="form-actions save-bar">
            <span class="dirty-note"><?= e(t('settings.dirty')) ?></span>
            <button type="button" class="btn btn-ghost" data-llm-test><?= e(t('settings.ia.test')) ?></button>
            <button class="btn btn-primary" type="submit"><?= e(t('settings.save')) ?></button>
        </div>
    </form>

    <section class="panel panel-help">
        <h2><?= e(t('settings.ia.how_title')) ?></h2>
        <p class="hint">
            <?= t('settings.ia.how_text_html') ?>

        </p>
        <p class="hint">
            <?= e(t('settings.ia.how_privacy')) ?>

        </p>
    </section>
    </div>

    <div class="tab-panel" data-panel="chat" hidden>
    <header class="form-head settings-sub">
        <h2><?= e(t('settings.chat.title')) ?></h2>
        <p class="form-sub"><?= e(t('settings.chat.subtitle')) ?></p>
    </header>

    <form class="settings-stack" method="post" action="/settings/chat" data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <section class="settings-card">
            <h3 class="settings-card-title"><?= e(t('settings.user_profile.title')) ?></h3>
            <label class="check">
                <input type="checkbox" name="chat_user_profile_enabled" value="1" <?= setting('chat_user_profile_enabled', '0') === '1' ? 'checked' : '' ?>>
                <span><?= e(t('settings.user_profile.enable')) ?></span>
            </label>
            <label class="field">
                <span><?= e(t('settings.user_profile.label')) ?></span>
                <textarea class="input" name="chat_user_profile" rows="3" maxlength="<?= CHAT_USER_PROFILE_MAX ?>" aria-describedby="chat-user-profile-help"><?= e(setting('chat_user_profile', '')) ?></textarea>
            </label>
            <p class="hint" id="chat-user-profile-help"><?= e(t('settings.user_profile.help')) ?></p>
        </section>

        <section class="settings-card">
        <h3 class="settings-card-title"><?= e(t('settings.chat.connection_title')) ?> <?= $info('chat-model', t('settings.info.chat_model_label')) ?></h3>
        <div class="row2">
            <div class="field">
                <span><?= e(t('settings.field.provider')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="chat_provider" class="sel-native">
                        <?php foreach (['lmstudio' => t('settings.provider.lmstudio'), 'openai' => 'OpenAI', 'openrouter' => 'OpenRouter', 'custom' => t('settings.provider.other')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $chat['provider'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="field">
                <span><?= e(t('settings.field.base_url')) ?></span>
                <input class="input" name="chat_base_url" value="<?= e($chat['base_url']) ?>" placeholder="http://localhost:1234/v1" spellcheck="false">
            </label>
        </div>

        <div class="field">
            <span><?= e(t('settings.field.model')) ?></span>
            <?php if ($chatModels): ?>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.chat.model_aria')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="chat_model" class="sel-native">
                        <?php foreach ($chatModelList as $m): ?>
                            <option value="<?= e($m['id']) ?>" <?= $m['id'] === $chat['model'] ? 'selected' : '' ?>><?= $m['fav'] ? '★ ' : '' ?><?= e($m['id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small><?= e(t('settings.chat.models_detected', count($chatModels))) ?></small>
            <?php else: ?>
                <input class="input" name="chat_model" value="<?= e($chat['model']) ?>" placeholder="qwen/qwen3.5-9b" spellcheck="false">
                <small><?= e(t('settings.chat.model_manual')) ?></small>
            <?php endif; ?>
        </div>

        <label class="field">
            <span><?= e(t('settings.chat.api_key')) ?></span>
            <input class="input" type="password" name="chat_api_key" autocomplete="off" spellcheck="false"
                   placeholder="<?= e($chatHasKey ? t('settings.chat.api_key_saved_placeholder') : t('settings.chat.api_key_placeholder')) ?>">
        </label>

        <?php if ($chatHasKey): ?>
            <label class="check">
                <input type="checkbox" name="chat_clear_key" value="1">
                <span><?= e(t('settings.clear_key')) ?></span>
            </label>
        <?php endif; ?>
        </section>

        <section class="settings-card">
        <h3 class="settings-card-title"><?= e(t('settings.chat.memory_title')) ?> <?= $info('compression', t('settings.info.compression_label')) ?></h3>
        <div class="field">
            <span><?= e(t('settings.chat.reasoning')) ?> <?= $info('reasoning', t('settings.info.reasoning_label')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.chat.reasoning')) ?>">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="chat_reasoning_effort" class="sel-native">
                    <?php foreach (['none' => t('settings.chat.reasoning_none'), 'low' => t('settings.chat.reasoning_low'), 'medium' => t('settings.chat.reasoning_medium'), 'high' => t('settings.chat.reasoning_high')] as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= ($chatReasoning ?? 'none') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small><?= e(t('settings.chat.reasoning_help')) ?></small>
        </div>

        <div class="row2">
            <label class="field">
                <span><?= e(t('settings.chat.threshold')) ?></span>
                <input class="input" type="number" name="chat_memory_threshold" min="600" max="100000" step="100" value="<?= (int) chat_memory_threshold() ?>">
                <small><?= e(t('settings.chat.threshold_help')) ?></small>
            </label>
            <label class="field">
                <span><?= e(t('settings.chat.target')) ?></span>
                <input class="input" type="number" name="chat_memory_target" min="300" max="50000" step="100" value="<?= (int) chat_memory_target() ?>">
                <small><?= e(t('settings.chat.target_help')) ?></small>
            </label>
        </div>
        </section>

        <details class="settings-card settings-fold">
            <summary>
                <span class="fold-text">
                    <span class="fold-title"><?= e(t('settings.split.title')) ?> <?= $info('split', t('settings.info.split_label')) ?></span>
                    <span class="fold-sum" data-fold-sum="split"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint"><?= e(t('settings.split.hint')) ?></p>

            <label class="check">
                <input type="checkbox" name="chat_split_enabled" value="1" <?= setting('chat_split_enabled', '1') === '1' ? 'checked' : '' ?>>
                <span><?= e(t('settings.split.enable')) ?></span>
            </label>

            <label class="field range-field">
                <span><?= e(t('settings.split.words')) ?> <b data-out="words"><?= (int) setting('chat_split_words', '50') ?></b></span>
                <input class="range" type="range" name="chat_split_words" min="15" max="120" step="5"
                       value="<?= (int) setting('chat_split_words', '50') ?>" data-out="words">
                <small><?= e(t('settings.split.words_help')) ?></small>
            </label>

            <label class="field range-field">
                <span><?= e(t('settings.split.bubbles')) ?> <b data-out="bubbles"><?= (int) setting('chat_split_max_bubbles', '2') ?></b></span>
                <input class="range" type="range" name="chat_split_max_bubbles" min="1" max="8" step="1"
                       value="<?= (int) setting('chat_split_max_bubbles', '2') ?>" data-out="bubbles">
                <small><?= e(t('settings.split.bubbles_help')) ?></small>
            </label>

            <?php $dMin = (float) setting('chat_split_delay_min', '1'); $dMax = (float) setting('chat_split_delay_max', '2'); ?>
            <div class="field range-field">
                <span><?= e(t('settings.split.delay')) ?> <b data-pause-out><?= e(t('js.settings.pause_range', ['min' => fmt_number($dMin, 1, false), 'max' => fmt_number($dMax, 1, false)])) ?></b></span>
                <!-- un seul curseur, deux poignées : pause minimum (gauche) et maximum (droite) -->
                <div class="dual-range" data-dual-range>
                    <span class="dual-range-fill" aria-hidden="true"></span>
                    <input class="range" type="range" name="chat_split_delay_min" min="0.5" max="4" step="0.1"
                           value="<?= e((string) $dMin) ?>" aria-label="<?= e(t('settings.split.delay_min')) ?>">
                    <input class="range" type="range" name="chat_split_delay_max" min="0.5" max="4" step="0.1"
                           value="<?= e((string) $dMax) ?>" aria-label="<?= e(t('settings.split.delay_max')) ?>">
                </div>
            </div>
            <p class="hint"><?= e(t('settings.split.delay_help')) ?></p>
            </div>
        </details>

        <details class="settings-card settings-fold" id="followup">
            <summary>
                <span class="fold-text">
                    <span class="fold-title"><?= e(t('settings.followup.title')) ?> <?= $info('followup', t('settings.info.followup_label')) ?></span>
                    <span class="fold-sum" data-fold-sum="followup"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint"><?= e(t('settings.followup.hint')) ?></p>

            <?php
            // état de la tâche planifiée bin/followup.php (battement de cœur écrit à chaque passage)
            $fuLast   = setting('followup_last_run', '');
            $fuRes    = json_decode(setting('followup_last_result', ''), true) ?: [];
            $fuAge    = $fuLast !== '' ? time() - (int) strtotime($fuLast . ' UTC') : null;
            $fuStale  = $fuAge !== null && $fuAge > 2 * 3600;
            $fuLine   = '*/30 * * * * cd ' . PV_ROOT . ' && php bin/followup.php >> storage/followup.log 2>&1';
            $fuReason = match ((string) ($fuRes['reason'] ?? '')) {
                'disabled'  => t('settings.followup.cron_res_disabled'),
                'off_hours' => t('settings.followup.cron_res_off_hours'),
                'llm_down'  => t('settings.followup.cron_res_llm_down'),
                'done'      => t('settings.followup.cron_res_sent', (int) ($fuRes['sent'] ?? 0)),
                default     => '',
            };
            ?>
            <div class="cron-box <?= $fuLast === '' || $fuStale ? 'is-warn' : 'is-ok' ?>">
                <p class="cron-state">
                    <span class="dot <?= $fuLast === '' || $fuStale ? 'dot-ko' : 'dot-ok' ?>"></span>
                    <?php if ($fuLast === ''): ?>
                        <?= e(t('settings.followup.cron_never')) ?>
                    <?php else: ?>
                        <?= e(t('settings.followup.cron_last', ['when' => rel_time_long($fuLast)])) ?><?= $fuReason !== '' ? ' — ' . e($fuReason) : '' ?>
                    <?php endif; ?>
                </p>
                <?php if ($fuStale): ?>
                    <p class="hint"><?= e(t('settings.followup.cron_stale')) ?></p>
                <?php endif; ?>
                <details class="cron-how" <?= $fuLast === '' ? 'open' : '' ?>>
                    <summary><?= e(t('settings.followup.cron_how')) ?></summary>
                    <p class="hint"><?= e(t('settings.followup.cron_help')) ?></p>
                    <div class="cron-line">
                        <code id="cron-line"><?= e($fuLine) ?></code>
                        <button type="button" class="mini" data-copy="#cron-line"><?= e(t('settings.followup.cron_copy')) ?></button>
                    </div>
                    <p class="hint"><?= e(t('settings.followup.cron_test')) ?> <code>php bin/followup.php --dry</code></p>
                </details>
            </div>

            <label class="check">
                <input type="checkbox" name="chat_followup_enabled" value="1" <?= setting('chat_followup_enabled', '1') === '1' ? 'checked' : '' ?>>
                <span><?= e(t('settings.followup.enable')) ?></span>
            </label>

            <div class="row2">
                <label class="field">
                    <span><?= e(t('settings.followup.idle')) ?></span>
                    <input class="input" type="number" name="chat_followup_idle_hours" min="1" max="72" step="1" value="<?= (int) setting('chat_followup_idle_hours', '6') ?>">
                    <small><?= e(t('settings.followup.idle_help')) ?></small>
                </label>
                <label class="field">
                    <span><?= e(t('settings.followup.gap')) ?></span>
                    <input class="input" type="number" inputmode="numeric" name="chat_followup_min_gap_hours" min="1" max="168" step="1" value="<?= (int) setting('chat_followup_min_gap_hours', '12') ?>">
                    <small><?= e(t('settings.followup.gap_help')) ?></small>
                </label>
                <div class="field">
                    <span><?= e(t('settings.followup.hours')) ?></span>
                    <div class="hours-range">
                        <span><?= e(t('settings.followup.hours_from')) ?></span>
                        <input class="input" type="number" inputmode="numeric" name="chat_followup_from" min="0" max="23" value="<?= (int) setting('chat_followup_from', '8') ?>" aria-label="<?= e(t('settings.followup.from_aria')) ?>">
                        <span><?= e(t('settings.followup.hours_to')) ?></span>
                        <input class="input" type="number" inputmode="numeric" name="chat_followup_to" min="1" max="24" value="<?= (int) setting('chat_followup_to', '23') ?>" aria-label="<?= e(t('settings.followup.to_aria')) ?>">
                        <span><?= e(t('settings.followup.hours_end')) ?></span>
                    </div>
                    <small><?= e(t('settings.followup.hours_help')) ?></small>
                </div>
            </div>
            </div>
        </details>

        <details class="settings-card settings-fold">
            <summary>
                <span class="fold-text">
                    <span class="fold-title"><?= e(t('settings.facts.title')) ?> <?= $info('facts', t('settings.info.facts_label')) ?></span>
                    <span class="fold-sum" data-fold-sum="facts"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint"><?= e(t('settings.facts.hint')) ?></p>

            <div class="field">
                <span><?= e(t('settings.facts.frequency')) ?> <?= $info('facts-frequency', t('settings.info.facts_frequency_label')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.facts.frequency_aria')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="facts_frequency" class="sel-native">
                        <?php foreach (['each' => t('settings.facts.freq_each'), 'every' => t('settings.facts.freq_every'), 'explicit' => t('settings.facts.freq_explicit'), 'off' => t('settings.facts.freq_off')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $facts['frequency'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="field range-field" data-show-if="facts_frequency=every">
                <span><?= e(t('settings.facts.every')) ?> <b data-out="factsevery"><?= (int) $facts['every'] ?></b></span>
                <input class="range" type="range" name="facts_every" min="2" max="30" step="1"
                       value="<?= (int) $facts['every'] ?>" data-out="factsevery">
                <small><?= e(t('settings.facts.every_help')) ?></small>
            </label>

            <div class="field" data-show-if="facts_frequency!=off">
                <span><?= e(t('settings.facts.timing')) ?> <?= $info('facts-timing', t('settings.info.facts_timing_label')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.facts.timing_aria')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="facts_timing" class="sel-native">
                        <option value="now" <?= $facts['timing'] === 'now' ? 'selected' : '' ?>><?= e(t('settings.facts.timing_now')) ?></option>
                        <option value="idle" <?= $facts['timing'] === 'idle' ? 'selected' : '' ?>><?= e(t('settings.facts.timing_idle')) ?></option>
                    </select>
                </div>
            </div>

            <label class="field" data-show-if="facts_timing=idle&facts_frequency!=off">
                <span><?= e(t('settings.facts.idle')) ?></span>
                <input class="input" type="number" inputmode="numeric" name="facts_idle_seconds" min="10" max="600" step="5" value="<?= (int) $facts['idle'] ?>">
                <small><?= e(t('settings.facts.idle_help')) ?></small>
            </label>

            <div class="field" data-show-if="facts_frequency!=off">
                <span><?= e(t('settings.field.model_used')) ?> <?= $info('facts-model', t('settings.info.facts_model_label')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.facts.model_aria')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="facts_model" class="sel-native">
                        <option value="chat" <?= $facts['model'] === 'chat' ? 'selected' : '' ?>><?= e(t('settings.model.chat')) ?></option>
                        <option value="remote" <?= $facts['model'] === 'remote' ? 'selected' : '' ?>><?= e(t('settings.model.remote')) ?></option>
                    </select>
                </div>
                <?php if ($facts['model'] === 'remote' && !llm_ready()): ?>
                    <small class="warn"><?= e(t('settings.model.remote_not_ready')) ?></small>
                <?php endif; ?>
            </div>
            </div>
        </details>

        <?php $titleMode = chat_title_mode(); ?>
        <details class="settings-card settings-fold">
            <summary>
                <span class="fold-text">
                    <span class="fold-title"><?= e(t('settings.titles.title')) ?> <?= $info('titles', t('settings.info.titles_label')) ?></span>
                    <span class="fold-sum" data-fold-sum="titles"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint"><?= e(t('settings.titles.hint')) ?></p>
            <div class="field">
                <span><?= e(t('settings.field.model_used')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.titles.model_aria')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="chat_title_model" class="sel-native">
                        <?php foreach (['remote' => t('settings.model.remote'), 'chat' => t('settings.model.chat'), 'off' => t('settings.titles.off')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $titleMode === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($titleMode === 'remote' && !llm_ready()): ?>
                    <small class="warn"><?= e(t('settings.model.remote_not_ready')) ?></small>
                <?php endif; ?>
            </div>
            </div>
        </details>

        <!-- un seul modèle à la fois dans LM Studio : les autres sont déchargés à chaque changement -->
        <div class="settings-card single-model">
            <label class="check">
                <input type="checkbox" name="lms_single_model" value="1" <?= setting('lms_single_model', '1') === '1' ? 'checked' : '' ?>>
                <span><?= e(t('settings.single_model.label')) ?></span>
            </label>
            <p class="hint"><?= e(t('settings.single_model.hint')) ?></p>
        </div>

        <!-- libérer la mémoire de LM Studio après un temps d'inactivité (0 = jamais) -->
        <?php $idleMin = (int) setting('lms_idle_unload_minutes', '0'); ?>
        <div class="settings-card">
            <label class="field range-field">
                <span><?= e(t('settings.idle_unload.label')) ?> <b data-out="idle"><?= e($idleMin === 0 ? t('settings.idle_unload.never') : idle_duration_label($idleMin)) ?></b></span>
                <input class="range" type="range" name="lms_idle_unload_minutes" min="0" max="480" step="30"
                       value="<?= $idleMin ?>" data-out="idle" data-format="idle">
                <small><?= e(t('settings.idle_unload.hint')) ?></small>
            </label>
        </div>

        <div class="form-actions save-bar">
            <span class="dirty-note"><?= e(t('settings.dirty')) ?></span>
            <button class="btn btn-primary" type="submit"><?= e(t('settings.save')) ?></button>
        </div>
    </form>

    <section class="backup-card settings-maint">
        <h3><?= e(t('settings.purge.title')) ?> <?= $info('purge', t('settings.info.purge_label')) ?></h3>
        <p class="hint" data-purge-status>
            <?= e(t('settings.purge.text')) ?>

        </p>
        <div class="form-actions">
            <button class="btn btn-ghost" type="button" data-purge-lms><?= e(t('settings.purge.button')) ?></button>
        </div>
    </section>

    <!-- journal : ce qui part au modèle et ce qui en revient (20 derniers échanges) -->
    <a class="backup-card settings-logs-link" href="/settings/logs">
        <span class="settings-logs-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 7h10M7 12h10M7 17h6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><path d="M17.5 14.5v5m0 0-2-2m2 2 2-2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <span class="settings-logs-text">
            <strong><?= e(t('logs.link_title')) ?></strong>
            <small><?= e(t('logs.link_text')) ?></small>
        </span>
        <svg class="settings-logs-go" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </a>
    </div>

    <div class="tab-panel" data-panel="look" hidden>
    <header class="form-head settings-sub">
        <h2><?= e(t('settings.look.title')) ?> <?= $info('look', t('settings.info.look_label')) ?></h2>
        <p class="form-sub"><?= e(t('settings.look.subtitle')) ?></p>
    </header>

    <form class="card-form" method="post" action="/settings/ui" data-ui-form data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <?php $uiLang = setting('ui_lang', 'auto'); ?>
        <div class="field">
            <span><?= e(t('settings.lang.label')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.lang.label')) ?>">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="ui_lang" class="sel-native">
                    <option value="auto" <?= $uiLang === 'auto' ? 'selected' : '' ?>><?= e(t('settings.lang.auto')) ?></option>
                    <?php foreach (i18n_available() as $code => $label): ?>
                        <option value="<?= e($code) ?>" lang="<?= e($code) ?>" <?= $uiLang === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <span><?= e(t('settings.look.tabbar')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.look.tabbar')) ?>">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="ui_tabbar" class="sel-native" data-tabbar-select>
                    <?php foreach (['float' => t('settings.look.tabbar_float'), 'pill' => t('settings.look.tabbar_pill'), 'classic' => t('settings.look.tabbar_classic')] as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= $style['tabbar'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small><?= e(t('settings.look.tabbar_help')) ?></small>
        </div>

        <div class="row2">
            <div class="field">
                <span><?= e(t('theme.accent')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('theme.accent')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_accent" class="sel-native" data-accent-select>
                        <?php foreach (['violet' => t('theme.accent_violet'), 'ocean' => t('theme.accent_ocean'), 'emerald' => t('theme.accent_emerald'), 'coral' => t('theme.accent_coral'), 'rose' => t('theme.accent_rose'), 'amber' => t('theme.accent_amber')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['accent'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <span><?= e(t('theme.mode')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('theme.mode')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_mode" class="sel-native" data-mode-select data-theme-colors="<?= e((string) json_encode(UI_THEME_COLORS)) ?>">
                        <?php foreach (['dark' => t('theme.mode_dark'), 'light' => t('theme.mode_light'), 'auto' => t('theme.mode_auto')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['mode'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small><?= e(t('theme.help')) ?></small>
            </div>
        </div>

        <div class="field">
            <span><?= e(t('settings.look.chat_list')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.look.chat_list')) ?>">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="ui_chat_list" class="sel-native">
                    <?php foreach (['threads' => t('settings.look.chat_threads'), 'contacts' => t('settings.look.chat_contacts')] as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= setting('ui_chat_list', 'threads') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="ui-preview" data-ui-preview aria-label="<?= e(t('settings.look.preview')) ?>">
            <div class="pv-msg pv-bot">
                <span class="pv-av">L</span>
                <div class="pv-bubble"><?= t('settings.look.preview_bot_html') ?></div>
            </div>
            <div class="pv-msg pv-user">
                <div class="pv-bubble"><?= t('settings.look.preview_user_html') ?></div>
            </div>
        </div>

        <div class="row2">
            <div class="field">
                <span><?= e(t('settings.look.font_size')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.look.font_size')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_font_size" class="sel-native">
                        <?php foreach (['13.5' => t('settings.look.font_small'), '14.5' => t('settings.look.font_normal'), '16' => t('settings.look.font_large'), '17.5' => t('settings.look.font_xlarge')] as $px => $label): ?>
                            <option value="<?= e((string) $px) ?>" <?= abs((float) $style['font'] - (float) $px) < 0.01 ? 'selected' : '' ?>><?= e($label) ?> · <?= e((string) $px) ?> px</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <span><?= e(t('settings.look.bubble_style')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.look.bubble_style')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_bubble_style" class="sel-native">
                        <?php foreach (['bulle' => t('settings.look.bubble_round'), 'plat' => t('settings.look.bubble_flat'), 'carte' => t('settings.look.bubble_card')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['bubble'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="row2">
            <div class="field">
                <span><?= e(t('settings.look.density')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.look.density')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_density" class="sel-native">
                        <?php foreach (['confort' => t('settings.look.density_comfort'), 'compact' => t('settings.look.density_compact')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['density'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <span><?= e(t('settings.look.width')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('settings.look.width')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_width" class="sel-native">
                        <?php foreach (['normal' => t('settings.look.width_normal'), 'large' => t('settings.look.width_large')] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['width'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <label class="check">
            <input type="checkbox" name="ui_avatars" value="1" <?= $style['avatars'] ? 'checked' : '' ?>>
            <span><?= e(t('settings.look.avatars')) ?></span>
        </label>

        <div class="form-actions save-bar">
            <span class="dirty-note"><?= e(t('settings.dirty')) ?></span>
            <button class="btn btn-primary" type="submit"><?= e(t('settings.look.save')) ?></button>
        </div>
    </form>
    </div>

    <div class="tab-panel" data-panel="prompts" hidden>
    <header class="form-head settings-sub">
        <h2><?= e(t('settings.prompts.title')) ?> <?= $info('prompts', t('settings.info.prompts_label')) ?></h2>
        <p class="form-sub"><?= e(t('settings.prompts.subtitle')) ?></p>
    </header>

    <form class="card-form" method="post" action="/settings/prompts" data-prompts-form data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <?php
        $promptLabels = [
            'memory'   => [t('settings.prompts.memory_label'), t('settings.prompts.memory_help')],
            'facts'    => [t('settings.prompts.facts_label'), t('settings.prompts.facts_help')],
            'followup' => [t('settings.prompts.followup_label'), t('settings.prompts.followup_help')],
        ];
        $defaults = engine_prompt_defaults();
        foreach ($promptLabels as $pk => [$plabel, $phelp]):
            $custom = engine_prompt_is_custom($pk);
        ?>
            <div class="prompt-block">
                <div class="prompt-head">
                    <h3><?= e($plabel) ?><?= $custom ? ' <span class="pill">' . e(t('settings.prompts.custom')) . '</span>' : '' ?></h3>
                    <button type="button" class="mini" data-reset-prompt="<?= e($pk) ?>"><?= e(t('settings.prompts.reset')) ?></button>
                </div>
                <p class="hint"><?= e($phelp) ?></p>
                <textarea class="input prompt-area" name="prompt_<?= e($pk) ?>" rows="6"
                          data-default="<?= e($defaults[$pk]) ?>"><?= e(engine_prompt($pk)) ?></textarea>
            </div>
        <?php endforeach; ?>

        <div class="form-actions save-bar">
            <span class="dirty-note"><?= e(t('settings.dirty')) ?></span>
            <button class="btn btn-primary" type="submit"><?= e(t('settings.prompts.save')) ?></button>
        </div>
    </form>
    </div>

    <div class="tab-panel" data-panel="backup" hidden>
    <header class="form-head settings-sub">
        <h2><?= e(t('settings.backup.title')) ?> <?= $info('backup', t('settings.info.backup_label')) ?></h2>
        <p class="form-sub"><?= e(t('settings.backup.subtitle')) ?></p>
    </header>

    <section class="backup-card">
        <h3><?= e(t('settings.backup.export')) ?></h3>
        <p class="hint">
            <?= e(t('settings.backup.count_personas', (int) $backupStats['personas'])) ?> ·
            <?= e(t('settings.backup.count_photos', (int) $backupStats['photos'])) ?> ·
            <?= e(t('settings.backup.count_prompts', (int) $backupStats['prompts'])) ?> ·
            <?= e(t('settings.backup.count_conversations', (int) $backupStats['conversations'])) ?>
        </p>

        <label class="check">
            <input type="checkbox" data-backup-conv value="1">
            <span><?= e(t('settings.backup.include_conversations')) ?></span>
        </label>

        <a class="btn btn-primary btn-block" href="/backup/export.zip" data-backup-link>
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 4v10m0 0 4-4m-4 4-4-4M5 18h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <?= e(t('settings.backup.download')) ?>

        </a>

        <p class="hint">
            <?php if (setting('backup_last_at', '') !== ''): ?>
                <?= e(t('settings.backup.last', ['date' => fr_date(setting('backup_last_at'))])) ?>
                · <?= e(t('settings.backup.size_mb', ['size' => fmt_number(((int) setting('backup_last_size', '0')) / 1048576, 1)])) ?>

            <?php else: ?>
                <?= e(t('settings.backup.none')) ?>

            <?php endif; ?>
        </p>
    </section>

    <section class="backup-card">
        <h3><?= e(t('settings.backup.restore')) ?></h3>
        <p class="hint"><?= e(t('settings.backup.restore_hint')) ?></p>

        <form method="post" action="/backup/import" enctype="multipart/form-data" data-confirm="<?= e(t('settings.backup.import_confirm')) ?>" data-confirm-title="<?= e(t('settings.backup.import_confirm_title')) ?>" data-confirm-ok="<?= e(t('settings.backup.import_confirm_ok')) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <label class="file-pick" data-file-pick>
                <input type="file" name="archive" accept=".zip,application/zip" required>
                <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M12 16V5m0 0-4 4m4-4 4 4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="file-pick-name" data-empty="<?= e(t('settings.backup.choose_file')) ?>"><?= e(t('settings.backup.choose_file')) ?></span>
            </label>
            <div class="form-actions">
                <button class="btn btn-ghost" type="submit" data-file-submit disabled><?= e(t('settings.backup.import_submit')) ?></button>
            </div>
        </form>
    </section>

    <p class="hint"><?= e(t('settings.backup.tip')) ?></p>
    </div>

    <?php
    $infos = [
        'ia-model'        => [t('settings.info.ia_model_title'), t('settings.info.ia_model_html')],
        'api-key'         => [t('settings.ia.api_key'), t('settings.info.api_key_html')],
        'chat-model'      => [t('settings.chat.model_aria'), t('settings.info.chat_model_html')],
        'reasoning'       => [t('settings.chat.reasoning'), t('settings.info.reasoning_html')],
        'compression'     => [t('settings.info.compression_title'), t('settings.info.compression_html')],
        'split'           => [t('settings.split.title'), t('settings.info.split_html')],
        'followup'        => [t('settings.followup.title'), t('settings.info.followup_html')],
        'facts'           => [t('settings.facts.title'), t('settings.info.facts_html')],
        'facts-frequency' => [t('settings.facts.frequency_aria'), t('settings.info.facts_frequency_html')],
        'facts-timing'    => [t('settings.facts.timing_aria'), t('settings.info.facts_timing_html')],
        'facts-model'     => [t('settings.facts.model_aria'), t('settings.info.facts_model_html')],
        'titles'          => [t('settings.titles.title'), t('settings.info.titles_html')],
        'purge'           => [t('settings.purge.button'), t('settings.info.purge_html')],
        'look'            => [t('settings.tab.look'), t('settings.info.look_html')],
        'prompts'         => [t('settings.prompts.title'), t('settings.info.prompts_html')],
        'backup'          => [t('settings.backup.title'), t('settings.info.backup_html')],
    ];
    // contenus « _html » : HTML de confiance écrit dans lang/, affiché sans échappement
    foreach ($infos as $key => [$ttl, $html]): ?>
        <template data-info-body="<?= e($key) ?>" data-title="<?= e($ttl) ?>">
            <?= $html ?></template>
    <?php endforeach; ?>

</section>
