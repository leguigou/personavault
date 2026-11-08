<?php
/** @var array $persona @var array $photos @var array $prompts */
$tags     = json_list($persona['tags']);
$meta     = json_list($persona['meta']);
$kinds    = prompt_kinds();
$hue      = hue_for($persona['name']);
$avatar   = (string) $persona['avatar'];
$isArch   = (int) $persona['archived'] === 1;
$token    = csrf_token();
?>
<a class="back" href="/">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('nav.library')), "\n" ?>
</a>

<section class="hero" style="--hue: <?= $hue ?>">
    <?php if ($avatar): ?>
        <div class="hero-bg" style="background-image:url('<?= e(media_url($avatar, false)) ?>')" aria-hidden="true"></div>
    <?php endif; ?>

    <div class="hero-portrait">
        <?php if ($avatar): ?>
            <img src="<?= e(media_url($avatar, false)) ?>" alt="<?= e($persona['name']) ?>">
        <?php else: ?>
            <div class="avatar-fallback"><span><?= e(initials($persona['name'])) ?></span></div>
        <?php endif; ?>
    </div>

    <div class="hero-body">
        <div class="hero-badges">
            <?php if ((int) $persona['favorite']): ?><span class="badge badge-gold"><?= e(t('persona.badge_favorite')) ?></span><?php endif; ?>
            <?php if ($isArch): ?><span class="badge"><?= e(t('persona.badge_archived')) ?></span><?php endif; ?>
            <?php foreach ($tags as $t): ?>
                <a class="badge badge-tag" href="/?tag=<?= urlencode((string) $t) ?>" title="<?= e(t('persona.tag_link_title')) ?>">#<?= e((string) $t) ?></a>
            <?php endforeach; ?>
        </div>

        <h1><?= e($persona['name']) ?></h1>
        <?php if ($persona['tagline']): ?><p class="hero-tagline"><?= e($persona['tagline']) ?></p><?php endif; ?>
        <?php if ($persona['description']): ?><p class="hero-desc"><?= nl2br(e($persona['description'])) ?></p><?php endif; ?>

        <div class="hero-facts">
            <span><?= e(t('persona.count_photos', count($photos))) ?></span>
            <span><?= e(t('persona.count_prompts', count($prompts))) ?></span>
        </div>

        <div class="hero-actions">
            <a class="btn btn-primary" href="/p/<?= e($persona['slug']) ?>/edit">
                <svg viewBox="0 0 24 24" class="ico"><path d="M4 20h4l10-10-4-4L4 16v4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                <?= e(t('persona.edit')), "\n" ?>
            </a>
            <?php if (llm_ready()): ?>
                <button type="button" class="btn btn-ai" data-ai-fill="/p/<?= e($persona['slug']) ?>/ai-fill" data-token="<?= e($token) ?>"
                        title="<?= e(t('persona.ai_fill_title')) ?>">
                    <svg viewBox="0 0 24 24" class="ico"><path d="M12 3.4l1.7 4.9 4.9 1.7-4.9 1.7L12 16.6l-1.7-4.9L5.4 10l4.9-1.7L12 3.4Z" fill="currentColor"/><path d="M18.4 14.6l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7.7-2Z" fill="currentColor" opacity=".65"/></svg>
                    <span class="ai-full"><?= e(t('persona.ai_fill')) ?></span><span class="ai-short"><?= e(t('persona.ai_fill_short')) ?></span>
                </button>
            <?php endif; ?>
            <form method="post" action="/chat">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="persona_id" value="<?= (int) $persona['id'] ?>">
                <button class="btn btn-ghost" type="submit">
                    <svg viewBox="0 0 24 24" class="ico"><path d="M7 5h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4 3v-3H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                    <?= e(t('persona.chat')), "\n" ?>
                </button>
            </form>
            <button class="btn btn-ghost" type="button" data-toggle="#export-panel" aria-controls="export-panel">
                <svg viewBox="0 0 24 24" class="ico"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <?= e(t('cards.export')), "\n" ?>
            </button>
            <form method="post" action="/p/<?= e($persona['slug']) ?>/favorite">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <button class="btn btn-ghost" type="submit" title="<?= e(t('persona.favorite_toggle')) ?>">
                    <svg viewBox="0 0 24 24" class="ico"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z" fill="<?= (int) $persona['favorite'] ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                    <?= e(t('persona.favorite')), "\n" ?>
                </button>
            </form>
            <form method="post" action="/p/<?= e($persona['slug']) ?>/archive">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <button class="btn btn-ghost" type="submit">
                    <svg viewBox="0 0 24 24" class="ico"><path d="M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8Zm-1-3h18v3H3V5Zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                    <?= e($isArch ? t('persona.restore') : t('persona.archive')) ?>
                </button>
            </form>
            <form method="post" action="/p/<?= e($persona['slug']) ?>/delete" class="hero-delete" data-confirm="<?= e(t('persona.delete_confirm', ['name' => $persona['name']])) ?>">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <button class="btn btn-danger-ghost" type="submit">
                    <svg viewBox="0 0 24 24" class="ico"><path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                    <?= e(t('persona.delete')), "\n" ?>
                </button>
            </form>
        </div>
    </div>
</section>

<section class="panel panel-wide" id="export-panel" hidden>
    <header class="panel-head">
        <h2><?= e(t('cards.export_title')) ?></h2>
        <button type="button" class="mini" data-toggle="#export-panel"><?= e(t('cards.export_close')) ?></button>
    </header>
    <div class="prompt-list">
        <?php foreach ([
            ['export.zip', t('cards.export_zip'), t('cards.export_zip_hint')],
            ['card.json', t('cards.export_json'), t('cards.export_json_hint')],
            ['card.png', t('cards.export_png'), $avatar ? t('cards.export_png_hint') : t('cards.export_png_hint_initials')],
        ] as [$file, $label, $hint]): ?>
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 14px">
                <div style="min-width:0;flex:1 1 220px">
                    <strong><?= e($label) ?></strong>
                    <p class="hint"><?= e($hint) ?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="/p/<?= e($persona['slug']) ?>/<?= e($file) ?>" download data-export="<?= e($file) ?>">
                    <svg viewBox="0 0 24 24" class="ico"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?= e(t('cards.download')), "\n" ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <p class="hint" style="margin-top:12px"><?= e(t('cards.export_note')) ?></p>
</section>

<div class="detail-cols">
    <section class="panel">
        <header class="panel-head">
            <h2><?= e(t('persona.photos_title')) ?> <span class="count"><?= count($photos) ?></span></h2>
        </header>

        <?php if (!$photos): ?>
            <p class="hint"><?= e(t('persona.photos_empty')) ?></p>
        <?php endif; ?>

        <p class="hint paste-hint only-pointer">
            <?= t('persona.paste_hint_html'), "\n" ?>
        </p>

        <div class="gallery">
                <?php foreach ($photos as $ph): ?>
                    <figure class="shot">
                        <img src="<?= e(media_url($ph['file'])) ?>" alt="<?= e($ph['caption'] ?: $persona['name']) ?>" loading="lazy"
                             data-full="<?= e(media_url($ph['file'], false)) ?>" role="button" tabindex="0">
                        <div class="shot-tools">
                            <form method="post" action="/photos/<?= (int) $ph['id'] ?>/avatar">
                                <input type="hidden" name="_token" value="<?= e($token) ?>">
                                <button type="submit" title="<?= e(t('persona.photo_set_avatar')) ?>" aria-label="<?= e(t('persona.photo_set_avatar')) ?>">
                                    <svg viewBox="0 0 24 24"><path d="M12 4.5 14.6 9.6l5.6.8-4.1 3.9 1 5.6-5.1-2.7-5 2.7 1-5.6L3.9 10.4l5.6-.8L12 4.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                                </button>
                            </form>
                            <form method="post" action="/photos/<?= (int) $ph['id'] ?>/delete" data-confirm="<?= e(t('persona.photo_delete_confirm')) ?>">
                                <input type="hidden" name="_token" value="<?= e($token) ?>">
                                <button type="submit" title="<?= e(t('persona.delete')) ?>" aria-label="<?= e(t('persona.delete')) ?>">
                                    <svg viewBox="0 0 24 24"><path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                                </button>
                            </form>
                        </div>
                    </figure>
                <?php endforeach; ?>

                <form method="post" action="/p/<?= e($persona['slug']) ?>/photos" enctype="multipart/form-data" class="shot shot-add" data-photo-upload>
                    <input type="hidden" name="_token" value="<?= e($token) ?>">
                    <label>
                        <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                        <span><?= e(t('persona.add')) ?></span>
                        <input type="file" name="photos[]" accept="image/*" multiple hidden data-autosubmit>
                    </label>
                </form>
            </div>
    </section>

    <aside class="panel panel-side">
        <?php if ($meta): ?>
            <dl class="metas">
                <?php foreach ($meta as $row): ?>
                    <?php if (!is_array($row) || empty($row['k'])) continue; ?>
                    <div>
                        <dt><?= e((string) $row['k']) ?></dt>
                        <dd><?= e((string) ($row['v'] ?? '')) ?: '—' ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php else: ?>
            <p class="hint"><?= e(t('persona.meta_empty')) ?></p>
        <?php endif; ?>
    </aside>
</div>

<?php if (trim((string) $persona['directive']) !== ''): ?>
<section class="panel panel-wide">
    <header class="panel-head">
        <h2><?= e(t('persona.directive_title')) ?></h2>
        <a class="mini" href="/p/<?= e((string) $persona['slug']) ?>/edit#ia"><?= e(t('persona.edit')) ?></a>
    </header>
    <p class="directive-text"><?= nl2br(e((string) $persona['directive'])) ?></p>
    <p class="hint"><?= e(t('persona.directive_hint')) ?></p>
</section>
<?php endif; ?>

<section class="panel panel-wide">
    <header class="panel-head">
        <h2><?= e(t('persona.prompts_title')) ?> <span class="count"><?= count($prompts) ?></span></h2>
        <button class="btn btn-ghost btn-sm" type="button" data-toggle="#new-prompt">
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            <?= e(t('persona.add')), "\n" ?>
        </button>
    </header>

    <?php if (!$prompts): ?>
        <p class="hint"><?= e(t('persona.prompts_empty')) ?></p>
    <?php endif; ?>

    <div class="prompt-list">
        <?php foreach ($prompts as $pr): ?>
            <article class="prompt">
                <header class="prompt-head">
                    <?php $kindLabel = $kinds[$pr['kind']] ?? t('persona.prompt_kind_other'); ?>
                    <span class="kind kind-<?= e((string) $pr['kind']) ?>"><?= e($kindLabel) ?></span>
                    <?php if ($pr['title'] !== '' && mb_strtolower($pr['title']) !== mb_strtolower($kindLabel)): ?>
                        <strong><?= e($pr['title']) ?></strong>
                    <?php endif; ?>
                    <div class="prompt-tools">
                        <button type="button" class="mini" data-copy="#body-<?= (int) $pr['id'] ?>"><?= e(t('persona.prompt_copy')) ?></button>
                        <button type="button" class="mini" data-toggle="#edit-<?= (int) $pr['id'] ?>"><?= e(t('persona.prompt_edit')) ?></button>
                        <form method="post" action="/prompts/<?= (int) $pr['id'] ?>/delete" data-confirm="<?= e(t('persona.prompt_delete_confirm')) ?>">
                            <input type="hidden" name="_token" value="<?= e($token) ?>">
                            <button type="submit" class="mini mini-danger" title="<?= e(t('persona.delete')) ?>" aria-label="<?= e(t('persona.delete')) ?>">&times;</button>
                        </form>
                    </div>
                </header>
                <pre class="prompt-body" id="body-<?= (int) $pr['id'] ?>"><?= e($pr['body']) ?></pre>

                <form method="post" action="/prompts/<?= (int) $pr['id'] ?>" class="prompt-edit" id="edit-<?= (int) $pr['id'] ?>" hidden>
                    <input type="hidden" name="_token" value="<?= e($token) ?>">
                    <div class="row2">
                        <label class="field"><span><?= e(t('persona.prompt_title')) ?></span><input class="input" name="title" value="<?= e($pr['title']) ?>"></label>
<div class="field">
                            <span><?= e(t('persona.prompt_kind')) ?></span>
                            <div class="sel" data-sel>
                                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('persona.prompt_kind_label')) ?>">
                                    <span class="sel-value"></span>
                                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </button>
                                <select name="kind" class="sel-native">
                                    <?php foreach ($kinds as $k => $label): ?>
                                        <option value="<?= e($k) ?>" <?= $pr['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <label class="field"><span><?= e(t('persona.prompt_content')) ?></span><textarea class="input" name="body" rows="5" required><?= e($pr['body']) ?></textarea></label>
                    <div class="form-actions">
                        <button class="btn btn-primary btn-sm" type="submit"><?= e(t('persona.prompt_save')) ?></button>
                    </div>
                </form>
            </article>
        <?php endforeach; ?>
    </div>

    <form method="post" action="/p/<?= e($persona['slug']) ?>/prompts" class="prompt-new" id="new-prompt" hidden>
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <div class="row2">
            <label class="field"><span><?= e(t('persona.prompt_title')) ?></span><input class="input" name="title" placeholder="<?= e(t('persona.prompt_title_placeholder')) ?>"></label>
            <div class="field">
                <span><?= e(t('persona.prompt_kind')) ?></span>
                <div class="sel" data-sel>
                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('persona.prompt_kind_label')) ?>">
                        <span class="sel-value"></span>
                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                    <select name="kind" class="sel-native">
                        <?php foreach ($kinds as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <label class="field"><span><?= e(t('persona.prompt_body')) ?></span><textarea class="input" name="body" rows="4" placeholder="<?= e(t('persona.prompt_body_placeholder')) ?>" required></textarea></label>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit"><?= e(t('persona.prompt_create')) ?></button>
        </div>
    </form>
</section>
