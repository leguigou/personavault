<?php
/** @var array $personas @var array $counts @var array $allTags */
$heading = $fav ? t('library.title_favorites') : ($arch ? t('library.title_archived') : t('library.title'));
$eyebrow = $fav ? t('nav.favorites') : ($arch ? t('nav.archives') : '');
$baseQs  = array_filter(['q' => $q, 'fav' => $fav ? '1' : '', 'arch' => $arch ? '1' : '']);
$tagList = [];
foreach ($allTags as $t => $n) {
    $tagList[] = ['t' => (string) $t, 'n' => (int) $n];
}
$activeFilters = ($q !== '') || ($tag !== '') || $fav || $arch;
?>
<section class="lib-head">
    <div class="lib-title">
        <?php if ($eyebrow !== ''): ?><p class="eyebrow"><?= e($eyebrow) ?></p><?php endif; ?>
        <h1>
            <?= e($heading) ?>
            <span class="count"><?= count($personas) ?></span>
        </h1>
    </div>

    <div class="view-toggle" role="group" aria-label="<?= e(t('library.view_toggle')) ?>">
        <button type="button" data-view-btn="grid" title="<?= e(t('library.view_grid')) ?>" aria-label="<?= e(t('library.view_grid_label')) ?>" aria-pressed="true">
            <svg viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="4" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
        </button>
        <button type="button" data-view-btn="list" title="<?= e(t('library.view_list')) ?>" aria-label="<?= e(t('library.view_list_label')) ?>" aria-pressed="false">
            <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" fill="none"/></svg>
        </button>
    </div>
</section>

<form class="searchbar" method="get" action="/" role="search" data-search
      data-tags="<?= e(json_encode($tagList, JSON_UNESCAPED_UNICODE)) ?>">
    <?php if ($fav): ?><input type="hidden" name="fav" value="1"><?php endif; ?>
    <?php if ($arch): ?><input type="hidden" name="arch" value="1"><?php endif; ?>
    <?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?= e($tag) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('library.search_placeholder')) ?>" aria-label="<?= e(t('library.search_label')) ?>"
           autocomplete="off" spellcheck="false" data-search-input>
    <button class="search-go" type="submit" aria-label="<?= e(t('library.search_submit')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <div class="suggest" data-suggest hidden></div>
</form>

<?php if ($activeFilters): ?>
<div class="filters-active">
    <?php if ($q !== ''): ?><span class="filter-pill"><?= e(t('library.filter_query', ['query' => $q])) ?></span><?php endif; ?>
    <?php if ($tag !== ''): ?><span class="filter-pill">#<?= e($tag) ?></span><?php endif; ?>
    <?php if ($fav): ?><span class="filter-pill"><?= e(t('nav.favorites')) ?></span><?php endif; ?>
    <?php if ($arch): ?><span class="filter-pill"><?= e(t('nav.archives')) ?></span><?php endif; ?>
    <a class="btn btn-clear" href="/">
        <svg viewBox="0 0 24 24" class="ico"><path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <?= e(t('library.clear_filters')), "\n" ?>
    </a>
</div>
<?php endif; ?>

<?php if ($allTags): ?>
<div class="tagrow">
    <a class="chip <?= $tag === '' ? 'is-on' : '' ?>" href="/?<?= http_build_query($baseQs) ?>"><?= e(t('library.tag_all')) ?></a>
    <?php foreach (array_slice($allTags, 0, 14, true) as $t => $n): ?>
        <a class="chip <?= $tag === (string) $t ? 'is-on' : '' ?>" href="/?<?= http_build_query(array_merge($baseQs, ['tag' => $t])) ?>">
            <?= e((string) $t) ?><em><?= (int) $n ?></em>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$personas): ?>
    <?php
    // un écran vide par situation : recherche sans résultat, favoris, archives, bibliothèque vide
    $emptyKind = ($q !== '' || $tag !== '') ? 'found' : ($fav ? 'fav' : ($arch ? 'arch' : 'lib'));
    ?>
    <div class="empty empty-<?= e($emptyKind) ?>">
        <div class="empty-orb" aria-hidden="true">
            <?php if ($emptyKind === 'found'): ?>
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 16 4 4M8.5 11h5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <?php elseif ($emptyKind === 'fav'): ?>
                <svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            <?php elseif ($emptyKind === 'arch'): ?>
                <svg viewBox="0 0 24 24"><path d="M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8Zm-1-3h18v3H3V5Zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
            <?php else: ?>
                <svg viewBox="0 0 24 24"><path d="M12 3.2 14.9 9l6.4.9-4.7 4.5 1.1 6.4L12 17.8 6.3 20.8l1.1-6.4L2.7 9.9 9.1 9 12 3.2Z" fill="url(#eg)"/><defs><linearGradient id="eg" x1="2" y1="2" x2="22" y2="22"><stop stop-color="#a78bfa"/><stop offset="1" stop-color="#f472b6"/></linearGradient></defs></svg>
            <?php endif; ?>
        </div>
        <h2><?= e(match ($emptyKind) {
            'found' => t('library.empty_found_title'),
            'fav'   => t('library.empty_fav_title'),
            'arch'  => t('library.empty_arch_title'),
            default => t('library.empty_title'),
        }) ?></h2>
        <p><?= e(match ($emptyKind) {
            'found' => t('library.empty_found_text'),
            'fav'   => t('library.empty_fav_text'),
            'arch'  => t('library.empty_arch_text'),
            default => t('library.empty_text'),
        }) ?></p>
        <?php if ($emptyKind === 'found'): ?>
            <a class="btn btn-ghost btn-sm" href="/<?= $fav ? '?fav=1' : ($arch ? '?arch=1' : '') ?>"><?= e(t('library.empty_found_reset')) ?></a>
        <?php elseif ($emptyKind === 'fav' || $emptyKind === 'arch'): ?>
            <a class="btn btn-ghost btn-sm" href="/"><?= e(t('library.empty_back')) ?></a>
        <?php endif; ?>
        <?php if ($emptyKind === 'lib'): ?>
            <a class="btn btn-primary" href="/p/new"><?= e(t('library.create')) ?></a>
            <form method="post" action="/import" enctype="multipart/form-data" style="margin:0" data-card-import>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <label class="btn btn-ghost btn-sm" title="<?= e(t('cards.import_title')) ?>">
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M12 15V4m0 0-4 4m4-4 4 4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <?= e(t('cards.import_file')), "\n" ?>
                    <input type="file" name="card" accept=".zip,.json,.png,application/zip,application/json,image/png" hidden data-autosubmit>
                </label>
            </form>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($personas as $p): ?>
            <?php
            $photos  = $counts['p' . $p['id']] ?? 0;
            $prompts = $counts['t' . $p['id']] ?? 0;
            $hue     = hue_for($p['name']);
            $tags    = json_list($p['tags']);
            $facts   = [];
            if ($photos > 0) {
                $facts[] = t('library.card_photos', (int) $photos);
            }
            if ($prompts > 0) {
                $facts[] = t('library.card_prompts', (int) $prompts);
            }
            // 2e ligne : chiffres si on en a, sinon l'accroche
            $factsText = $facts ? implode(' · ', $facts) : (string) $p['tagline'];
            ?>
            <a class="persona-card <?= (int) $p['archived'] ? 'is-archived' : '' ?>" href="/p/<?= e($p['slug']) ?>" style="--hue: <?= $hue ?>">
                <div class="persona-media">
                    <?php if ($p['avatar']): ?>
                        <img src="<?= e(media_url($p['avatar'])) ?>" alt="<?= e($p['name']) ?>" loading="lazy" width="700" height="875">
                    <?php else: ?>
                        <div class="avatar-fallback" aria-hidden="true"><span><?= e(initials($p['name'])) ?></span></div>
                    <?php endif; ?>
                    <?php if ((int) $p['favorite']): ?>
                        <span class="persona-heart" aria-label="<?= e(t('library.card_favorite')) ?>">
                            <svg viewBox="0 0 24 24"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2.6A4 4 0 0 1 19 11c0 4.6-7 9-7 9Z" fill="currentColor"/></svg>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="persona-caption">
                    <strong><?= e($p['name']) ?></strong>
                    <?php if ($factsText !== ''): ?><span><?= e($factsText) ?></span><?php endif; ?>
                </div>
                <?php if ($tags): ?>
                    <div class="persona-extra">
                        <?php foreach (array_slice($tags, 0, 4) as $t): ?><span>#<?= e((string) $t) ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </a>
        <?php endforeach; ?>

        <?php if (!$fav && !$arch): ?>
            <a class="persona-card add-tile" href="/p/new">
                <div class="persona-media">
                    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </div>
                <div class="persona-caption"><strong><?= e(t('nav.new_persona')) ?></strong></div>
            </a>
            <form class="persona-card add-tile" method="post" action="/import" enctype="multipart/form-data" data-card-import>
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <label style="display:block;cursor:pointer" title="<?= e(t('cards.import_title')) ?>">
                    <div class="persona-media">
                        <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M12 15V4m0 0-4 4m4-4 4 4M5 19h14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </div>
                    <div class="persona-caption"><strong><?= e(t('cards.import_tile')) ?></strong></div>
                    <input type="file" name="card" accept=".zip,.json,.png,application/zip,application/json,image/png" hidden data-autosubmit>
                </label>
            </form>
        <?php endif; ?>
    </div>
<?php endif; ?>
