<section class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <span class="brand-mark brand-mark-lg" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none"><path d="M12 3.2 14.9 9l6.4.9-4.7 4.5 1.1 6.4L12 17.8 6.3 20.8l1.1-6.4L2.7 9.9 9.1 9 12 3.2Z" fill="url(#g2)"/><defs><linearGradient id="g2" x1="2" y1="2" x2="22" y2="22"><stop stop-color="#a78bfa"/><stop offset="1" stop-color="#f472b6"/></linearGradient></defs></svg>
            </span>
            <h1>Persona<em>Vault</em></h1>
            <p>Ta bibliothèque de personas, photos et prompts.</p>
        </div>

        <?php if ((string) config('password_hash') === ''): ?>
            <div class="flash flash-error"><span>Aucun mot de passe configuré : copie <code>config.local.example.php</code> en <code>config.local.php</code> et renseigne ton hash.</span></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="flash flash-error"><span><?= e($error) ?></span></div>
        <?php endif; ?>

        <form method="post" action="/login" class="login-form">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <label class="field">
                <span>Mot de passe</span>
                <input class="input" type="password" name="password" autocomplete="current-password" autofocus required>
            </label>
            <button class="btn btn-primary btn-block" type="submit">Entrer</button>
        </form>
    </div>
</section>
