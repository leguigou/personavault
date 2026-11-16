<?php
/** @var array $personas */
/*
 * Essai de discussion entre deux personas : choix des participants, échange réplique par
 * réplique (le navigateur demande chaque réplique), puis indicateurs et rapport à télécharger.
 */
$token = csrf_token();
?>
<a class="back" href="/settings#chat">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('nav.settings')), "\n" ?>
</a>

<section class="lib-head">
    <div class="lib-title">
        <p class="eyebrow"><?= e(t('duo.eyebrow')) ?></p>
        <h1><?= e(t('duo.title')) ?></h1>
    </div>
</section>

<p class="hint duo-intro"><?= e(t('duo.intro')) ?></p>

<div class="duo" data-duo data-token="<?= e($token) ?>">
    <?php if (count($personas) < 2): ?>
        <div class="empty-state"><p><?= e(t('duo.need_two')) ?></p></div>
    <?php else: ?>
    <form class="card-form duo-setup" data-duo-form novalidate>
        <div class="duo-slots">
            <?php foreach ([0, 1] as $slot): ?>
                <button type="button" class="duo-slot" data-duo-slot="<?= $slot ?>" aria-label="<?= e(t('duo.slot_clear')) ?>" disabled>
                    <span class="duo-avatar duo-slot-avatar" data-duo-slot-avatar aria-hidden="true"><span class="duo-avatar-empty"><?= $slot + 1 ?></span></span>
                    <span class="duo-slot-text">
                        <small><?= e($slot === 0 ? t('duo.slot_a') : t('duo.slot_b')) ?></small>
                        <strong data-duo-slot-name><?= e(t('duo.slot_empty')) ?></strong>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>

        <label class="field">
            <span><?= e(t('duo.search')) ?></span>
            <input class="input" type="search" data-duo-search autocomplete="off" placeholder="<?= e(t('duo.search_placeholder')) ?>">
        </label>
        <ul class="duo-list" data-duo-list>
            <?php foreach ($personas as $p): ?>
                <li data-duo-item data-duo-text="<?= e(mb_strtolower($p['name'] . ' ' . $p['tagline'])) ?>">
                    <button type="button" class="duo-pick" data-duo-pick="<?= (int) $p['id'] ?>" data-name="<?= e($p['name']) ?>" aria-pressed="false">
                        <span class="duo-avatar" data-duo-avatar aria-hidden="true">
                            <?php if ($p['avatar']): ?>
                                <img src="<?= e(media_url($p['avatar'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="avatar-fallback"><span><?= e(initials($p['name'])) ?></span></span>
                            <?php endif; ?>
                        </span>
                        <span class="duo-pick-text">
                            <strong><?= e($p['name']) ?></strong>
                            <?php if (trim((string) $p['tagline']) !== ''): ?><small><?= e($p['tagline']) ?></small><?php endif; ?>
                        </span>
                        <span class="duo-pick-badge" data-duo-badge hidden></span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="hint" data-duo-nomatch hidden><?= e(t('duo.no_match')) ?></p>

        <label class="field">
            <span><?= e(t('duo.topic')) ?></span>
            <input class="input" name="topic" maxlength="300" placeholder="<?= e(t('duo.topic_placeholder')) ?>">
        </label>
        <div class="row2">
            <label class="field">
                <span data-duo-goal-label="0"><?= e(t('duo.goal_a')) ?></span>
                <input class="input" name="goal_a" maxlength="300" placeholder="<?= e(t('duo.goal_placeholder')) ?>">
            </label>
            <label class="field">
                <span data-duo-goal-label="1"><?= e(t('duo.goal_b')) ?></span>
                <input class="input" name="goal_b" maxlength="300" placeholder="<?= e(t('duo.goal_placeholder')) ?>">
            </label>
        </div>
        <label class="field duo-turns">
            <span><?= e(t('duo.turns')) ?></span>
            <input class="input" type="number" inputmode="numeric" name="turns" min="2" max="<?= DUO_MAX_TURNS ?>" step="1" value="12">
        </label>
        <p class="hint"><?= e(t('duo.privacy')) ?></p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit" data-duo-start disabled><?= e(t('duo.start')) ?></button>
        </div>
    </form>

    <section class="duo-run" data-duo-run hidden>
        <header class="duo-run-head">
            <p class="duo-status" data-duo-status role="status" aria-live="polite"></p>
            <button type="button" class="btn btn-ghost btn-sm" data-duo-stop><?= e(t('duo.stop')) ?></button>
        </header>
        <ol class="duo-thread" data-duo-thread></ol>

        <div class="duo-report" data-duo-report hidden>
            <h2><?= e(t('duo.metrics.title')) ?></h2>
            <div class="duo-table-wrap">
                <table class="duo-table">
                    <thead>
                        <tr>
                            <th scope="col"><span class="sr-only"><?= e(t('duo.metrics.persona')) ?></span></th>
                            <th scope="col"><?= e(t('duo.metrics.lines')) ?></th>
                            <th scope="col"><?= e(t('duo.metrics.words')) ?></th>
                            <th scope="col"><?= e(t('duo.metrics.repeats')) ?></th>
                            <th scope="col"><?= e(t('duo.metrics.agreements')) ?></th>
                            <th scope="col"><?= e(t('duo.metrics.time')) ?></th>
                        </tr>
                    </thead>
                    <tbody data-duo-metrics></tbody>
                </table>
            </div>
            <p class="hint" data-duo-voice></p>
            <p class="hint"><?= e(t('duo.metrics.help')) ?></p>

            <h2><?= e(t('duo.check.title')) ?></h2>
            <ul class="duo-checklist">
                <?php foreach (duo_checklist() as $item): ?>
                    <li><label class="check"><input type="checkbox"><span><?= e($item) ?></span></label></li>
                <?php endforeach; ?>
            </ul>

        </div>
        <div class="form-actions" data-duo-after hidden>
            <button type="button" class="btn btn-primary" data-duo-download hidden><?= e(t('duo.download')) ?></button>
            <button type="button" class="btn btn-ghost" data-duo-again><?= e(t('duo.again')) ?></button>
        </div>
    </section>
    <?php endif; ?>
</div>
