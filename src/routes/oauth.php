<?php
declare(strict_types=1);

/*
 * OAuth du connecteur MCP (src/mcp_oauth.php). Inclus par public/index.php AVANT require_login() :
 * les métadonnées et le point de jeton sont publics (protégés par le secret du client), la page
 * d'autorisation demande elle-même la connexion.
 */

$oauthJson = static function (int $status, array $body): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

/* --- découverte : ressource protégée (/mcp) et autorité --- */
if ($method === 'GET' && in_array($path, ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'], true)) {
    mcp_enabled() && mcp_oauth_ready() ? $oauthJson(200, mcp_oauth_resource_metadata()) : $oauthJson(404, ['error' => 'not_found']);
}
if ($method === 'GET' && in_array($path, ['/.well-known/oauth-authorization-server', '/.well-known/oauth-authorization-server/mcp',
    '/.well-known/openid-configuration'], true)) {
    mcp_enabled() && mcp_oauth_ready() ? $oauthJson(200, mcp_oauth_server_metadata()) : $oauthJson(404, ['error' => 'not_found']);
}

/* --- point de jeton : code → jeton, renouvellement --- */
if ($path === '/oauth/token') {
    if ($method !== 'POST') {
        header('Allow: POST');
        $oauthJson(405, ['error' => 'invalid_request']);
    }
    $basic = null;
    if (preg_match('/^Basic\s+(\S+)$/i', mcp_authorization_header(), $m)) {
        $pair = explode(':', (string) base64_decode($m[1], true), 2);
        $basic = [urldecode($pair[0]), urldecode($pair[1] ?? '')];
    }
    [$status, $body] = mcp_oauth_token_response($_POST, $basic);
    if ($status === 401) {
        header('WWW-Authenticate: Basic realm="PersonaVault"');
    }
    $oauthJson($status, $body);
}

/* --- page d'autorisation : « Autoriser ChatGPT à accéder à PersonaVault ? » --- */
if ($path === '/oauth/authorize') {
    $q = $method === 'POST' ? $_POST : $_GET;
    $fields = array_intersect_key($q, array_flip(['response_type', 'client_id', 'redirect_uri', 'state', 'code_challenge',
        'code_challenge_method', 'scope', 'resource']));
    $fields = array_map(static fn ($v) => is_string($v) ? mb_substr($v, 0, 2000) : '', $fields);
    $problem = mcp_oauth_check_request($fields);
    if ($problem !== '') {
        // client ou URL de rappel douteux : on affiche, on ne redirige jamais
        http_response_code(400);
        render('oauth_consent', ['title' => t('oauth.title'), 'problem' => $problem, 'fields' => []]);
        exit;
    }
    $back = static function (array $params) use ($fields): never {
        header('Location: ' . mcp_oauth_redirect_url($fields['redirect_uri'], $params + ['state' => $fields['state'] ?? '']), true, 302);
        exit;
    };
    $error = mcp_oauth_request_error($fields);
    if ($error !== '') {
        $back(['error' => $error]);
    }
    if (!is_logged()) {
        // après la connexion, retour sur cette page (src/routes/auth.php)
        $_SESSION['login_next'] = '/oauth/authorize?' . http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        redirect('/login');
    }
    if ($method === 'POST') {
        csrf_check();
        if (($_POST['decision'] ?? '') !== 'allow') {
            $back(['error' => 'access_denied']);
        }
        $back(['code' => mcp_oauth_issue_code($fields['redirect_uri'], $fields['code_challenge'] ?? '', $fields['scope'] ?? '')]);
    }
    render('oauth_consent', ['title' => t('oauth.title'), 'problem' => '', 'fields' => $fields]);
    exit;
}
