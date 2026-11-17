<?php
/** @var array $personas @var array $sessions */
/*
 * Discussions entre deux personas : nouvelle discussion (choix des participants, sujet,
 * objectifs, longueur) et liste des discussions précédentes.
 */
$token = csrf_token();
$avatar = static function (string $file, string $name): string {
    return $file !== ''
        ? '<img src="' . e(media_url($file)) . '" alt="" loading="lazy">'
        : '<span class="avatar-fallback"><span>' . e(initials($name)) . '</span></span>';
};
$statusLabel = static fn (string $st): string => match ($st) {
    'running' => t('duo.status.running'),
    'paused'  => t('duo.status.paused'),
    'done'    => t('duo.status.done'),
    'stopped' => t('duo.status.stopped'),
    default   => t('duo.status.error'),
};
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

<?php if ($sessions): ?>
    <section class="duo-sessions" aria-labelledby="duo-sessions-title">
        <h2 id="duo-sessions-title"><?= e(t('duo.sessions')) ?></h2>
        <ul class="duo-session-list">
            <?php foreach ($sessions as $s): ?>
                <li>
                    <a class="duo-session-link" href="/settings/duo/<?= (int) $s['id'] ?>">
                        <span class="duo-pair" aria-hidden="true">
                            <span class="duo-avatar"><?= $avatar((string) $s['avatar_a'], (string) $s['name_a']) ?></span>
                            <span class="duo-avatar"><?= $avatar((string) $s['avatar_b'], (string) $s['name_b']) ?></span>
                        </span>
                        <span class="duo-session-text">
                            <strong><?= e(t('duo.pair', ['a' => $s['name_a'], 'b' => $s['name_b']])) ?></strong>
                            <small><?= e(trim((string) $s['topic']) !== '' ? (string) $s['topic'] : t('duo.report.free')) ?></small>
                        </span>
                        <span class="duo-badge is-<?= e((string) $s['status']) ?>"><?= e($statusLabel((string) $s['status'])) ?> · <?= (int) $s['lines'] ?>/<?= (int) $s['target'] ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<div class="duo" data-duo>
    <?php if (count($personas) < 2): ?>
        <div class="empty-state"><p><?= e(t('duo.need_two')) ?></p></div>
    <?php else: ?>
    <form class="card-form duo-setup" method="post" action="/settings/duo" data-duo-form>
        <h2><?= e(t('duo.new')) ?></h2>
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <input type="hidden" name="a" value="" data-duo-input="0">
        <input type="hidden" name="b" value="" data-duo-input="1">
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
                        <span class="duo-avatar" data-duo-avatar aria-hidden="true"><?= $avatar((string) $p['avatar'], (string) $p['name']) ?></span>
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
            <span><?= e(t('duo.scene')) ?></span>
            <input class="input" name="scene" maxlength="300" placeholder="<?= e(t('duo.scene_placeholder')) ?>">
        </label>
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
            <input class="input" type="number" inputmode="numeric" name="turns" min="2" max="<?= DUO_MAX_TURNS ?>" step="1" value="20">
        </label>
        <p class="hint"><?= e(t('duo.privacy')) ?></p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit" data-duo-start disabled><?= e(t('duo.start')) ?></button>
        </div>
    </form>
    <?php endif; ?>
</div>
