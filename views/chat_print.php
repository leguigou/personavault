<?php
/**
 * Page imprimable d'une conversation (/chat/{id}/print), sans le layout de l'application :
 * la boîte d'impression du navigateur permet de l'« Enregistrer en PDF ».
 * Styles : public/assets/print.css. Tout le texte est échappé (e()).
 * @var array $conv @var array $persona @var iterable $rows @var int $count @var bool $auto
 */
$name   = (string) $persona['name'];
$avatar = (string) ($persona['avatar'] ?? '');
$portrait = static function () use ($avatar, $name): string {
    return $avatar !== ''
        ? '<img class="pr-face" src="' . e(media_url($avatar)) . '" alt="">'
        : '<span class="pr-face pr-face-txt">' . e(initials($name)) . '</span>';
};
?>
<!doctype html>
<html lang="<?= e(locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e((string) $conv['title']) ?> · PersonaVault</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_url('print.css')) ?>">
</head>
<body class="pr-body">
<div class="pr-toolbar" role="toolbar">
    <a class="pr-btn pr-btn-ghost" href="/chat/<?= (int) $conv['id'] ?>"><?= e(t('export.print.back')) ?></a>
    <button class="pr-btn" type="button" data-print><?= e(t('export.print.button')) ?></button>
    <p class="pr-tip"><?= e(t('export.print.tip')) ?></p>
</div>

<main class="pr-page">
    <header class="pr-head">
        <?= $portrait() ?>
        <div>
            <h1><?= e((string) $conv['title']) ?></h1>
            <p class="pr-meta">
                <?= e(t('export.with', ['name' => $name])) ?> ·
                <?= e(t('export.exported_at', ['date' => export_datetime(gmdate('Y-m-d H:i:s'))])) ?> ·
                <?= e(t('export.count', $count)) . "\n" ?>
            </p>
        </div>
    </header>

    <?php $any = false; ?>
    <?php foreach ($rows as $m): ?>
        <?php
        $role = (string) $m['role'];
        $any  = true;
        ?>
        <?php if ($role === 'memory' || $role === 'notice'): ?>
            <?php if (trim((string) $m['content']) !== ''): ?>
                <p class="pr-note"><?= e(trim((string) $m['content'])) ?></p>
            <?php endif; ?>
        <?php elseif ($role === 'user' || $role === 'assistant'): ?>
            <article class="pr-msg <?= $role === 'user' ? 'pr-me' : 'pr-bot' ?>">
                <div class="pr-msg-head">
                    <?php if ($role !== 'user'): ?><?= $portrait() ?><?php endif; ?>
                    <strong><?= e(export_author($role, $name)) ?></strong>
                    <time><?= e(export_datetime((string) $m['created_at'])) ?></time>
                </div>
                <?php if ((string) ($m['image'] ?? '') !== ''): ?>
                    <img class="pr-img" src="<?= e(media_url((string) $m['image'], true)) ?>" alt="<?= e(t('export.photo')) ?>">
                <?php endif; ?>
                <?php foreach (export_bubbles($m) as $part): ?>
                    <div class="pr-bubble"><?= nl2br(e($part)) ?></div>
                <?php endforeach; ?>
            </article>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$any): ?>
        <p class="pr-empty"><?= e(t('export.empty')) ?></p>
    <?php endif; ?>
</main>

<script>
(function () {
    var btn = document.querySelector('[data-print]');
    if (btn) { btn.addEventListener('click', function () { window.print(); }); }
    <?php if ($auto): ?>
    // impression automatique, une fois les images chargées (sinon elles manquent au PDF)
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
    <?php endif; ?>
})();
</script>
</body>
</html>
