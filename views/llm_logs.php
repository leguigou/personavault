<?php
/** @var array $rows */
/*
 * Journal des échanges avec les modèles : un bloc par appel, du plus récent au plus ancien.
 * « Envoyé » = ce qui part au modèle (réglages + messages) ; « Reçu » = sa réponse ou l'erreur.
 */
$purposes = llm_log_purposes();
$token    = csrf_token();
$roleLabel = static fn (string $r): string => match ($r) {
    'system'    => t('logs.role.system'),
    'user'      => t('logs.role.user'),
    'assistant' => t('logs.role.assistant'),
    default     => $r,
};
?>
<a class="back" href="/settings#chat">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e(t('nav.settings')), "\n" ?>
</a>

<section class="lib-head">
    <div class="lib-title">
        <p class="eyebrow"><?= e(t('logs.eyebrow')) ?></p>
        <h1><?= e(t('logs.title')) ?> <span class="count"><?= count($rows) ?></span></h1>
    </div>
    <?php if ($rows): ?>
        <form method="post" action="/settings/logs/clear" data-confirm="<?= e(t('logs.clear_confirm')) ?>">
            <input type="hidden" name="_token" value="<?= e($token) ?>">
            <button class="btn btn-ghost btn-sm" type="submit"><?= e(t('logs.clear')) ?></button>
        </form>
    <?php endif; ?>
</section>

<p class="hint logs-intro"><?= e(t('logs.intro')) ?></p>

<?php if (!$rows): ?>
    <div class="empty-state logs-empty">
        <p><?= e(t('logs.empty')) ?></p>
    </div>
<?php endif; ?>

<div class="logs">
    <?php foreach ($rows as $i => $r): ?>
        <?php
        $d       = local_dt((string) $r['created_at']);
        $status  = (string) $r['status'];
        $msgs    = (array) $r['request'];
        $params  = (array) $r['params'];
        $paramTxt = [];
        foreach (['temperature', 'max_tokens', 'reasoning_effort', 'response_format', 'stream'] as $k) {
            if (array_key_exists($k, $params)) {
                $v = $params[$k];
                $paramTxt[] = $k . ' = ' . (is_array($v) ? json_encode($v) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
            }
        }
        // texte brut de l'échange (bouton copier)
        $raw = '# ' . ($d ? $d->format('Y-m-d H:i:s') : '') . ' · ' . ($purposes[$r['purpose']] ?? $r['purpose']) . ' · ' . $r['model'] . ' @ ' . $r['endpoint'] . "\n"
            . implode(', ', $paramTxt) . "\n\n## " . t('logs.sent') . "\n";
        foreach ($msgs as $m) {
            $raw .= "\n[" . $m['role'] . "]\n" . $m['content'] . "\n";
        }
        $raw .= "\n## " . t('logs.received') . "\n" . ($r['error'] !== '' ? 'ERREUR : ' . $r['error'] . "\n" : '') . $r['response'];
        ?>
        <details class="log log-<?= e($status) ?>"<?= $i === 0 ? ' open' : '' ?>>
            <summary class="log-head">
                <span class="log-dot" aria-hidden="true"></span>
                <span class="log-purpose"><?= e($purposes[$r['purpose']] ?? (string) $r['purpose']) ?></span>
                <span class="log-model"><?= e((string) $r['model']) ?></span>
                <span class="log-facts">
                    <time datetime="<?= e($d ? $d->format(DATE_ATOM) : '') ?>" title="<?= e($d ? $d->format('Y-m-d H:i:s') : '') ?>"><?= e(rel_time((string) $r['created_at'])) ?></time>
                    · <?= e(t('logs.ms', ['ms' => number_format((int) $r['ms'] / 1000, 1, ',', ' ')])) ?>
                    <?php if ((int) $r['prompt_tokens'] || (int) $r['completion_tokens']): ?>
                        · <span title="<?= e(t('logs.tokens_title')) ?>">↑<?= (int) $r['prompt_tokens'] ?> ↓<?= (int) $r['completion_tokens'] ?></span>
                    <?php endif; ?>
                    <?php if ($status !== 'ok'): ?>
                        · <strong class="log-status"><?= e($status === 'aborted' ? t('logs.status.aborted') : t('logs.status.error')) ?><?= (int) $r['http_code'] >= 300 ? ' ' . (int) $r['http_code'] : '' ?></strong>
                    <?php endif; ?>
                </span>
            </summary>

            <div class="log-body">
                <p class="log-meta">
                    <?= e((string) $r['endpoint']) ?>
                    <?php if (!empty($r['conv_title'])): ?>
                        · <a href="/chat/<?= (int) $r['conversation_id'] ?>"><?= e((string) $r['conv_title']) ?></a>
                    <?php endif; ?>
                    <?php if ($paramTxt): ?>
                        <br><code><?= e(implode(' · ', $paramTxt)) ?></code>
                    <?php endif; ?>
                </p>

                <section class="log-part log-out">
                    <h3><span aria-hidden="true">↑</span> <?= e(t('logs.sent')) ?> <span class="count"><?= count($msgs) ?></span></h3>
                    <?php foreach ($msgs as $j => $m): ?>
                        <?php
                        $len  = mb_strlen((string) $m['content']);
                        $last = $j === count($msgs) - 1;
                        // consignes longues repliées ; le dernier message (celui qui déclenche la réponse) ouvert
                        $fold = !$last && ($m['role'] === 'system' || $len > 600);
                        ?>
                        <details class="log-msg role-<?= e((string) $m['role']) ?>"<?= $fold ? '' : ' open' ?>>
                            <summary>
                                <span class="log-role"><?= e($roleLabel((string) $m['role'])) ?></span>
                                <span class="log-len"><?= e(t('logs.chars', $len)) ?></span>
                                <?php if ($fold): ?><span class="log-peek"><?= e(mb_substr(preg_replace('/\s+/u', ' ', (string) $m['content']) ?? '', 0, 90)) ?>…</span><?php endif; ?>
                            </summary>
                            <pre><?= e((string) $m['content']) ?></pre>
                        </details>
                    <?php endforeach; ?>
                </section>

                <section class="log-part log-in">
                    <h3><span aria-hidden="true">↓</span> <?= e(t('logs.received')) ?></h3>
                    <?php if ((string) $r['error'] !== ''): ?>
                        <pre class="log-error"><?= e((string) $r['error']) ?></pre>
                    <?php endif; ?>
                    <?php if ((string) $r['response'] !== ''): ?>
                        <pre class="log-reply"><?= e((string) $r['response']) ?></pre>
                    <?php elseif ((string) $r['error'] === ''): ?>
                        <p class="hint"><?= e(t('logs.no_reply')) ?></p>
                    <?php endif; ?>
                </section>

                <div class="log-tools">
                    <textarea class="sr-only" id="log-raw-<?= (int) $r['id'] ?>" readonly tabindex="-1" aria-hidden="true"><?= e($raw) ?></textarea>
                    <button type="button" class="btn btn-ghost btn-sm" data-copy="#log-raw-<?= (int) $r['id'] ?>"><?= e(t('logs.copy')) ?></button>
                </div>
            </div>
        </details>
    <?php endforeach; ?>
</div>
