<?php
$profileUrl = '/p/' . rawurlencode($persona['slug']);
$url = $profileUrl . '/relationship';
$token = csrf_token();
$kinds = [
    'agreement' => t('relationship.agreement'),
    'change' => t('relationship.change'),
    'conflict' => t('relationship.conflict'),
];
?>
<a class="back" href="<?= e($profileUrl) ?>">
    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="m14 6-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e($persona['name']), "\n" ?>
</a>
<section class="lib-head">
    <div class="lib-title"><h1><?= e(t('relationship.title')) ?></h1></div>
</section>

<form class="settings-card settings-stack" method="post" action="<?= e($url) ?>">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <input type="hidden" name="operation" value="settings">
    <label class="check">
        <input type="checkbox" name="automatic" value="1" <?= $state['automatic'] ? 'checked' : '' ?>>
        <span><?= e(t('relationship.automatic')) ?></span>
    </label>
    <p class="hint"><?= e(t('relationship.automatic_help')) ?></p>
    <label class="field">
        <span><?= e(t('relationship.address')) ?></span>
        <select name="address" class="input">
            <option value=""><?= e(t('relationship.address_default')) ?></option>
            <option value="tu" <?= $state['address'] === 'tu' ? 'selected' : '' ?>><?= e(t('relationship.address_tu')) ?></option>
            <option value="vous" <?= $state['address'] === 'vous' ? 'selected' : '' ?>><?= e(t('relationship.address_vous')) ?></option>
        </select>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('relationship.save')) ?></button></div>
</form>

<details class="mem-add-fold"<?= !$entries ? ' open' : '' ?>>
    <summary class="btn btn-ghost btn-sm"><?= e(t('relationship.add')) ?></summary>
    <?php $entry = []; require __DIR__ . '/relationship_form.php'; ?>
</details>

<?php if ($entries): ?>
    <div class="mem-list">
        <?php foreach ($entries as $entry): ?>
            <article class="mem-item">
                <div class="mem-item-head">
                    <span class="cat"><?= e($kinds[$entry['kind']]) ?></span>
                    <?php if ($entry['kind'] === 'conflict'): ?>
                        <span class="mem-scope"><?= e($entry['resolved'] ? t('relationship.resolved') : t('relationship.active')) ?></span>
                    <?php endif; ?>
                    <form method="post" action="<?= e($url) ?>" data-confirm="<?= e(t('relationship.delete_confirm')) ?>"
                          data-confirm-ok="<?= e(t('relationship.delete_action')) ?>">
                        <input type="hidden" name="_token" value="<?= e($token) ?>">
                        <input type="hidden" name="operation" value="delete">
                        <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                        <button class="mini mini-danger" type="submit" aria-label="<?= e(t('relationship.delete')) ?>">&times;</button>
                    </form>
                </div>
                <p class="mem-content"><?= nl2br(e($entry['content'])) ?></p>
                <?php if ($entry['source_conversation_id']): ?>
                    <a class="mini" href="/chat/<?= (int) $entry['source_conversation_id'] ?>?message=<?= (int) $entry['source_message_id'] ?>"><?= e(t('relationship.source')) ?></a>
                <?php endif; ?>
                <details class="mem-edit">
                    <summary><?= e(t('relationship.edit')) ?></summary>
                    <?php require __DIR__ . '/relationship_form.php'; ?>
                </details>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
