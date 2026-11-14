<?php
/** @var array|null $persona */
$isEdit   = is_array($persona);
$slug     = $isEdit ? (string) $persona['slug'] : '';
$name     = $isEdit ? (string) $persona['name'] : '';
$tagline  = $isEdit ? (string) $persona['tagline'] : '';
$desc     = $isEdit ? (string) $persona['description'] : '';
$tagsIn   = $isEdit ? implode(', ', array_map('strval', json_list($persona['tags']))) : '';
$metaIn   = $isEdit ? meta_to_text(json_list($persona['meta'])) : '';
$avatar   = $isEdit ? (string) $persona['avatar'] : '';
$hue      = hue_for($name !== '' ? $name : 'persona');
$action   = $isEdit ? '/p/' . $slug : '/p';
?>
<a class="back" href="<?= $isEdit ? '/p/' . e($slug) : '/' ?>">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('form.cancel')), "\n" ?>
</a>

<section class="form-wrap">
    <header class="form-head">
        <p class="eyebrow"><?= e($isEdit ? t('form.eyebrow_edit') : t('form.eyebrow_new')) ?></p>
        <h1><?= e($isEdit ? t('form.title_edit') : t('form.title_new')) ?></h1>
    </header>

    <?php if (!$isEdit && llm_ready()): ?>
        <!-- création assistée : entrée discrète (le formulaire reste l'action principale) -->
        <a class="assist-link" href="/p/assist">
            <svg class="assist-link-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9L12 3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2Z" fill="currentColor"/></svg>
            <span class="assist-link-text">
                <strong><?= e(t('assist.entry_title')) ?></strong>
                <small><?= e(t('assist.entry_text')) ?></small>
            </span>
            <svg class="assist-link-go" viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
    <?php endif; ?>

    <?php if (!$isEdit): ?>
        <div class="modal" id="import-modal" data-modal hidden>
            <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="import-title">
                <header class="modal-head">
                    <h2 id="import-title"><?= e(t('cards.import_title_modal')) ?></h2>
                    <button type="button" class="icon-btn btn-close" data-close-modal aria-label="<?= e(t('common.close')) ?>">&times;</button>
                </header>
                <form method="post" action="/import" enctype="multipart/form-data" class="import-modal-body" data-card-import>
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <p class="hint"><?= e(t('cards.import_hint')) ?></p>
                    <label class="btn btn-primary">
                        <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M12 15V4m0 0-4 4m4-4 4 4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <?= e(t('cards.import_file')) . "\n" ?>
                        <input type="file" name="card" accept=".zip,.json,.png,application/zip,application/json,image/png" hidden data-autosubmit>
                    </label>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- data-dirty-guard : barre « Enregistrer » collée en bas dès qu'on modifie, alerte si on quitte sans enregistrer -->
    <form class="card-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data" data-dirty-guard>
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="avatar-picker">
            <div class="avatar-preview" id="avatar-preview" style="--hue: <?= $hue ?>">
                <?php if ($avatar): ?>
                    <img src="<?= e(media_url($avatar)) ?>" alt="<?= e(t('form.avatar_current')) ?>">
                <?php else: ?>
                    <div class="avatar-fallback"><span><?= e(initials($name !== '' ? $name : '?')) ?></span></div>
                <?php endif; ?>
            </div>
            <div>
                <label class="btn btn-ghost btn-sm">
                    <?= e($avatar ? t('form.avatar_change') : t('form.avatar_choose')) ?>
                    <input type="file" name="avatar" accept="image/*" hidden data-preview="#avatar-preview">
                </label>
                <p><?= e(t('form.avatar_formats')) ?></p>
            </div>
        </div>

        <div class="row2">
            <label class="field">
                <span><?= e(t('form.name')) ?></span>
                <input class="input" name="name" value="<?= e($name) ?>" placeholder="<?= e(t('form.name_placeholder')) ?>" required maxlength="80" autofocus>
            </label>
            <label class="field">
                <span><?= e(t('form.tagline')) ?></span>
                <input class="input" name="tagline" value="<?= e($tagline) ?>" placeholder="<?= e(t('form.tagline_placeholder')) ?>" maxlength="120">
            </label>
        </div>

        <label class="field">
            <span><?= e(t('form.description')) ?></span>
            <textarea class="input" name="description" rows="4" placeholder="<?= e(t('form.description_placeholder')) ?>"><?= e($desc) ?></textarea>
        </label>

        <label class="field">
            <span><?= e(t('form.tags')) ?></span>
            <input class="input" name="tags" value="<?= e($tagsIn) ?>" placeholder="<?= e(t('form.tags_placeholder')) ?>">
            <small><?= e(t('form.tags_hint')) ?></small>
        </label>

        <label class="field">
            <span><?= e(t('form.meta')) ?></span>
            <textarea class="input" name="meta" rows="4" placeholder="<?= str_replace("\n", '&#10;', e(t('form.meta_placeholder'))) ?>"><?= e($metaIn) ?></textarea>
            <small><?= e(t('form.meta_hint')) ?></small>
        </label>

        <?php if ($isEdit): ?>
            <div class="persona-ai" id="ia">
                <div class="persona-ai-head">
                    <h3><?= e(t('form.ai_title')) ?></h3>
                    <p class="hint"><?= e(t('form.ai_hint')) ?></p>
                </div>

                <div class="field">
                    <span><?= e(t('form.model')) ?></span>
                    <?php if (!empty($chatModels)): ?>
                        <div class="sel" data-sel>
                            <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('form.model')) ?>">
                                <span class="sel-value"></span>
                                <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <select name="model" class="sel-native">
                                <option value=""><?= e(t('form.model_default')) ?></option>
                                <?php foreach ($chatModels as $cm): ?>
                                    <option value="<?= e($cm['id']) ?>" <?= (string) $persona['model'] === $cm['id'] ? 'selected' : '' ?>><?= $cm['fav'] ? '★ ' : '' ?><?= e($cm['id']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input class="input" name="model" value="<?= e((string) $persona['model']) ?>" placeholder="<?= e(t('form.model_placeholder')) ?>" spellcheck="false">
                        <small><?= e(t('form.model_unreachable')) ?></small>
                    <?php endif; ?>
                </div>

                <label class="field">
                    <span><?= e(t('settings.chat.reasoning')) ?></span>
                    <select class="input" name="reasoning_effort">
                        <option value=""><?= e(t('chat.reasoning_global')) ?></option>
                        <?php foreach (chat_reasoning_labels() as $effort => $label): ?>
                            <option value="<?= $effort ?>" <?= $persona['reasoning_effort'] === $effort ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small><?= e(t('settings.chat.reasoning_help')) ?></small>
                </label>

                <input type="hidden" name="spontaneous_gifs" value="0">
                <label class="check">
                    <input type="checkbox" name="spontaneous_gifs" value="1" <?= $persona['spontaneous_gifs'] ? 'checked' : '' ?>>
                    <span><?= e(t('chat.gif_spontaneous')) ?></span>
                </label>

                <div class="row2">
                    <label class="field">
                        <span><?= e(t('form.temperature')) ?></span>
                        <input class="input" type="number" name="temperature" step="0.01" min="0" max="2" value="<?= e((string) $persona['temperature']) ?>">
                        <small><?= e(t('form.temperature_hint')) ?></small>
                    </label>
                    <label class="field">
                        <span><?= e(t('form.max_tokens')) ?></span>
                        <input class="input" type="number" name="max_tokens" step="1" min="64" max="4096" value="<?= (int) $persona['max_tokens'] ?>">
                    </label>
                </div>

                <label class="field">
                    <span><?= e(t('form.context_window')) ?></span>
                    <input class="input" type="number" name="context_window" step="1024" min="2048" max="131072" value="<?= (int) $persona['context_window'] ?>">
                </label>

                <div class="field">
                    <div class="field-head">
                        <span><?= e(t('form.directive')) ?></span>
                        <button type="button" class="mini-link" data-ai-directive
                                data-url="/p/<?= e((string) $persona['slug']) ?>/ai-directive"
                                data-token="<?= e(csrf_token()) ?>"><?= e(t('form.directive_ai')) ?></button>
                    </div>
                    <textarea class="input" name="directive" rows="3" data-directive
                              placeholder="<?= e(t('form.directive_placeholder')) ?>"><?= e((string) $persona['directive']) ?></textarea>
                    <small><?= e(t('form.directive_hint')) ?></small>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-actions save-bar">
            <a class="btn btn-ghost" href="<?= $isEdit ? '/p/' . e($slug) : '/' ?>"><?= e(t('form.cancel')) ?></a>
            <button class="btn btn-primary" type="submit"><?= e($isEdit ? t('form.save') : t('form.create')) ?></button>
        </div>

        <?php if (!$isEdit): ?>
            <!-- import : rarement utilisé, donc en pied de carte ; ouvre une fenêtre -->
            <div class="form-import">
                <span class="form-import-or"><?= e(t('form.or')) ?></span>
                <button type="button" class="import-tile" data-open-modal="#import-modal">
                    <span class="import-tile-ico" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><path d="M12 15V4m0 0-4 4m4-4 4 4M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <span class="import-tile-text">
                        <strong><?= e(t('cards.import_title_modal')) ?></strong>
                        <small><?= e(t('cards.import_formats')) ?></small>
                    </span>
                    <svg class="import-tile-go" viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
        <?php endif; ?>
    </form>
</section>
