<?php
/** @var array $personas @var array $counts @var array $allTags */
$heading = $fav ? 'Mes favoris' : ($arch ? 'Archivés' : 'Ma bibliothèque de personas');
$eyebrow = $fav ? 'Favoris' : ($arch ? 'Archives' : '');
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

    <div class="view-toggle" role="group" aria-label="Affichage de la bibliothèque">
        <button type="button" data-view-btn="grid" title="Grille" aria-label="Vue grille" aria-pressed="true">
            <svg viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="4" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="4" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><rect x="13" y="13" width="7" height="7" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
        </button>
        <button type="button" data-view-btn="list" title="Liste" aria-label="Vue liste" aria-pressed="false">
            <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" fill="none"/></svg>
        </button>
    </div>
</section>

<form class="searchbar" method="get" action="/" role="search" data-search
      data-tags="<?= e(json_encode($tagList, JSON_UNESCAPED_UNICODE)) ?>">
    <?php if ($fav): ?><input type="hidden" name="fav" value="1"><?php endif; ?>
    <?php if ($arch): ?><input type="hidden" name="arch" value="1"><?php endif; ?>
    <?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?= e($tag) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nom, tag, mot-clé…" aria-label="Rechercher un persona"
           autocomplete="off" spellcheck="false" data-search-input>
    <button class="search-go" type="submit" aria-label="Rechercher">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
    <div class="suggest" data-suggest hidden></div>
</form>

<?php if ($activeFilters): ?>
<div class="filters-active">
    <?php if ($q !== ''): ?><span class="filter-pill">« <?= e($q) ?> »</span><?php endif; ?>
    <?php if ($tag !== ''): ?><span class="filter-pill">#<?= e($tag) ?></span><?php endif; ?>
    <?php if ($fav): ?><span class="filter-pill">Favoris</span><?php endif; ?>
    <?php if ($arch): ?><span class="filter-pill">Archives</span><?php endif; ?>
    <a class="btn btn-clear" href="/">
        <svg viewBox="0 0 24 24" class="ico"><path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        Effacer les filtres
    </a>
</div>
<?php endif; ?>

<?php if ($allTags): ?>
<div class="tagrow">
    <a class="chip <?= $tag === '' ? 'is-on' : '' ?>" href="/?<?= http_build_query($baseQs) ?>">Tout</a>
    <?php foreach (array_slice($allTags, 0, 14, true) as $t => $n): ?>
        <a class="chip <?= $tag === (string) $t ? 'is-on' : '' ?>" href="/?<?= http_build_query(array_merge($baseQs, ['tag' => $t])) ?>">
            <?= e((string) $t) ?><em><?= (int) $n ?></em>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$personas): ?>
    <div class="empty">
        <div class="empty-orb" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 3.2 14.9 9l6.4.9-4.7 4.5 1.1 6.4L12 17.8 6.3 20.8l1.1-6.4L2.7 9.9 9.1 9 12 3.2Z" fill="url(#eg)"/><defs><linearGradient id="eg" x1="2" y1="2" x2="22" y2="22"><stop stop-color="#a78bfa"/><stop offset="1" stop-color="#f472b6"/></linearGradient></defs></svg>
        </div>
        <h2><?= $q !== '' || $tag !== '' ? 'Rien trouvé' : 'La bibliothèque est vide' ?></h2>
        <p><?= $q !== '' || $tag !== '' ? 'Essaie un autre mot-clé ou retire le filtre.' : 'Crée ton premier persona : un portrait, une accroche, tes prompts.' ?></p>
        <?php if ($q === '' && $tag === ''): ?>
            <a class="btn btn-primary" href="/p/new">Créer un persona</a>
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
                $facts[] = $photos . ' photo' . ($photos > 1 ? 's' : '');
            }
            if ($prompts > 0) {
                $facts[] = $prompts . ' prompt' . ($prompts > 1 ? 's' : '');
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
                        <span class="persona-heart" aria-label="Favori">
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
                <div class="persona-caption"><strong>Nouveau persona</strong></div>
            </a>
        <?php endif; ?>
    </div>
<?php endif; ?>
