<?php
/** @var array $rows */
/*
 * Journal du connecteur MCP : chaque appel d'outil par l'assistant (ChatGPT…), du plus récent au plus ancien.
 */
$token = csrf_token();
$titles = array_map(static fn (array $tool): string => (string) $tool['annotations']['title'], mcp_tools());
?>
<a class="back" href="/settings#ia">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('nav.settings')), "\n" ?>
</a>

<section class="lib-head">
    <div class="lib-title">
        <p class="eyebrow"><?= e(t('settings.mcp.title')) ?></p>
        <h1><?= e(t('mcplog.title')) ?> <span class="count"><?= count($rows) ?></span></h1>
    </div>
    <?php if ($rows): ?>
        <form method="post" action="/settings/mcp-log/clear" data-confirm="<?= e(t('mcplog.clear_confirm')) ?>">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('mcplog.clear')) ?></button>
        </form>
    <?php endif; ?>
</section>

<p class="hint logs-intro"><?= e(t('mcplog.intro')) ?></p>

<?php if (!$rows): ?>
    <div class="empty-state logs-empty"><p><?= e(t('mcplog.empty')) ?></p></div>
<?php endif; ?>

<div class="logs">
    <?php foreach ($rows as $r): ?>
        <?php
        $d = local_dt((string) $r['at']);
        $ok = (int) $r['ok'] === 1;
        $readOnly = !empty(mcp_tools()[(string) $r['tool']]['annotations']['readOnlyHint']);
        ?>
        <details class="log <?= $ok ? 'log-ok' : 'log-error' ?>">
            <summary class="log-head">
                <span class="log-dot" aria-hidden="true"></span>
                <span class="log-purpose"><?= e($readOnly ? t('mcplog.read') : t('mcplog.write')) ?></span>
                <span class="log-model"><?= e($titles[(string) $r['tool']] ?? (string) $r['tool']) ?></span>
                <span class="log-facts">
                    <time datetime="<?= e($d ? $d->format(DATE_ATOM) : '') ?>" title="<?= e($d ? $d->format('Y-m-d H:i:s') : '') ?>"><?= e(rel_time((string) $r['at'])) ?></time>
                    <?php if ((string) $r['target'] !== ''): ?> · <?= e((string) $r['target']) ?><?php endif; ?>
                    <?php if (!$ok): ?> · <strong class="log-status"><?= e(t('mcplog.failed')) ?></strong><?php endif; ?>
                </span>
            </summary>
            <div class="log-body">
                <p class="log-meta mcp-log-args"><code><?= e((string) $r['tool']) ?></code> · <code><?= e((string) $r['args']) ?></code></p>
                <pre class="mcp-log-result <?= $ok ? 'log-reply' : 'log-error' ?>"><?= e((string) $r['result']) ?></pre>
            </div>
        </details>
    <?php endforeach; ?>
</div>
