<?php
declare(strict_types=1);

/*
 * Connecteur MCP (src/mcp.php) : POST /mcp (jeton dans « Authorization: Bearer … ») ou
 * POST /mcp/<jeton>. Sans session ni jeton CSRF : seul le jeton secret donne accès.
 * Inclus par public/index.php AVANT require_login().
 */

if ($p0 === 'mcp') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (!mcp_enabled()) {
        http_response_code(404);
        echo json_encode(mcp_error(null, -32000, 'MCP connector disabled'));
        exit;
    }
    if (!mcp_token_ok(mcp_request_token($path))) {
        http_response_code(401);
        echo json_encode(mcp_error(null, -32001, 'Unauthorized'));
        exit;
    }
    if ($method !== 'POST') {
        // pas de flux SSE côté serveur : le client se contente des réponses JSON aux POST
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(mcp_error(null, -32000, 'Method not allowed'));
        exit;
    }
    $msg = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($msg)) {
        http_response_code(400);
        echo json_encode(mcp_error(null, -32700, 'Parse error'));
        exit;
    }
    $out = mcp_handle($msg);
    if ($out === null) {
        http_response_code(202); // notifications seulement : rien à répondre
        exit;
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}
