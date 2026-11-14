<form class="card-form" method="post" action="<?= e($url) ?>">
    <input type="hidden" name="_token" value="<?= e($token) ?>">
    <input type="hidden" name="operation" value="save">
    <?php if ($entry): ?><input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>"><?php endif; ?>
    <label class="field">
        <span><?= e(t('relationship.type')) ?></span>
        <select class="input" name="kind">
            <?php foreach ($kinds as $kind => $label): ?>
                <option value="<?= e($kind) ?>" <?= ($entry['kind'] ?? '') === $kind ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span><?= e(t('relationship.content')) ?></span>
        <textarea class="input" name="content" rows="2" maxlength="400" required><?= e($entry['content'] ?? '') ?></textarea>
    </label>
    <label class="check" data-relationship-conflict <?= ($entry['kind'] ?? '') !== 'conflict' ? 'hidden' : '' ?>>
        <input type="checkbox" name="resolved" value="1" <?= !empty($entry['resolved']) ? 'checked' : '' ?>>
        <span><?= e(t('relationship.resolved')) ?></span>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('relationship.save')) ?></button></div>
</form>
