<?php
/** @var array $conversations @var array $personas @var bool $chatReady @var string $chatModel */
// « . "\n" » après un texte en fin de ligne : garde le saut de ligne qu’avalerait la balise PHP fermante.
?>
<section class="lib-head">
    <div class="lib-title">
        <p class="eyebrow"><?= e(t('nav.chats')) ?></p>
        <h1>
            <?= e(t('chat.list.title')) . "\n" ?>
            <span class="count"><?= count($conversations) ?></span>
        </h1>
    </div>
    <button class="btn btn-primary" type="button" data-open-picker>
        <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        <?= e(t('chat.list.new')) . "\n" ?>
    </button>
</section>

<p class="chat-status <?= $chatReady ? '' : 'is-ko' ?>">
    <span class="dot <?= $chatReady ? 'dot-ok' : 'dot-ko' ?>"></span>
    <?php if ($chatReady): ?>
        <?= t_html('chat.list.status_local', ['model' => '<strong>' . e($chatModel !== '' ? $chatModel : t('chat.list.model_auto')) . '</strong>']) . "\n" ?>
    <?php else: ?>
        <?= e(t('chat.list.no_model')) . "\n" ?>
    <?php endif; ?>
</p>

<?php if (!$conversations): ?>
    <div class="empty">
        <div class="empty-orb" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M7 5h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4 3v-3H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
        </div>
        <h2><?= e(t('chat.list.empty_title')) ?></h2>
        <p><?= e(t('chat.list.empty_text')) ?></p>
        <button class="btn btn-primary" type="button" data-open-picker><?= e(t('chat.list.pick_persona')) ?></button>
    </div>
<?php else: ?>
    <?php
    $pinnedConvs = array_values(array_filter($conversations, static fn (array $c): bool => (int) ($c['pinned'] ?? 0) === 1));
    $otherConvs  = array_values(array_filter($conversations, static fn (array $c): bool => (int) ($c['pinned'] ?? 0) !== 1));
    $groups = $pinnedConvs ? [[t('chat.list.pinned'), $pinnedConvs], [t('chat.list.recent'), $otherConvs]] : [['', $otherConvs]];
    ?>
    <?php foreach ($groups as [$groupTitle, $list]): ?>
        <?php if (!$list) continue; ?>
        <?php if ($groupTitle !== ''): ?><h2 class="conv-group"><?= e($groupTitle) ?></h2><?php endif; ?>
        <div class="conv-list">
            <?php foreach ($list as $c): ?>
                <?php
                $hue     = hue_for((string) $c['persona_name']);
                $unread  = (int) ($c['unread'] ?? 0) === 1;
                $pinned  = (int) ($c['pinned'] ?? 0) === 1;
                $preview = trim((string) ($c['last_msg'] ?? ''));
                if ($preview === '' && (string) ($c['last_image'] ?? '') !== '') {
                    $preview = t('chat.list.photo');
                }
                $when = (string) ($c['last_at'] ?? '') !== '' ? (string) $c['last_at'] : (string) $c['updated_at'];
                ?>
                <div class="conv-item<?= $unread ? ' is-unread' : '' ?><?= $pinned ? ' is-pinned' : '' ?>">
                    <a class="conv-card" href="/chat/<?= (int) $c['id'] ?>" style="--hue: <?= $hue ?>">
                        <div class="conv-avatar">
                            <?php if ($c['persona_avatar']): ?>
                                <img src="<?= e(media_url((string) $c['persona_avatar'])) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <div class="avatar-fallback"><span><?= e(initials((string) $c['persona_name'])) ?></span></div>
                            <?php endif; ?>
                        </div>
                        <div class="conv-body">
                            <div class="conv-line">
                                <strong><?= e((string) $c['title']) ?></strong>
                                <time datetime="<?= e(gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($when . ' UTC'))) ?>"><?= e(rel_time($when)) ?></time>
                            </div>
                            <div class="conv-line">
                                <span class="conv-preview">
                                    <?php if ($preview === ''): ?>
                                        <?= e(t('chat.list.no_message')) ?>
                                    <?php else: ?>
                                        <?php if ((string) ($c['last_role'] ?? '') === 'user'): ?><b class="conv-you"><?= e(t('chat.list.you')) ?></b><?php endif; ?>
                                        <?= e(mb_substr($preview, 0, 110)) ?>
                                    <?php endif; ?>
                                </span>
                                <?php if ($unread): ?><span class="conv-badge"><?= e(t('chat.list.new_badge')) ?></span><?php endif; ?>
                            </div>
                        </div>
                    </a>
                    <form method="post" action="/chat/<?= (int) $c['id'] ?>/pin" class="conv-pin-form" data-pin-form>
                        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="pinned" value="<?= $pinned ? '0' : '1' ?>">
                        <button type="submit" class="conv-pin" aria-pressed="<?= $pinned ? 'true' : 'false' ?>"
                                aria-label="<?= e($pinned ? t('chat.list.unpin') : t('chat.list.pin')) ?>" title="<?= e($pinned ? t('chat.list.unpin') : t('chat.list.pin')) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4h6l-1 5 3.5 3.5v1.5h-11v-1.5L10 9 9 4Zm3 10v6" fill="<?= $pinned ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round"/></svg>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- popin de sélection du persona -->
<div class="modal" id="persona-picker" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="picker-title">
        <header class="modal-head">
            <h2 id="picker-title"><?= e(t('chat.list.pick_persona')) ?></h2>
            <button type="button" class="icon-btn btn-close" data-close-picker aria-label="<?= e(t('common.close')) ?>">&times;</button>
        </header>

        <input class="input" type="search" placeholder="<?= e(t('chat.list.search_persona')) ?>" data-picker-search autocomplete="off">

        <div class="picker-grid">
            <?php foreach ($personas as $p): ?>
                <?php $hue = hue_for((string) $p['name']); ?>
                <form method="post" action="/chat" class="picker-item" data-name="<?= e(mb_strtolower((string) $p['name'])) ?>">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="persona_id" value="<?= (int) $p['id'] ?>">
                    <button type="submit" style="--hue: <?= $hue ?>">
                        <span class="picker-thumb">
                            <?php if ($p['avatar']): ?>
                                <img src="<?= e(media_url((string) $p['avatar'])) ?>" alt="">
                            <?php else: ?>
                                <span class="avatar-fallback"><span><?= e(initials((string) $p['name'])) ?></span></span>
                            <?php endif; ?>
                        </span>
                        <span class="picker-name"><?= e((string) $p['name']) ?></span>
                    </button>
                </form>
            <?php endforeach; ?>
        </div>

        <?php if (!$personas): ?>
            <p class="hint"><?= e(t('chat.list.no_persona')) ?></p>
        <?php endif; ?>
    </div>
</div>
