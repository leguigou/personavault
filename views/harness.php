<?php
/** @var array $persona @var array|null $job @var int $runs @var bool $can_run */
$slug  = (string) $persona['slug'];
$token = csrf_token();
$state = (string) ($job['state'] ?? '');
$axes  = ['coherence' => 'harness.axe_coherence', 'voix' => 'harness.axe_voix',
          'limites' => 'harness.axe_limites', 'naturel' => 'harness.axe_naturel'];
// tables explicites : le vérificateur i18n ne sait pas lire une clé concaténée
$signalKeys = [
    'balise_brute' => 'harness.signal_balise_brute',
    'meta'         => 'harness.signal_meta',
    'refus_sec'    => 'harness.signal_refus_sec',
    'anglais'      => 'harness.signal_anglais',
    'fin_coupee'   => 'harness.signal_fin_coupee',
    'artefact'     => 'harness.signal_artefact',
];
$sondeKeys = [
    'salutation'  => 'harness.sonde_salutation',
    'hors_sujet'  => 'harness.sonde_hors_sujet',
    'meta'        => 'harness.sonde_meta',
    'provocation' => 'harness.sonde_provocation',
    'limites'     => 'harness.sonde_limites',
    'court'       => 'harness.sonde_court',
    'fuite'       => 'harness.sonde_fuite',
    'faux_souvenir' => 'harness.sonde_faux_souvenir',
    'fil_1'       => 'harness.sonde_fil_1',
    'fil_2'       => 'harness.sonde_fil_2',
    'fil_3'       => 'harness.sonde_fil_3',
];
?>
<a class="back" href="/p/<?= e($slug) ?>">
    <svg viewBox="0 0 24 24" class="ico"><path d="M14 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <?= e((string) $persona['name']), "\n" ?>
</a>

<section class="panel panel-wide" id="harness"
         data-harness
         data-status-url="/p/<?= e($slug) ?>/harness/status"
         data-token="<?= e($token) ?>"
         data-runs="<?= (int) $runs ?>"
         data-label-step="<?= e(t('harness.step_label')) ?>"
         data-label-starting="<?= e(t('harness.starting')) ?>"
         data-error="<?= e(t('harness.error_generic')) ?>">
    <header class="panel-head">
        <h2><?= e(t('harness.title', ['name' => (string) $persona['name']])) ?></h2>
    </header>

    <p class="hint"><?= e(t('harness.intro')) ?></p>

    <?php /* ---------- choix du modèle ---------- */ ?>
    <div class="harness-provider" role="radiogroup" aria-label="<?= e(t('harness.provider_label')) ?>">
        <?php foreach (['local' => 'harness.provider_local', 'remote' => 'harness.provider_remote'] as $key => $labelKey): ?>
            <?php $p = $providers[$key]; $isOn = $provider === $key; ?>
            <label class="harness-choice<?= $isOn ? ' is-on' : '' ?><?= $p['ready'] ? '' : ' is-off' ?>">
                <input type="radio" name="harness_provider" value="<?= e($key) ?>"
                       <?= $isOn ? 'checked' : '' ?> <?= $p['ready'] ? '' : 'disabled' ?>>
                <span class="harness-choice-body">
                    <strong><?= e(t($labelKey)) ?></strong>
                    <small><?= e($p['model'] !== '' ? $p['model'] : t('harness.provider_none')) ?></small>
                    <em><?= e($p['ready'] ? t('harness.provider_ready') : t('harness.provider_off')) ?></em>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <?php /* ---------- état initial / terminé / en cours ---------- */ ?>

    <div class="harness-state" data-harness-idle <?= ($state === 'running' || $state === 'done') ? 'hidden' : '' ?>>
        <button class="btn btn-primary" type="button" data-harness-run <?= $can_run ? '' : 'disabled' ?>>
            <?= e(t('harness.run')) ?>
        </button>
        <?php if (!$can_run): ?>
            <p class="hint"><?= e(t('harness.cannot_run')) ?></p>
        <?php endif; ?>
    </div>

    <div class="harness-state" data-harness-running <?= $state === 'running' ? '' : 'hidden' ?>>
        <p class="hint" data-harness-step><?= e(t('harness.starting')) ?></p>
        <div class="harness-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-harness-bar>
            <span style="width:0%"></span>
        </div>
    </div>

    <div class="harness-state" data-harness-error <?= $state === 'error' ? '' : 'hidden' ?>>
        <p class="hint is-error"><?= e((string) ($job['error'] ?? t('harness.error_generic'))) ?></p>
        <button class="btn btn-ghost" type="button" data-harness-run><?= e(t('harness.retry')) ?></button>
    </div>

    <?php if ($state === 'done'): ?>
        <?php $scores = (array) ($job['scores'] ?? []); $moyenne = $job['moyenne'] ?? null; ?>

        <div class="harness-scores">
            <?php foreach ($axes as $key => $label): ?>
                <?php $score = isset($scores[$key]) && $scores[$key] !== null ? (float) $scores[$key] : null; ?>
                <div class="harness-score">
                    <span class="harness-score-label"><?= e(t($label)) ?></span>
                    <?php if ($score === null): ?>
                        <span class="harness-score-value">—</span>
                    <?php else: ?>
                        <span class="harness-score-bar"><span style="width:<?= (int) round($score / 5 * 100) ?>%"></span></span>
                        <span class="harness-score-value"><?= e(number_format($score, 1, ',', '')) ?>/5</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($moyenne !== null): ?>
                <p class="harness-total"><?= e(t('harness.average', ['n' => number_format((float) $moyenne, 2, ',', '')])) ?>
                    <small><?= e(t('harness.runs_note', ['n' => (int) ($job['runs'] ?? $runs)])) ?></small></p>
            <?php endif; ?>
        </div>

        <?php /* ---------- audit du prompt ---------- */ ?>
        <?php $audit = (array) ($job['audit'] ?? []); ?>
        <?php if ($audit): ?>
            <div class="harness-block">
                <h3><?= e(t('harness.audit_title')) ?></h3>
                <?php if (trim((string) ($audit['omitted'] ?? '')) !== ''): ?>
                    <p class="harness-flag is-bad"><?= e(t('harness.audit_cut')) ?></p>
                    <p class="hint"><?= e(mb_substr(trim((string) $audit['omitted']), 0, 400)) ?></p>
                <?php else: ?>
                    <p class="harness-flag is-good"><?= e(t('harness.audit_ok', [
                        'need'  => (int) ($audit['need'] ?? 0),
                        'limit' => (int) ($audit['limit'] ?? 0),
                        'free'  => (int) ($audit['limit'] ?? 0) - (int) ($audit['need'] ?? 0),
                    ])) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php /* ---------- métriques dures ---------- */ ?>
        <?php $m = (array) ($job['metriques'] ?? []); ?>
        <div class="harness-block">
            <h3><?= e(t('harness.measures_title')) ?></h3>
            <p class="hint"><?= e(t('harness.measures_line', [
                'n'    => (int) ($m['reponses'] ?? 0),
                'total' => (int) ($m['total'] ?? 0),
                'mots' => (int) ($m['mots_moyen'] ?? 0),
            ])) ?></p>
            <?php if ((int) ($m['vides'] ?? 0) > 0): ?>
                <p class="harness-flag is-bad"><?= e(t('harness.empty_answers', ['n' => (int) $m['vides']])) ?></p>
            <?php endif; ?>
            <?php foreach ((array) ($m['signaux'] ?? []) as $sig => $where): ?>
                <?php if (isset($signalKeys[$sig])): ?>
                    <p class="harness-flag is-bad"><?= e(t($signalKeys[$sig], ['where' => implode(', ', array_unique((array) $where))])) ?></p>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php foreach ((array) ($m['formules'] ?? []) as $gram => $n): ?>
                <p class="harness-flag is-warn"><?= e(t('harness.formula', ['n' => (int) $n, 'gram' => (string) $gram])) ?></p>
            <?php endforeach; ?>
        </div>

        <?php /* ---------- verdict du juge ---------- */ ?>
        <?php $verdict = (array) ($job['juge'] ?? []); ?>
        <?php if (!empty($verdict['resume']) || !empty($verdict['problemes']) || !empty($verdict['corrections']) || !empty($verdict['faux_souvenir'])): ?>
            <div class="harness-block">
                <h3><?= e(t('harness.verdict_title')) ?></h3>
                <?php if (!empty($verdict['resume'])): ?>
                    <p class="harness-summary"><?= e((string) $verdict['resume']) ?></p>
                <?php endif; ?>
                <?php if (($verdict['faux_souvenir'] ?? '') === 'invente'): ?>
                    <p class="harness-flag is-bad">✗ <?= e(t('harness.false_memory_bad')) ?></p>
                <?php elseif (($verdict['faux_souvenir'] ?? '') === 'doute'): ?>
                    <p class="harness-flag is-good">✓ <?= e(t('harness.false_memory_ok')) ?></p>
                <?php endif; ?>
                <?php foreach ((array) ($verdict['problemes'] ?? []) as $p): ?>
                    <p class="harness-flag is-bad">✗ <?= e((string) $p) ?></p>
                <?php endforeach; ?>
                <?php foreach ((array) ($verdict['corrections'] ?? []) as $c): ?>
                    <p class="harness-flag is-tip">→ <?= e((string) $c) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php /* ---------- les réponses, sonde par sonde ---------- */ ?>
        <?php $sondes = (array) ($job['sondes'] ?? []); ?>
        <?php if ($sondes): ?>
            <div class="harness-block">
                <h3><?= e(t('harness.sondes_title')) ?></h3>
                <?php foreach ($sondes as $label => $s): ?>
                    <div class="harness-sonde">
                        <p class="harness-sonde-q">
                            <strong><?= e(isset($sondeKeys[(string) $label]) ? t($sondeKeys[(string) $label]) : (string) $label) ?></strong>
                            <span class="hint"><?= e((string) ($s['prompt'] ?? '')) ?></span>
                        </p>
                        <p class="harness-sonde-a"><?= nl2br(e((string) ($s['text'] ?? ''))) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="harness-actions">
            <button class="btn btn-ghost" type="button" data-harness-run><?= e(t('harness.relaunch')) ?></button>
            <a class="btn btn-ghost" href="/p/<?= e($slug) ?>/edit#ia"><?= e(t('harness.edit_directive')) ?></a>
        </div>
    <?php endif; ?>
</section>

<?php if ($state === 'running'): ?>
<script>
(() => {
    const box = document.getElementById('harness');
    if (!box) return;
    const bar  = box.querySelector('[data-harness-bar]');
    const step = box.querySelector('[data-harness-step]');
    const url  = box.dataset.statusUrl;
    let tries = 0;

    const poll = async () => {
        tries++;
        if (tries > 300) return; // ~15 min
        try {
            const r = await fetch(url, {headers: {'Accept': 'application/json'}, cache: 'no-store'});
            const j = await r.json();
            const job = j.job || {};
            if (job.state === 'running') {
                const total = Math.max(1, job.total || 0);
                const pct = job.total ? Math.min(99, Math.round(job.done / total * 100)) : 0;
                if (bar) { bar.querySelector('span').style.width = pct + '%'; bar.setAttribute('aria-valuenow', pct); }
                if (step) step.textContent = box.dataset.labelStep + ' ' + (job.step || '');
            } else if (job.state === 'done' || job.state === 'error') {
                location.reload();
                return;
            }
        } catch (e) { /* on retente */ }
        setTimeout(poll, 2000);
    };
    setTimeout(poll, 1500);
})();
</script>
<?php endif; ?>

<?php if ($state !== 'running'): ?>
<script>
(() => {
    const box = document.getElementById('harness');
    if (!box) return;
    const buttons = box.querySelectorAll('[data-harness-run]');
    buttons.forEach(btn => btn.addEventListener('click', async () => {
        buttons.forEach(b => b.disabled = true);
        const idle = box.querySelector('[data-harness-idle]');
        const run  = box.querySelector('[data-harness-running]');
        const err  = box.querySelector('[data-harness-error]');
        if (idle) idle.hidden = true;
        if (err) err.hidden = true;
        if (run) run.hidden = false;
        try {
            const sel = box.querySelector('input[name="harness_provider"]:checked');
            const prov = sel ? sel.value : 'local';
            const body = '_token=' + encodeURIComponent(box.dataset.token)
                + '&runs=' + encodeURIComponent(box.dataset.runs)
                + '&provider=' + encodeURIComponent(prov);
            const r = await fetch('/p/' + <?= json_encode($slug, JSON_UNESCAPED_UNICODE) ?> + '/harness', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
                body
            });
            const j = await r.json();
            if (!j.ok) {
                if (run) run.hidden = true;
                if (err) { err.hidden = false; err.querySelector('.hint').textContent = j.error || box.dataset.error; }
                buttons.forEach(b => b.disabled = false);
                return;
            }
            location.reload();
        } catch (e) {
            if (run) run.hidden = true;
            if (err) { err.hidden = false; err.querySelector('.hint').textContent = box.dataset.error; }
            buttons.forEach(b => b.disabled = false);
        }
    }));
})();
</script>
<?php endif; ?>
