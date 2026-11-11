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
            <!-- couverture : s'ouvre dans la visionneuse, avec les photos de la galerie -->
            <img src="<?= e(media_url($avatar, false)) ?>" alt="<?= e($persona['name']) ?>"
                 data-full="<?= e(media_url($avatar, false)) ?>" data-gallery-of="#persona-gallery"
                 role="button" tabindex="0" aria-label="<?= e(t('persona.cover_open')) ?>">
        <?php else: ?>
            <div class="avatar-fallback"><span><?= e(initials($persona['name'])) ?></span></div>
        <?php endif; ?>
        <!-- favori : cœur rond posé sur le portrait -->
        <form method="post" action="/p/<?= e($persona['slug']) ?>/favorite" class="hero-fav-form">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <button class="hero-fav<?= (int) $persona['favorite'] ? ' is-on' : '' ?>" type="submit" aria-pressed="<?= (int) $persona['favorite'] ? 'true' : 'false' ?>"
                    aria-label="<?= e((int) $persona['favorite'] ? t('persona.favorite_remove') : t('persona.favorite_add')) ?>" title="<?= e((int) $persona['favorite'] ? t('persona.favorite_remove') : t('persona.favorite_add')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z" fill="<?= (int) $persona['favorite'] ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg>
            </button>
        </form>
    </div>

    <div class="hero-body">
        <div class="hero-head">
        <div class="hero-badges">
            <?php if ($isArch): ?><span class="badge"><?= e(t('persona.badge_archived')) ?></span><?php endif; ?>
            <?php foreach ($tags as $t): ?>
                <a class="badge badge-tag" href="/?tag=<?= urlencode((string) $t) ?>" title="<?= e(t('persona.tag_link_title')) ?>">#<?= e((string) $t) ?></a>
            <?php endforeach; ?>
        </div>

        <h1><?= e($persona['name']) ?></h1>
        <?php if ($persona['tagline']): ?><p class="hero-tagline"><?= e($persona['tagline']) ?></p><?php endif; ?>
        </div>
        <?php if ($persona['description']): ?><p class="hero-desc"><?= nl2br(e($persona['description'])) ?></p><?php endif; ?>

        <div class="hero-facts">
            <span><?= e(t('persona.count_photos', count($photos))) ?></span>
            <span><?= e(t('persona.count_prompts', count($prompts))) ?></span>
        </div>

        <!-- actions : une rangée de boutons ronds avec libellé (Discuter, Modifier, Compléter, Plus) -->
        <div class="hero-actions">
            <form method="post" action="/chat" class="round-act">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <input type="hidden" name="persona_id" value="<?= (int) $persona['id'] ?>">
                <button class="round-btn is-main" type="submit" aria-describedby="act-chat">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4 3v-3H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </button>
                <span class="round-label" id="act-chat"><?= e(t('persona.chat')) ?></span>
            </form>
            <div class="round-act">
                <a class="round-btn" href="/p/<?= e($persona['slug']) ?>/edit" aria-describedby="act-edit">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16v4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </a>
                <span class="round-label" id="act-edit"><?= e(t('persona.edit')) ?></span>
            </div>
            <?php if (llm_ready()): ?>
                <div class="round-act">
                    <button type="button" class="round-btn" data-ai-fill="/p/<?= e($persona['slug']) ?>/ai-fill" data-token="<?= e($token) ?>"
                            title="<?= e(t('persona.ai_fill_title')) ?>" aria-describedby="act-ai">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.4l1.7 4.9 4.9 1.7-4.9 1.7L12 16.6l-1.7-4.9L5.4 10l4.9-1.7L12 3.4Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><path d="M18.4 14.6l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7.7-2Z" fill="currentColor" opacity=".7"/></svg>
                    </button>
                    <span class="round-label" id="act-ai"><?= e(t('persona.ai_fill_short')) ?></span>
                </div>
            <?php endif; ?>
            <div class="round-act">
                <button type="button" class="round-btn" data-open-modal="#more-modal" aria-haspopup="dialog" aria-describedby="act-more">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="12" r="1.7" fill="currentColor"/><circle cx="12" cy="12" r="1.7" fill="currentColor"/><circle cx="18" cy="12" r="1.7" fill="currentColor"/></svg>
                </button>
                <span class="round-label" id="act-more"><?= e(t('persona.more')) ?></span>
            </div>
        </div>
    </div>
</section>

<!-- « Plus » : les actions moins fréquentes -->
<div class="modal action-sheet" id="more-modal" data-modal hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="more-title">
        <header class="modal-head">
            <div>
                <p class="eyebrow"><?= e((string) $persona['name']) ?></p>
                <h2 id="more-title"><?= e(t('persona.more_title')) ?></h2>
            </div>
            <button type="button" class="icon-btn btn-close" data-close-modal aria-label="<?= e(t('common.close')) ?>">&times;</button>
        </header>
        <div class="action-list">
            <button type="button" class="action-row action-row-2" data-open-modal="#export-modal">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><strong><?= e(t('cards.export')) ?></strong><small><?= e(t('persona.more_export_hint')) ?></small></span>
            </button>
            <form method="post" action="/p/<?= e($persona['slug']) ?>/duplicate">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <button type="submit" class="action-row action-row-2">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8.5" y="8.5" width="11" height="11" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M15.5 8.5V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7.5a2 2 0 0 0 2 2h2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
                    <span><strong><?= e(t('persona.duplicate')) ?></strong><small><?= e(t('persona.duplicate_title')) ?></small></span>
                </button>
            </form>
            <button type="button" class="action-row action-row-2 is-danger" data-open-modal="#remove-modal">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                <span><strong><?= e($isArch ? t('persona.more_remove_archived') : t('persona.more_remove')) ?></strong><small><?= e(t('persona.more_remove_hint')) ?></small></span>
            </button>
        </div>
    </div>
</div>

<!-- retirer la fiche : archiver (récupérable) ou supprimer pour de bon -->
<div class="modal action-sheet" id="remove-modal" data-modal hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="remove-title">
        <header class="modal-head">
            <div>
                <p class="eyebrow"><?= e((string) $persona['name']) ?></p>
                <h2 id="remove-title"><?= e(t('persona.remove_title')) ?></h2>
            </div>
            <button type="button" class="icon-btn btn-close" data-close-modal aria-label="<?= e(t('common.close')) ?>">&times;</button>
        </header>
        <div class="action-list">
            <form method="post" action="/p/<?= e($persona['slug']) ?>/archive">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <button type="submit" class="action-row action-row-2">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8Zm-1-3h18v3H3V5Zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                    <span>
                        <strong><?= e($isArch ? t('persona.restore') : t('persona.archive')) ?></strong>
                        <small><?= e($isArch ? t('persona.remove_restore_hint') : t('persona.remove_archive_hint')) ?></small>
                    </span>
                </button>
            </form>
            <form method="post" action="/p/<?= e($persona['slug']) ?>/delete" data-confirm="<?= e(t('persona.delete_confirm', ['name' => $persona['name']])) ?>">
                <input type="hidden" name="_token" value="<?= e($token) ?>">
                <button type="submit" class="action-row action-row-2 is-danger">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
                    <span>
                        <strong><?= e(t('persona.remove_delete')) ?></strong>
                        <small><?= e(t('persona.remove_delete_hint')) ?></small>
                    </span>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- export de la fiche : fenêtre (feuille en bas sur mobile) -->
<div class="modal action-sheet" id="export-modal" data-modal hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="export-title">
        <header class="modal-head">
            <div>
                <p class="eyebrow"><?= e((string) $persona['name']) ?></p>
                <h2 id="export-title"><?= e(t('cards.export_title')) ?></h2>
            </div>
            <button type="button" class="icon-btn btn-close" data-close-modal aria-label="<?= e(t('common.close')) ?>">&times;</button>
        </header>
        <div class="export-list">
            <?php foreach ([
                ['export.zip', 'zip', t('cards.export_zip'), t('cards.export_zip_hint'), t('cards.export_badge_full')],
                ['card.json', 'json', t('cards.export_json'), t('cards.export_json_hint'), ''],
                ['card.png', 'png', t('cards.export_png'), $avatar ? t('cards.export_png_hint') : t('cards.export_png_hint_initials'), ''],
            ] as [$file, $kind, $label, $hint, $badge]): ?>
                <a class="export-option export-<?= e($kind) ?>" href="/p/<?= e($persona['slug']) ?>/<?= e($file) ?>" download data-export="<?= e($file) ?>">
                    <span class="export-ico" aria-hidden="true"><?= e(strtoupper($kind)) ?></span>
                    <span class="export-text">
                        <strong><?= e($label) ?><?php if ($badge !== ''): ?> <em class="export-badge"><?= e($badge) ?></em><?php endif; ?></strong>
                        <small><?= e($hint) ?></small>
                    </span>
                    <span class="export-go" aria-hidden="true"><svg viewBox="0 0 24 24" class="ico"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="hint export-note"><?= e(t('cards.export_note')) ?></p>
    </div>
</div>

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

        <div class="gallery" id="persona-gallery" data-gallery data-gallery-name="<?= e(slugify((string) $persona['name'])) ?>"
             data-photo-actions data-token="<?= e($token) ?>" data-delete-confirm="<?= e(t('persona.photo_delete_confirm')) ?>">
                <?php
                // la photo de portrait (étoile) passe en tête ; les autres gardent leur ordre
                $galleryPhotos = $photos;
                usort($galleryPhotos, static fn (array $a, array $b): int =>
                    ((string) $b['file'] === (string) $persona['avatar']) <=> ((string) $a['file'] === (string) $persona['avatar']));
                ?>
                <?php foreach ($galleryPhotos as $ph): ?>
                    <?php $isPortrait = (string) $ph['file'] === (string) $persona['avatar']; ?>
                    <!-- les actions (portrait, supprimer) sont dans la visionneuse, photo ouverte en grand -->
                    <figure class="shot<?= $isPortrait ? ' is-portrait' : '' ?>">
                        <img src="<?= e(media_url($ph['file'])) ?>" alt="<?= e($ph['caption'] ?: $persona['name']) ?>" loading="lazy"
                             data-full="<?= e(media_url($ph['file'], false)) ?>" data-photo-id="<?= (int) $ph['id'] ?>"
                             <?= $isPortrait ? 'data-portrait="1"' : '' ?> role="button" tabindex="0">
                        <?php if ($isPortrait): ?>
                            <span class="shot-badge" title="<?= e(t('persona.photo_is_avatar')) ?>" aria-label="<?= e(t('persona.photo_is_avatar')) ?>">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5 14.6 9.6l5.6.8-4.1 3.9 1 5.6-5.1-2.7-5 2.7 1-5.6L3.9 10.4l5.6-.8L12 4.5Z" fill="currentColor"/></svg>
                            </span>
                        <?php endif; ?>
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
        <button class="btn btn-ghost btn-sm" type="button" data-prompt-new>
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            <?= e(t('persona.add')), "\n" ?>
        </button>
    </header>

    <?php if (!$prompts): ?>
        <p class="hint"><?= e(t('persona.prompts_empty')) ?></p>
    <?php endif; ?>

    <div class="prompt-list">
        <?php foreach ($prompts as $pr): ?>
            <article class="prompt" data-prompt-id="<?= (int) $pr['id'] ?>" data-prompt-title="<?= e((string) $pr['title']) ?>" data-prompt-kind="<?= e((string) $pr['kind']) ?>">
                <header class="prompt-head">
                    <?php $kindLabel = $kinds[$pr['kind']] ?? t('persona.prompt_kind_other'); ?>
                    <span class="kind kind-<?= e((string) $pr['kind']) ?>"><?= e($kindLabel) ?></span>
                    <?php if ($pr['title'] !== '' && mb_strtolower($pr['title']) !== mb_strtolower($kindLabel)): ?>
                        <strong><?= e($pr['title']) ?></strong>
                    <?php endif; ?>
                    <div class="prompt-tools">
                        <button type="button" class="icon-tool" data-copy="#body-<?= (int) $pr['id'] ?>" aria-label="<?= e(t('persona.prompt_copy')) ?>" title="<?= e(t('persona.prompt_copy')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8.5" y="8.5" width="11" height="11" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M15.5 8.5V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7.5a2 2 0 0 0 2 2h2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                        <?php if (llm_ready()): ?>
                            <button type="button" class="icon-tool is-ai-soft" data-prompt-edit data-prompt-studio aria-label="<?= e(t('persona.prompt_studio_open')) ?>" title="<?= e(t('persona.prompt_studio_open')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9L12 3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2Z" fill="currentColor"/></svg></button>
                        <?php endif; ?>
                        <button type="button" class="icon-tool" data-prompt-edit aria-label="<?= e(t('persona.prompt_edit')) ?>" title="<?= e(t('persona.prompt_edit')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4l10-10-4-4L4 16v4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></button>
                    </div>
                </header>
                <pre class="prompt-body" id="body-<?= (int) $pr['id'] ?>" data-prompt-edit><?= e($pr['body']) ?></pre>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- éditeur de prompt (piste G) : grande fenêtre, pour modifier comme pour ajouter -->
    <div class="modal prompt-editor" id="prompt-editor" data-modal hidden
         data-new-action="/p/<?= e($persona['slug']) ?>/prompts" data-delete-confirm="<?= e(t('persona.prompt_delete_confirm')) ?>"
         data-label-new="<?= e(t('persona.prompt_new_title')) ?>" data-label-edit="<?= e(t('persona.prompt_edit_title')) ?>">
        <form method="post" class="modal-card pe-card" role="dialog" aria-modal="true" aria-labelledby="pe-heading" data-pe-form>
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <h2 id="pe-heading" class="sr-only" data-pe-heading></h2>
            <div class="pe-top">
                <label class="sr-only" for="pe-title"><?= e(t('persona.prompt_title')) ?></label>
                <input id="pe-title" class="pe-title" name="title" maxlength="120" placeholder="<?= e(t('persona.prompt_title_placeholder')) ?>" autocomplete="off">
                <button type="button" class="icon-btn btn-close" data-close-modal aria-label="<?= e(t('common.close')) ?>">&times;</button>
            </div>
            <div class="pe-kinds" role="radiogroup" aria-label="<?= e(t('persona.prompt_kind_label')) ?>">
                <?php foreach ($kinds as $k => $label): ?>
                    <label class="pe-kind kind-<?= e($k) ?>">
                        <input type="radio" name="kind" value="<?= e($k) ?>" class="sr-only">
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php
            // atelier IA : modèle des Réglages › IA ; lit-il la photo principale ?
            $aiOn  = llm_ready();
            $aiCfg = llm_config();
            $cover = (string) ($persona['avatar'] ?? '') !== '' ? (string) $persona['avatar'] : (string) ($photos[0]['file'] ?? '');
            $aiEye = $cover !== '' && !llm_provider_without_vision((string) $aiCfg['provider'], (string) $aiCfg['model']);
            ?>
            <!-- onglets : le texte, l'atelier IA, l'historique des modifications -->
            <div class="pe-tabs" role="tablist" aria-label="<?= e(t('persona.prompt_tabs')) ?>">
                <button type="button" role="tab" class="pe-tab" data-pe-tab="text" aria-selected="true"><?= e(t('persona.prompt_tab_text')) ?></button>
                <?php if ($aiOn): ?>
                    <button type="button" role="tab" class="pe-tab is-ai" data-pe-tab="studio" aria-selected="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9L12 3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2Z" fill="currentColor"/></svg><?= e(t('persona.prompt_tab_studio')) ?></button>
                <?php endif; ?>
                <button type="button" role="tab" class="pe-tab" data-pe-tab="history" aria-selected="false"><?= e(t('persona.prompt_tab_history')) ?></button>
            </div>

            <label class="sr-only" for="pe-body"><?= e(t('persona.prompt_body')) ?></label>
            <textarea id="pe-body" class="pe-body" name="body" required data-pe-pane="text" placeholder="<?= e(t('persona.prompt_body_placeholder')) ?>"></textarea>

            <?php if ($aiOn): ?>
                <!-- atelier IA : créer, faire le bilan, optimiser / compléter, retouches, variantes ;
                     toute proposition passe par une revue (différences mot à mot) avant d'être appliquée -->
                <div class="pe-pane st" data-pe-pane="studio" hidden
                     data-endpoint="/p/<?= e($persona['slug']) ?>/ai-prompt" data-vision="<?= $aiEye ? '1' : '0' ?>">
                    <div class="st-context">
                        <span class="st-model" title="<?= e(t('persona.studio_model_title')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9L12 3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2Z" fill="currentColor"/></svg><span><?= e((string) $aiCfg['model']) ?></span></span>
                        <?php if ($aiEye): ?>
                            <span class="st-vision is-on" title="<?= e(t('persona.studio_vision_on_title')) ?>">
                                <img src="<?= e(media_url($cover)) ?>" alt=""><?= e(t('persona.studio_vision_on')) ?>
                            </span>
                        <?php else: ?>
                            <span class="st-vision" title="<?= e(t('persona.studio_vision_off_title')) ?>"><?= e(t('persona.studio_vision_off')) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="st-scroll" data-st-scroll>
                        <!-- prompt vide : création guidée -->
                        <section class="st-card st-hero" data-st-empty hidden>
                            <div class="st-hero-ico" aria-hidden="true"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9L12 3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2Z" fill="currentColor"/></svg></div>
                            <h3><?= e(t('persona.studio_create_title')) ?></h3>
                            <p><?= e(t('persona.studio_create_text')) ?></p>
                            <button type="button" class="btn btn-primary st-cta" data-st-run="create"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9L12 3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2Z" fill="currentColor"/></svg><?= e(t('persona.studio_create_go')) ?></button>
                            <div class="st-chips" data-st-starters></div>
                        </section>

                        <!-- prompt rempli : aperçu, bilan, retouches -->
                        <div data-st-filled hidden>
                            <button type="button" class="st-current" data-st-current title="<?= e(t('persona.studio_current_title')) ?>"></button>

                            <section class="st-card st-review-card" data-st-bilan>
                                <div class="st-bilan-empty" data-st-bilan-empty>
                                    <div>
                                        <h3><?= e(t('persona.studio_bilan_title')) ?></h3>
                                        <p><?= e(t('persona.studio_bilan_text')) ?></p>
                                    </div>
                                    <button type="button" class="btn btn-ghost btn-sm" data-st-run="analyze"><?= e(t('persona.studio_bilan_go')) ?></button>
                                </div>
                                <div class="st-bilan" data-st-bilan-result hidden></div>
                            </section>

                            <section class="st-card">
                                <h3 class="st-h"><?= e(t('persona.studio_actions_title')) ?></h3>
                                <div class="st-actions">
                                    <button type="button" class="st-action" data-st-run="optimize" data-st-visual>
                                        <strong><?= e(t('persona.studio_optimize')) ?></strong><small><?= e(t('persona.studio_optimize_hint')) ?></small>
                                    </button>
                                    <button type="button" class="st-action" data-st-run="complete" data-st-textual>
                                        <strong><?= e(t('persona.studio_complete')) ?></strong><small><?= e(t('persona.studio_complete_hint')) ?></small>
                                    </button>
                                    <button type="button" class="st-action" data-st-run="variants" data-st-scenic>
                                        <strong><?= e(t('persona.studio_variants')) ?></strong><small><?= e(t('persona.studio_variants_hint')) ?></small>
                                    </button>
                                </div>
                                <h3 class="st-h"><?= e(t('persona.studio_chips_title')) ?></h3>
                                <div class="st-chips" data-st-chips></div>
                            </section>
                        </div>

                        <!-- travail en cours -->
                        <section class="st-card st-busy" data-st-busy hidden aria-live="polite">
                            <div class="st-shimmer" aria-hidden="true"><i></i><i></i><i></i></div>
                            <p data-st-busy-text></p>
                            <button type="button" class="btn btn-ghost btn-sm" data-st-stop><?= e(t('persona.studio_stop')) ?></button>
                        </section>

                        <!-- revue d'une proposition : différences, puis appliquer ou non -->
                        <section class="st-card st-proposal" data-st-proposal hidden aria-live="polite"></section>
                    </div>

                    <!-- consigne libre -->
                    <div class="st-compose">
                        <label class="sr-only" for="st-input"><?= e(t('persona.prompt_assist_label')) ?></label>
                        <input id="st-input" class="st-input" data-st-input maxlength="1000" autocomplete="off" form="pe-noform"
                               data-ph-empty="<?= e(t('persona.studio_input_empty')) ?>" data-ph-filled="<?= e(t('persona.studio_input_filled')) ?>">
                        <button type="button" class="st-send" data-st-send aria-label="<?= e(t('persona.prompt_assist_go')) ?>" title="<?= e(t('persona.prompt_assist_go')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                    </div>
                </div>
            <?php endif; ?>

            <!-- historique : 10 dernières modifications (lignes ajoutées / supprimées) -->
            <div class="pe-pane pe-history" data-pe-pane="history" hidden>
                <p class="pe-history-intro"><?= e(t('persona.prompt_history_title')) ?></p>
                <div class="pe-history-list" data-pe-history-list></div>
            </div>

            <div class="pe-meta">
                <span class="pe-count" data-pe-count aria-live="polite"></span>
                <button type="button" class="pe-undo" data-pe-undo hidden title="<?= e(t('persona.studio_undo_title')) ?>"><?= e(t('persona.studio_undo')) ?></button>
                <div class="pe-tools">
                    <button type="button" class="icon-tool" data-pe-copy aria-label="<?= e(t('persona.prompt_copy')) ?>" title="<?= e(t('persona.prompt_copy')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8.5" y="8.5" width="11" height="11" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M15.5 8.5V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7.5a2 2 0 0 0 2 2h2.5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></button>
                    <button type="button" class="icon-tool is-danger" data-pe-delete aria-label="<?= e(t('persona.prompt_delete')) ?>" title="<?= e(t('persona.prompt_delete')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg></button>
                </div>
            </div>
            <div class="pe-actions">
                <button type="button" class="btn btn-ghost" data-close-modal><?= e(t('form.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= e(t('persona.prompt_save')) ?></button>
            </div>
        </form>
    </div>
</section>
