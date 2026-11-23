<?php
/**
 * Autorisation OAuth du connecteur MCP (src/routes/oauth.php) : un assistant extérieur (ChatGPT)
 * demande l'accès. $problem : demande refusée d'office ; sinon $fields : paramètres à renvoyer.
 */
$host = $fields ? (string) (parse_url((string) $fields['redirect_uri'], PHP_URL_HOST) ?: '') : '';
?>
<section class="login-wrap">
    <div class="login-card oauth-card">
        <div class="login-brand">
            <span class="brand-mark brand-mark-lg" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><path d="M12 3.2 14.9 9l6.4.9-4.7 4.5 1.1 6.4L12 17.8 6.3 20.8l1.1-6.4L2.7 9.9 9.1 9 12 3.2Z" fill="url(#g3)"/><defs><linearGradient id="g3" x1="2" y1="2" x2="22" y2="22"><stop stop-color="#a78bfa"/><stop offset="1" stop-color="#f472b6"/></linearGradient></defs></svg>
            </span>
            <h1><?= e(t('oauth.title')) ?></h1>
        </div>

        <?php if ($problem !== ''): ?>
            <div class="flash flash-error"><span><?= e($problem) ?></span></div>
            <a class="btn btn-ghost btn-block" href="/settings"><?= e(t('oauth.to_settings')) ?></a>
        <?php else: ?>
            <p class="oauth-lead"><?= e(t('oauth.lead', ['host' => $host])) ?></p>
            <ul class="oauth-list">
                <li><?= e(t('oauth.can.personas')) ?></li>
                <li><?= e(t('oauth.can.read')) ?></li>
                <li><?= e(t('oauth.can.write')) ?></li>
            </ul>
            <p class="hint"><?= e(t('oauth.privacy')) ?></p>
            <form method="post" action="/oauth/authorize" class="oauth-actions">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <?php foreach ($fields as $name => $value): ?>
                    <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
                <?php endforeach; ?>
                <button class="btn btn-ghost" type="submit" name="decision" value="deny"><?= e(t('oauth.deny')) ?></button>
                <button class="btn btn-primary" type="submit" name="decision" value="allow"><?= e(t('oauth.allow')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
