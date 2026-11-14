<?php
/** @var array $conversations @var array $contacts @var bool $contactView @var bool $chatReady @var string $chatModel */
// « . "\n" » après un texte en fin de ligne : garde le saut de ligne qu’avalerait la balise PHP fermante.
?>
<section class="lib-head">
    <div class="lib-title">
        <h1>
            <?= e($contactView ? t('settings.look.chat_contacts') : t('chat.list.title')) . "\n" ?>
            <span class="count"><?= count($contactView ? $contacts : $conversations) ?></span>
        </h1>
    </div>
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
        <a class="btn btn-primary" href="/"><?= e(t('nav.library')) ?></a>
    </div>
<?php elseif ($contactView): ?>
    <div class="conv-list contact-list">
        <?php foreach ($contacts as $pid => $contact): ?>
            <?php
            $latest = $contact['latest'];
            $when = $latest['last_at'] ?? $latest['created_at'];
            ?>
            <article class="contact-item<?= $contact['unread'] ? ' is-unread' : '' ?>" data-contact="<?= $pid ?>">
                <a class="conv-card contact-open" href="/chat/<?= (int) $latest['id'] ?>" style="--hue: <?= hue_for($latest['persona_name']) ?>">
                    <div class="conv-avatar">
                        <?php if ($latest['persona_avatar']): ?>
                            <img src="<?= e(media_url($latest['persona_avatar'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <div class="avatar-fallback"><span><?= e(initials($latest['persona_name'])) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="conv-body">
                        <div class="conv-line">
                            <strong><?= e($latest['persona_name']) ?></strong>
                            <time datetime="<?= e(gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($when . ' UTC'))) ?>"><?= e(rel_time($when)) ?></time>
                        </div>
                        <div class="conv-line">
                            <span class="conv-preview" data-contact-title="<?= (int) $latest['id'] ?>"><?= e($latest['title']) ?></span>
                            <?php if ($contact['unread']): ?><span class="conv-badge"><?= e(t('chat.list.new_badge')) ?></span><?php endif; ?>
                        </div>
                    </div>
                </a>
                <details class="contact-history">
                    <summary><?= e(t('chat.list.thread_count', count($contact['threads']))) ?></summary>
                    <div class="conv-list contact-threads">
                        <?php foreach ($contact['threads'] as $c): ?>
                            <?php require __DIR__ . '/chat_thread_row.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </details>
            </article>
        <?php endforeach; ?>
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
                <?php require __DIR__ . '/chat_thread_row.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($conversations): ?>
    <p class="conv-hint hint"><?= e(t('chat.list.long_press_hint')) ?></p>
    <!-- menu d'une conversation : appui long (mobile), clic droit ou « ⋯ » (ordinateur) -->
    <div class="modal action-sheet" id="conv-actions" data-token="<?= e(csrf_token()) ?>" hidden>
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="conv-actions-title">
            <header class="modal-head">
                <div>
                    <p class="eyebrow"><?= e(t('chat.list.actions')) ?></p>
                    <h2 id="conv-actions-title" data-act-title></h2>
                </div>
                <button type="button" class="icon-btn btn-close" data-act-close aria-label="<?= e(t('common.close')) ?>">&times;</button>
            </header>
            <div class="action-list">
                <button type="button" class="action-row" data-act="pin">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4h6l-1 5 3.5 3.5v1.5h-11v-1.5L10 9 9 4Zm3 10v6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round"/></svg>
                    <span data-label-pin="<?= e(t('chat.list.pin')) ?>" data-label-unpin="<?= e(t('chat.list.unpin')) ?>"></span>
                </button>
                <button type="button" class="action-row" data-act="retitle">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l1.9 6.1L20 11l-6.1 1.9L12 19l-1.9-6.1L4 11l6.1-1.9L12 3Zm6.5 12v5M16 17.5h5" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><?= e(t('chat.list.retitle')) ?></span>
                </button>
                <button type="button" class="action-row is-danger" data-act="delete"
                        data-confirm="<?= e(t('chat.list.delete_confirm')) ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 11v6m4-6v6M8 7l1-3h6l1 3m-9 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span><?= e(t('chat.list.delete')) ?></span>
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>
