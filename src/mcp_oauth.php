<?php
declare(strict_types=1);

/*
 * OAuth 2.1 pour le connecteur MCP (ChatGPT exige OAuth pour un serveur MCP personnalisé).
 * Un seul client, enregistré dans Réglages › IA › Connecteur MCP (ID client + secret) :
 *   GET  /.well-known/oauth-protected-resource      quelle autorité protège /mcp (RFC 9728)
 *   GET  /.well-known/oauth-authorization-server    adresses d'autorisation et de jeton (RFC 8414)
 *   GET  /oauth/authorize                           page « Autoriser ChatGPT ? » (connexion exigée)
 *   POST /oauth/token                               code → jeton d'accès, renouvellement (PKCE S256)
 * Codes et jetons ne sont gardés qu'en empreinte SHA-256 ; le secret du client aussi.
 */

const MCP_OAUTH_SCOPE = 'personavault';
const MCP_OAUTH_CODE_TTL = 600;            // 10 min pour échanger le code
const MCP_OAUTH_ACCESS_TTL = 3600;         // jeton d'accès : 1 h
const MCP_OAUTH_REFRESH_TTL = 90 * 86400;  // jeton de renouvellement : 90 jours (renouvelé à chaque usage)

/** Adresse publique de l'app (config « public_url », sinon déduite de la requête). */
function mcp_public_base(): string
{
    $configured = rtrim(trim((string) config('public_url', '')), '/');
    if ($configured !== '') {
        return $configured;
    }
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return (request_is_https() ? 'https' : 'http') . '://' . $host;
}

function mcp_oauth_client_id(): string
{
    return setting('mcp_oauth_client_id', '');
}

function mcp_oauth_ready(): bool
{
    return mcp_oauth_client_id() !== '' && setting('mcp_oauth_secret_hash', '') !== '';
}

/** Nouveau client (ID + secret) : les connexions existantes sont révoquées. Le secret n'est affiché qu'une fois. */
function mcp_oauth_new_client(): string
{
    if (mcp_oauth_client_id() === '') {
        set_setting('mcp_oauth_client_id', 'pvc_' . bin2hex(random_bytes(12)));
    }
    $secret = 'pvs_' . bin2hex(random_bytes(24));
    set_setting('mcp_oauth_secret_hash', hash('sha256', $secret));
    mcp_oauth_revoke_all();
    return $secret;
}

function mcp_oauth_client_ok(string $id, string $secret): bool
{
    $hash = setting('mcp_oauth_secret_hash', '');
    return $hash !== '' && $id !== '' && hash_equals(mcp_oauth_client_id(), $id) && hash_equals($hash, hash('sha256', $secret));
}

/** URL de rappel acceptée : celles collées dans les réglages, ou une adresse de rappel de ChatGPT. */
function mcp_oauth_redirect_ok(string $uri): bool
{
    if (!preg_match('~^https://[^\s#]+$~i', $uri) && !preg_match('~^http://(localhost|127\.0\.0\.1)(:\d+)?/[^\s#]*$~i', $uri)) {
        return false;
    }
    $listed = array_filter(array_map('trim', preg_split('/\R/', setting('mcp_oauth_redirects', '')) ?: []));
    if (in_array($uri, $listed, true)) {
        return true;
    }
    // URL de rappel affichée par ChatGPT dans « Ajouter un serveur MCP personnalisé » (config pour en ajouter)
    foreach ((array) config('mcp_oauth_redirect_prefixes', ['https://chatgpt.com/connector/oauth/']) as $prefix) {
        if (is_string($prefix) && $prefix !== '' && str_starts_with($uri, $prefix)) {
            return true;
        }
    }
    return false;
}

/** Ajoute des paramètres à une URL de rappel (qui peut déjà en avoir). */
function mcp_oauth_redirect_url(string $uri, array $params): string
{
    return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query(array_filter($params, static fn ($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
}

/** Vérifie une demande d'autorisation ; renvoie le message d'erreur à AFFICHER (jamais de redirection vers une URL douteuse). */
function mcp_oauth_check_request(array $q): string
{
    if (!mcp_enabled() || !mcp_oauth_ready()) {
        return t('oauth.error.disabled');
    }
    if (!hash_equals(mcp_oauth_client_id(), (string) ($q['client_id'] ?? ''))) {
        return t('oauth.error.client');
    }
    if (!mcp_oauth_redirect_ok((string) ($q['redirect_uri'] ?? ''))) {
        return t('oauth.error.redirect');
    }
    return '';
}

/** Erreur à renvoyer à l'application (redirection vers son URL de rappel), ou '' si la demande est correcte. */
function mcp_oauth_request_error(array $q): string
{
    if (($q['response_type'] ?? '') !== 'code') {
        return 'unsupported_response_type';
    }
    $challenge = (string) ($q['code_challenge'] ?? '');
    if ($challenge !== '' && (($q['code_challenge_method'] ?? 'plain') !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge))) {
        return 'invalid_request';
    }
    return '';
}

function mcp_oauth_cleanup(): void
{
    db()->prepare('DELETE FROM mcp_oauth_codes WHERE expires_at < ?')->execute([time()]);
    db()->prepare('DELETE FROM mcp_oauth_tokens WHERE expires_at < ?')->execute([time()]);
}

/** Code d'autorisation à usage unique (10 min). */
function mcp_oauth_issue_code(string $redirectUri, string $challenge, string $scope): string
{
    mcp_oauth_cleanup();
    $code = 'pva_' . bin2hex(random_bytes(24));
    db()->prepare('INSERT INTO mcp_oauth_codes (code_hash, client_id, redirect_uri, code_challenge, scope, expires_at) VALUES (?,?,?,?,?,?)')
        ->execute([hash('sha256', $code), mcp_oauth_client_id(), $redirectUri, $challenge, $scope, time() + MCP_OAUTH_CODE_TTL]);
    return $code;
}

/** Jeton d'accès + jeton de renouvellement. */
function mcp_oauth_issue_tokens(string $scope): array
{
    $access = 'pvt_' . bin2hex(random_bytes(24));
    $refresh = 'pvr_' . bin2hex(random_bytes(24));
    $ins = db()->prepare('INSERT INTO mcp_oauth_tokens (token_hash, kind, client_id, scope, expires_at, created_at) VALUES (?,?,?,?,?,?)');
    $ins->execute([hash('sha256', $access), 'access', mcp_oauth_client_id(), $scope, time() + MCP_OAUTH_ACCESS_TTL, time()]);
    $ins->execute([hash('sha256', $refresh), 'refresh', mcp_oauth_client_id(), $scope, time() + MCP_OAUTH_REFRESH_TTL, time()]);
    return [
        'access_token'  => $access,
        'token_type'    => 'Bearer',
        'expires_in'    => MCP_OAUTH_ACCESS_TTL,
        'refresh_token' => $refresh,
        'scope'         => $scope !== '' ? $scope : MCP_OAUTH_SCOPE,
    ];
}

/** Jeton d'accès OAuth valide pour /mcp ? */
function mcp_oauth_access_ok(string $token): bool
{
    if ($token === '' || !mcp_oauth_ready()) {
        return false;
    }
    $st = db()->prepare("SELECT 1 FROM mcp_oauth_tokens WHERE token_hash = ? AND kind = 'access' AND client_id = ? AND expires_at > ?");
    $st->execute([hash('sha256', $token), mcp_oauth_client_id(), time()]);
    return (bool) $st->fetchColumn();
}

function mcp_oauth_revoke_all(): void
{
    db()->exec('DELETE FROM mcp_oauth_codes');
    db()->exec('DELETE FROM mcp_oauth_tokens');
}

/** Connexions actives (jetons de renouvellement encore valides). */
function mcp_oauth_active_count(): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM mcp_oauth_tokens WHERE kind = 'refresh' AND expires_at > ?");
    $st->execute([time()]);
    return (int) $st->fetchColumn();
}

/**
 * Point de jeton (RFC 6749 §4.1.3 et §6). $post : corps du formulaire ; $basic : [id, secret] de l'en-tête.
 * @return array{0:int, 1:array} [statut HTTP, réponse JSON]
 */
function mcp_oauth_token_response(array $post, ?array $basic): array
{
    $err = static fn (int $status, string $code, string $desc = ''): array => [$status, array_filter(['error' => $code, 'error_description' => $desc])];
    if (!mcp_enabled() || !mcp_oauth_ready()) {
        return $err(400, 'invalid_client', 'OAuth is not enabled');
    }
    [$id, $secret] = $basic ?? [(string) ($post['client_id'] ?? ''), (string) ($post['client_secret'] ?? '')];
    if (!mcp_oauth_client_ok($id, $secret)) {
        return $err(401, 'invalid_client');
    }
    $grant = (string) ($post['grant_type'] ?? '');
    if ($grant === 'authorization_code') {
        $code = (string) ($post['code'] ?? '');
        return db_tx(static function (PDO $pdo) use ($code, $post, $err): array {
            $st = $pdo->prepare('SELECT * FROM mcp_oauth_codes WHERE code_hash = ?');
            $st->execute([hash('sha256', $code)]);
            $row = $st->fetch();
            $st->closeCursor();
            // usage unique : supprimé dès qu'il est présenté, même si la suite échoue
            $pdo->prepare('DELETE FROM mcp_oauth_codes WHERE code_hash = ?')->execute([hash('sha256', $code)]);
            if (!$row || (int) $row['expires_at'] < time() || !hash_equals((string) $row['client_id'], mcp_oauth_client_id())) {
                return $err(400, 'invalid_grant', 'Unknown or expired code');
            }
            if (isset($post['redirect_uri']) && (string) $post['redirect_uri'] !== (string) $row['redirect_uri']) {
                return $err(400, 'invalid_grant', 'redirect_uri mismatch');
            }
            if ((string) $row['code_challenge'] !== '') {
                $verifier = (string) ($post['code_verifier'] ?? '');
                $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
                if ($verifier === '' || !hash_equals((string) $row['code_challenge'], $computed)) {
                    return $err(400, 'invalid_grant', 'PKCE verification failed');
                }
            }
            return [200, mcp_oauth_issue_tokens((string) $row['scope'])];
        });
    }
    if ($grant === 'refresh_token') {
        $refresh = (string) ($post['refresh_token'] ?? '');
        return db_tx(static function (PDO $pdo) use ($refresh, $err): array {
            $st = $pdo->prepare("SELECT * FROM mcp_oauth_tokens WHERE token_hash = ? AND kind = 'refresh'");
            $st->execute([hash('sha256', $refresh)]);
            $row = $st->fetch();
            $st->closeCursor();
            if (!$row || (int) $row['expires_at'] < time() || !hash_equals((string) $row['client_id'], mcp_oauth_client_id())) {
                return $err(400, 'invalid_grant', 'Unknown or expired refresh token');
            }
            // renouvellement à usage unique : l'ancien jeton est remplacé
            $pdo->prepare('DELETE FROM mcp_oauth_tokens WHERE token_hash = ?')->execute([hash('sha256', $refresh)]);
            return [200, mcp_oauth_issue_tokens((string) $row['scope'])];
        });
    }
    return $err(400, 'unsupported_grant_type');
}

/** Métadonnées de l'autorité (RFC 8414), aussi servies sous /.well-known/openid-configuration. */
function mcp_oauth_server_metadata(): array
{
    $base = mcp_public_base();
    return [
        'issuer'                                => $base,
        'authorization_endpoint'                => $base . '/oauth/authorize',
        'token_endpoint'                        => $base . '/oauth/token',
        'response_types_supported'              => ['code'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'      => ['S256'],
        'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        'scopes_supported'                      => [MCP_OAUTH_SCOPE],
    ];
}

/** Métadonnées de la ressource protégée (RFC 9728) : /mcp et son autorité. */
function mcp_oauth_resource_metadata(): array
{
    $base = mcp_public_base();
    return [
        'resource'                 => $base . '/mcp',
        'authorization_servers'    => [$base],
        'scopes_supported'         => [MCP_OAUTH_SCOPE],
        'bearer_methods_supported' => ['header'],
        'resource_name'            => 'PersonaVault',
    ];
}
