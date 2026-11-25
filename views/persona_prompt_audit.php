<?php
$profileUrl = '/p/' . rawurlencode($persona['slug']);
$kinds = prompt_kinds();
?>
<a class="back" href="<?= e($profileUrl) ?>">
    <svg viewBox="0 0 24 24" class="ico" aria-hidden="true"><path d="m14 6-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e($persona['name']) ?>
</a>
<section class="lib-head">
    <div class="lib-title"><h1><?= e(t('persona.audit.title')) ?></h1></div>
</section>

<form class="settings-card prompt-audit-panel" method="post" action="<?= e($profileUrl) ?>/prompt-audit">
    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
    <label class="field">
        <span><?= e(t('persona.audit.conversation')) ?></span>
        <select class="input" name="conversation_id">
            <option value="0"><?= e(t('persona.audit.new')) ?></option>
            <?php foreach ($conversations as $thread): ?>
                <option value="<?= (int) $thread['id'] ?>" <?= ($selected['id'] ?? null) === $thread['id'] ? 'selected' : '' ?>><?= e($thread['title']) ?> (#<?= (int) $thread['id'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span><?= e(t('persona.audit.message')) ?></span>
        <textarea class="input" name="message" rows="2"><?= e($message) ?></textarea>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('persona.audit.run')) ?></button></div>
</form>

<section class="settings-card prompt-audit-panel" aria-labelledby="audit-budget">
    <h2 id="audit-budget"><?= e(t('persona.audit.budget')) ?></h2>
    <p class="hint"><?= e(t('persona.audit.budget_help')) ?></p>
    <dl class="prompt-audit-metrics">
        <div><dt><?= e(t('persona.audit.limit')) ?></dt><dd><?= (int) $audit['limit'] ?></dd></div>
        <div><dt><?= e(t('persona.audit.need')) ?></dt><dd><?= (int) $audit['need'] ?></dd></div>
        <div><dt><?= e(t('persona.audit.margin')) ?></dt><dd><?= sprintf('%+d', $audit['margin']) ?></dd></div>
    </dl>
    <?php $fix = $audit['suggestion']; ?>
    <?php if ($fix === null): ?>
        <p class="prompt-audit-ok"><?= e(t('persona.audit.fits', ['margin' => fmt_number($audit['margin'])])) ?></p>
    <?php elseif ($fix['window'] === null): ?>
        <p class="prompt-audit-cut"><?= e(t('persona.audit.cut', ['missing' => fmt_number($fix['shortfall']), 'maximum' => fmt_number(131072)])) ?></p>
        <p class="hint"><?= e(t('persona.audit.window_impossible')) ?></p>
    <?php else: ?>
        <p class="prompt-audit-cut"><?= e(t('persona.audit.cut', ['missing' => fmt_number($fix['shortfall']), 'maximum' => fmt_number(131072)])) ?></p>
        <form class="prompt-audit-fix" method="post" action="<?= e($profileUrl) ?>/prompt-audit"
              data-confirm="<?= e(t('persona.audit.window_confirm', ['configured' => fmt_number($fix['configured']), 'window' => fmt_number($fix['window'])])) ?>">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="apply_window" value="<?= (int) $fix['window'] ?>">
            <button class="btn btn-primary" type="submit"><?= e(t('persona.audit.window_apply', ['window' => fmt_number($fix['window'])])) ?></button>
            <span class="hint"><?= e(t('persona.audit.window_min', ['minimum' => fmt_number((int) $fix['minimum'])])) ?></span>
        </form>
        <?php if ($fix['reload']): ?>
            <p class="hint"><?= e(t('persona.audit.window_reload')) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <p class="hint"><?= e(t('persona.audit.window', ['configured' => $audit['configuredWindow'], 'effective' => $audit['effectiveWindow']])) ?>
        <?= e($audit['loadedWindow'] > 0 ? t('persona.audit.loaded', ['n' => $audit['loadedWindow']]) : t('persona.audit.unverified')) ?>
        <?php if ($audit['model'] !== ''): ?><code><?= e($audit['model']) ?></code><?php endif; ?>
    </p>
    <p class="hint"><?= e(t('persona.audit.scope')) ?></p>
</section>

<section class="settings-card prompt-audit-panel" aria-labelledby="audit-paragraphs">
    <h2 id="audit-paragraphs"><?= e(t('persona.audit.paragraphs')) ?></h2>
    <?php if (!$audit['rows']): ?>
        <p class="hint"><?= e(t('persona.audit.no_system')) ?></p>
    <?php endif; ?>
    <?php foreach ($audit['rows'] as $row): ?>
        <article class="prompt">
            <header class="prompt-head">
                <strong><?= e($row['title'] ?: $kinds['system']) ?></strong>
                <span class="hint"><?= e(t('persona.audit.kept', ['kept' => $row['count'] - count($row['omissions']), 'total' => $row['count']])) ?></span>
            </header>
            <?php foreach ($row['omissions'] as $omission): ?>
                <details class="prompt-audit-omission">
                    <summary><?= e($omission['partial']
                        ? t('persona.audit.partial', ['n' => $omission['number']])
                        : t('persona.audit.omitted', ['n' => $omission['number']])) ?></summary>
                    <pre class="prompt-body"><?= e($omission['text']) ?></pre>
                </details>
            <?php endforeach; ?>
        </article>
    <?php endforeach; ?>
    <?php if ($audit['otherPrompts']): ?>
        <h3><?= e(t('persona.audit.other')) ?></h3>
        <ul>
            <?php foreach ($audit['otherPrompts'] as $prompt): ?>
                <li><strong><?= e($prompt['title'] ?: ($kinds[$prompt['kind']] ?? $kinds['other'])) ?></strong>
                    <?= e($prompt['kind'] === 'planning' ? t('persona.audit.planning') : t('persona.audit.not_chat')) ?></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
    <details class="prompt">
        <summary class="prompt-audit-omission"><?= e(t('persona.audit.preview')) ?></summary>
        <pre class="prompt-body"><?= e($audit['system']) ?></pre>
    </details>
</section>
