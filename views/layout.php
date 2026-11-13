<?php
$flashes = $flashes ?? [];
$nav = $nav ?? [];
$isAuth = is_logged();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isFav = $currentPath === '/' && ($_GET['fav'] ?? '') === '1';
$isArch = $currentPath === '/' && ($_GET['arch'] ?? '') === '1';
$isNew = $currentPath === '/p/new' || $currentPath === '/p/assist';
$isSettings = $currentPath === '/settings' || str_starts_with($currentPath, '/settings/');
// Bibliothèque : l'accueil et les fiches persona, pas les Réglages ni la création
$isLib = !$isFav && !$isArch && !$isNew && !$isSettings
    && !str_starts_with($currentPath, '/chat') && !str_starts_with($currentPath, '/memories');
$isChat = str_starts_with($currentPath, '/chat') || str_starts_with($currentPath, '/memories');
$isChatRoom = (bool) preg_match('#^/chat/\d+#', $currentPath);
$chatUnread = $isAuth && !$isChatRoom && chat_has_unread();
$chatIcon = '<svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M7 5h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4 3v-3H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
$chatBadge = '<span class="nav-chat-dot" data-chat-unread' . ($chatUnread ? '' : ' hidden') . '><span class="sr-only">' . e(t('chat.unread')) . '</span></span>';
// assistant de création : plein écran comme une discussion
$isAssist = (bool) preg_match('#^/p/(?:[a-z0-9-]+/)?assist$#', $currentPath);
?>
<!doctype html>
<?php $__ui = ui_style(); ?>
<html lang="<?= e(locale()) ?>"
      data-bubble="<?= e($__ui['bubble']) ?>"
      data-density="<?= e($__ui['density']) ?>"
      data-avatars="<?= $__ui['avatars'] ? '1' : '0' ?>"
      data-width="<?= e($__ui['width']) ?>"
      data-tabbar="<?= e($__ui['tabbar']) ?>"
      data-accent="<?= e($__ui['accent']) ?>"
      data-mode="<?= e($__ui['mode']) ?>"
      style="--chat-font: <?= e((string) $__ui['font']) ?>px">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <?php /* barre du navigateur : une couleur par mode ; en « auto », le navigateur choisit selon le téléphone */ ?>
    <?php if ($__ui['mode'] === 'auto'): ?>
    <meta name="theme-color" content="<?= e(UI_THEME_COLORS['dark']) ?>" media="(prefers-color-scheme: dark)" data-theme-color="dark">
    <meta name="theme-color" content="<?= e(UI_THEME_COLORS['light']) ?>" media="(prefers-color-scheme: light)" data-theme-color="light">
    <?php else: ?>
    <meta name="theme-color" content="<?= e(UI_THEME_COLORS[$__ui['mode']]) ?>" data-theme-color="<?= e($__ui['mode']) ?>">
    <?php endif; ?>
    <meta name="color-scheme" content="<?= $__ui['mode'] === 'auto' ? 'dark light' : e($__ui['mode']) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="PersonaVault">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= e($title ?? t('app.default_title')) ?> · PersonaVault</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/icon-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
    <link rel="manifest" href="/assets/manifest.webmanifest">
    <link rel="stylesheet" href="<?= e(asset_url('app.css')) ?>">
    <script>try{document.documentElement.dataset.view=localStorage.getItem("pv_view")==="list"?"list":"grid";}catch(e){}</script>
</head>
<body class="<?= $isAuth ? 'is-auth' : 'is-guest' ?> <?= $isChatRoom ? 'chat-room' : '' ?> <?= $isAssist ? 'assist-room' : '' ?>">
<div class="aurora" aria-hidden="true">
    <span class="orb orb-1"></span>
    <span class="orb orb-2"></span>
    <span class="orb orb-3"></span>
</div>

<?php if ($isAuth && !$isChatRoom): ?>
<header class="topbar">
    <a class="brand" href="/">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none"><path d="M12 3.2 14.9 9l6.4.9-4.7 4.5 1.1 6.4L12 17.8 6.3 20.8l1.1-6.4L2.7 9.9 9.1 9 12 3.2Z" fill="url(#g)"/><defs><linearGradient id="g" x1="2" y1="2" x2="22" y2="22"><stop stop-color="#a78bfa"/><stop offset="1" stop-color="#f472b6"/></linearGradient></defs></svg>
        </span>
        <span class="brand-name">Persona<em>Vault</em></span>
    </a>

    <nav class="topnav" aria-label="<?= e(t('nav.main')) ?>">
        <a href="/" class="<?= $isLib ? 'is-active' : '' ?>"><?= e(t('nav.library')) ?></a>
        <a href="/chat" class="nav-chat-link <?= $isChat ? 'is-active' : '' ?>"><?= e(t('nav.chats')) ?><?= $chatBadge ?></a>
        <a href="/?fav=1" class="<?= $isFav ? 'is-active' : '' ?>"><?= e(t('nav.favorites')) ?></a>
        <a href="/?arch=1" class="<?= $isArch ? 'is-active' : '' ?>"><?= e(t('nav.archives')) ?></a>
    </nav>

    <div class="topbar-actions">
        <a class="btn btn-primary btn-sm" href="/p/new">
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            <span class="hide-sm"><?= e(t('nav.new')) ?></span>
        </a>
        <a class="icon-btn nav-chat-link topbar-chat <?= $isChat ? 'is-active' : '' ?>" href="/chat" title="<?= e(t('nav.chats')) ?>"<?= $currentPath === '/chat' ? ' aria-current="page"' : '' ?>>
            <?= $chatIcon ?><span class="sr-only"><?= e(t('nav.chats')) ?></span><?= $chatBadge ?>
        </a>
        <a class="icon-btn <?= $isSettings ? 'is-active' : '' ?>"<?= $isSettings ? ' aria-current="page"' : '' ?> href="/settings" title="<?= e(t('nav.settings')) ?>" aria-label="<?= e(t('nav.settings')) ?>">
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 13.5a7.6 7.6 0 0 0 0-3l1.7-1.2-1.8-3.1-2 .8a7.6 7.6 0 0 0-2.6-1.5L14.4 3h-3.6l-.3 2.5a7.6 7.6 0 0 0-2.6 1.5l-2-.8L4 9.3l1.7 1.2a7.6 7.6 0 0 0 0 3L4 14.7l1.8 3.1 2-.8a7.6 7.6 0 0 0 2.6 1.5l.3 2.5h3.6l.3-2.5a7.6 7.6 0 0 0 2.6-1.5l2 .8 1.8-3.1-1.6-1.2Z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        </a>
        <form method="post" action="/logout" class="logout-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <button class="icon-btn" type="submit" title="<?= e(t('nav.logout')) ?>" aria-label="<?= e(t('nav.logout')) ?>">
                <svg viewBox="0 0 24 24" class="ico"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
            </button>
        </form>
    </div>
</header>
<?php endif; ?>

<main class="page <?= $isChatRoom ? 'page-chat' : '' ?> <?= $isAssist ? 'page-assist' : '' ?>">
    <?php foreach ($flashes as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status">
            <span><?= e($f['message']) ?></span>
            <button type="button" class="flash-close" aria-label="<?= e(t('common.close')) ?>">&times;</button>
        </div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<?php if ($isAuth && !$isChatRoom && !$isAssist): /* assistant : plein écran, la saisie est en bas */ ?>
<nav class="tabbar" aria-label="<?= e(t('nav.mobile')) ?>">
    <a href="/" class="<?= $isLib ? 'is-active' : '' ?>"<?= $isLib ? ' aria-current="page"' : '' ?>>
        <span class="tab-ico"><svg viewBox="0 0 24 24" class="ico"><path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg></span>
        <span class="tab-label"><?= e(t('nav.library')) ?></span>
    </a>
    <a href="/chat" class="<?= $isChat ? 'is-active' : '' ?>"<?= $isChat ? ' aria-current="page"' : '' ?>>
        <span class="tab-ico"><?= $chatIcon ?><?= $chatBadge ?></span>
        <span class="tab-label"><?= e(t('nav.chats')) ?></span>
    </a>
    <a href="/p/new" class="tab-add"<?= $isNew ? ' aria-current="page"' : '' ?> aria-label="<?= e(t('nav.new_persona')) ?>">
        <span class="tab-ico"><svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg></span>
        <span class="tab-label"><?= e(t('nav.new')) ?></span>
    </a>
    <a href="/?fav=1" class="<?= $isFav ? 'is-active' : '' ?>"<?= $isFav ? ' aria-current="page"' : '' ?>>
        <span class="tab-ico"><svg viewBox="0 0 24 24" class="ico"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg></span>
        <span class="tab-label"><?= e(t('nav.favorites')) ?></span>
    </a>
    <a href="/?arch=1" class="<?= $isArch ? 'is-active' : '' ?>"<?= $isArch ? ' aria-current="page"' : '' ?>>
        <span class="tab-ico"><svg viewBox="0 0 24 24" class="ico"><path d="M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8Zm-1-3h18v3H3V5Zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span>
        <span class="tab-label"><?= e(t('nav.archives')) ?></span>
    </a>
</nav>
<?php endif; ?>

<!-- visionneuse : glisser à gauche / à droite entre les photos d'un même ensemble ([data-gallery]) -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="<?= e(t('lightbox.label')) ?>" hidden>
    <div class="lb-track" data-lb-track></div>
    <div class="lb-top">
        <span class="lb-count" data-lb-count aria-live="polite"></span>
        <div class="lb-actions">
            <!-- photos d'une fiche seulement : portrait et suppression -->
            <button class="lb-btn" type="button" data-lb-portrait hidden
                    data-label-set="<?= e(t('persona.photo_set_avatar')) ?>" data-label-is="<?= e(t('persona.photo_is_avatar')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4.5 14.6 9.6l5.6.8-4.1 3.9 1 5.6-5.1-2.7-5 2.7 1-5.6L3.9 10.4l5.6-.8L12 4.5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </button>
            <button class="lb-btn lb-danger" type="button" data-lb-delete hidden aria-label="<?= e(t('persona.photo_delete')) ?>" title="<?= e(t('persona.photo_delete')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12M9 7V5h6v2m-8 0 1 13h8l1-13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </button>
            <a class="lb-btn" data-lb-download download href="#" aria-label="<?= e(t('lightbox.download')) ?>" title="<?= e(t('lightbox.download')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>
            <button class="lb-btn lightbox-close" type="button" aria-label="<?= e(t('common.close')) ?>" title="<?= e(t('common.close')) ?>">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </button>
        </div>
    </div>
    <button class="lb-nav lb-prev" type="button" data-lb-step="-1" aria-label="<?= e(t('lightbox.prev')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 6-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <button class="lb-nav lb-next" type="button" data-lb-step="1" aria-label="<?= e(t('lightbox.next')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <div class="lb-dots" data-lb-dots aria-hidden="true"></div>
</div>

<script type="application/json" id="pv-i18n"><?= json_encode((object) i18n_js(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= e(asset_url('app.js')) ?>" defer></script>
</body>
</html>
