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
            $error = 'Trop de tentatives. Réessaie dans ' . ceil($wait / 60) . ' min.';
        } elseif (attempt_login((string) ($_POST['password'] ?? ''))) {
            redirect('/');
        } else {
            $error = 'Mot de passe incorrect.';
            $wait  = login_blocked_for();
            if ($wait > 0) {
                $error .= ' Trop de tentatives : réessaie dans ' . ceil($wait / 60) . ' min.';
            }
        }
    }
    render('login', ['title' => 'Connexion', 'error' => $error]);
    exit;
}

if ($path === '/logout' && $method === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
    redirect('/login');
}
