<?php
declare(strict_types=1);

/* Client LLM minimal (API compatible OpenAI : DeepSeek, OpenAI, OpenRouter, LM Studio…) */

/** Erreur de disponibilité du modèle (permet de distinguer les cas côté client). */
class LlmUnavailable extends RuntimeException
{
    public string $kind;

    public function __construct(string $message, string $kind = 'error')
    {
        parent::__construct($message);
        $this->kind = $kind;
    }
}

function llm_config(): array
{
    $defaults = (array) config('llm', []);
    return [
        'provider' => setting('llm_provider', (string) ($defaults['provider'] ?? 'deepseek')),
        'base_url' => rtrim(setting('llm_base_url', (string) ($defaults['base_url'] ?? '')), '/'),
        'model'    => setting('llm_model', (string) ($defaults['model'] ?? '')),
        'key'      => setting('llm_api_key', getenv('DEEPSEEK_API_KEY') ?: ''),
    ];
}

function llm_ready(): bool
{
    $c = llm_config();
    return $c['key'] !== '' && $c['base_url'] !== '' && $c['model'] !== '';
}


/**
 * Appel en streaming (SSE OpenAI). $onDelta est appelé à chaque fragment de texte.
 *
 * $options['should_abort'] (callable, facultatif) est interrogé environ chaque seconde,
 * même quand le modèle n'émet rien : s'il renvoie true, la requête est coupée et la
 * fonction renvoie le texte déjà reçu avec 'aborted' => true (sans exception).
 *
 * @return array{content:string, model:string, prompt_tokens:int, completion_tokens:int, ms:int, aborted:bool}
 * @throws RuntimeException
 */
function llm_stream(array $messages, array $cfg, array $options, callable $onDelta): array
{
    $base  = rtrim((string) ($cfg['base_url'] ?? ''), '/');
    $model = (string) ($cfg['model'] ?? '');
    $key   = (string) ($cfg['key'] ?? '');
    if ($base === '' || $model === '') {
        throw new RuntimeException('Modèle non configuré.');
    }

    $body = [
        'model'          => $model,
        'messages'       => $messages,
        'temperature'    => (float) ($options['temperature'] ?? 0.8),
        'max_tokens'     => (int) ($options['max_tokens'] ?? 400),
        'stream'         => true,
        'stream_options' => ['include_usage' => true],
    ];
    if (!empty($options['reasoning_effort'])) {
        $body['reasoning_effort'] = (string) $options['reasoning_effort'];
    }

    $headers = ['Content-Type: application/json', 'Accept: text/event-stream'];
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    $buffer    = '';
    $content   = '';
    $usage     = null;
    $used      = $model;
    $t0        = microtime(true);
    $rawHead   = '';
    $streamErr = '';
    $aborted   = false;
    $shouldAbort = isset($options['should_abort']) && is_callable($options['should_abort'])
        ? $options['should_abort'] : null;

    // traite une ligne SSE complète
    $handleLine = static function (string $line) use (&$content, &$usage, &$used, &$streamErr, $onDelta): void {
        $line = rtrim($line, "\r");
        if (!str_starts_with($line, 'data:')) {
            return;
        }
        $payload = trim(substr($line, 5));
        if ($payload === '' || $payload === '[DONE]') {
            return;
        }
        $json = json_decode($payload, true);
        if (!is_array($json)) {
            return;
        }
        // erreur signalée DANS le flux (OpenRouter, LM Studio, vLLM…)
        if (isset($json['error'])) {
            $err = $json['error'];
            $streamErr = is_array($err)
                ? (string) ($err['message'] ?? json_encode($err, JSON_UNESCAPED_UNICODE))
                : (string) $err;
            return;
        }
        if (!empty($json['model'])) {
            $used = (string) $json['model'];
        }
        if (!empty($json['usage'])) {
            $usage = $json['usage'];
        }
        $delta = $json['choices'][0]['delta']['content'] ?? '';
        if (is_string($delta) && $delta !== '') {
            $content .= $delta;
            $onDelta($delta);
        }
    };

    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? 240),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_WRITEFUNCTION  => static function ($ch, $chunk) use (&$buffer, &$rawHead, $handleLine) {
            if (strlen($rawHead) < 400) {
                $rawHead .= $chunk;
            }
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $handleLine($line);
            }
            return strlen($chunk);
        },
    ]);
    if ($shouldAbort) {
        // appelé ~1 fois/s même sans données : permet d'arrêter pendant la « réflexion »
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function () use ($shouldAbort, &$aborted): int {
            if ($shouldAbort()) {
                $aborted = true;
                return 1; // non nul = interruption du transfert
            }
            return 0;
        });
    }

    $ok   = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($buffer !== '') {
        $handleLine($buffer); // dernière ligne sans saut de ligne final
        $buffer = '';
    }

    $result = [
        'content'           => $content,
        'model'             => $used,
        'prompt_tokens'     => (int) ($usage['prompt_tokens'] ?? 0),
        'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
        'ms'                => (int) round((microtime(true) - $t0) * 1000),
        'aborted'           => $aborted,
    ];

    if ($aborted) {
        return $result; // arrêt demandé par le client : on rend ce qui a été reçu
    }
    if ($ok === false) {
        throw new RuntimeException('Connexion impossible : ' . $err);
    }
    if ($code < 200 || $code >= 300) {
        $data = json_decode($rawHead, true);
        $msg  = $data['error']['message'] ?? mb_substr(trim($rawHead), 0, 180);
        throw new RuntimeException('Erreur API (' . $code . ') : ' . $msg);
    }
    if ($streamErr !== '') {
        throw new RuntimeException('Erreur du modèle : ' . mb_substr($streamErr, 0, 300));
    }
    if (trim($content) === '') {
        throw new RuntimeException('Réponse vide du modèle.');
    }

    return $result;
}

/**
 * Appel générique /chat/completions : renvoie contenu + usage + latence.
 * $cfg = ['base_url' => ..., 'model' => ..., 'key' => ...]
 * @throws RuntimeException
 */
function llm_request(array $messages, array $cfg, array $options = []): array
{
    $base  = rtrim((string) ($cfg['base_url'] ?? ''), '/');
    $model = (string) ($cfg['model'] ?? '');
    $key   = (string) ($cfg['key'] ?? '');

    if ($base === '' || $model === '') {
        throw new RuntimeException('Modèle non configuré.');
    }

    $body = [
        'model'       => $model,
        'messages'    => $messages,
        'temperature' => (float) ($options['temperature'] ?? 0.8),
        'max_tokens'  => (int) ($options['max_tokens'] ?? 400),
        'stream'      => false,
    ];
    if (!empty($options['json'])) {
        $body['response_format'] = ['type' => 'json_object'];
    }
    // LM Studio / modèles « thinking » : "none" coupe la réflexion (réponse directe)
    if (!empty($options['reasoning_effort'])) {
        $body['reasoning_effort'] = (string) $options['reasoning_effort'];
    }

    $headers = ['Content-Type: application/json'];
    if ($key !== '') {
        $headers[] = 'Authorization: Bearer ' . $key;
    }

    $t0 = microtime(true);
    $ch = curl_init($base . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => (int) ($options['timeout'] ?? 180),
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($raw === false) {
        throw new RuntimeException('Connexion impossible : ' . $err);
    }

    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? mb_substr(trim((string) $raw), 0, 200);
        throw new RuntimeException('Erreur API (' . $code . ') : ' . $msg);
    }

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('Réponse vide du modèle.');
    }

    return [
        'content'           => $content,
        'model'             => (string) ($data['model'] ?? $model),
        'prompt_tokens'     => (int) ($data['usage']['prompt_tokens'] ?? 0),
        'completion_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
        'ms'                => $ms,
    ];
}

/**
 * Appelle /chat/completions et renvoie le contenu texte.
 * @throws RuntimeException
 */
function llm_chat(array $messages, bool $json = false, int $timeout = 90, ?array $override = null): string
{
    $c = $override ? array_merge(llm_config(), $override) : llm_config();
    if ($c['key'] === '') {
        throw new RuntimeException('Aucune clé API enregistrée.');
    }

    $body = [
        'model'       => $c['model'],
        'messages'    => $messages,
        'temperature' => 0.4,
        'stream'      => false,
    ];
    if ($json) {
        $body['response_format'] = ['type' => 'json_object'];
    }

    $ch = curl_init($c['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $c['key'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Connexion impossible : ' . $err);
    }

    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? mb_substr(trim((string) $raw), 0, 200);
        throw new RuntimeException('Erreur API (' . $code . ') : ' . $msg);
    }
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('Réponse vide du modèle.');
    }
    return $content;
}

/** Extrait un objet JSON d'une réponse qui peut contenir du texte ou des ```json. */
function llm_json(?string $raw): array
{
    $raw = trim((string) $raw);
    if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $raw, $m)) {
        $raw = $m[1];
    }
    $start = strpos($raw, '{');
    $end   = strrpos($raw, '}');
    if ($start === false || $end === false || $end < $start) {
        throw new RuntimeException('Le modèle n’a pas renvoyé de JSON exploitable.');
    }
    $data = json_decode(substr($raw, $start, $end - $start + 1), true);
    if (!is_array($data)) {
        throw new RuntimeException('JSON invalide renvoyé par le modèle.');
    }
    return $data;
}

/** Demande au modèle de compléter une fiche à partir des prompts fournis. */
function llm_complete_persona(array $persona, array $prompts): array
{
    $existingKeys = [];
    foreach (json_list($persona['meta'] ?? '[]') as $row) {
        if (is_array($row) && !empty($row['k'])) {
            $existingKeys[] = (string) $row['k'];
        }
    }

    $promptText = '';
    foreach ($prompts as $p) {
        $kind  = (string) ($p['kind'] ?? 'image');
        $title = trim((string) ($p['title'] ?? ''));
        $promptText .= '[' . strtoupper($kind) . ($title !== '' ? ' — ' . $title : '') . "]\n" . trim((string) $p['body']) . "\n\n";
    }

    $system = <<<TXT
Tu complètes des fiches de persona (personnages récurrents pour la génération d'images et d'agents conversationnels).
On te donne le nom, l'accroche, la description, les tags et les attributs déjà saisis, ainsi que les PROMPTS existants.
Les prompts sont la source de vérité : déduis-en ce qui manque (apparence, style, univers, âge, métier, morphologie, garde-robe, palette, éclairage, caméra, modèle de génération, seeds…).
N'invente rien qui contredise l'existant. Sois concis et factuel, en français (les valeurs techniques restent telles quelles).
Réponds UNIQUEMENT avec un objet JSON de cette forme :
{"tagline": "…", "description": "…", "tags": ["…"], "meta": [{"k": "Âge", "v": "29"}]}
- tagline : une ligne max, 90 caractères.
- description : 1 à 3 phrases.
- tags : 3 à 6 tags en minuscules, sans #.
- meta : 3 à 6 attributs au format clé/valeur, clés courtes et en français.
Ne remplis un champ texte que s'il apporte une information : si l'existant est déjà complet, renvoie une chaîne vide.
N'ajoute pas d'attribut déjà présent dans la fiche.
TXT;

    $user = json_encode([
        'nom'         => $persona['name'],
        'accroche'    => $persona['tagline'],
        'description' => $persona['description'],
        'tags'        => json_list($persona['tags'] ?? '[]'),
        'attributs'   => json_list($persona['meta'] ?? '[]'),
        'deja_presents' => $existingKeys,
        'prompts'     => $promptText,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $raw = llm_chat([
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => (string) $user],
    ], true);

    return llm_json($raw);
}
