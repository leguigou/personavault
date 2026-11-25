<?php
/** @var array $persona @var array|null $job @var array $runs @var array|null $run @var bool $ready @var bool $can_run */
$slug    = (string) $persona['slug'];
$token   = csrf_token();
$running = psy_job_running($job);
$failed  = !$running && ($job['state'] ?? '') === 'error';
$labels  = psy_trait_labels();
$steps   = [
    'demarrage'  => t('psy.step.start'),
    'attendu'    => t('psy.step.expected'),
    'questions'  => t('psy.step.questions'),
    'observe'    => t('psy.step.observed'),
    'conclusion' => t('psy.step.conclusion'),
];
?>
<a class="back" href="/p/<?= e(rawurlencode($slug)) ?>">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e((string) $persona['name']), "\n" ?>
</a>

<section class="panel panel-wide psy" id="psy"
         data-psy
         data-status-url="/p/<?= e(rawurlencode($slug)) ?>/psy/status"
         data-run-url="/p/<?= e(rawurlencode($slug)) ?>/psy"
         data-token="<?= e($token) ?>"
         data-steps="<?= e(json_encode($steps, JSON_UNESCAPED_UNICODE)) ?>"
         data-answer-label="<?= e(t('psy.step.answer')) ?>"
         data-error="<?= e(t('psy.error_generic')) ?>">
    <header class="panel-head">
        <h2><?= e(t('psy.title', ['name' => (string) $persona['name']])) ?></h2>
    </header>
    <p class="hint"><?= e(t('psy.intro')) ?></p>

    <div class="psy-state" data-psy-idle <?= $running ? 'hidden' : '' ?>>
        <?php if ($failed): ?>
            <p class="hint is-error" data-psy-error><?= e((string) ($job['error'] ?? t('psy.error_generic'))) ?></p>
        <?php else: ?>
            <p class="hint is-error" data-psy-error hidden></p>
        <?php endif; ?>
        <button class="btn btn-primary" type="button" data-psy-run <?= $ready && $can_run ? '' : 'disabled' ?>>
            <?= e($run ? t('psy.relaunch') : t('psy.run')) ?>
        </button>
        <p class="hint"><?= e(!$ready ? t('psy.not_ready') : (!$can_run ? t('psy.no_spawn') : t('psy.run_hint'))) ?></p>
    </div>

    <div class="psy-state" data-psy-running <?= $running ? '' : 'hidden' ?>>
        <p class="hint" data-psy-step><?= e(t('psy.step.start')) ?></p>
        <div class="harness-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-psy-bar><span style="width:0%"></span></div>
        <p class="hint"><?= e(t('psy.leave_hint')) ?></p>
    </div>
</section>

<?php if ($run): ?>
    <section class="panel panel-wide psy-result">
        <header class="panel-head psy-head">
            <div>
                <h2><?= e(t('psy.result_title')) ?></h2>
                <p class="hint"><?= e(t('psy.result_meta', ['date' => fr_date((string) $run['created_at']), 'local' => (string) $run['local_model'], 'remote' => (string) $run['remote_model']])) ?></p>
            </div>
            <div class="psy-fidelity" title="<?= e(t('psy.fidelity_hint')) ?>">
                <strong><?= (int) $run['fidelity'] ?></strong><small>/100</small>
                <span><?= e(t('psy.fidelity')) ?></span>
            </div>
        </header>

        <figure class="psy-figure">
            <?= psy_radar_svg($run['expected'], $run['observed']) ?>
            <figcaption class="psy-legend">
                <span class="psy-key psy-key-expected"><?= e(t('psy.expected')) ?></span>
                <span class="psy-key psy-key-observed"><?= e(t('psy.observed')) ?></span>
            </figcaption>
        </figure>

        <?php if ((string) ($run['conclusion'] ?? '') !== ''): ?>
            <p class="psy-conclusion"><?= e((string) $run['conclusion']) ?></p>
        <?php endif; ?>
        <?php foreach ((array) ($run['corrections'] ?? []) as $c): ?>
            <p class="harness-flag is-tip">→ <?= e((string) $c) ?></p>
        <?php endforeach; ?>

        <div class="harness-block">
            <h3><?= e(t('psy.traits_title')) ?></h3>
            <div class="psy-table" role="table">
                <div class="psy-row psy-row-head" role="row">
                    <span role="columnheader"><?= e(t('psy.col_trait')) ?></span>
                    <span role="columnheader"><?= e(t('psy.col_expected')) ?></span>
                    <span role="columnheader"><?= e(t('psy.col_observed')) ?></span>
                    <span role="columnheader"><?= e(t('psy.col_gap')) ?></span>
                </div>
                <?php foreach (PSY_TRAITS as $k): ?>
                    <?php $gap = (int) ($run['gaps'][$k] ?? 0); ?>
                    <details class="psy-row-wrap">
                        <summary class="psy-row" role="row">
                            <span role="cell"><?= e($labels[$k]) ?></span>
                            <span role="cell"><?= (int) $run['expected'][$k]['score'] ?></span>
                            <span role="cell"><?= (int) $run['observed'][$k]['score'] ?></span>
                            <span role="cell" class="psy-gap<?= abs($gap) > 15 ? ' is-big' : '' ?>"><?= e(sprintf('%+d', $gap)) ?></span>
                        </summary>
                        <p class="hint"><strong><?= e(t('psy.why_expected')) ?></strong> <?= e((string) $run['expected'][$k]['raison']) ?></p>
                        <p class="hint"><strong><?= e(t('psy.why_observed')) ?></strong> <?= e((string) $run['observed'][$k]['raison']) ?></p>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>

        <details class="harness-block psy-qa">
            <summary><h3><?= e(t('psy.qa_title', ['n' => count((array) $run['qa'])])) ?></h3></summary>
            <?php foreach ((array) $run['qa'] as $i => $x): ?>
                <div class="harness-sonde">
                    <p class="harness-sonde-q"><strong><?= ($i + 1) . '. ' . e($labels[$x['trait']] ?? (string) $x['trait']) ?></strong>
                        <span class="hint"><?= e((string) $x['message']) ?></span></p>
                    <p class="harness-sonde-a"><?= $x['answer'] !== '' ? nl2br(e((string) $x['answer'])) : '<em class="hint">' . e(t('psy.no_reply')) . '</em>' ?></p>
                </div>
            <?php endforeach; ?>
        </details>
    </section>
<?php endif; ?>

<?php if (count($runs) > 1): ?>
    <section class="panel panel-wide">
        <h3><?= e(t('psy.history')) ?></h3>
        <ul class="psy-history">
            <?php foreach ($runs as $r): ?>
                <li<?= $run && (int) $r['id'] === (int) $run['id'] ? ' class="is-on"' : '' ?>>
                    <a href="/p/<?= e(rawurlencode($slug)) ?>/psy?run=<?= (int) $r['id'] ?>">
                        <span><?= e(fr_date((string) $r['created_at'])) ?> · <?= e((string) $r['local_model']) ?></span>
                        <strong><?= (int) $r['fidelity'] ?>/100</strong>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php endif; ?>

<script>
(() => {
    const box = document.getElementById('psy');
    if (!box) return;
    const idle = box.querySelector('[data-psy-idle]');
    const running = box.querySelector('[data-psy-running]');
    const bar = box.querySelector('[data-psy-bar]');
    const step = box.querySelector('[data-psy-step]');
    const err = box.querySelector('[data-psy-error]');
    const steps = JSON.parse(box.dataset.steps || '{}');
    const label = (s) => steps[s] || (String(s).startsWith('reponse ') ? box.dataset.answerLabel + ' ' + String(s).slice(8) : s);
    // suivi : la tâche tourne sur le serveur, on peut quitter la page et revenir
    const poll = async () => {
        try {
            const r = await fetch(box.dataset.statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            const job = (await r.json()).job || {};
            if (job.state === 'running') {
                const pct = job.total ? Math.min(99, Math.round(job.done / job.total * 100)) : 0;
                bar.querySelector('span').style.width = pct + '%';
                bar.setAttribute('aria-valuenow', pct);
                step.textContent = label(job.step || 'demarrage') + (job.total ? ' (' + job.done + '/' + job.total + ')' : '');
            } else {
                location.reload();
                return;
            }
        } catch (e) { /* on retente */ }
        setTimeout(poll, 2500);
    };
    if (!running.hidden) setTimeout(poll, 1000);
    const btn = box.querySelector('[data-psy-run]');
    btn?.addEventListener('click', async () => {
        btn.disabled = true;
        err.hidden = true;
        try {
            const r = await fetch(box.dataset.runUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                body: '_token=' + encodeURIComponent(box.dataset.token),
            });
            const j = await r.json();
            if (!j.ok) throw new Error(j.error || box.dataset.error);
            idle.hidden = true;
            running.hidden = false;
            setTimeout(poll, 1000);
        } catch (e) {
            err.textContent = e.message || box.dataset.error;
            err.hidden = false;
            btn.disabled = false;
        }
    });
})();
</script>
