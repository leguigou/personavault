<?php
/** @var array|null $persona @var array $draft @var array $progress @var bool $ready */
/*
 * Assistant de création de persona : la fiche en cours en haut (jauge + ce qui manque),
 * la conversation au milieu (seule zone qui défile), les réponses rapides et la saisie en bas.
 */
$isNew = $persona === null;
$back  = $isNew ? '/p/new' : '/p/' . (string) $persona['slug'];
$token = csrf_token();
$SPARK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9L12 3.5Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M18.5 15.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8.8-2Z" fill="currentColor"/></svg>';
?>
<section class="as" data-assist
         data-slug="<?= e($isNew ? '' : (string) $persona['slug']) ?>"
         data-token="<?= e($token) ?>"
         data-draft="<?= e((string) json_encode($draft, JSON_UNESCAPED_UNICODE)) ?>"
         data-progress="<?= e((string) json_encode($progress, JSON_UNESCAPED_UNICODE)) ?>"
         data-new="<?= $isNew ? '1' : '0' ?>">
    <header class="as-head">
        <a class="as-hbtn" href="<?= e($back) ?>" aria-label="<?= e(t('assist.back')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <h1 class="as-title"><?= e($isNew ? t('assist.head_new') : t('assist.head_complete')) ?></h1>
        <?php if ($ready): ?>
            <button type="button" class="as-hbtn" data-as-restart aria-label="<?= e(t('assist.restart')) ?>" title="<?= e(t('assist.restart')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3M4.5 4.5v3.8h3.8" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <button type="button" class="as-create" data-as-create>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span><?= e(t('assist.review')) ?></span>
            </button>
        <?php endif; ?>
    </header>

    <?php if (!$ready): ?>
        <div class="as-empty">
            <p><?= e(t('assist.not_ready')) ?></p>
            <a class="btn btn-primary" href="/settings"><?= e(t('assist.open_settings')) ?></a>
        </div>
    <?php else: ?>
        <!-- la fiche en cours : jauge et étapes toujours visibles ; un toucher ouvre l'aperçu de ce qui est rédigé -->
        <details class="as-fiche" data-as-fiche>
            <summary>
                <span class="as-fiche-name" data-as-name></span>
                <span class="as-fiche-pct" data-as-pct></span>
                <span class="as-bar" aria-hidden="true"><i data-as-bar></i></span>
                <ol class="as-steps" data-as-steps></ol>
            </summary>
            <div class="as-preview">
                <p class="as-preview-title"><?= e(t('assist.preview_title')) ?></p>
                <dl class="as-preview-list" data-as-preview></dl>
                <p class="as-fiche-note"><?= e($isNew ? t('assist.fiche_note_new') : t('assist.fiche_note_complete')) ?></p>
            </div>
        </details>

        <div class="as-thread" data-as-thread aria-live="polite"></div>

        <div class="as-bottom">
            <div class="as-chips" data-as-chips></div>
            <form class="as-compose" data-as-form>
                <label class="sr-only" for="as-input"><?= e(t('assist.input_label')) ?></label>
                <textarea id="as-input" class="as-input" rows="1" maxlength="2000" data-as-input placeholder="<?= e(t('assist.input_placeholder')) ?>"></textarea>
                <button type="submit" class="as-send" data-as-send aria-label="<?= e(t('assist.send')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </form>
        </div>

        <!-- rédaction finale -->
        <div class="as-final" data-as-final hidden>
            <div class="as-final-card">
                <div class="as-final-ico" aria-hidden="true"><?= $SPARK ?></div>
                <strong><?= e($isNew ? t('assist.final_title_new') : t('assist.final_title_complete')) ?></strong>
                <p><?= e(t('assist.final_text')) ?></p>
                <div class="as-final-dots" aria-hidden="true"><i></i><i></i><i></i></div>
            </div>
        </div>

        <dialog class="as-review" data-as-review aria-labelledby="as-review-title">
            <form class="as-review-form" data-as-review-form>
                <header>
                    <h2 id="as-review-title" tabindex="-1" autofocus><?= e(t('assist.review_title')) ?></h2>
                    <p data-as-review-progress hidden></p>
                    <?php if (!$isNew): ?><p><?= e(t('assist.fiche_note_complete')) ?></p><?php endif; ?>
                </header>
                <div class="as-review-scroll" data-as-review-scroll>
                    <fieldset class="as-review-fields" data-as-review-fields></fieldset>
                </div>
                <footer>
                    <p class="as-review-error" data-as-review-error role="alert" hidden></p>
                    <div class="as-review-actions">
                        <button type="button" class="btn btn-ghost" data-as-review-back><?= e(t('assist.review_back')) ?></button>
                        <button type="submit" class="btn btn-primary" data-as-review-save><?= e($isNew ? t('assist.create') : t('assist.apply')) ?></button>
                    </div>
                </footer>
            </form>
        </dialog>
    <?php endif; ?>
</section>
