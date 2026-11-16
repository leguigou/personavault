<?php
/** @var array $session @var array $people @var array $state */
/*
 * Une discussion entre deux personas, suivie en direct. Elle est menée par le serveur :
 * la page affiche l'échange, l'humeur et les ressentis de chacun, et permet de mettre en
 * pause, reprendre, prolonger, arrêter ou glisser une consigne au maître du jeu.
 */
$token = csrf_token();
$id = (int) $session['id'];
$avatar = static function (array $p): string {
    return (string) $p['avatar'] !== ''
        ? '<img src="' . e(media_url((string) $p['avatar'])) . '" alt="" loading="lazy">'
        : '<span class="avatar-fallback"><span>' . e(initials((string) $p['name'])) . '</span></span>';
};
$names = [(string) $people[0]['name'], (string) $people[1]['name']];
?>
<a class="back" href="/settings/duo">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('duo.title')), "\n" ?>
</a>

<div class="duo-live" data-duo-live data-id="<?= $id ?>" data-token="<?= e($token) ?>"
     data-names="<?= e(json_encode($names, JSON_UNESCAPED_UNICODE)) ?>">
    <script type="application/json" data-duo-initial><?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <div class="duo-bar" data-duo-bar>
        <div class="duo-bar-people">
            <?php foreach ([0, 1] as $w): ?>
                <div class="duo-who duo-who-<?= $w ?>">
                    <span class="duo-avatar duo-who-avatar" data-duo-avatar-of="<?= $w ?>" aria-hidden="true"><?= $avatar($people[$w]) ?></span>
                    <span class="duo-who-text">
                        <strong><?= e($names[$w]) ?></strong>
                        <span class="duo-mood" data-duo-mood="<?= $w ?>"></span>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="duo-bar-row">
            <p class="duo-status" data-duo-status role="status" aria-live="polite"></p>
            <div class="duo-actions">
                <button type="button" class="btn btn-ghost btn-sm" data-duo-op="pause" hidden>
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M9 5v14M15 5v14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
                    <?= e(t('duo.op.pause')) ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm" data-duo-op="resume" hidden>
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M7 5l12 7-12 7z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/></svg>
                    <?= e(t('duo.op.resume')) ?>
                </button>
                <button type="button" class="btn btn-ghost btn-sm" data-duo-op="extend" hidden><?= e(t('duo.op.extend', ['n' => DUO_EXTEND])) ?></button>
                <button type="button" class="btn btn-ghost btn-sm duo-stop" data-duo-op="stop" hidden aria-label="<?= e(t('duo.op.stop')) ?>">
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M7 7h10v10H7z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round"/></svg>
                    <span class="hide-sm"><?= e(t('duo.op.stop')) ?></span>
                </button>
            </div>
        </div>

        <div class="duo-bar-tabs">
            <button type="button" class="duo-tab" data-duo-toggle="feelings" aria-expanded="false" aria-controls="duo-feelings-<?= $id ?>"><?= e(t('duo.tab.feelings')) ?></button>
            <button type="button" class="duo-tab" data-duo-toggle="mj" aria-expanded="false" aria-controls="duo-mj-<?= $id ?>"><?= e(t('duo.tab.mj')) ?></button>
            <a class="duo-tab" href="/settings/duo/<?= $id ?>/report"><?= e(t('duo.tab.report')) ?></a>
        </div>

        <div class="duo-panel" id="duo-feelings-<?= $id ?>" data-duo-panel="feelings" hidden>
            <?php foreach ([0, 1] as $w): ?>
                <div class="duo-feel">
                    <p class="duo-feel-title"><?= e(t('duo.feelings.towards', ['name' => $names[$w], 'other' => $names[1 - $w]])) ?></p>
                    <?php foreach (['friendship' => t('duo.gauge.friendship'), 'love' => t('duo.gauge.love'), 'anger' => t('duo.gauge.anger')] as $k => $label): ?>
                        <div class="duo-gauge duo-gauge-<?= $k ?>" data-duo-gauge="<?= $w ?>-<?= $k ?>">
                            <span class="duo-gauge-label"><?= e($label) ?></span>
                            <span class="duo-gauge-track" role="meter" aria-label="<?= e($label) ?>"
                                  aria-valuemin="<?= $k === 'friendship' ? -100 : 0 ?>" aria-valuemax="100" aria-valuenow="0">
                                <span class="duo-gauge-fill"></span>
                            </span>
                            <span class="duo-gauge-value">0</span>
                        </div>
                    <?php endforeach; ?>
                    <p class="duo-feel-reason" data-duo-reason="<?= $w ?>"></p>
                </div>
            <?php endforeach; ?>
            <div class="duo-legend">
                <p class="duo-feel-title"><?= e(t('duo.legend')) ?></p>
                <ul class="duo-legend-list">
                    <?php foreach (duo_emotions() as $key => $emotion): ?>
                        <li><span class="duo-dot" style="--mood: <?= e($emotion['color']) ?>" aria-hidden="true"></span><?= e(duo_emotion_label($key)) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p class="hint"><?= e(t('duo.feelings.help')) ?></p>
        </div>

        <form class="duo-panel duo-mj" id="duo-mj-<?= $id ?>" data-duo-panel="mj" data-duo-direction hidden>
            <div class="duo-mj-row">
                <label class="sr-only" for="duo-target-<?= $id ?>"><?= e(t('duo.mj.target')) ?></label>
                <select class="input duo-mj-target" id="duo-target-<?= $id ?>" name="target">
                    <option value="-1"><?= e(t('duo.mj.both')) ?></option>
                    <option value="0"><?= e($names[0]) ?></option>
                    <option value="1"><?= e($names[1]) ?></option>
                </select>
                <label class="sr-only" for="duo-text-<?= $id ?>"><?= e(t('duo.mj.label')) ?></label>
                <input class="input" id="duo-text-<?= $id ?>" name="text" maxlength="300" autocomplete="off" placeholder="<?= e(t('duo.mj.placeholder')) ?>">
                <button class="btn btn-primary btn-sm" type="submit"><?= e(t('duo.mj.send')) ?></button>
            </div>
            <p class="hint"><?= e(t('duo.mj.help')) ?></p>
        </form>
    </div>

    <?php if (trim((string) $session['topic']) !== ''): ?>
        <p class="duo-topic"><?= e(t('duo.report.topic')) ?> : <?= e((string) $session['topic']) ?></p>
    <?php endif; ?>
    <ol class="duo-thread" data-duo-thread aria-live="off"></ol>

    <form class="duo-delete" method="post" action="/settings/duo/<?= $id ?>/delete" data-confirm="<?= e(t('duo.delete_confirm')) ?>">
        <input type="hidden" name="_token" value="<?= e($token) ?>">
        <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('duo.delete')) ?></button>
    </form>
</div>
