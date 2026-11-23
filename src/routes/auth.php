<?php
declare(strict_types=1);

/*
 * Connexion et déconnexion.
 */

/* ------------------------------------------------------------------ login */

// après la connexion : la page demandée avant (autorisation OAuth du connecteur MCP), sinon les discussions
$afterLogin = static function (): never {
    $next = (string) ($_SESSION['login_next'] ?? '');
    unset($_SESSION['login_next']);
    redirect(str_starts_with($next, '/oauth/authorize?') ? $next : '/chat');
};

if ($path === '/login') {
    if (is_logged()) {
        $afterLogin();
    }
    $error = '';
    if ($method === 'POST') {
        csrf_check();
        $wait = login_blocked_for();
        if ($wait > 0) {
            $error = t('login.blocked', (int) ceil($wait / 60));
        } elseif (attempt_login((string) ($_POST['password'] ?? ''))) {
            $afterLogin();
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
