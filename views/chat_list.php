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
    <div class="conv-list">
        <?php foreach ($conversations as $c): ?>
            <?php $hue = hue_for((string) $c['persona_name']); ?>
            <a class="conv-card" href="/chat/<?= (int) $c['id'] ?>" style="--hue: <?= $hue ?>">
                <div class="conv-avatar">
                    <?php if ($c['persona_avatar']): ?>
                        <img src="<?= e(media_url((string) $c['persona_avatar'])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <div class="avatar-fallback"><span><?= e(initials((string) $c['persona_name'])) ?></span></div>
                    <?php endif; ?>
                </div>
                <div class="conv-body">
                    <strong>
                        <?php if ((int) ($c['unread'] ?? 0) === 1): ?><span class="unread-dot" aria-label="<?= e(t('chat.unread')) ?>"></span><?php endif; ?>
                        <?= e((string) $c['title']) ?>
                    </strong>
                    <span><?= e(mb_substr(trim((string) ($c['last_msg'] ?? '')), 0, 90)) ?: e(t('chat.list.no_message')) ?></span>
                </div>
                <div class="conv-meta">
                    <em><?= e(t('chat.list.message_count', (int) $c['n'])) ?></em>
                    <time><?= e(date(t('date.day_month_format'), strtotime((string) $c['updated_at'] . ' UTC'))) ?></time>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- popin de sélection du persona -->
<div class="modal" id="persona-picker" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="picker-title">
        <header class="modal-head">
            <h2 id="picker-title"><?= e(t('chat.list.pick_persona')) ?></h2>
            <button type="button" class="icon-btn" data-close-picker aria-label="<?= e(t('common.close')) ?>">&times;</button>
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
