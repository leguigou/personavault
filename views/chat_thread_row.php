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
<div class="conv-item<?= $unread ? ' is-unread' : '' ?><?= $pinned ? ' is-pinned' : '' ?>"
     data-conv-id="<?= (int) $c['id'] ?>" data-conv-title="<?= e((string) $c['title']) ?>" data-pinned="<?= $pinned ? '1' : '0' ?>">
    <a class="conv-card" href="/chat/<?= (int) $c['id'] ?>" style="--hue: <?= $hue ?>">
        <?php if (!$contactView): ?>
            <div class="conv-avatar">
                <?php if ($c['persona_avatar']): ?>
                    <img src="<?= e(media_url((string) $c['persona_avatar'])) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <div class="avatar-fallback"><span><?= e(initials((string) $c['persona_name'])) ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="conv-body">
            <div class="conv-line">
                <strong><?php if ($pinned): ?><svg class="conv-pinned-ico" viewBox="0 0 24 24" aria-label="<?= e(t('chat.list.pinned')) ?>"><path d="M9 4h6l-1 5 3.5 3.5v1.5h-11v-1.5L10 9 9 4Zm3 10v6" fill="currentColor" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></svg><?php endif; ?><span data-conv-title-text><?= e((string) $c['title']) ?></span></strong>
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
                <em class="conv-count"><?= e(t('chat.list.message_count', (int) $c['n'])) ?></em>
            </div>
        </div>
    </a>
    <button type="button" class="conv-more" data-conv-more aria-haspopup="dialog"
            aria-label="<?= e(t('chat.list.more')) ?>" title="<?= e(t('chat.list.more')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="12" r="1.6" fill="currentColor"/><circle cx="12" cy="12" r="1.6" fill="currentColor"/><circle cx="18" cy="12" r="1.6" fill="currentColor"/></svg>
    </button>
</div>
