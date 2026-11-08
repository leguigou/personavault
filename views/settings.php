<?php
/** @var array $llm @var bool $hasKey @var array $chat @var array $chatModels @var bool $chatHasKey */
$providers = [
    'deepseek'   => 'DeepSeek',
    'openai'     => 'OpenAI',
    'openrouter' => 'OpenRouter',
    'lmstudio'   => 'LM Studio (local)',
    'custom'     => 'Autre / compatible OpenAI',
];
$plural = 'plural'; // « 0 photo », « 1 photo », « 2 photos » (src/bootstrap.php)
// bouton « i » qui ouvre une explication en fenêtre (contenu : <template data-info-body> en bas de page)
$info = static fn (string $key, string $label): string =>
    '<button type="button" class="info-btn" data-info="' . e($key) . '" aria-label="' . e('En savoir plus : ' . $label) . '">i</button>';
$facts = facts_settings();
?>
<a class="back settings-back" href="/">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Bibliothèque
</a>

<section class="form-wrap settings-page">
    <header class="form-head settings-head">
        <h1>Réglages</h1>
        <p class="form-sub">Connexions, discussions et apparence — un onglet par sujet.</p>
    </header>

    <nav class="tabs" data-tabs aria-label="Sections des réglages">
        <button type="button" class="tab is-on" data-tab="ia"><span class="tab-long">Intelligence</span><span class="tab-short">IA</span></button>
        <button type="button" class="tab" data-tab="chat"><span class="tab-long">Discussion</span><span class="tab-short">Chat</span></button>
        <button type="button" class="tab" data-tab="look">Apparence</button>
        <button type="button" class="tab" data-tab="prompts">Prompts</button>
        <button type="button" class="tab" data-tab="backup">Sauvegarde</button>
    </nav>

    <div class="tab-panel" data-panel="ia">
    <header class="form-head settings-sub">
        <h2>Création de fiches <?= $info('ia-model', 'modèle des fiches') ?></h2>
        <p class="form-sub">Modèle qui remplit les fiches persona (bouton IA).</p>
    </header>

    <form class="card-form" method="post" action="/settings" data-settings-form data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="field">
            <span>Fournisseur</span>
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
        </div>

        <div class="row2">
            <label class="field">
                <span>URL de base</span>
                <input class="input" name="base_url" value="<?= e($llm['base_url']) ?>" placeholder="https://api.deepseek.com/v1" spellcheck="false">
            </label>
            <label class="field">
                <span>Modèle</span>
                <input class="input" name="model" value="<?= e($llm['model']) ?>" placeholder="deepseek-chat" spellcheck="false" autocomplete="off" data-model-input>
                <small>Ex. deepseek-chat, deepseek-reasoner</small>
            </label>
        </div>

        <label class="field">
            <span>Clé API <?= $info('api-key', 'clé API') ?></span>
            <input class="input" type="password" name="api_key" autocomplete="off" spellcheck="false"
                   placeholder="<?= $hasKey ? 'Laisser vide pour conserver la clé' : 'sk-…' ?>">
            <small>
                <?php if ($hasKey): ?>
                    Une clé est déjà enregistrée. Saisis-en une nouvelle pour la remplacer.
                <?php else: ?>
                    Stockée dans la base locale de l’app, jamais affichée en clair.
                <?php endif; ?>
            </small>
        </label>

        <?php if ($hasKey): ?>
            <label class="check">
                <input type="checkbox" name="clear_key" value="1">
                <span>Supprimer la clé enregistrée</span>
            </label>
        <?php endif; ?>

        <div class="llm-status" data-llm-status>
            <?php if ($hasKey): ?>
                <span class="dot dot-ok"></span> Prêt · <?= e($llm['model']) ?>
            <?php else: ?>
                <span class="dot"></span> Aucune clé enregistrée
            <?php endif; ?>
        </div>

        <div class="form-actions save-bar">
            <span class="dirty-note">Modifications non enregistrées</span>
            <button type="button" class="btn btn-ghost" data-llm-test>Test de connexion</button>
            <button class="btn btn-primary" type="submit">Enregistrer</button>
        </div>
    </form>

    <section class="panel panel-help">
        <h2>Comment ça marche</h2>
        <p class="hint">
            Sur une fiche, le bouton <strong>Compléter avec l’IA</strong> lit les prompts du persona et
            remplit uniquement ce qui manque : accroche, description, tags et attributs.
            Ce qui est déjà saisi n’est jamais écrasé, et <strong>tes prompts ne sont jamais modifiés</strong> :
            l’IA ne fait que les lire.
        </p>
        <p class="hint">
            Rien ne part sans que tu cliques : l’app n’envoie les données au modèle qu’au moment de cette action.
        </p>
    </section>
    </div>

    <div class="tab-panel" data-panel="chat" hidden>
    <header class="form-head settings-sub">
        <h2>Modèle de chat</h2>
        <p class="form-sub">Utilisé uniquement pour parler aux personas. Si LM Studio est éteint, l’app bascule sur DeepSeek en te prévenant.</p>
    </header>

    <form class="settings-stack" method="post" action="/settings/chat" data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <section class="settings-card">
        <h3 class="settings-card-title">Connexion au modèle <?= $info('chat-model', 'modèle de discussion') ?></h3>
        <div class="row2">
            <div class="field">
                <span>Fournisseur</span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="chat_provider" class="sel-native">
                        <?php foreach (['lmstudio' => 'LM Studio (local)', 'openai' => 'OpenAI', 'openrouter' => 'OpenRouter', 'custom' => 'Autre'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $chat['provider'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="field">
                <span>URL de base</span>
                <input class="input" name="chat_base_url" value="<?= e($chat['base_url']) ?>" placeholder="http://localhost:1234/v1" spellcheck="false">
            </label>
        </div>

        <div class="field">
            <span>Modèle</span>
            <?php if ($chatModels): ?>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Modèle de discussion">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="chat_model" class="sel-native">
                        <?php foreach ($chatModelList as $m): ?>
                            <option value="<?= e($m['id']) ?>" <?= $m['id'] === $chat['model'] ? 'selected' : '' ?>><?= $m['fav'] ? '★ ' : '' ?><?= e($m['id']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <small><?= count($chatModels) ?> modèles détectés · ★ = recommandés pour la discussion.</small>
            <?php else: ?>
                <input class="input" name="chat_model" value="<?= e($chat['model']) ?>" placeholder="qwen/qwen3.5-9b" spellcheck="false">
                <small>Serveur injoignable : saisis le nom du modèle à la main.</small>
            <?php endif; ?>
        </div>

        <label class="field">
            <span>Clé API (facultative)</span>
            <input class="input" type="password" name="chat_api_key" autocomplete="off" spellcheck="false"
                   placeholder="<?= $chatHasKey ? 'Clé enregistrée — laisser vide' : 'vide pour LM Studio' ?>">
        </label>

        <?php if ($chatHasKey): ?>
            <label class="check">
                <input type="checkbox" name="chat_clear_key" value="1">
                <span>Supprimer la clé enregistrée</span>
            </label>
        <?php endif; ?>
        </section>

        <section class="settings-card">
        <h3 class="settings-card-title">Réflexion et mémoire <?= $info('compression', 'mémoire de conversation') ?></h3>
        <div class="field">
            <span>Réflexion du modèle <?= $info('reasoning', 'réflexion du modèle') ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Réflexion du modèle">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="chat_reasoning_effort" class="sel-native">
                    <?php foreach (['none' => 'Désactivée (recommandé)', 'low' => 'Faible', 'medium' => 'Moyenne', 'high' => 'Élevée'] as $val => $label): ?>
                        <option value="<?= e($val) ?>" <?= ($chatReasoning ?? 'none') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <small>Les modèles Qwen3 / Gemma 4 « réfléchissent » avant de répondre et peuvent épuiser le budget sans jamais répondre. Désactivée = réponse directe.</small>
        </div>

        <div class="row2">
            <label class="field">
                <span>Seuil de compression (tokens)</span>
                <input class="input" type="number" name="chat_memory_threshold" min="600" max="100000" step="100" value="<?= (int) chat_memory_threshold() ?>">
                <small>Au-delà, les vieux messages sont résumés.</small>
            </label>
            <label class="field">
                <span>Messages récents gardés (tokens)</span>
                <input class="input" type="number" name="chat_memory_target" min="300" max="50000" step="100" value="<?= (int) chat_memory_target() ?>">
                <small>Ce qui reste en clair après compression.</small>
            </label>
        </div>
        </section>

        <details class="settings-card settings-fold">
            <summary>
                <span class="fold-text">
                    <span class="fold-title">Réponses en plusieurs bulles <?= $info('split', 'bulles') ?></span>
                    <span class="fold-sum" data-fold-sum="split"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint">Coupe une longue réponse en deux messages, à la fin d’une phrase, avec une petite pause.</p>

            <label class="check">
                <input type="checkbox" name="chat_split_enabled" value="1" <?= setting('chat_split_enabled', '1') === '1' ? 'checked' : '' ?>>
                <span>Découper les réponses en plusieurs bulles</span>
            </label>

            <label class="field range-field">
                <span>Mots maximum par bulle <b data-out="words"><?= (int) setting('chat_split_words', '50') ?></b></span>
                <input class="range" type="range" name="chat_split_words" min="15" max="120" step="5"
                       value="<?= (int) setting('chat_split_words', '50') ?>" data-out="words">
                <small>La bulle se coupe à la dernière fin de phrase avant cette limite.</small>
            </label>

            <label class="field range-field">
                <span>Bulles maximum <b data-out="bubbles"><?= (int) setting('chat_split_max_bubbles', '2') ?></b></span>
                <input class="range" type="range" name="chat_split_max_bubbles" min="1" max="8" step="1"
                       value="<?= (int) setting('chat_split_max_bubbles', '2') ?>" data-out="bubbles">
                <small>1 = jamais de découpage.</small>
            </label>

            <div class="row2">
                <label class="field range-field">
                    <span>Pause min <b data-out="dmin"><?= e(number_format((float) setting('chat_split_delay_min', '1'), 1, ',', '')) ?> s</b></span>
                    <input class="range" type="range" name="chat_split_delay_min" min="0.5" max="3" step="0.1"
                           value="<?= e((string) setting('chat_split_delay_min', '1')) ?>" data-out="dmin" data-suffix=" s">
                </label>
                <label class="field range-field">
                    <span>Pause max <b data-out="dmax"><?= e(number_format((float) setting('chat_split_delay_max', '2'), 1, ',', '')) ?> s</b></span>
                    <input class="range" type="range" name="chat_split_delay_max" min="0.5" max="4" step="0.1"
                           value="<?= e((string) setting('chat_split_delay_max', '2')) ?>" data-out="dmax" data-suffix=" s">
                </label>
            </div>
            <p class="hint">Pause aléatoire entre deux bulles, dans cette plage.</p>
            </div>
        </details>

        <details class="settings-card settings-fold">
            <summary>
                <span class="fold-text">
                    <span class="fold-title">Messages spontanés <?= $info('followup', 'messages spontanés') ?></span>
                    <span class="fold-sum" data-fold-sum="followup"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint">Le persona peut t’écrire de lui-même après un long silence (désactivable conversation par conversation).</p>

            <label class="check">
                <input type="checkbox" name="chat_followup_enabled" value="1" <?= setting('chat_followup_enabled', '1') === '1' ? 'checked' : '' ?>>
                <span>Autoriser les relances spontanées</span>
            </label>

            <div class="row2">
                <label class="field">
                    <span>Sécurité : silence minimum</span>
                    <input class="input" type="number" name="chat_followup_idle_hours" min="1" max="72" step="1" value="<?= (int) setting('chat_followup_idle_hours', '6') ?>">
                    <small>Heures de silence avant qu’il/elle puisse relancer.</small>
                </label>
                <div class="field">
                    <span>Plage horaire</span>
                    <div class="hours-range">
                        <span>de</span>
                        <input class="input" type="number" inputmode="numeric" name="chat_followup_from" min="0" max="23" value="<?= (int) setting('chat_followup_from', '8') ?>" aria-label="Heure de début">
                        <span>h à</span>
                        <input class="input" type="number" inputmode="numeric" name="chat_followup_to" min="1" max="24" value="<?= (int) setting('chat_followup_to', '23') ?>" aria-label="Heure de fin">
                        <span>h</span>
                    </div>
                    <small>Aucune relance en dehors de cette plage.</small>
                </div>
            </div>
            </div>
        </details>

        <details class="settings-card settings-fold">
            <summary>
                <span class="fold-text">
                    <span class="fold-title">Souvenirs durables <?= $info('facts', 'souvenirs durables') ?></span>
                    <span class="fold-sum" data-fold-sum="facts"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint">Ce que les personas retiennent de toi d’une conversation à l’autre. Choisis quand
                l’app les extrait et avec quel modèle.</p>

            <div class="field">
                <span>Fréquence <?= $info('facts-frequency', 'fréquence') ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Fréquence de l’extraction">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="facts_frequency" class="sel-native">
                        <?php foreach (['each' => 'Après chaque échange', 'every' => 'Tous les N messages', 'explicit' => 'Seulement sur demande (« retiens que… »)', 'off' => 'Jamais'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $facts['frequency'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label class="field range-field" data-show-if="facts_frequency=every">
                <span>Nombre de nouveaux messages <b data-out="factsevery"><?= (int) $facts['every'] ?></b></span>
                <input class="range" type="range" name="facts_every" min="2" max="30" step="1"
                       value="<?= (int) $facts['every'] ?>" data-out="factsevery">
                <small>Un échange = 2 messages (le tien et la réponse).</small>
            </label>

            <div class="field" data-show-if="facts_frequency!=off">
                <span>Moment <?= $info('facts-timing', 'moment de l’extraction') ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Moment de l’extraction">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="facts_timing" class="sel-native">
                        <option value="now" <?= $facts['timing'] === 'now' ? 'selected' : '' ?>>Dès la réponse</option>
                        <option value="idle" <?= $facts['timing'] === 'idle' ? 'selected' : '' ?>>Quand la discussion est calme</option>
                    </select>
                </div>
            </div>

            <label class="field" data-show-if="facts_timing=idle&facts_frequency!=off">
                <span>Calme depuis (secondes)</span>
                <input class="input" type="number" inputmode="numeric" name="facts_idle_seconds" min="10" max="600" step="5" value="<?= (int) $facts['idle'] ?>">
                <small>Sans nouveau message ni frappe pendant ce délai.</small>
            </label>

            <div class="field" data-show-if="facts_frequency!=off">
                <span>Modèle utilisé <?= $info('facts-model', 'modèle des souvenirs') ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Modèle utilisé pour les souvenirs">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="facts_model" class="sel-native">
                        <option value="chat" <?= $facts['model'] === 'chat' ? 'selected' : '' ?>>Modèle de discussion (local)</option>
                        <option value="remote" <?= $facts['model'] === 'remote' ? 'selected' : '' ?>>Modèle des fiches (distant)</option>
                    </select>
                </div>
                <?php if ($facts['model'] === 'remote' && !llm_ready()): ?>
                    <small class="warn">Le modèle des fiches n’est pas configuré (onglet IA) : le modèle local est utilisé en attendant.</small>
                <?php endif; ?>
            </div>
            </div>
        </details>

        <?php $titleMode = chat_title_mode(); ?>
        <details class="settings-card settings-fold">
            <summary>
                <span class="fold-text">
                    <span class="fold-title">Titres des discussions <?= $info('titles', 'titres automatiques') ?></span>
                    <span class="fold-sum" data-fold-sum="titles"></span>
                </span>
            </summary>
            <div class="fold-body">
            <p class="hint">Après les premiers échanges, un titre court est donné à la discussion
                (tant que tu ne l’as pas renommée toi-même).</p>
            <div class="field">
                <span>Modèle utilisé</span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Modèle des titres automatiques">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="chat_title_model" class="sel-native">
                        <?php foreach (['remote' => 'Modèle des fiches (distant)', 'chat' => 'Modèle de discussion (local)', 'off' => 'Pas de titre automatique'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $titleMode === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($titleMode === 'remote' && !llm_ready()): ?>
                    <small class="warn">Le modèle des fiches n’est pas configuré (onglet IA) : le modèle local est utilisé en attendant.</small>
                <?php endif; ?>
            </div>
            </div>
        </details>

        <div class="form-actions save-bar">
            <span class="dirty-note">Modifications non enregistrées</span>
            <button class="btn btn-primary" type="submit">Enregistrer</button>
        </div>
    </form>

    <section class="backup-card settings-maint">
        <h3>Maintenance LM Studio <?= $info('purge', 'purge') ?></h3>
        <p class="hint" data-purge-status>
            Changer de modèle décharge déjà l’ancien automatiquement. Ce bouton force la libération
            de la mémoire vidéo (VRAM) si LM Studio garde des modèles chargés.
        </p>
        <div class="form-actions">
            <button class="btn btn-ghost" type="button" data-purge-lms>Purger la mémoire LM Studio</button>
        </div>
    </section>
    </div>

    <div class="tab-panel" data-panel="look" hidden>
    <header class="form-head settings-sub">
        <h2>Apparence des conversations <?= $info('look', 'apparence') ?></h2>
        <p class="form-sub">S’applique à toutes les discussions. L’aperçu réagit en direct.</p>
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

        <div class="ui-preview" data-ui-preview aria-label="Aperçu">
            <div class="pv-msg pv-bot">
                <span class="pv-av">L</span>
                <div class="pv-bubble">Salut&nbsp;! Tu as passé une bonne journée&nbsp;?</div>
            </div>
            <div class="pv-msg pv-user">
                <div class="pv-bubble">Ouais, et toi&nbsp;?</div>
            </div>
        </div>

        <div class="row2">
            <div class="field">
                <span>Taille du texte</span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Taille du texte">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_font_size" class="sel-native">
                        <?php foreach (['13.5' => 'Petit', '14.5' => 'Normal', '16' => 'Grand', '17.5' => 'Très grand'] as $px => $label): ?>
                            <option value="<?= e((string) $px) ?>" <?= abs((float) $style['font'] - (float) $px) < 0.01 ? 'selected' : '' ?>><?= e($label) ?> · <?= e((string) $px) ?> px</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <span>Style des bulles</span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Style des bulles">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_bubble_style" class="sel-native">
                        <?php foreach (['bulle' => 'Bulles arrondies', 'plat' => 'Plates', 'carte' => 'Cartes'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['bubble'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="row2">
            <div class="field">
                <span>Densité</span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Densité">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_density" class="sel-native">
                        <?php foreach (['confort' => 'Confort', 'compact' => 'Compact'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['density'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <span>Largeur des messages</span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Largeur des messages">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="ui_width" class="sel-native">
                        <?php foreach (['normal' => 'Normale', 'large' => 'Large'] as $val => $label): ?>
                            <option value="<?= e($val) ?>" <?= $style['width'] === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <label class="check">
            <input type="checkbox" name="ui_avatars" value="1" <?= $style['avatars'] ? 'checked' : '' ?>>
            <span>Afficher l’avatar du personnage à côté de ses messages</span>
        </label>

        <div class="form-actions save-bar">
            <span class="dirty-note">Modifications non enregistrées</span>
            <button class="btn btn-primary" type="submit">Enregistrer l’apparence</button>
        </div>
    </form>
    </div>

    <div class="tab-panel" data-panel="prompts" hidden>
    <header class="form-head settings-sub">
        <h2>Prompts internes <?= $info('prompts', 'prompts internes') ?></h2>
        <p class="form-sub">Les consignes que l’app envoie au modèle pour la mémoire, les souvenirs et les relances. Vides = texte par défaut.</p>
    </header>

    <form class="card-form" method="post" action="/settings/prompts" data-prompts-form data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <?php
        $promptLabels = [
            'memory'   => ['Résumé de conversation', 'Compresse les anciens messages. Doit renvoyer un JSON avec current_topic, conversation_goal, important_facts, decisions, open_questions, context_summary.'],
            'facts'    => ['Extraction des souvenirs', 'Décide ce qui devient une mémoire durable sur l’utilisateur (persona). Doit renvoyer un JSON avec memories / forget.'],
            'followup' => ['Relance spontanée', 'Instruction du message non sollicité. Placeholders disponibles : {persona} et {gap}.'],
        ];
        $defaults = engine_prompt_defaults();
        foreach ($promptLabels as $pk => [$plabel, $phelp]):
            $custom = engine_prompt_is_custom($pk);
        ?>
            <div class="prompt-block">
                <div class="prompt-head">
                    <h3><?= e($plabel) ?><?= $custom ? ' <span class="pill">personnalisé</span>' : '' ?></h3>
                    <button type="button" class="mini" data-reset-prompt="<?= e($pk) ?>">Réinitialiser</button>
                </div>
                <p class="hint"><?= e($phelp) ?></p>
                <textarea class="input prompt-area" name="prompt_<?= e($pk) ?>" rows="6"
                          data-default="<?= e($defaults[$pk]) ?>"><?= e(engine_prompt($pk)) ?></textarea>
            </div>
        <?php endforeach; ?>

        <div class="form-actions save-bar">
            <span class="dirty-note">Modifications non enregistrées</span>
            <button class="btn btn-primary" type="submit">Enregistrer les prompts</button>
        </div>
    </form>
    </div>

    <div class="tab-panel" data-panel="backup" hidden>
    <header class="form-head settings-sub">
        <h2>Sauvegarde <?= $info('backup', 'sauvegarde') ?></h2>
        <p class="form-sub">Un ZIP par sauvegarde : un dossier par persona avec sa fiche, ses prompts et ses photos.</p>
    </header>

    <section class="backup-card">
        <h3>Exporter</h3>
        <p class="hint">
            <?= e($plural((int) $backupStats['personas'], 'persona', 'personas')) ?> ·
            <?= e($plural((int) $backupStats['photos'], 'photo', 'photos')) ?> ·
            <?= e($plural((int) $backupStats['prompts'], 'prompt', 'prompts')) ?> ·
            <?= e($plural((int) $backupStats['conversations'], 'conversation', 'conversations')) ?>
        </p>

        <label class="check">
            <input type="checkbox" data-backup-conv value="1">
            <span>Inclure les conversations, les messages et les souvenirs</span>
        </label>

        <a class="btn btn-primary btn-block" href="/backup/export.zip" data-backup-link>
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 4v10m0 0 4-4m-4 4-4-4M5 18h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Télécharger la sauvegarde
        </a>

        <p class="hint">
            <?php if (setting('backup_last_at', '') !== ''): ?>
                Dernière sauvegarde : <?= e(fr_date(setting('backup_last_at'))) ?>
                · <?= e(number_format(((int) setting('backup_last_size', '0')) / 1048576, 1, ',', ' ')) ?> Mo
            <?php else: ?>
                Aucune sauvegarde téléchargée pour l’instant.
            <?php endif; ?>
        </p>
    </section>

    <section class="backup-card">
        <h3>Restaurer</h3>
        <p class="hint">Importe un ZIP de sauvegarde. Les personas existants ne sont jamais écrasés : un slug unique est créé.</p>

        <form method="post" action="/backup/import" enctype="multipart/form-data" data-confirm="Importer cette sauvegarde ? Les personas seront ajoutés à la bibliothèque." data-confirm-title="Importer la sauvegarde" data-confirm-ok="Importer">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <label class="file-pick" data-file-pick>
                <input type="file" name="archive" accept=".zip,application/zip" required>
                <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M12 16V5m0 0-4 4m4-4 4 4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span class="file-pick-name" data-empty="Choisir un fichier ZIP">Choisir un fichier ZIP</span>
            </label>
            <div class="form-actions">
                <button class="btn btn-ghost" type="submit" data-file-submit disabled>Importer l’archive</button>
            </div>
        </form>
    </section>

    <p class="hint">Astuce : garde le ZIP hors du serveur (disque externe, cloud). Il contient tout ce qu’il faut pour repartir de zéro.</p>
    </div>

    <?php
    $infos = [
        'ia-model' => ['Modèle des fiches', '
            <p>Ce modèle sert aux tâches ponctuelles : <strong>compléter une fiche</strong> avec le bouton IA,
            proposer une consigne de jeu, et éventuellement extraire les souvenirs si tu le choisis.</p>
            <p>C’est en général un service distant (DeepSeek, OpenAI, OpenRouter…). Les données ne partent
            qu’au moment où tu cliques sur une action IA.</p>'],
        'api-key' => ['Clé API', '
            <p>La clé est enregistrée dans la base locale de l’app et n’est <strong>jamais réaffichée</strong>
            dans la page. Laisse le champ vide pour conserver la clé actuelle.</p>
            <p>Le bouton « Test de connexion » n’envoie la clé enregistrée qu’à l’adresse enregistrée : si tu
            changes d’adresse, saisis la clé à nouveau.</p>'],
        'chat-model' => ['Modèle de discussion', '
            <p>C’est le modèle qui fait parler les personas, en général <strong>LM Studio sur ta machine</strong>
            (adresse <code>http://localhost:1234/v1</code> par défaut). Rien ne sort de chez toi.</p>
            <p>Si le serveur est injoignable, l’app te demande quoi faire : elle ne bascule jamais toute seule
            vers un service distant.</p>'],
        'reasoning' => ['Réflexion du modèle', '
            <p>Certains modèles (Qwen3, Gemma…) « réfléchissent » avant de répondre. C’est plus lent et ça
            consomme le budget de tokens, parfois au point de ne jamais répondre.</p>
            <p>Pour une conversation naturelle, laisse <strong>Désactivée</strong>.</p>'],
        'compression' => ['Mémoire de la conversation', '
            <p>Le modèle ne peut lire qu’un nombre limité de tokens (sa « fenêtre »). Quand les messages
            <strong>pas encore résumés</strong> dépassent le <strong>seuil de compression</strong>, les plus anciens
            sont résumés en quelques lignes (sujet, faits, décisions).</p>
            <p>Ensuite, le modèle reçoit ce résumé + les messages récents (environ la valeur
            « messages récents gardés »), et non plus tout l’historique : réponses plus rapides et
            plus de place pour la suite.</p>
            <p>Un token ≈ 3 à 4 caractères en français.</p>'],
        'split' => ['Réponses en plusieurs bulles', '
            <p>Une longue réponse est coupée à la fin d’une phrase et affichée en plusieurs messages, avec une
            courte pause entre chaque, comme dans une vraie messagerie.</p>
            <p>« Bulles maximum » à 1 désactive le découpage.</p>'],
        'followup' => ['Messages spontanés', '
            <p>Après un long silence, le persona peut t’écrire de lui-même, uniquement dans la plage horaire
            choisie et au plus une fois par période de silence.</p>
            <p>Ces relances sont déclenchées par une tâche planifiée sur le serveur, par exemple toutes les
            30 minutes : <code>php bin/followup.php</code> (cron). Sans elle, rien ne part.</p>'],
        'facts' => ['Souvenirs durables', '
            <p>Après certains échanges, l’app demande à un modèle ce qui mérite d’être retenu sur toi
            (prénom, goûts, projets…). Ces souvenirs sont partagés entre toutes les conversations d’un persona
            et visibles dans la page <strong>Souvenirs</strong>.</p>
            <p>L’extraction se fait <strong>en arrière-plan</strong> : elle ne bloque jamais l’affichage.
            En revanche, si elle utilise le modèle local, elle l’occupe quelques secondes.</p>'],
        'facts-frequency' => ['Fréquence de l’extraction', '
            <p><strong>Après chaque échange</strong> : le plus réactif, mais un appel au modèle à chaque réponse.</p>
            <p><strong>Tous les N messages</strong> : regroupe plusieurs échanges en un seul appel. Rien n’est perdu,
            tous les messages depuis la dernière extraction sont analysés.</p>
            <p><strong>Seulement sur demande</strong> : uniquement quand tu écris « retiens que… », « souviens-toi… ».</p>
            <p><strong>Jamais</strong> : aucun nouveau souvenir automatique (les existants restent utilisés).</p>'],
        'facts-timing' => ['Moment de l’extraction', '
            <p><strong>Dès la réponse</strong> : lancée juste après chaque réponse.</p>
            <p><strong>Quand la discussion est calme</strong> : attend que tu n’aies rien écrit pendant le délai
            choisi. Le modèle local reste ainsi disponible pendant que vous échangez. Si tu quittes la page
            avant, l’extraction se fera à la prochaine occasion, sans perte.</p>'],
        'facts-model' => ['Modèle utilisé pour les souvenirs', '
            <p><strong>Modèle de discussion (local)</strong> : tout reste sur ta machine.</p>
            <p><strong>Modèle des fiches (distant)</strong> : soulage le modèle local, mais les derniers messages de
            la conversation sont alors <strong>envoyés au service distant</strong> (DeepSeek, OpenAI…).
            À choisir en connaissance de cause.</p>'],
        'titles' => ['Titres des discussions', '
            <p><strong>Modèle des fiches (distant)</strong> : les 4 premiers messages de la discussion
            (300 caractères chacun au plus) sont <strong>envoyés au service distant</strong> pour trouver un titre.
            Rapide, et le modèle local reste libre.</p>
            <p><strong>Modèle de discussion (local)</strong> : rien ne sort de ta machine ; le modèle local
            est occupé une ou deux secondes après le premier échange.</p>
            <p><strong>Pas de titre automatique</strong> : la discussion garde « Discussion avec … »
            jusqu’à ce que tu la renommes.</p>'],
        'purge' => ['Purger la mémoire LM Studio', '
            <p>Décharge tous les modèles chargés dans LM Studio pour libérer la mémoire de la carte graphique
            (VRAM). Utile si LM Studio garde plusieurs modèles en mémoire et ralentit.</p>
            <p>Le modèle de discussion sera rechargé automatiquement au prochain message.</p>'],
        'look' => ['Apparence', '
            <p>Ces réglages ne changent que l’affichage des conversations, sur tous tes appareils.
            L’aperçu en haut du formulaire montre le rendu avant d’enregistrer.</p>'],
        'prompts' => ['Prompts internes', '
            <p>Ce sont les consignes que l’app envoie au modèle pour <strong>résumer</strong> une conversation,
            <strong>extraire les souvenirs</strong> et écrire les <strong>relances</strong>.</p>
            <p>Les deux premiers doivent faire répondre le modèle en JSON avec les champs indiqués : si tu les
            modifies, garde ce format. « Réinitialiser » remet le texte d’origine.</p>'],
        'backup' => ['Sauvegarde', '
            <p>Le ZIP contient un dossier par persona : fiche, prompts, portrait et photos. Coche l’option pour
            y ajouter les conversations et les souvenirs.</p>
            <p>À l’import, rien n’est écrasé : un persona déjà présent est recréé sous un nouveau nom d’URL.
            Garde tes sauvegardes hors du serveur.</p>'],
    ];
    foreach ($infos as $key => [$ttl, $html]): ?>
        <template data-info-body="<?= e($key) ?>" data-title="<?= e($ttl) ?>"><?= $html ?></template>
    <?php endforeach; ?>

    <form method="post" action="/logout" class="settings-logout" data-confirm="<?= e(t('logout.confirm')) ?>" data-confirm-title="<?= e(t('logout.title')) ?>" data-confirm-ok="<?= e(t('nav.logout')) ?>">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <button class="btn btn-ghost" type="submit"><?= e(t('nav.logout')) ?></button>
    </form>
</section>
