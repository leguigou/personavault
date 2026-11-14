<?php
/** @var array $rows @var string $q @var array $personas @var array $stats */
$cats = facts_categories();
$confs = facts_confidences();
$names = [];
foreach ($personas as $p) {
    $names[(int) $p['id']] = (string) $p['name'];
}
$token = csrf_token();
?>
<a class="back" href="/chat">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('nav.chats')), "\n" ?>
</a>

<section class="lib-head">
    <div class="lib-title">
        <p class="eyebrow"><?= e(t('memories.eyebrow')) ?></p>
        <h1><?= e(t('memories.title')) ?> <span class="count"><?= (int) $stats['total'] ?></span></h1>
    </div>
</section>

<p class="hint mem-intro">
    <?= e(t('memories.intro')), "\n" ?>
    <?= t('memories.intro_shared_html', (int) $stats['shared']), "\n" ?>
</p>

<form class="searchbar" method="get" action="/memories" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="<?= e(t('memories.search_placeholder')) ?>" aria-label="<?= e(t('memories.search_label')) ?>">
    <button class="search-go" type="submit" aria-label="<?= e(t('memories.search_submit')) ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
</form>

<details class="mem-add-fold"<?= !$rows && $q === '' ? ' open' : '' ?>>
    <summary class="btn btn-ghost btn-sm">
        <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        <?= e(t('memories.add_open')) . "\n" ?>
    </summary>
<form class="card-form mem-add" method="post" action="/memories">
    <input type="hidden" name="_token" value="<?= e($token) ?>">

    <label class="field">
        <span><?= e(t('memories.new')) ?></span>
        <input class="input" name="content" placeholder="<?= e(t('memories.new_placeholder')) ?>" required maxlength="400">
    </label>

    <div class="row2">
        <div class="field">
            <span><?= e(t('memories.type')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('memories.type_label')) ?>">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="category" class="sel-native">
                    <?php foreach ($cats as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <span><?= e(t('memories.scope')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('memories.scope_label')) ?>">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="persona_id" class="sel-native">
                    <option value="0"><?= e(t('memories.scope_shared_option')) ?></option>
                    <?php foreach ($personas as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e((string) $p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="mem-add-foot">
        <div class="field">
            <span><?= e(t('memories.confidence')) ?></span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('memories.confidence')) ?>">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="confidence" class="sel-native">
                    <?php foreach ($confs as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button class="btn btn-primary" type="submit"><?= e(t('memories.add')) ?></button>
    </div>
</form>
</details>

<?php if (!$rows): ?>
    <div class="empty">
        <div class="empty-orb" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 5a4 4 0 0 0-4 4 3 3 0 0 0 0 6 3.5 3.5 0 0 0 4 4V5Zm0 0a4 4 0 0 1 4 4 3 3 0 0 1 0 6 3.5 3.5 0 0 1-4 4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg>
        </div>
        <?php if ($q !== ''): ?>
            <h2><?= e(t('library.empty_found_title')) ?></h2>
            <p><?= e(t('memories.empty_found_text')) ?></p>
            <a class="btn btn-ghost btn-sm" href="/memories"><?= e(t('library.empty_found_reset')) ?></a>
        <?php else: ?>
            <h2><?= e(t('memories.empty_title')) ?></h2>
            <p><?= e(t('memories.empty_text')) ?></p>
            <a class="btn btn-ghost btn-sm" href="/chat"><?= e(t('memories.empty_cta')) ?></a>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="mem-list">
        <?php foreach ($rows as $r): ?>
            <?php $pid = (int) ($r['persona_id'] ?? 0); ?>
            <article class="mem-item">
                <div class="mem-item-head">
                    <span class="cat cat-<?= e((string) $r['category']) ?>"><?= e($cats[$r['category']] ?? t('memories.category_fallback')) ?></span>
                    <span class="conf conf-<?= e((string) $r['confidence']) ?>"><?= e($confs[$r['confidence']] ?? t('memories.confidence_fallback')) ?></span>
                    <span class="mem-scope"><?= e($pid > 0 ? ($names[$pid] ?? t('memories.scope_unknown')) : t('memories.scope_shared')) ?></span>
                    <?php if ($r['subject'] === 'story'): ?><span class="mem-scope"><?= e(t('chat.facts.story')) ?></span><?php endif; ?>
                    <form method="post" action="/memories/<?= (int) $r['id'] ?>/delete" data-confirm="<?= e(t('memories.delete_confirm')) ?>">
                        <input type="hidden" name="_token" value="<?= e($token) ?>">
                        <button class="mini mini-danger" type="submit" aria-label="<?= e(t('memories.delete')) ?>">&times;</button>
                    </form>
                </div>

                <p class="mem-content"><?= e((string) $r['content']) ?></p>

                <details class="mem-edit">
                    <summary><?= e(t('memories.edit')) ?></summary>
                    <form method="post" action="/memories/<?= (int) $r['id'] ?>">
                        <input type="hidden" name="_token" value="<?= e($token) ?>">
                        <label class="field">
                            <span><?= e(t('memories.content')) ?></span>
                            <textarea class="input" name="content" rows="2" required><?= e((string) $r['content']) ?></textarea>
                        </label>
                        <div class="row2">
                            <div class="field">
                                <span><?= e(t('memories.type')) ?></span>
                                <div class="sel" data-sel>
                                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('memories.type')) ?>">
                                        <span class="sel-value"></span>
                                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                    <select name="category" class="sel-native">
                                        <?php foreach ($cats as $k => $label): ?>
                                            <option value="<?= e($k) ?>" <?= $r['category'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="field">
                                <span><?= e(t('memories.confidence')) ?></span>
                                <div class="sel" data-sel>
                                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= e(t('memories.confidence')) ?>">
                                        <span class="sel-value"></span>
                                        <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                    </button>
                                    <select name="confidence" class="sel-native">
                                        <?php foreach ($confs as $k => $label): ?>
                                            <option value="<?= e($k) ?>" <?= $r['confidence'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-primary btn-sm" type="submit"><?= e(t('memories.save')) ?></button>
                        </div>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
