<?php
declare(strict_types=1);

/* Client LLM minimal (API compatible OpenAI : DeepSeek, OpenAI, OpenRouter, LM Studio…) */

/*
 * Codes des exceptions levées ici : les messages sont traduits (langue de l'interface),
 * on reconnaît donc les cas par leur code et non par leur texte.
 * Erreur HTTP de l'API : code = statut HTTP (400, 404…).
 */
const LLM_ERR_CONNECT = 1001; // connexion impossible (serveur éteint, délai dépassé…)
const LLM_ERR_EMPTY   = 1002; // réponse vide du modèle

/** Erreur de disponibilité du modèle (permet de distinguer les cas côté client). */
class LlmUnavailable extends RuntimeException
{
    public string $kind;

    /** $previous : exception d'origine, dont le code (LLM_ERR_*) est conservé. */
    public function __construct(string $message, string $kind = 'error', ?Throwable $previous = null)
    {
        parent::__construct($message, $previous ? (int) $previous->getCode() : 0, $previous);
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
        throw new RuntimeException(t('error.llm.no_model'));
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
        throw new RuntimeException(t('error.llm.connect', ['error' => $err]), LLM_ERR_CONNECT);
    }
    if ($code < 200 || $code >= 300) {
        $data = json_decode($rawHead, true);
        $msg  = $data['error']['message'] ?? mb_substr(trim($rawHead), 0, 180);
        throw new RuntimeException(t('error.llm.api', ['code' => $code, 'message' => $msg]), $code);
    }
    if ($streamErr !== '') {
        throw new RuntimeException(t('error.llm.model_error', ['message' => mb_substr($streamErr, 0, 300)]));
    }
    if (trim($content) === '') {
        throw new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
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
        throw new RuntimeException(t('error.llm.no_model'));
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
    // option on_tick : appelée toutes les ~2 s pendant l'attente (ex. ping SSE pour
    // qu'un reverse proxy ne coupe pas une connexion qui semble inactive)
    if (isset($options['on_tick']) && is_callable($options['on_tick'])) {
        $onTick = $options['on_tick'];
        $last   = microtime(true);
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, static function () use ($onTick, &$last): int {
            if (microtime(true) - $last >= 2.0) {
                $last = microtime(true);
                $onTick();
            }
            return 0;
        });
    }
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    if ($raw === false) {
        throw new RuntimeException(t('error.llm.connect', ['error' => $err]), LLM_ERR_CONNECT);
    }

    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? mb_substr(trim((string) $raw), 0, 200);
        throw new RuntimeException(t('error.llm.api', ['code' => $code, 'message' => $msg]), $code);
    }

    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
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
        throw new RuntimeException(t('error.llm.no_key'));
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
        throw new RuntimeException(t('error.llm.connect', ['error' => $err]), LLM_ERR_CONNECT);
    }

    $data = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300) {
        $msg = $data['error']['message'] ?? mb_substr(trim((string) $raw), 0, 200);
        throw new RuntimeException(t('error.llm.api', ['code' => $code, 'message' => $msg]), $code);
    }
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
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
        throw new RuntimeException(t('error.llm.no_json'));
    }
    $data = json_decode(substr($raw, $start, $end - $start + 1), true);
    if (!is_array($data)) {
        throw new RuntimeException(t('error.llm.bad_json'));
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

/**
 * Fournisseurs connus pour lesquels on sait que l'API n'accepte pas d'image :
 * on n'envoie pas la photo (évite un aller-retour en erreur).
 */
function llm_provider_without_vision(string $provider, string $model = ''): bool
{
    if ($provider === 'deepseek') {
        return true;
    }
    // via OpenRouter (ou adresse perso) : les modèles DeepSeek de discussion ne lisent pas non plus les images
    $m = strtolower($model);
    return str_contains($m, 'deepseek') && !str_contains($m, 'vl');
}

/**
 * Rédige (ou améliore) un prompt à partir de la fiche : description, attributs, prompts
 * existants (dont le prompt système) et, si possible, la photo principale analysée par
 * le modèle. Style : réaliste si la photo est une photo, sinon celui du dessin.
 *
 * @return array{body:string, used_image:bool, image_note:string}
 * @throws RuntimeException
 */
function llm_visual_prompt(array $persona, array $prompts, string $kind, string $title, string $body, int $skipId = 0): array
{
    $cfg = llm_config();
    if ($cfg['key'] === '') {
        throw new RuntimeException(t('error.llm.no_key'));
    }

    $kinds = ['image', 'negative', 'video', 'system', 'other'];
    if (!in_array($kind, $kinds, true)) {
        $kind = 'image';
    }

    // contexte : les autres prompts de la fiche (le système d'abord, il décrit souvent le physique)
    usort($prompts, static fn ($a, $b) => ((string) $a['kind'] === 'system' ? 0 : 1) <=> ((string) $b['kind'] === 'system' ? 0 : 1));
    $ctx = '';
    foreach ($prompts as $p) {
        if ((int) ($p['id'] ?? 0) === $skipId) {
            continue;
        }
        $ptitle = trim((string) ($p['title'] ?? ''));
        $chunk  = '[' . strtoupper((string) $p['kind']) . ($ptitle !== '' ? ' — ' . $ptitle : '') . "]\n" . trim((string) $p['body']) . "\n\n";
        if (mb_strlen($ctx) + mb_strlen($chunk) > 12000) {
            break;
        }
        $ctx .= $chunk;
    }

    $goal = [
        'image'    => "un prompt de génération d'image (portrait ou scène) qui fasse apparaître CETTE personne de façon reconnaissable",
        'negative' => "un prompt négatif (ce que le générateur d'images doit éviter) adapté à ce persona et à son style",
        'video'    => "un prompt de génération vidéo (plan, mouvement de caméra, action, ambiance) qui fasse apparaître CETTE personne de façon reconnaissable",
        'system'   => "un prompt système pour un agent conversationnel qui incarne ce persona (identité, apparence, voix, ton, limites)",
        'other'    => "un prompt utile pour ce persona",
    ][$kind];

    $system = <<<TXT
Tu es un expert en prompts pour les générateurs d'images et de vidéos (Stable Diffusion, Flux, Midjourney, DALL·E, Sora…) et pour les agents conversationnels.
Ta tâche : écrire {$goal}.

Méthode :
1. Rassemble TOUS les détails physiques disponibles dans la fiche, les prompts existants (surtout le prompt système) et la photo principale si elle est jointe :
   visage (forme, yeux et leur couleur, sourcils, nez, lèvres, peau, grains de beauté, taches de rousseur, cicatrices), cheveux (couleur, longueur, coupe, texture),
   âge apparent, origine apparente si elle est explicite, morphologie, taille, tenue habituelle, accessoires, maquillage, tatouages, expression, posture.
2. Détermine le STYLE à partir de la photo principale (et à défaut de la fiche) :
   - si c'est une vraie photo : prompt photoréaliste (type d'appareil ou d'objectif, focale, lumière, grain, profondeur de champ, rendu de la peau) ;
   - si c'est un dessin, un manga, un anime, une 3D, une peinture… : reprends précisément ce style (technique, trait, couleurs, ombrage, époque ou artiste de référence s'il est évident).
3. Les informations de la fiche priment sur ce que tu crois voir ; n'invente rien qui les contredise. Si un détail est inconnu, ne le comble pas au hasard.

Règles de sortie :
- Si un texte existant est fourni, garde son intention et ses choix, complète-le et améliore-le plutôt que de tout réécrire.
- Pour un prompt d'image ou de vidéo : écris en anglais (langue la mieux comprise par les générateurs), sauf si le texte existant est clairement dans une autre langue ; liste de segments séparés par des virgules, le sujet et le visage d'abord, puis tenue, pose, décor, lumière, cadrage, style et qualité.
- Pour un prompt système ou autre : écris en français, sauf si le texte existant est dans une autre langue.
- Réponds UNIQUEMENT avec le texte du prompt, sans titre, sans guillemets, sans explication, sans balises markdown.
TXT;

    $facts = [
        'nom'         => (string) $persona['name'],
        'accroche'    => (string) $persona['tagline'],
        'description' => (string) $persona['description'],
        'tags'        => json_list($persona['tags'] ?? '[]'),
        'attributs'   => json_list($persona['meta'] ?? '[]'),
    ];
    $userText = "FICHE\n" . json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
        . 'PROMPTS EXISTANTS' . "\n" . ($ctx !== '' ? $ctx : "(aucun)\n\n")
        . 'TYPE DEMANDÉ : ' . $kind . "\n"
        . ($title !== '' ? 'TITRE DU PROMPT : ' . $title . "\n" : '')
        . (trim($body) !== ''
            ? "TEXTE EXISTANT À COMPLÉTER ET AMÉLIORER :\n" . trim($body)
            : "TEXTE EXISTANT : (vide) — écris le prompt complet.");

    // photo principale : la couverture, sinon la première photo de la galerie
    $base = (string) ($persona['avatar'] ?? '');
    if ($base === '') {
        $first = fetch_photos((int) $persona['id'])[0] ?? null;
        $base  = $first ? (string) $first['file'] : '';
    }
    $image = '';
    $note  = '';
    if ($base !== '') {
        if (llm_provider_without_vision((string) $cfg['provider'], (string) $cfg['model'])) {
            $note = t('labels.ai_prompt.no_vision_provider');
        } else {
            $image = media_data_url($base, 1024);
        }
    }

    $opts = ['temperature' => 0.6, 'max_tokens' => 900, 'timeout' => 150];
    $text = static fn (bool $withImage): array => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $withImage
            ? [
                ['type' => 'text', 'text' => $userText . "\n\nPHOTO PRINCIPALE DU PERSONA : jointe ci-dessous. Analyse le visage et le style."],
                ['type' => 'image_url', 'image_url' => ['url' => $image]],
            ]
            : $userText . ($base !== '' ? "\n\n(La photo principale n'a pas pu être analysée : déduis le style de la fiche ; par défaut, photoréaliste.)" : '')],
    ];

    $usedImage = $image !== '';
    try {
        $res = llm_request($text($usedImage), $cfg, $opts);
    } catch (RuntimeException $e) {
        // modèle sans vision : l'API refuse l'image (400/415/422 ; OpenRouter répond 404
        // « aucun fournisseur n'accepte l'image » pour ce modèle) → on réessaie sans
        if (!$usedImage || !in_array((int) $e->getCode(), [400, 404, 415, 422], true)) {
            throw $e;
        }
        $usedImage = false;
        $note      = t('labels.ai_prompt.no_vision_model');
        $res       = llm_request($text(false), $cfg, $opts);
    }

    $out = trim((string) $res['content']);
    // modèles « thinking » ou bavards : on retire la réflexion et les clôtures markdown
    $out = trim((string) preg_replace('~<think>.*?</think>~s', '', $out));
    $out = trim((string) preg_replace('~^```[a-z]*\s*|\s*```$~i', '', $out));
    $out = trim($out, " \t\n\r\"“”«»");
    if ($out === '') {
        throw new RuntimeException(t('error.llm.empty'), LLM_ERR_EMPTY);
    }

    return ['body' => $out, 'used_image' => $usedImage, 'image_note' => $note];
}
