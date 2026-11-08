<?php
declare(strict_types=1);

/*
 * Connexion et déconnexion.
 */

/* ------------------------------------------------------------------ login */

if ($path === '/login') {
    if (is_logged()) {
        redirect('/');
    }
    $error = '';
    if ($method === 'POST') {
        csrf_check();
        $wait = login_blocked_for();
        if ($wait > 0) {
            $error = t('login.blocked', (int) ceil($wait / 60));
        } elseif (attempt_login((string) ($_POST['password'] ?? ''))) {
            redirect('/');
        } else {
            $error = t('login.wrong');
            $wait  = login_blocked_for();
            if ($wait > 0) {
                $error .= ' ' . t('login.now_blocked', (int) ceil($wait / 60));
            }
        }
    }
    render('login', ['title' => t('login.title'), 'error' => $error]);
    exit;
}

if ($path === '/logout' && $method === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    redirect('/login');
}
