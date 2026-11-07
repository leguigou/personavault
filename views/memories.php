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
    Discussions
</a>

<section class="lib-head">
    <div class="lib-title">
        <p class="eyebrow">Mémoire longue</p>
        <h1>Souvenirs <span class="count"><?= (int) $stats['total'] ?></span></h1>
    </div>
</section>

<p class="hint mem-intro">
    Ce que les personas retiennent de toi d’une conversation à l’autre : préférences, projets, contraintes.
    <strong><?= (int) $stats['shared'] ?></strong> partagé<?= (int) $stats['shared'] > 1 ? 's' : '' ?> avec tous les personas, le reste propre à un persona.
</p>

<form class="searchbar" method="get" action="/memories" role="search">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Chercher un souvenir…" aria-label="Chercher un souvenir">
    <button class="search-go" type="submit" aria-label="Chercher">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13M12.5 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </button>
</form>

<form class="card-form mem-add" method="post" action="/memories">
    <input type="hidden" name="_token" value="<?= e($token) ?>">

    <label class="field">
        <span>Nouveau souvenir</span>
        <input class="input" name="content" placeholder="Ex. Préfère les cafés glacés et les sorties en fin de journée" required maxlength="400">
    </label>

    <div class="row2">
        <div class="field">
            <span>Type</span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Type de souvenir">
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
            <span>Portée</span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Portée du souvenir">
                    <span class="sel-value"></span>
                    <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
                <select name="persona_id" class="sel-native">
                    <option value="0">Partagé (tous les personas)</option>
                    <?php foreach ($personas as $p): ?>
                        <option value="<?= (int) $p['id'] ?>"><?= e((string) $p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="mem-add-foot">
        <div class="field">
            <span>Confiance</span>
            <div class="sel" data-sel>
                <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Confiance">
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
        <button class="btn btn-primary" type="submit">Ajouter</button>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="empty">
        <h2>Aucun souvenir</h2>
        <p>Ils se remplissent tout seuls quand tu discutes, ou ajoute-les à la main.</p>
    </div>
<?php else: ?>
    <div class="mem-list">
        <?php foreach ($rows as $r): ?>
            <?php $pid = (int) ($r['persona_id'] ?? 0); ?>
            <article class="mem-item">
                <div class="mem-item-head">
                    <span class="cat cat-<?= e((string) $r['category']) ?>"><?= e($cats[$r['category']] ?? 'Fait') ?></span>
                    <span class="conf conf-<?= e((string) $r['confidence']) ?>"><?= e($confs[$r['confidence']] ?? 'Confirmé') ?></span>
                    <span class="mem-scope"><?= $pid > 0 ? e($names[$pid] ?? 'persona') : 'Partagé' ?></span>
                    <form method="post" action="/memories/<?= (int) $r['id'] ?>/delete" data-confirm="Supprimer ce souvenir ?">
                        <input type="hidden" name="_token" value="<?= e($token) ?>">
                        <button class="mini mini-danger" type="submit" aria-label="Supprimer">&times;</button>
                    </form>
                </div>

                <p class="mem-content"><?= e((string) $r['content']) ?></p>

                <details class="mem-edit">
                    <summary>Modifier</summary>
                    <form method="post" action="/memories/<?= (int) $r['id'] ?>">
                        <input type="hidden" name="_token" value="<?= e($token) ?>">
                        <label class="field">
                            <span>Contenu</span>
                            <textarea class="input" name="content" rows="2" required><?= e((string) $r['content']) ?></textarea>
                        </label>
                        <div class="row2">
                            <div class="field">
                                <span>Type</span>
                                <div class="sel" data-sel>
                                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Type">
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
                                <span>Confiance</span>
                                <div class="sel" data-sel>
                                    <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Confiance">
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
                            <button class="btn btn-primary btn-sm" type="submit">Enregistrer</button>
                        </div>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
