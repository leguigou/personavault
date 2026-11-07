<?php
/** @var array|null $persona */
$isEdit   = is_array($persona);
$slug     = $isEdit ? (string) $persona['slug'] : '';
$name     = $isEdit ? (string) $persona['name'] : '';
$tagline  = $isEdit ? (string) $persona['tagline'] : '';
$desc     = $isEdit ? (string) $persona['description'] : '';
$tagsIn   = $isEdit ? implode(', ', array_map('strval', json_list($persona['tags']))) : '';
$metaIn   = $isEdit ? meta_to_text(json_list($persona['meta'])) : '';
$avatar   = $isEdit ? (string) $persona['avatar'] : '';
$hue      = hue_for($name !== '' ? $name : 'persona');
$action   = $isEdit ? '/p/' . $slug : '/p';
?>
<a class="back" href="<?= $isEdit ? '/p/' . e($slug) : '/' ?>">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    Annuler
</a>

<section class="form-wrap">
    <header class="form-head">
        <p class="eyebrow"><?= $isEdit ? 'Édition' : 'Nouveau' ?></p>
        <h1><?= $isEdit ? 'Modifier le persona' : 'Créer un persona' ?></h1>
    </header>

    <form class="card-form" method="post" action="<?= e($action) ?>" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">

        <div class="avatar-picker">
            <div class="avatar-preview" id="avatar-preview" style="--hue: <?= $hue ?>">
                <?php if ($avatar): ?>
                    <img src="<?= e(media_url($avatar)) ?>" alt="Portrait actuel">
                <?php else: ?>
                    <div class="avatar-fallback"><span><?= e(initials($name !== '' ? $name : '?')) ?></span></div>
                <?php endif; ?>
            </div>
            <div>
                <label class="btn btn-ghost btn-sm">
                    <?= $avatar ? 'Changer le portrait' : 'Choisir un portrait' ?>
                    <input type="file" name="avatar" accept="image/*" hidden data-preview="#avatar-preview">
                </label>
                <p>JPG, PNG ou WEBP · 15 Mo max</p>
            </div>
        </div>

        <div class="row2">
            <label class="field">
                <span>Nom *</span>
                <input class="input" name="name" value="<?= e($name) ?>" placeholder="Ex. Camille Lefebvre" required maxlength="80" autofocus>
            </label>
            <label class="field">
                <span>Accroche</span>
                <input class="input" name="tagline" value="<?= e($tagline) ?>" placeholder="Ex. Cheffe de produit, 32 ans" maxlength="120">
            </label>
        </div>

        <label class="field">
            <span>Description</span>
            <textarea class="input" name="description" rows="4" placeholder="Personnalité, univers, contexte…"><?= e($desc) ?></textarea>
        </label>

        <label class="field">
            <span>Tags</span>
            <input class="input" name="tags" value="<?= e($tagsIn) ?>" placeholder="romance, studio, cyberpunk">
            <small>Séparés par des virgules (12 max).</small>
        </label>

        <label class="field">
            <span>Attributs</span>
            <textarea class="input" name="meta" rows="4" placeholder="Âge: 32&#10;Style: cinématique&#10;Modèle: Flux.1 dev"><?= e($metaIn) ?></textarea>
            <small>Une ligne par attribut, au format « Clé: valeur ».</small>
        </label>

        <?php if ($isEdit): ?>
            <div class="persona-ai" id="ia">
                <div class="persona-ai-head">
                    <h3>Réglages du personnage</h3>
                    <p class="hint">Ces valeurs suivent ce persona dans toutes ses conversations.</p>
                </div>

                <div class="field">
                    <span>Modèle de discussion</span>
                    <?php if (!empty($chatModels)): ?>
                        <div class="sel" data-sel>
                            <button type="button" class="sel-btn" aria-haspopup="listbox" aria-expanded="false" aria-label="Modèle de discussion">
                                <span class="sel-value"></span>
                                <svg class="sel-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                            <select name="model" class="sel-native">
                                <option value="">Modèle global (par défaut)</option>
                                <?php foreach ($chatModels as $cm): ?>
                                    <option value="<?= e($cm['id']) ?>" <?= (string) $persona['model'] === $cm['id'] ? 'selected' : '' ?>><?= $cm['fav'] ? '★ ' : '' ?><?= e($cm['id']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input class="input" name="model" value="<?= e((string) $persona['model']) ?>" placeholder="laisser vide = modèle global" spellcheck="false">
                        <small>LM Studio injoignable : saisis le nom à la main.</small>
                    <?php endif; ?>
                </div>

                <div class="row2">
                    <label class="field">
                        <span>Température</span>
                        <input class="input" type="number" name="temperature" step="0.01" min="0" max="2" value="<?= e((string) $persona['temperature']) ?>">
                        <small>Basse = posée, haute = imprévisible.</small>
                    </label>
                    <label class="field">
                        <span>Réponse max (tokens)</span>
                        <input class="input" type="number" name="max_tokens" step="1" min="64" max="4096" value="<?= (int) $persona['max_tokens'] ?>">
                    </label>
                </div>

                <label class="field">
                    <span>Fenêtre de contexte (tokens)</span>
                    <input class="input" type="number" name="context_window" step="1024" min="2048" max="131072" value="<?= (int) $persona['context_window'] ?>">
                </label>

                <div class="field">
                    <div class="field-head">
                        <span>Consigne de jeu</span>
                        <button type="button" class="mini-link" data-ai-directive
                                data-url="/p/<?= e((string) $persona['slug']) ?>/ai-directive"
                                data-token="<?= e(csrf_token()) ?>">Proposer avec l’IA</button>
                    </div>
                    <textarea class="input" name="directive" rows="3" data-directive
                              placeholder="Ex. Réponds en messages courts, tutoie, jamais de listes."><?= e((string) $persona['directive']) ?></textarea>
                    <small>Ajoutée au prompt système de toutes ses conversations, si elle existe.</small>
                </div>
            </div>
        <?php endif; ?>

        <div class="form-actions">
            <a class="btn btn-ghost" href="<?= $isEdit ? '/p/' . e($slug) : '/' ?>">Annuler</a>
            <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Enregistrer' : 'Créer le persona' ?></button>
        </div>
    </form>
</section>
