<?php
$flashes = $flashes ?? [];
$nav = $nav ?? [];
$isAuth = is_logged();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$isFav = ($_GET['fav'] ?? '') === '1';
$isArch = ($_GET['arch'] ?? '') === '1';
$isLib = !$isFav && !$isArch && !str_starts_with($currentPath, '/chat');
$isChat = str_starts_with($currentPath, '/chat') || str_starts_with($currentPath, '/memories');
$isChatRoom = (bool) preg_match('#^/chat/\d+#', $currentPath);
?>
<!doctype html>
<?php $__ui = ui_style(); ?>
<html lang="fr"
      data-bubble="<?= e($__ui['bubble']) ?>"
      data-density="<?= e($__ui['density']) ?>"
      data-avatars="<?= $__ui['avatars'] ? '1' : '0' ?>"
      data-width="<?= e($__ui['width']) ?>"
      style="--chat-font: <?= e((string) $__ui['font']) ?>px">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="theme-color" content="#08080f">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="PersonaVault">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= e($title ?? 'Personas') ?> · PersonaVault</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/assets/icon-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
    <link rel="manifest" href="/assets/manifest.webmanifest">
    <link rel="stylesheet" href="/assets/app.css?v=67">
    <script>try{document.documentElement.dataset.view=localStorage.getItem("pv_view")==="list"?"list":"grid";}catch(e){}</script>
</head>
<body class="<?= $isAuth ? 'is-auth' : 'is-guest' ?> <?= $isChatRoom ? 'chat-room' : '' ?>">
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

    <nav class="topnav" aria-label="Navigation principale">
        <a href="/" class="<?= $isLib ? 'is-active' : '' ?>">Bibliothèque</a>
        <a href="/chat" class="<?= $isChat ? 'is-active' : '' ?>">Discussions</a>
        <a href="/?fav=1" class="<?= $isFav ? 'is-active' : '' ?>">Favoris</a>
        <a href="/?arch=1" class="<?= $isArch ? 'is-active' : '' ?>">Archives</a>
    </nav>

    <div class="topbar-actions">
        <a class="btn btn-primary btn-sm" href="/p/new">
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
            <span class="hide-sm">Nouveau</span>
        </a>
        <a class="icon-btn <?= $currentPath === '/settings' ? 'is-active' : '' ?>" href="/settings" title="Réglages" aria-label="Réglages">
            <svg viewBox="0 0 24 24" class="ico"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 13.5a7.6 7.6 0 0 0 0-3l1.7-1.2-1.8-3.1-2 .8a7.6 7.6 0 0 0-2.6-1.5L14.4 3h-3.6l-.3 2.5a7.6 7.6 0 0 0-2.6 1.5l-2-.8L4 9.3l1.7 1.2a7.6 7.6 0 0 0 0 3L4 14.7l1.8 3.1 2-.8a7.6 7.6 0 0 0 2.6 1.5l.3 2.5h3.6l.3-2.5a7.6 7.6 0 0 0 2.6-1.5l2 .8 1.8-3.1-1.6-1.2Z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
        </a>
        <form method="post" action="/logout" class="logout-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <button class="icon-btn" type="submit" title="Se déconnecter" aria-label="Se déconnecter">
                <svg viewBox="0 0 24 24" class="ico"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
            </button>
        </form>
    </div>
</header>
<?php endif; ?>

<main class="page <?= $isChatRoom ? 'page-chat' : '' ?>">
    <?php foreach ($flashes as $f): ?>
        <div class="flash flash-<?= e($f['type']) ?>" role="status">
            <span><?= e($f['message']) ?></span>
            <button type="button" class="flash-close" aria-label="Fermer">&times;</button>
        </div>
    <?php endforeach; ?>

    <?= $content ?>
</main>

<?php if ($isAuth && !$isChatRoom): ?>
<nav class="tabbar" aria-label="Navigation mobile">
    <a href="/" class="<?= $isLib ? 'is-active' : '' ?>">
        <svg viewBox="0 0 24 24" class="ico"><path d="M4 6h16M4 12h16M4 18h10" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
        <span>Bibliothèque</span>
    </a>
    <a href="/chat" class="<?= $isChat ? 'is-active' : '' ?>">
        <svg viewBox="0 0 24 24" class="ico"><path d="M7 5h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-6l-4 3v-3H7a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        <span>Discussions</span>
    </a>
    <a href="/p/new" class="tab-add" aria-label="Nouveau persona">
        <svg viewBox="0 0 24 24" class="ico"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
        <span>Nouveau</span>
    </a>
    <a href="/?fav=1" class="<?= $isFav ? 'is-active' : '' ?>">
        <svg viewBox="0 0 24 24" class="ico"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/></svg>
        <span>Favoris</span>
    </a>
    <a href="/?arch=1" class="<?= $isArch ? 'is-active' : '' ?>">
        <svg viewBox="0 0 24 24" class="ico"><path d="M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8Zm-1-3h18v3H3V5Zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        <span>Archives</span>
    </a>
</nav>
<?php endif; ?>

<div class="lightbox" id="lightbox" hidden>
    <button class="lightbox-close" type="button" aria-label="Fermer">&times;</button>
    <img alt="">
</div>

<script src="/assets/app.js?v=72" defer></script>
</body>
</html>
