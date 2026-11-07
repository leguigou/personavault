<?php
/** @var array $conversations @var array $personas @var bool $chatReady @var string $chatModel */
?>
<section class="lib-head">
    <div class="lib-title">
        <p class="eyebrow">Discussions</p>
        <h1>
            Mes conversations
            <span class="count"><?= count($conversations) ?></span>
        </h1>
    </div>
    <button class="btn btn-primary" type="button" data-open-picker>
        <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        Nouvelle
    </button>
</section>

<p class="chat-status <?= $chatReady ? '' : 'is-ko' ?>">
    <span class="dot <?= $chatReady ? 'dot-ok' : 'dot-ko' ?>"></span>
    <?php if ($chatReady): ?>
        Discussion locale · <strong><?= e($chatModel !== '' ? $chatModel : 'modèle auto') ?></strong>
    <?php else: ?>
        Aucun modèle de discussion joignable
    <?php endif; ?>
</p>

<?php if (!$conversations): ?>
    <div class="empty">
        <div class="empty-orb" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M7 5h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4 3v-3H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
        </div>
        <h2>Aucune discussion</h2>
        <p>Choisis un persona et commence à lui parler.</p>
        <button class="btn btn-primary" type="button" data-open-picker>Choisir un persona</button>
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
                        <?php if ((int) ($c['unread'] ?? 0) === 1): ?><span class="unread-dot" aria-label="Nouveau message"></span><?php endif; ?>
                        <?= e((string) $c['title']) ?>
                    </strong>
                    <span><?= e(mb_substr(trim((string) ($c['last_msg'] ?? '')), 0, 90)) ?: 'Pas encore de message' ?></span>
                </div>
                <div class="conv-meta">
                    <em><?= (int) $c['n'] ?> msg</em>
                    <time><?= e(date('d/m', strtotime((string) $c['updated_at'] . ' UTC'))) ?></time>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- popin de sélection du persona -->
<div class="modal" id="persona-picker" hidden>
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="picker-title">
        <header class="modal-head">
            <h2 id="picker-title">Choisir un persona</h2>
            <button type="button" class="icon-btn" data-close-picker aria-label="Fermer">&times;</button>
        </header>

        <input class="input" type="search" placeholder="Chercher un persona…" data-picker-search autocomplete="off">

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
            <p class="hint">Aucun persona en bibliothèque. Crée une fiche d’abord.</p>
        <?php endif; ?>
    </div>
</div>
