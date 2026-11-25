<?php
declare(strict_types=1);

/*
 * Connecteur MCP (Model Context Protocol) : un assistant extérieur (ChatGPT, Claude…) pilote
 * PersonaVault avec des outils : fiches des personas, discussions, messages écrits à la place
 * de l'utilisateur ou du persona, réponse générée par le persona.
 *
 * Transport « Streamable HTTP » réduit à l'essentiel : POST JSON-RPC sur /mcp, réponse JSON
 * (pas de flux SSE, pas de session). Désactivé par défaut ; accès par jeton secret, envoyé
 * dans l'en-tête « Authorization: Bearer … » ou dans l'adresse (/mcp/<jeton>) pour les clients
 * qui ne savent pas ajouter d'en-tête. Voir Réglages › Connecteur MCP et le README.
 */

const MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];
const MCP_SOURCE_MODEL = 'mcp';

/** Le connecteur est-il activé, avec un moyen d'accès (jeton simple ou client OAuth) ? */
function mcp_enabled(): bool
{
    return setting('mcp_enabled', '0') === '1' && (setting('mcp_token_hash', '') !== '' || mcp_oauth_ready());
}

/** Nouveau jeton : seule son empreinte est gardée, le jeton n'est affiché qu'une fois. */
function mcp_token_new(): string
{
    $token = 'pv_' . bin2hex(random_bytes(24));
    set_setting('mcp_token_hash', hash('sha256', $token));
    return $token;
}

/** Jeton simple des réglages, ou jeton d'accès OAuth (ChatGPT) encore valide. */
function mcp_token_ok(string $token): bool
{
    $hash = setting('mcp_token_hash', '');
    return ($hash !== '' && $token !== '' && hash_equals($hash, hash('sha256', $token))) || mcp_oauth_access_ok($token);
}

/** En-tête Authorization de la requête (Apache mod_php ne le met pas toujours dans $_SERVER). */
function mcp_authorization_header(): string
{
    $auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $auth = (string) $value;
            }
        }
    }
    return trim($auth);
}

/** Jeton de la requête : en-tête Authorization, sinon dernier segment de l'adresse (/mcp/<jeton>). */
function mcp_request_token(string $path): string
{
    $auth = mcp_authorization_header();
    if (preg_match('/^Bearer\s+(\S+)$/i', trim($auth), $m)) {
        return $m[1];
    }
    $parts = explode('/', trim($path, '/'));
    return count($parts) === 2 && $parts[0] === 'mcp' ? $parts[1] : '';
}

/* --------------------------------------------------------------------------- protocole */

/**
 * Traite un message JSON-RPC (ou un lot). Renvoie la réponse, ou null pour une notification.
 * @param array<mixed> $msg
 */
function mcp_handle(array $msg): ?array
{
    if (array_is_list($msg)) {
        $out = array_values(array_filter(array_map(static fn ($m) => is_array($m) ? mcp_handle($m) : mcp_error(null, -32600, 'Invalid Request'), $msg)));
        return $out ?: null;
    }
    $id = $msg['id'] ?? null;
    $method = is_string($msg['method'] ?? null) ? $msg['method'] : '';
    $isNotification = !array_key_exists('id', $msg);
    if (($msg['jsonrpc'] ?? '') !== '2.0' || $method === '') {
        return $isNotification ? null : mcp_error($id, -32600, 'Invalid Request');
    }
    if ($isNotification) {
        return null; // notifications/initialized, notifications/cancelled… : rien à répondre
    }
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    try {
        $result = match ($method) {
            'initialize' => mcp_initialize($params),
            'ping'       => new stdClass(),
            'tools/list' => ['tools' => mcp_tools_list()],
            'tools/call' => mcp_tools_call($params),
            default      => throw new McpError(-32601, 'Method not found: ' . $method),
        };
    } catch (McpError $e) {
        return mcp_error($id, $e->getCode(), $e->getMessage());
    }
    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
}

/** Erreur de protocole JSON-RPC (méthode inconnue, outil inconnu…). */
final class McpError extends RuntimeException
{
    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}

/** Erreur d'un outil, expliquée au modèle (persona introuvable, champ vide…). */
final class McpToolError extends RuntimeException
{
}

function mcp_error(mixed $id, int $code, string $message): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
}

function mcp_initialize(array $params): array
{
    $asked = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
    return [
        'protocolVersion' => in_array($asked, MCP_PROTOCOL_VERSIONS, true) ? $asked : MCP_PROTOCOL_VERSIONS[0],
        'capabilities'    => ['tools' => ['listChanged' => false]],
        'serverInfo'      => ['name' => 'PersonaVault', 'version' => '1.0'],
        'instructions'    => "PersonaVault est une bibliothèque de personas (personnages) avec qui l'utilisateur discute. "
            . "Une fiche = nom, accroche et description (affichage), attributs courts (âge, ville, métier…), prompt système "
            . "(psychologie, histoire, voix) et consigne de jeu (forme des messages). "
            . "Dans une discussion, trois façons d'intervenir : send_user_message écrit À LA PLACE DE L'UTILISATEUR "
            . "(et, par défaut, le persona répond avec son propre modèle) ; write_as_persona écrit À LA PLACE DU PERSONA, "
            . "sans modèle ; generate_persona_reply fait répondre le persona au dernier message de l'utilisateur. "
            . "Lis la discussion (get_conversation) avant d'écrire, et garde la voix du persona quand tu écris pour lui.",
    ];
}

/* --------------------------------------------------------------------------- outils */

/** Définition des outils : nom, description, schéma des paramètres, indications (lecture seule…). */
function mcp_tools(): array
{
    $persona = ['type' => 'string', 'description' => 'Persona : identifiant, slug ou nom exact.'];
    $cid = ['type' => 'integer', 'description' => 'Identifiant de la discussion (list_conversations).'];
    $attributes = ['type' => 'object', 'additionalProperties' => ['type' => 'string'],
        'description' => 'Attributs courts, faits durs seulement : {"Âge": "23", "Ville": "Toulon", "Métier": "…"}. Remplace la liste entière.'];
    $read = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
    $write = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
    return [
        'list_personas' => [
            'description' => 'Liste les personas (id, slug, nom, accroche, nombre de discussions). Filtre facultatif sur le nom ou l’accroche.',
            'properties'  => ['query' => ['type' => 'string'], 'include_archived' => ['type' => 'boolean']],
            'required'    => [],
            'annotations' => $read + ['title' => 'Lister les personas'],
        ],
        'get_persona' => [
            'description' => 'Fiche complète d’un persona : champs, attributs, tags, consigne de jeu, réglages et prompts (système, image…).',
            'properties'  => ['persona' => $persona],
            'required'    => ['persona'],
            'annotations' => $read + ['title' => 'Lire une fiche'],
        ],
        'create_persona' => [
            'description' => 'Crée un persona. Le prompt système porte la psychologie, l’histoire et la voix ; les attributs, les faits courts ; la consigne, la forme des messages.',
            'properties'  => [
                'name' => ['type' => 'string'], 'tagline' => ['type' => 'string'], 'description' => ['type' => 'string'],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']], 'attributes' => $attributes,
                'system_prompt' => ['type' => 'string'], 'image_prompt' => ['type' => 'string'],
                'directive' => ['type' => 'string', 'description' => 'Consigne de jeu : registre, longueur, tics, emojis.'],
            ],
            'required'    => ['name'],
            'annotations' => $write + ['title' => 'Créer un persona'],
        ],
        'update_persona' => [
            'description' => 'Modifie les champs d’un persona. Seuls les champs fournis changent ; tags et attributs remplacent la liste entière. Pour un prompt, utilise set_persona_prompt.',
            'properties'  => [
                'persona' => $persona, 'name' => ['type' => 'string'], 'tagline' => ['type' => 'string'],
                'description' => ['type' => 'string'], 'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'attributes' => $attributes, 'directive' => ['type' => 'string'],
                'web_search' => ['type' => 'boolean', 'description' => 'Le persona peut chercher des informations.'],
                'archived' => ['type' => 'boolean'],
            ],
            'required'    => ['persona'],
            'annotations' => $write + ['title' => 'Modifier un persona', 'idempotentHint' => true],
        ],
        'set_persona_prompt' => [
            'description' => 'Écrit un prompt du persona (system, image, negative, video, planning, other). Remplace le premier prompt de ce type (l’ancien texte reste dans l’historique), ou le crée.',
            'properties'  => [
                'persona' => $persona, 'kind' => ['type' => 'string', 'enum' => array_keys(prompt_kinds())],
                'body' => ['type' => 'string'], 'title' => ['type' => 'string'],
                'prompt_id' => ['type' => 'integer', 'description' => 'Prompt précis à remplacer (get_persona), si le persona en a plusieurs du même type.'],
            ],
            'required'    => ['persona', 'kind', 'body'],
            'annotations' => $write + ['title' => 'Écrire un prompt', 'idempotentHint' => true],
        ],
        'set_persona_avatar' => [
            'description' => 'Remplace le portrait (avatar) du persona par une image téléchargée depuis une adresse https:// publique '
                . '(JPEG, PNG, WebP ou GIF, 15 Mo au plus). L’image doit être accessible sans connexion ; elle est réencodée comme un import.',
            'properties'  => ['persona' => $persona, 'image_url' => ['type' => 'string', 'description' => 'Adresse https:// de l’image.']],
            'required'    => ['persona', 'image_url'],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => true, 'title' => 'Changer le portrait'],
        ],
        'get_prompt_preview' => [
            'description' => 'Prompt complet tel que le persona le reçoit pour un message donné : partie fixe (prompt système, fiche, '
                . 'consigne, résumé, règles) et contexte variable (heure, souvenirs, humeur). Pour diagnostiquer une fiche : '
                . 'redondances, informations manquantes, consignes contradictoires. N’envoie rien au modèle.',
            'properties'  => ['conversation_id' => $cid, 'message' => ['type' => 'string', 'description' => 'Message de l’utilisateur supposé (défaut : « Bonjour »).']],
            'required'    => ['conversation_id'],
            'annotations' => $read + ['title' => 'Voir le prompt envoyé'],
        ],
        'search_messages' => [
            'description' => 'Recherche plein texte dans les messages (tous les mots, en début de mot, accents ignorés), les plus récents d’abord. '
                . 'Filtre facultatif par persona ou par discussion.',
            'properties'  => ['query' => ['type' => 'string'], 'persona' => $persona, 'conversation_id' => $cid,
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]],
            'required'    => ['query'],
            'annotations' => $read + ['title' => 'Chercher dans les discussions'],
        ],
        'list_memories' => [
            'description' => 'Souvenirs durables d’un persona : « user » = ce que l’on sait de l’utilisateur (dont les souvenirs globaux, '
                . 'partagés par tous les personas), « story » = histoire du persona et moments partagés. Filtre facultatif sur le texte.',
            'properties'  => ['persona' => $persona, 'subject' => ['type' => 'string', 'enum' => ['all', 'user', 'story']], 'query' => ['type' => 'string']],
            'required'    => ['persona'],
            'annotations' => $read + ['title' => 'Lister les souvenirs'],
        ],
        'add_memory' => [
            'description' => 'Ajoute un souvenir durable : une phrase courte à la 3e personne, un seul fait. Évite les doublons (list_memories d’abord) '
                . 'et les détails du moment. global=true : souvenir sur l’utilisateur partagé par tous les personas.',
            'properties'  => [
                'persona' => $persona, 'content' => ['type' => 'string'],
                'subject' => ['type' => 'string', 'enum' => ['user', 'story'], 'default' => 'user'],
                'category' => ['type' => 'string', 'enum' => array_keys(facts_categories())],
                'confidence' => ['type' => 'string', 'enum' => array_keys(facts_confidences())],
                'global' => ['type' => 'boolean'],
            ],
            'required'    => ['persona', 'content'],
            'annotations' => $write + ['title' => 'Ajouter un souvenir'],
        ],
        'update_memory' => [
            'description' => 'Corrige un souvenir (list_memories) : seuls les champs fournis changent.',
            'properties'  => [
                'memory_id' => ['type' => 'integer'], 'content' => ['type' => 'string'],
                'category' => ['type' => 'string', 'enum' => array_keys(facts_categories())],
                'confidence' => ['type' => 'string', 'enum' => array_keys(facts_confidences())],
            ],
            'required'    => ['memory_id'],
            'annotations' => $write + ['title' => 'Corriger un souvenir', 'idempotentHint' => true],
        ],
        'delete_memory' => [
            'description' => 'Supprime définitivement un souvenir (list_memories). Demande confirmation à l’utilisateur avant.',
            'properties'  => ['memory_id' => ['type' => 'integer']],
            'required'    => ['memory_id'],
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false, 'title' => 'Supprimer un souvenir'],
        ],
        'try_persona_reply' => [
            'description' => 'ESSAI : le persona répond à un message avec son modèle, comme dans l’app, mais RIEN n’est enregistré '
                . '(ni le message ni la réponse). Pour régler une fiche : system_prompt et directive remplacent ceux du persona '
                . 'le temps de l’essai seulement. Sans recherche web ni média.',
            'properties'  => [
                'conversation_id' => $cid, 'message' => ['type' => 'string'],
                'system_prompt' => ['type' => 'string', 'description' => 'Prompt système à essayer à la place de celui du persona (non enregistré).'],
                'directive' => ['type' => 'string', 'description' => 'Consigne de jeu à essayer à la place de celle du persona et de la discussion (non enregistrée).'],
            ],
            'required'    => ['conversation_id', 'message'],
            'annotations' => $read + ['title' => 'Essayer une réponse (sans enregistrer)'],
        ],
        'update_conversation' => [
            'description' => 'Réglages d’une discussion : titre, consigne propre à la discussion (s’ajoute à celle du persona), mémoire '
                . '(résumé glissant), souvenirs partagés avec les autres discussions, recherche d’informations, épinglage. Seuls les champs fournis changent.',
            'properties'  => [
                'conversation_id' => $cid, 'title' => ['type' => 'string'], 'directive' => ['type' => 'string'],
                'memory_enabled' => ['type' => 'boolean'], 'shared_memories' => ['type' => 'boolean'],
                'web_search' => ['type' => 'boolean'], 'pinned' => ['type' => 'boolean'],
            ],
            'required'    => ['conversation_id'],
            'annotations' => $write + ['title' => 'Régler une discussion', 'idempotentHint' => true],
        ],
        'set_feelings' => [
            'description' => 'Règle l’humeur du persona dans une discussion (émotion et intensité 0-100) et ce qu’il ressent pour '
                . 'l’utilisateur (amitié et amour de -100 à 100, colère de 0 à 100), comme le réglage à la main de l’app. Seuls les champs fournis changent.',
            'properties'  => [
                'conversation_id' => $cid, 'emotion' => ['type' => 'string', 'enum' => array_keys(duo_emotions())],
                'intensity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'friendship' => ['type' => 'integer', 'minimum' => -100, 'maximum' => 100],
                'love' => ['type' => 'integer', 'minimum' => -100, 'maximum' => 100],
                'anger' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
            'required'    => ['conversation_id'],
            'annotations' => $write + ['title' => 'Régler l’humeur', 'idempotentHint' => true],
        ],
        'list_conversations' => [
            'description' => 'Liste les discussions, les plus récentes d’abord (id, persona, titre, nombre de messages, dernier message).',
            'properties'  => ['persona' => $persona, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
            'required'    => [],
            'annotations' => $read + ['title' => 'Lister les discussions'],
        ],
        'get_conversation' => [
            'description' => 'Lit une discussion : messages (les plus récents, du plus ancien au plus récent), humeur et ressentis du persona, résumé de mémoire. before_id pour remonter.',
            'properties'  => ['conversation_id' => $cid, 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200], 'before_id' => ['type' => 'integer']],
            'required'    => ['conversation_id'],
            'annotations' => $read + ['title' => 'Lire une discussion'],
        ],
        'create_conversation' => [
            'description' => 'Ouvre une nouvelle discussion avec un persona.',
            'properties'  => ['persona' => $persona, 'title' => ['type' => 'string']],
            'required'    => ['persona'],
            'annotations' => $write + ['title' => 'Nouvelle discussion'],
        ],
        'send_user_message' => [
            'description' => 'Écrit un message À LA PLACE DE L’UTILISATEUR. Par défaut, le persona répond aussitôt avec son modèle, comme dans l’app (mémoire, recherche, humeur). reply=false : le message seul.',
            'properties'  => ['conversation_id' => $cid, 'text' => ['type' => 'string'], 'reply' => ['type' => 'boolean', 'default' => true]],
            'required'    => ['conversation_id', 'text'],
            'annotations' => $write + ['title' => 'Écrire comme l’utilisateur'],
        ],
        'generate_persona_reply' => [
            'description' => 'Le persona répond (avec son modèle) au dernier message de l’utilisateur. S’il y avait déjà une réponse, elle est remplacée ; l’ancienne reste consultable dans les versions.',
            'properties'  => ['conversation_id' => $cid],
            'required'    => ['conversation_id'],
            'annotations' => $write + ['title' => 'Faire répondre le persona'],
        ],
        'write_as_persona' => [
            'description' => 'Écrit un message À LA PLACE DU PERSONA, sans passer par son modèle. Garde sa voix et sa consigne de jeu (get_persona).',
            'properties'  => ['conversation_id' => $cid, 'text' => ['type' => 'string']],
            'required'    => ['conversation_id', 'text'],
            'annotations' => $write + ['title' => 'Écrire comme le persona'],
        ],
    ];
}

function mcp_tools_list(): array
{
    $list = [];
    foreach (mcp_tools() as $name => $tool) {
        $list[] = [
            'name'        => $name,
            'title'       => $tool['annotations']['title'],
            'description' => $tool['description'],
            'inputSchema' => ['type' => 'object', 'properties' => (object) $tool['properties'], 'required' => $tool['required'], 'additionalProperties' => false],
            'annotations' => $tool['annotations'],
        ];
    }
    return $list;
}

/** Appel d'outil : résultat JSON (texte + structuredContent), ou erreur d'outil lisible par le modèle. */
function mcp_tools_call(array $params): array
{
    $name = is_string($params['name'] ?? null) ? $params['name'] : '';
    $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
    if (!isset(mcp_tools()[$name])) {
        throw new McpError(-32602, 'Unknown tool: ' . $name);
    }
    try {
        $data = ('mcp_tool_' . $name)($args);
        $isError = false;
    } catch (McpError $e) {
        throw $e;
    } catch (Throwable $e) {
        $data = ['error' => public_error($e)];
        $isError = true;
    }
    mcp_log_write($name, $args, $isError, $data);
    return [
        'content'           => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]],
        'structuredContent' => $data,
        'isError'           => $isError,
    ];
}

/* --------------------------------------------------------------------------- journal */

const MCP_LOG_KEEP = 500;

/** Ce que l'assistant a fait : outil, cible, paramètres (abrégés), résultat. Les 500 derniers appels. */
function mcp_log_write(string $tool, array $args, bool $isError, array $data): void
{
    try {
        $target = match (true) {
            isset($args['conversation_id']) => 'discussion #' . (int) $args['conversation_id'],
            isset($args['memory_id']) => 'souvenir #' . (int) $args['memory_id'],
            isset($args['persona']) && is_scalar($args['persona']) => 'persona ' . mb_substr((string) $args['persona'], 0, 80),
            isset($args['name']) && is_scalar($args['name']) => 'persona ' . mb_substr((string) $args['name'], 0, 80),
            default => '',
        };
        $result = $isError ? (string) ($data['error'] ?? '') : mcp_log_summary($data);
        db()->prepare('INSERT INTO mcp_log (tool, target, args, ok, result) VALUES (?, ?, ?, ?, ?)')->execute([
            $tool, $target, mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 1000),
            $isError ? 0 : 1, mb_substr($result, 0, 400),
        ]);
        db()->exec('DELETE FROM mcp_log WHERE id <= (SELECT MAX(id) FROM mcp_log) - ' . MCP_LOG_KEEP);
    } catch (Throwable $e) {
        error_log('[PersonaVault] journal MCP : ' . $e->getMessage()); // le journal ne bloque jamais un outil
    }
}

/** Résumé court d'un résultat : la réponse du persona, sinon les clés et le nombre d'éléments. */
function mcp_log_summary(array $data): string
{
    if (isset($data['reply']['content'])) {
        return 'réponse : ' . mb_substr((string) $data['reply']['content'], 0, 300);
    }
    $parts = [];
    foreach ($data as $k => $v) {
        $parts[] = is_array($v) ? $k . ' (' . count($v) . ')' : $k . (is_scalar($v) && mb_strlen((string) $v) <= 60 ? ' = ' . var_export($v, true) : '');
        if (count($parts) >= 6) {
            break;
        }
    }
    return implode(', ', $parts);
}

function mcp_log_list(int $limit = 200): array
{
    return db()->query('SELECT * FROM mcp_log ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll();
}

/* --------------------------------------------------------------------------- aides */

function mcp_str(array $args, string $key, int $max = 20000): ?string
{
    if (!array_key_exists($key, $args) || $args[$key] === null) {
        return null;
    }
    if (!is_scalar($args[$key])) {
        throw new McpToolError("« {$key} » doit être un texte.");
    }
    return mb_substr(trim((string) $args[$key]), 0, $max);
}

function mcp_int(array $args, string $key, int $default = 0): int
{
    $v = $args[$key] ?? $default;
    return is_numeric($v) ? (int) $v : $default;
}

/** Persona désigné par son identifiant, son slug ou son nom exact (insensible à la casse). */
function mcp_persona(string $ref): array
{
    $ref = trim($ref);
    $st = ctype_digit($ref)
        ? db()->prepare('SELECT * FROM personas WHERE id = ?')
        : db()->prepare('SELECT * FROM personas WHERE slug = ?');
    $st->execute([$ref]);
    $p = $st->fetch();
    if (!$p && $ref !== '') {
        $st = db()->prepare('SELECT * FROM personas WHERE lower(name) = lower(?)');
        $st->execute([$ref]);
        $rows = $st->fetchAll();
        if (count($rows) > 1) {
            throw new McpToolError("Plusieurs personas s’appellent « {$ref} » : utilise l’identifiant (list_personas).");
        }
        $p = $rows[0] ?? false;
    }
    if (!$p) {
        throw new McpToolError("Persona introuvable : « {$ref} » (list_personas).");
    }
    return $p;
}

/** Discussion et son persona. */
function mcp_conversation(int $cid): array
{
    $st = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $st->execute([$cid]);
    $conv = $st->fetch();
    if (!$conv) {
        throw new McpToolError("Discussion introuvable : {$cid} (list_conversations).");
    }
    $st = db()->prepare('SELECT * FROM personas WHERE id = ?');
    $st->execute([(int) $conv['persona_id']]);
    return [$conv, $st->fetch() ?: throw new McpToolError('Persona de la discussion introuvable.')];
}

/** Attributs {clé: valeur} → lignes de la fiche (20 au plus, valeurs courtes). */
function mcp_attributes(mixed $raw): array
{
    if (!is_array($raw)) {
        throw new McpToolError('« attributes » doit être un objet {clé: valeur}.');
    }
    $rows = [];
    foreach ($raw as $k => $v) {
        $k = mb_substr(trim((string) $k), 0, 40);
        $v = is_scalar($v) ? mb_substr(trim((string) $v), 0, 200) : '';
        if ($k !== '' && $v !== '' && count($rows) < 20) {
            $rows[] = ['k' => $k, 'v' => $v];
        }
    }
    return $rows;
}

function mcp_tags(mixed $raw): array
{
    if (!is_array($raw)) {
        throw new McpToolError('« tags » doit être une liste.');
    }
    return parse_tags(implode(',', array_map(static fn ($t) => is_scalar($t) ? (string) $t : '', $raw)));
}

function mcp_persona_summary(array $p): array
{
    return ['id' => (int) $p['id'], 'slug' => (string) $p['slug'], 'name' => (string) $p['name'], 'tagline' => (string) $p['tagline']];
}

function mcp_message(array $m, string $personaName): array
{
    $role = (string) $m['role'];
    return [
        'id'         => (int) $m['id'],
        'role'       => match ($role) { 'user' => 'user', 'assistant' => 'persona', default => 'info' },
        'author'     => match ($role) { 'user' => 'utilisateur', 'assistant' => $personaName, default => $role },
        'content'    => (string) $m['content'],
        'created_at' => (string) $m['created_at'],
    ] + ((int) ($m['godmode'] ?? 0) === 1 ? ['godmode' => true] : [])
      + ((string) ($m['model'] ?? '') === MCP_SOURCE_MODEL ? ['written_by_connector' => true] : []);
}

/** Verrou d'écriture d'une discussion (une réponse en cours dans l'app le tient). */
function mcp_lock(int $cid)
{
    $lock = chat_try_lock($cid);
    if ($lock === false) {
        throw new RuntimeException('Une réponse est en cours dans cette discussion : réessaie dans un instant.');
    }
    return $lock;
}

/* --------------------------------------------------------------------------- outils : personas */

function mcp_tool_list_personas(array $args): array
{
    $sql = 'SELECT p.*, (SELECT COUNT(*) FROM conversations c WHERE c.persona_id = p.id) AS n FROM personas p WHERE 1 = 1';
    $params = [];
    if (empty($args['include_archived'])) {
        $sql .= ' AND p.archived = 0';
    }
    $q = mcp_str($args, 'query', 100) ?? '';
    if ($q !== '') {
        $sql .= " AND (p.name LIKE ? ESCAPE '\\' OR p.tagline LIKE ? ESCAPE '\\')";
        $like = '%' . chat_like_escape($q) . '%';
        array_push($params, $like, $like);
    }
    $st = db()->prepare($sql . ' ORDER BY p.favorite DESC, p.name COLLATE NOCASE LIMIT 300');
    $st->execute($params);
    return ['personas' => array_map(static fn (array $p): array => mcp_persona_summary($p) + [
        'archived' => (int) $p['archived'] === 1, 'conversations' => (int) $p['n'],
    ], $st->fetchAll())];
}

function mcp_tool_get_persona(array $args): array
{
    $p = mcp_persona((string) ($args['persona'] ?? ''));
    $attributes = [];
    foreach (json_list($p['meta']) as $row) {
        if (is_array($row) && isset($row['k'])) {
            $attributes[(string) $row['k']] = (string) ($row['v'] ?? '');
        }
    }
    return mcp_persona_summary($p) + [
        'description' => (string) $p['description'],
        'tags'        => json_list($p['tags']),
        'attributes'  => (object) $attributes,
        'directive'   => (string) ($p['directive'] ?? ''),
        'settings'    => [
            'model' => (string) ($p['model'] ?? ''), 'temperature' => (float) ($p['temperature'] ?? 0.85),
            'max_tokens' => (int) ($p['max_tokens'] ?? 400), 'web_search' => (int) ($p['web_search'] ?? 1) === 1,
        ],
        'archived'    => (int) $p['archived'] === 1,
        'has_avatar'  => (string) $p['avatar'] !== '',
        'prompts'     => array_map(static fn (array $pr): array => [
            'id' => (int) $pr['id'], 'kind' => (string) $pr['kind'], 'title' => (string) $pr['title'], 'body' => (string) $pr['body'],
        ], fetch_prompts((int) $p['id'])),
    ];
}

function mcp_tool_create_persona(array $args): array
{
    $name = mcp_str($args, 'name', 80) ?? '';
    if ($name === '') {
        throw new McpToolError('Le nom est obligatoire.');
    }
    $slug = unique_slug($name);
    $pid = db_tx(static function (PDO $pdo) use ($args, $name, $slug): int {
        $pdo->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta, directive) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([
                $slug, $name, mcp_str($args, 'tagline', 200) ?? '', mcp_str($args, 'description', 4000) ?? '', '',
                json_encode(isset($args['tags']) ? mcp_tags($args['tags']) : [], JSON_UNESCAPED_UNICODE),
                json_encode(isset($args['attributes']) ? mcp_attributes($args['attributes']) : [], JSON_UNESCAPED_UNICODE),
                mcp_str($args, 'directive', 2000) ?? '',
            ]);
        $pid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO prompts (persona_id, title, kind, body) VALUES (?,?,?,?)');
        foreach (['system' => 'assist.prompt.system_title', 'image' => 'assist.prompt.image_title'] as $kind => $title) {
            $body = mcp_str($args, $kind . '_prompt') ?? '';
            if ($body !== '') {
                $ins->execute([$pid, t($title), $kind, $body]);
            }
        }
        return $pid;
    });
    return ['created' => true] + mcp_tool_get_persona(['persona' => (string) $pid]);
}

function mcp_tool_update_persona(array $args): array
{
    $p = mcp_persona((string) ($args['persona'] ?? ''));
    $set = [];
    $name = mcp_str($args, 'name', 80);
    if ($name !== null) {
        if ($name === '') {
            throw new McpToolError('Le nom ne peut pas être vide.');
        }
        $set['name'] = $name;
        $set['slug'] = unique_slug($name, (int) $p['id']);
    }
    foreach (['tagline' => 200, 'description' => 4000, 'directive' => 2000] as $field => $max) {
        $v = mcp_str($args, $field, $max);
        if ($v !== null) {
            $set[$field] = $v;
        }
    }
    if (array_key_exists('tags', $args)) {
        $set['tags'] = json_encode(mcp_tags($args['tags']), JSON_UNESCAPED_UNICODE);
    }
    if (array_key_exists('attributes', $args)) {
        $set['meta'] = json_encode(mcp_attributes($args['attributes']), JSON_UNESCAPED_UNICODE);
    }
    foreach (['web_search', 'archived'] as $flag) {
        if (array_key_exists($flag, $args)) {
            $set[$flag] = $args[$flag] ? 1 : 0;
        }
    }
    if (!$set) {
        throw new McpToolError('Aucun champ à modifier.');
    }
    $sql = 'UPDATE personas SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($set)))
        . ', updated_at = datetime("now") WHERE id = ?';
    db()->prepare($sql)->execute([...array_values($set), (int) $p['id']]);
    return ['updated' => array_keys(array_diff_key($set, ['slug' => 1]))] + mcp_tool_get_persona(['persona' => (string) $p['id']]);
}

function mcp_tool_set_persona_prompt(array $args): array
{
    $p = mcp_persona((string) ($args['persona'] ?? ''));
    $kind = (string) ($args['kind'] ?? '');
    if (!isset(prompt_kinds()[$kind])) {
        throw new McpToolError('Type de prompt inconnu : ' . $kind);
    }
    $body = mcp_str($args, 'body', 20000) ?? '';
    if ($body === '') {
        throw new McpToolError('Le texte du prompt est vide.');
    }
    $title = mcp_str($args, 'title', 120);
    $target = null;
    foreach (fetch_prompts((int) $p['id']) as $pr) {
        $wanted = mcp_int($args, 'prompt_id');
        if (($wanted > 0 && (int) $pr['id'] === $wanted) || ($wanted === 0 && $pr['kind'] === $kind)) {
            $target = $pr;
            break;
        }
    }
    if (mcp_int($args, 'prompt_id') > 0 && $target === null) {
        throw new McpToolError('Ce prompt n’appartient pas à ce persona (get_persona).');
    }
    if ($target) {
        // historique : l'ancien texte reste consultable (10 dernières modifications)
        prompt_version_record((int) $target['id'], (string) $target['body'], $body);
        db()->prepare('UPDATE prompts SET title = ?, kind = ?, body = ? WHERE id = ?')
            ->execute([$title ?? (string) $target['title'], $kind, $body, (int) $target['id']]);
        $id = (int) $target['id'];
    } else {
        db()->prepare('INSERT INTO prompts (persona_id, title, kind, body) VALUES (?,?,?,?)')
            ->execute([(int) $p['id'], $title ?? prompt_kinds()[$kind], $kind, $body]);
        $id = (int) db()->lastInsertId();
    }
    db()->prepare('UPDATE personas SET updated_at = datetime("now") WHERE id = ?')->execute([(int) $p['id']]);
    return ['prompt_id' => $id, 'kind' => $kind, 'replaced' => $target !== null];
}

/**
 * Télécharge une image pour le serveur, sans jamais atteindre le réseau local : https seulement,
 * adresse IP publique vérifiée et imposée à cURL (pas de changement d'IP entre la vérification et
 * la connexion), redirections suivies une à une avec la même vérification, taille plafonnée.
 * config « mcp_avatar_allow_local » : tests seulement (http et 127.0.0.1 permis).
 * @return array{0:string, 1:int} [fichier temporaire, taille]
 */
function mcp_fetch_image(string $url): array
{
    $local = (bool) config('mcp_avatar_allow_local', false);
    $max = (int) config('max_upload', 15 * 1024 * 1024);
    for ($hop = 0; $hop <= 3; $hop++) {
        $parts = parse_url($url) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        if (!($scheme === 'https' || ($local && $scheme === 'http')) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new McpToolError('Adresse refusée : une adresse https:// publique est attendue.');
        }
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $rec) {
                if (is_string($rec['ipv6'] ?? null)) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new McpToolError('Adresse introuvable : ' . $host);
        }
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
                && !str_starts_with(strtolower($ip), '::ffff:');
            if (!$public && !$local) {
                throw new McpToolError('Adresse refusée : elle mène au réseau local.');
            }
        }
        $ip = $ips[0];
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pvav');
        $fh = fopen($tmp, 'wb');
        $size = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => [$host . ':' . $port . ':' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => $local ? (CURLPROTO_HTTPS | CURLPROTO_HTTP) : CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Accept: image/*', CHAT_WEB_USER_AGENT],
            CURLOPT_WRITEFUNCTION  => static function ($ch, string $data) use ($fh, &$size, $max): int {
                $size += strlen($data);
                return $size > $max ? 0 : (int) fwrite($fh, $data);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);
        fclose($fh);
        if ($status >= 300 && $status < 400 && $next !== '') {
            @unlink($tmp);
            $url = $next;
            continue;
        }
        if ($size > $max) {
            @unlink($tmp);
            throw new McpToolError(t('error.image.too_heavy', ['max' => human_bytes($max)]));
        }
        if ($ok === false || $status !== 200 || $size === 0) {
            @unlink($tmp);
            throw new McpToolError("Téléchargement impossible (HTTP {$status}) : l’image doit être accessible sans connexion.");
        }
        return [$tmp, $size];
    }
    throw new McpToolError('Trop de redirections.');
}

function mcp_tool_set_persona_avatar(array $args): array
{
    $p = mcp_persona((string) ($args['persona'] ?? ''));
    $url = mcp_str($args, 'image_url', 4000) ?? '';
    [$tmp, $size] = mcp_fetch_image($url);
    try {
        // même traitement qu'un portrait importé : contrôle, rotation, WebP sans métadonnées
        $avatar = store_upload(['tmp_name' => $tmp, 'size' => $size, 'error' => UPLOAD_ERR_OK]);
    } finally {
        @unlink($tmp);
    }
    db()->prepare('UPDATE personas SET avatar = ?, updated_at = datetime("now") WHERE id = ?')->execute([$avatar, (int) $p['id']]);
    if ((string) $p['avatar'] !== '' && (string) $p['avatar'] !== $avatar) {
        // l'ancien portrait peut encore servir dans la galerie : supprimé seulement s'il n'est plus référencé
        release_media((string) $p['avatar']);
    }
    return ['avatar_updated' => true] + mcp_persona_summary($p);
}

/* --------------------------------------------------------------------------- outils : diagnostic et recherche */

function mcp_tool_get_prompt_preview(array $args): array
{
    [$conv, $persona] = mcp_conversation(mcp_int($args, 'conversation_id'));
    $cid = (int) $conv['id'];
    $message = mcp_str($args, 'message', 2000) ?: 'Bonjour';
    $conv = chat_with_persona($conv, $persona);
    $built = chat_build_messages($persona, fetch_prompts((int) $persona['id']), $conv, fetch_context_messages($cid, $conv), $message,
        canReact: chat_can_react($cid));
    $last = end($built['messages']);
    $lastText = is_array($last['content']) ? (string) ($last['content'][0]['text'] ?? '') : (string) $last['content'];
    return [
        'conversation_id' => $cid,
        'persona'         => mcp_persona_summary($persona),
        'model'           => (string) ($conv['model'] ?? ''),
        'system_prompt'   => (string) $built['messages'][0]['content'],
        'last_message'    => $lastText,
        'history_messages_kept' => (int) $built['kept'],
        'estimated_tokens' => (int) ($built['tokens'] ?? 0),
    ];
}

function mcp_tool_search_messages(array $args): array
{
    $query = mcp_str($args, 'query', 200) ?? '';
    $words = array_slice(text_words($query, 1), 0, 8);
    if (!$words) {
        throw new McpToolError('Recherche vide.');
    }
    $where = "m.role IN ('user', 'assistant')";
    $params = [];
    if (isset($args['persona']) && (string) $args['persona'] !== '') {
        $where .= ' AND c.persona_id = ?';
        $params[] = (int) mcp_persona((string) $args['persona'])['id'];
    }
    if (mcp_int($args, 'conversation_id') > 0) {
        $where .= ' AND c.id = ?';
        $params[] = mcp_int($args, 'conversation_id');
    }
    $limit = max(1, min(50, mcp_int($args, 'limit', 20)));
    $select = 'SELECT m.id, m.conversation_id, m.role, m.content, m.created_at, c.title, p.name AS persona_name
               FROM %s JOIN conversations c ON c.id = m.conversation_id JOIN personas p ON p.id = c.persona_id
               WHERE %s ORDER BY m.id DESC LIMIT ' . $limit;
    try {
        $st = db()->prepare(sprintf($select, 'messages_fts f JOIN messages m ON m.id = f.rowid', 'messages_fts MATCH ? AND ' . $where));
        $st->execute([fts_query($words, 'AND'), ...$params]);
    } catch (Throwable $e) {
        // index plein texte indisponible : recherche de sous-chaîne
        $st = db()->prepare(sprintf($select, 'messages m', $where . " AND m.content LIKE ? ESCAPE '\\'"));
        $st->execute([...$params, '%' . chat_like_escape($query) . '%']);
    }
    return ['results' => array_map(static fn (array $r): array => [
        'message_id' => (int) $r['id'], 'conversation_id' => (int) $r['conversation_id'], 'conversation_title' => (string) $r['title'],
        'author' => $r['role'] === 'user' ? 'utilisateur' : (string) $r['persona_name'],
        'content' => mb_substr((string) $r['content'], 0, 600), 'created_at' => (string) $r['created_at'],
    ], $st->fetchAll())];
}

/* --------------------------------------------------------------------------- outils : essai et réglages */

/** Essai sans rien enregistrer : même préparation que chat_turn, sans insertion, sans recherche ni média. */
function mcp_tool_try_persona_reply(array $args): array
{
    [$conv, $persona] = mcp_conversation(mcp_int($args, 'conversation_id'));
    $cid = (int) $conv['id'];
    $message = mcp_str($args, 'message', 8000) ?? '';
    if ($message === '') {
        throw new McpToolError('Le message est vide.');
    }
    $prompts = fetch_prompts((int) $persona['id']);
    $overrides = [];
    $system = mcp_str($args, 'system_prompt', 20000);
    if ($system !== null && $system !== '') {
        $prompts = array_values(array_filter($prompts, static fn (array $p): bool => $p['kind'] !== 'system'));
        $prompts[] = ['kind' => 'system', 'title' => 'essai', 'body' => $system];
        $overrides[] = 'system_prompt';
    }
    $conv = chat_with_persona($conv, $persona);
    $directive = mcp_str($args, 'directive', 2000);
    if ($directive !== null) {
        $conv['directive'] = $directive;
        $overrides[] = 'directive';
    }
    @set_time_limit(600);
    try {
        $conv = chat_fit_context($conv)['conv'];
    } catch (Throwable $e) {
        // jamais bloquant : l'appel au modèle dira lui-même ce qui ne va pas
    }
    $built = chat_build_messages($persona, $prompts, $conv, fetch_context_messages($cid, $conv), $message, canReact: false);
    $built['messages'][0]['content'] .= ' Privilégie une réponse concise et termine ta dernière phrase dans le budget de sortie.';
    $context = new ChatContextOutput();
    $filtered = new ChatReactionStream(false);
    $res = chat_reply_stream($built['messages'], $conv, static function (string $delta) use ($context, $filtered): void {
        $filtered->push($context->push($delta));
    });
    $filtered->push($context->finish());
    $filtered->finish();
    $content = trim($filtered->content !== '' ? $filtered->content : chat_strip_context((string) $res['content']));
    if ($content === '') {
        throw new RuntimeException(t('error.llm.empty'));
    }
    return [
        'saved'     => false,
        'persona'   => (string) $persona['name'],
        'reply'     => ['author' => (string) $persona['name'], 'content' => $content],
        'overrides' => $overrides,
        'model'     => (string) $res['model'],
        'finish_reason' => (string) ($res['finish_reason'] ?? ''),
        'tokens'    => (int) ($res['prompt_tokens'] ?? 0) + (int) ($res['completion_tokens'] ?? 0),
        'ms'        => (int) ($res['ms'] ?? 0),
    ];
}

function mcp_tool_update_conversation(array $args): array
{
    [$conv] = mcp_conversation(mcp_int($args, 'conversation_id'));
    $set = [];
    $title = mcp_str($args, 'title', 120);
    if ($title !== null) {
        if ($title === '') {
            throw new McpToolError('Le titre ne peut pas être vide.');
        }
        $set['title'] = $title;
        $set['title_auto'] = 0;
    }
    $directive = mcp_str($args, 'directive', 2000);
    if ($directive !== null) {
        $set['directive'] = $directive;
    }
    foreach (['memory_enabled' => 'memory_enabled', 'shared_memories' => 'facts_enabled', 'web_search' => 'web_search', 'pinned' => 'pinned'] as $arg => $column) {
        if (array_key_exists($arg, $args)) {
            $set[$column] = $args[$arg] ? 1 : 0;
        }
    }
    if (!$set) {
        throw new McpToolError('Aucun réglage à modifier.');
    }
    db()->prepare('UPDATE conversations SET ' . implode(', ', array_map(static fn (string $c): string => $c . ' = ?', array_keys($set)))
        . ' WHERE id = ?')->execute([...array_values($set), (int) $conv['id']]);
    $st = db()->prepare('SELECT * FROM conversations WHERE id = ?');
    $st->execute([(int) $conv['id']]);
    $c = $st->fetch();
    return ['updated' => array_keys(array_diff_key($set, ['title_auto' => 1])), 'conversation_id' => (int) $c['id'], 'title' => (string) $c['title'],
        'directive' => (string) $c['directive'], 'memory_enabled' => (int) $c['memory_enabled'] === 1,
        'shared_memories' => (int) $c['facts_enabled'] === 1, 'web_search' => (int) $c['web_search'] === 1, 'pinned' => (int) $c['pinned'] === 1];
}

function mcp_tool_set_feelings(array $args): array
{
    [$conv, $persona] = mcp_conversation(mcp_int($args, 'conversation_id'));
    $values = array_intersect_key($args, array_flip(['emotion', 'intensity', 'friendship', 'love', 'anger']));
    if (isset($values['emotion']) && !isset(duo_emotions()[(string) $values['emotion']])) {
        throw new McpToolError('Émotion inconnue : ' . (string) $values['emotion']);
    }
    if (!$values) {
        throw new McpToolError('Aucune valeur à régler.');
    }
    $changed = feel_set((int) $conv['id'], $persona, array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $values));
    $f = feel_get((int) $conv['id'], $persona);
    return [
        'changed' => $changed,
        'feelings' => ['emotion' => $f['emotion'], 'intensity' => $f['intensity'], 'friendship' => $f['friendship'],
            'love' => $f['love'], 'anger' => $f['anger']],
        'note' => $f['romance'] ? '' : 'Persona mineur : l’amour reste à 0.',
    ];
}

/* --------------------------------------------------------------------------- outils : souvenirs */

function mcp_memory_row(array $m): array
{
    return [
        'id' => (int) $m['id'],
        'subject' => (string) ($m['subject'] ?? 'user'),
        'global' => $m['persona_id'] === null,
        'category' => (string) $m['category'],
        'confidence' => (string) $m['confidence'],
        'content' => (string) $m['content'],
        'reliability' => memory_guard_reliability($m),
        'origin' => (string) ($m['origin'] ?? ''),
        'evidence' => (string) ($m['evidence'] ?? ''),
        'confirmations' => (int) ($m['confirmations'] ?? 1),
        'expires_at' => (string) ($m['expires_at'] ?? ''),
        'updated_at' => (string) $m['updated_at'],
    ];
}

function mcp_memory(int $id): array
{
    $st = db()->prepare('SELECT * FROM user_memories WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: throw new McpToolError("Souvenir introuvable : {$id} (list_memories).");
}

function mcp_tool_list_memories(array $args): array
{
    $p = mcp_persona((string) ($args['persona'] ?? ''));
    $subject = (string) ($args['subject'] ?? 'all');
    $rows = array_filter(facts_list((int) $p['id'], mcp_str($args, 'query', 100) ?? '', 300),
        static fn (array $m): bool => $subject === 'all' || ($m['subject'] ?? 'user') === $subject);
    return ['persona' => mcp_persona_summary($p), 'memories' => array_values(array_map('mcp_memory_row', $rows))];
}

function mcp_tool_add_memory(array $args): array
{
    $p = mcp_persona((string) ($args['persona'] ?? ''));
    $content = mcp_str($args, 'content', 400) ?? '';
    if ($content === '') {
        throw new McpToolError('Le souvenir est vide.');
    }
    $story = ($args['subject'] ?? 'user') === 'story';
    if ($story && !empty($args['global'])) {
        throw new McpToolError('Un souvenir de l’histoire du persona ne peut pas être global.');
    }
    foreach (facts_list((int) $p['id'], '', 300) as $m) {
        if (facts_norm((string) $m['content']) === facts_norm($content)) {
            throw new McpToolError('Ce souvenir existe déjà (id ' . (int) $m['id'] . ').');
        }
    }
    $id = facts_add(!empty($args['global']) ? 0 : (int) $p['id'], $content, (string) ($args['category'] ?? 'fait'),
        (string) ($args['confidence'] ?? 'confirme'));
    facts_guard_set($id, ['origin' => 'mcp']);
    if ($story) {
        // histoire du persona ajoutée à la main : pas de message d'origine (source_message_id reste vide)
        db()->prepare("UPDATE user_memories SET subject = 'story' WHERE id = ?")->execute([$id]);
    }
    return ['added' => true] + mcp_memory_row(mcp_memory($id));
}

function mcp_tool_update_memory(array $args): array
{
    $m = mcp_memory(mcp_int($args, 'memory_id'));
    $content = mcp_str($args, 'content', 400);
    if ($content === '') {
        throw new McpToolError('Le souvenir ne peut pas être vide : utilise delete_memory.');
    }
    facts_update((int) $m['id'], $content ?? (string) $m['content'], (string) ($args['category'] ?? $m['category']),
        (string) ($args['confidence'] ?? $m['confidence']));
    return ['updated' => true] + mcp_memory_row(mcp_memory((int) $m['id']));
}

function mcp_tool_delete_memory(array $args): array
{
    $m = mcp_memory(mcp_int($args, 'memory_id'));
    facts_delete((int) $m['id']);
    return ['deleted' => true, 'id' => (int) $m['id'], 'content' => (string) $m['content']];
}

/* --------------------------------------------------------------------------- outils : discussions */

function mcp_tool_list_conversations(array $args): array
{
    $sql = "SELECT c.*, p.name AS persona_name,
                (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id AND m.role IN ('user', 'assistant')) AS n,
                (SELECT content FROM messages m WHERE m.conversation_id = c.id AND m.role IN ('user', 'assistant') ORDER BY m.id DESC LIMIT 1) AS last_msg
            FROM conversations c JOIN personas p ON p.id = c.persona_id";
    $params = [];
    if (isset($args['persona']) && (string) $args['persona'] !== '') {
        $sql .= ' WHERE c.persona_id = ?';
        $params[] = (int) mcp_persona((string) $args['persona'])['id'];
    }
    $limit = max(1, min(100, mcp_int($args, 'limit', 30)));
    $st = db()->prepare($sql . ' ORDER BY c.updated_at DESC, c.id DESC LIMIT ' . $limit);
    $st->execute($params);
    return ['conversations' => array_map(static fn (array $c): array => [
        'id' => (int) $c['id'], 'title' => (string) $c['title'],
        'persona' => ['id' => (int) $c['persona_id'], 'name' => (string) $c['persona_name']],
        'messages' => (int) $c['n'], 'updated_at' => (string) $c['updated_at'],
        'last_message' => mb_substr((string) ($c['last_msg'] ?? ''), 0, 200),
    ], $st->fetchAll())];
}

function mcp_tool_get_conversation(array $args): array
{
    [$conv, $persona] = mcp_conversation(mcp_int($args, 'conversation_id'));
    $limit = max(1, min(200, mcp_int($args, 'limit', 30)));
    $rows = fetch_messages((int) $conv['id'], $limit, max(0, mcp_int($args, 'before_id')));
    $f = feel_get((int) $conv['id'], $persona);
    return [
        'id'       => (int) $conv['id'],
        'title'    => (string) $conv['title'],
        'persona'  => mcp_persona_summary($persona),
        'feelings' => [
            'mood' => $f['mood'] !== '' ? $f['mood'] : $f['label'], 'intensity' => $f['intensity'],
            'friendship' => $f['friendship'], 'love' => $f['love'], 'anger' => $f['anger'], 'reason' => $f['reason'],
        ],
        'memory_summary' => (int) $conv['memory_enabled'] === 1 ? (string) ($conv['summary'] ?? '') : '',
        'messages' => array_map(static fn (array $m): array => mcp_message($m, (string) $persona['name']), $rows),
        'older_before_id' => $rows ? (int) $rows[0]['id'] : null,
    ];
}

function mcp_tool_create_conversation(array $args): array
{
    $p = mcp_persona((string) ($args['persona'] ?? ''));
    if ((int) $p['archived'] === 1) {
        throw new McpToolError('Ce persona est archivé : désarchive-le d’abord (update_persona).');
    }
    $cid = chat_create_conversation($p);
    if ($cid <= 0) {
        throw new RuntimeException('La discussion n’a pas pu être créée.');
    }
    $title = mcp_str($args, 'title', 120);
    if ($title !== null && $title !== '') {
        db()->prepare('UPDATE conversations SET title = ?, title_auto = 0 WHERE id = ?')->execute([$title, $cid]);
    }
    return ['conversation_id' => $cid, 'persona' => mcp_persona_summary($p)];
}

/**
 * Tour complet comme dans l'app (src/chat_turn.php) : les événements sont recueillis au lieu d'être
 * envoyés à un navigateur. $in['text'] pour un nouveau message, $in['regen'] pour (re)répondre.
 */
function mcp_run_turn(array $conv, array $persona, array $in): array
{
    $cid = (int) $conv['id'];
    $lock = mcp_lock($cid);
    @set_time_limit(600);
    $events = [];
    try {
        $st = db()->prepare('SELECT * FROM conversations WHERE id = ?');
        $st->execute([$cid]);
        $current = chat_with_persona($st->fetch(), $persona);
        chat_turn($cid, $current, $persona, fetch_prompts((int) $persona['id']), $in + ['finish_request' => false],
            static function (array $e) use (&$events): void {
                $events[] = $e;
            },
            static fn (): bool => false,
            $lock);
    } finally {
        if (is_resource($lock)) {
            fclose($lock);
        }
    }
    foreach ($events as $e) {
        if (isset($e['error'])) {
            throw new RuntimeException((string) $e['error']);
        }
    }
    $done = array_values(array_filter($events, static fn (array $e): bool => !empty($e['done'])))[0] ?? null;
    if ($done === null) {
        throw new RuntimeException('Le persona n’a pas répondu.');
    }
    // réponse arrivée sans que personne ne la lise dans l'app : badge « Nouveau »
    db()->prepare('UPDATE conversations SET unread = 1 WHERE id = ?')->execute([$cid]);
    $user = array_values(array_filter($events, static fn (array $e): bool => isset($e['user'])))[0]['user'] ?? null;
    $web = array_values(array_filter($events, static fn (array $e): bool => isset($e['web'])))[0]['web'] ?? null;
    return array_filter([
        'user_message_id' => $user['id'] ?? null,
        'search'          => $web['content'] ?? null,
        'reply'           => ['id' => (int) $done['reply']['id'], 'author' => (string) $persona['name'], 'content' => (string) $done['reply']['content']],
        'model'           => (string) ($done['meta']['model'] ?? ''),
        'notice'          => $done['notice']['content'] ?? null,
    ], static fn ($v) => $v !== null);
}

function mcp_tool_send_user_message(array $args): array
{
    [$conv, $persona] = mcp_conversation(mcp_int($args, 'conversation_id'));
    $text = mcp_str($args, 'text', 8000) ?? '';
    if ($text === '') {
        throw new McpToolError('Le message est vide.');
    }
    if (($args['reply'] ?? true) !== false) {
        return ['conversation_id' => (int) $conv['id']] + mcp_run_turn($conv, $persona, ['text' => $text]);
    }
    $lock = mcp_lock((int) $conv['id']);
    try {
        db()->prepare("INSERT INTO messages (conversation_id, role, content) VALUES (?, 'user', ?)")->execute([(int) $conv['id'], $text]);
        $id = (int) db()->lastInsertId();
        db()->prepare('UPDATE conversations SET updated_at = datetime("now") WHERE id = ?')->execute([(int) $conv['id']]);
    } finally {
        fclose($lock);
    }
    return ['conversation_id' => (int) $conv['id'], 'user_message_id' => $id, 'reply' => null];
}

function mcp_tool_generate_persona_reply(array $args): array
{
    [$conv, $persona] = mcp_conversation(mcp_int($args, 'conversation_id'));
    return ['conversation_id' => (int) $conv['id']] + mcp_run_turn($conv, $persona, ['regen' => true]);
}

function mcp_tool_write_as_persona(array $args): array
{
    [$conv, $persona] = mcp_conversation(mcp_int($args, 'conversation_id'));
    $text = mcp_str($args, 'text', 8000) ?? '';
    if ($text === '') {
        throw new McpToolError('Le message est vide.');
    }
    $cid = (int) $conv['id'];
    $splitPoints = setting('chat_split_enabled', '1') === '1'
        ? chat_split_points($text, (int) setting('chat_split_words', '50'), (int) setting('chat_split_max_bubbles', '2'), setting('chat_split_position', 'before'))
        : [];
    $lock = mcp_lock($cid);
    try {
        db()->prepare("INSERT INTO messages (conversation_id, role, content, model, split_points) VALUES (?, 'assistant', ?, ?, ?)")
            ->execute([$cid, $text, MCP_SOURCE_MODEL, $splitPoints ? json_encode($splitPoints) : '']);
        $id = (int) db()->lastInsertId();
        db()->prepare('UPDATE conversations SET unread = 1, updated_at = datetime("now") WHERE id = ?')->execute([$cid]);
    } finally {
        fclose($lock);
    }
    return ['conversation_id' => $cid, 'message_id' => $id, 'author' => (string) $persona['name']];
}
