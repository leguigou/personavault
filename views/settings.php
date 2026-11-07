<?php
/** @var array $llm @var bool $hasKey @var array $chat @var array $chatModels @var bool $chatHasKey */
$providers = [
    'deepseek'   => 'DeepSeek',
    'openai'     => 'OpenAI',
    'openrouter' => 'OpenRouter',
    'lmstudio'   => 'LM Studio (local)',
    'custom'     => 'Autre / compatible OpenAI',
];
?>
<a class="back" href="/">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Bibliothèque
</a>

<section class="form-wrap">
    <header class="form-head">
        <p class="eyebrow">Réglages</p>
        <h1>Paramètres</h1>
        <p class="form-sub">Connexions, discussions et apparence — un onglet par sujet.</p>
    </header>

    <nav class="tabs" data-tabs>
        <button type="button" class="tab is-on" data-tab="ia">Intelligence</button>
        <button type="button" class="tab" data-tab="chat">Discussion</button>
        <button type="button" class="tab" data-tab="look">Apparence</button>
        <button type="button" class="tab" data-tab="prompts">Prompts</button>
        <button type="button" class="tab" data-tab="backup">Sauvegarde</button>
    </nav>

    <div class="tab-panel" data-panel="ia">
    <header class="form-head settings-sub">
        <h2>Création de fiches</h2>
        <p class="form-sub">Modèle qui remplit les fiches persona (bouton IA).</p>
    </header>

    <form class="card-form" method="post" action="/settings" data-settings-form>
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
            <span>Clé API</span>
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

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-llm-test>Test de connexion</button>
            <button class="btn btn-primary" type="submit">Enregistrer</button>
        </div>
    </form>
    </div>

    <div class="tab-panel" data-panel="chat" hidden>
    <header class="form-head settings-sub">
        <h2>Modèle de chat</h2>
        <p class="form-sub">Utilisé uniquement pour parler aux personas. Si LM Studio est éteint, l’app bascule sur DeepSeek en te prévenant.</p>
    </header>

    <form class="card-form" method="post" action="/settings/chat">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

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

        <div class="field">
            <span>Réflexion du modèle</span>
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

        <div class="settings-block">
            <h3>Réponses en plusieurs bulles</h3>
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

        <div class="settings-block">
            <h3>Messages spontanés</h3>
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
                    <div class="row2 row-tight">
                        <label class="field">
                            <input class="input" type="number" name="chat_followup_from" min="0" max="23" value="<?= (int) setting('chat_followup_from', '8') ?>" aria-label="De">
                        </label>
                        <label class="field">
                            <input class="input" type="number" name="chat_followup_to" min="1" max="24" value="<?= (int) setting('chat_followup_to', '23') ?>" aria-label="À">
                        </label>
                    </div>
                    <small>De … h à … h. Rien la nuit.</small>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-ghost" type="button" data-purge-lms>Purger la mémoire LM Studio</button>
            <button class="btn btn-primary" type="submit">Enregistrer</button>
        </div>
        <p class="hint" data-purge-status>
            Changer de modèle décharge automatiquement l’ancien. Le bouton force la libération de la VRAM.
        </p>
    </form>
    </div>

    <div class="tab-panel" data-panel="look" hidden>
    <header class="form-head settings-sub">
        <h2>Apparence des conversations</h2>
        <p class="form-sub">S’applique à toutes les discussions. L’aperçu réagit en direct.</p>
    </header>

    <form class="card-form" method="post" action="/settings/ui" data-ui-form>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

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

        <div class="ui-preview" data-ui-preview>
            <div class="pv-msg pv-bot">
                <span class="pv-av">L</span>
                <div class="pv-bubble">Salut&nbsp;! Tu as passé une bonne journée&nbsp;?</div>
            </div>
            <div class="pv-msg pv-user">
                <div class="pv-bubble">Ouais, et toi&nbsp;?</div>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Enregistrer l’apparence</button>
        </div>
    </form>
    </div>

    <div class="tab-panel" data-panel="prompts" hidden>
    <header class="form-head settings-sub">
        <h2>Prompts internes</h2>
        <p class="form-sub">Les consignes que l’app envoie au modèle pour la mémoire, les souvenirs et les relances. Vides = texte par défaut.</p>
    </header>

    <form class="card-form" method="post" action="/settings/prompts" data-prompts-form>
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

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">Enregistrer les prompts</button>
        </div>
    </form>
    </div>

    <div class="tab-panel" data-panel="backup" hidden>
    <header class="form-head settings-sub">
        <h2>Sauvegarde</h2>
        <p class="form-sub">Un ZIP par sauvegarde : un dossier par persona avec sa fiche, ses prompts et ses photos.</p>
    </header>

    <section class="backup-card">
        <h3>Exporter</h3>
        <p class="hint">
            <?= (int) $backupStats['personas'] ?> persona(s) · <?= (int) $backupStats['photos'] ?> photo(s) ·
            <?= (int) $backupStats['prompts'] ?> prompt(s) · <?= (int) $backupStats['conversations'] ?> conversation(s)
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

        <form method="post" action="/backup/import" enctype="multipart/form-data" data-confirm="Importer cette sauvegarde ? Les personas seront ajoutés à la bibliothèque.">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input class="input" type="file" name="archive" accept=".zip,application/zip" required>
            <div class="form-actions">
                <button class="btn btn-ghost" type="submit">Importer l’archive</button>
            </div>
        </form>
    </section>

    <p class="hint">Astuce : garde le ZIP hors du serveur (disque externe, cloud). Il contient tout ce qu’il faut pour repartir de zéro.</p>
    </div>

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

    <form method="post" action="/logout" class="settings-logout" data-confirm="Se déconnecter de PersonaVault ?">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <button class="btn btn-ghost" type="submit">Se déconnecter</button>
    </form>
</section>
