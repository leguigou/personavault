<?php
declare(strict_types=1);

const CHAT_GIF_QUERIES = [
    'celebrate' => 'happy dance', 'laugh' => 'laughing reaction', 'surprise' => 'surprised reaction',
    'comfort' => 'comforting hug', 'excited' => 'excited reaction', 'facepalm' => 'funny facepalm',
    'wink' => 'playful wink', 'hello' => 'friendly wave',
];

const KLIPY_FORMATS = ['gif' => 1, 'sticker' => 2, 'clip' => 4];

function klipy_format_labels(): array
{
    return ['gif' => t('chat.klipy_gifs'), 'sticker' => t('chat.klipy_stickers'), 'clip' => t('chat.klipy_clips')];
}

function klipy_format_mask(mixed $formats): int
{
    $mask = 0;
    foreach (KLIPY_FORMATS as $format => $bit) {
        if (is_array($formats) && in_array($format, $formats, true)) {
            $mask |= $bit;
        }
    }
    return $mask;
}

function klipy_media_url(mixed $url): string
{
    return is_string($url) && preg_match('~\Ahttps://static\.klipy\.com/[a-z0-9/_\-.%]+\.(?:gif|webp|png|mp4)\z~i', $url) ? $url : '';
}

function klipy_search_media(string $format, string $mood, string $key, array $seen, bool $diagnostic = false): string
{
    if (!isset(CHAT_GIF_QUERIES[$mood])) {
        return '';
    }
    $params = ['q' => CHAT_GIF_QUERIES[$mood], 'per_page' => 8, 'content_filter' => 'high'];
    if ($format !== 'clip') {
        $params['format_filter'] = $format === 'gif' ? 'gif' : 'gif,webp';
    }
    // Si la première page contient uniquement des médias déjà vus, chercher plus loin.
    $maxPages = $seen ? ($diagnostic ? 3 : 2) : 1;
    for ($page = 1; $page <= $maxPages; $page++) {
        $data = klipy_search_page($format, $key, $params + ['page' => $page], $diagnostic);
        foreach ($data['data'] ?? [] as $result) {
            if (!is_array($result) || ($result['type'] ?? $format) !== $format) {
                continue;
            }
            $file = $result['file'] ?? [];
            $url = match ($format) {
                'clip' => $file['mp4'] ?? '',
                'sticker' => $file['md']['webp']['url'] ?? $file['md']['gif']['url'] ?? $file['md']['png']['url']
                    ?? $file['sm']['webp']['url'] ?? $file['sm']['gif']['url'] ?? '',
                default => $file['md']['gif']['url'] ?? $file['sm']['gif']['url'] ?? '',
            };
            $url = klipy_media_url($url);
            if ($url !== '' && !in_array($url, $seen, true)) {
                return $url;
            }
        }
        if (empty($data['has_next'])) {
            break;
        }
    }
    return '';
}

function klipy_search_page(string $format, string $key, array $params, bool $diagnostic): array
{
    $base = (string) config('klipy_api_base', 'https://api.klipy.com/api/v1');
    $ch = curl_init(rtrim($base, '/') . '/' . rawurlencode($key) . '/' . $format . 's/search?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $data = $status === 200 && is_string($body) ? json_decode($body, true) : null;
    $results = $data['data']['data'] ?? null;
    if (($data['result'] ?? null) === false || !is_array($results)) {
        if ($diagnostic) {
            throw new RuntimeException(match ($status) {
                400, 401, 403, 404 => t('settings.chat.klipy_test_rejected', ['status' => $status]),
                429 => t('settings.chat.klipy_test_limit'),
                default => t('settings.chat.klipy_test_unavailable'),
            });
        }
        return [];
    }
    return $data['data'];
}

function chat_seen_klipy(int $cid): array
{
    $stmt = db()->prepare("SELECT klipy_url FROM messages WHERE conversation_id = ? AND klipy_url <> ''
        UNION SELECT klipy_url FROM reply_versions WHERE conversation_id = ? AND klipy_url <> ''");
    $stmt->execute([$cid, $cid]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/** Les demandes explicites utilisent des mots-clés prédéfinis, sans transmettre le message à KLIPY. */
function klipy_requested_media(string $message): ?array
{
    if (!preg_match('/(?:^\s*(?:\/|(?:un|a)\s+(?:petit\s+)?)|\b(?:envoi(?:e[sz]?|yer)|montr(?:e[sz]?|er)|trouv(?:e[sz]?|er)|cherch(?:e[sz]?|er)|ajout(?:e[sz]?|er)|affich(?:e[sz]?|er)|partag(?:e[sz]?|er)|donn(?:e[sz]?|er)|send|show|veux|voudrais)\b[^.!?\n]{0,80}?\s)(gif|sticker|clip)\b/iu', $message, $match)) {
        return null;
    }
    $mood = match (true) {
        (bool) preg_match('/clin d[’\x27](?:œ|oe)il|wink|sédu|provocat|sexy|flirt|pomme|apple/iu', $message) => 'wink',
        (bool) preg_match('/f[êe]t|c[ée]l[ée]br|\bdans[ea]|réuss|applaud|celebr/iu', $message) => 'celebrate',
        (bool) preg_match('/rire|dr[oô]le|laugh|funny/iu', $message) => 'laugh',
        (bool) preg_match('/c[âa]lin|réconfort|hug/iu', $message) => 'comfort',
        (bool) preg_match('/surpris|étonn|shock/iu', $message) => 'surprise',
        default => 'hello',
    };
    return [strtolower($match[1]), $mood];
}

function chat_spontaneous_klipy(int $cid, array $persona, array $conv, string $message, string $reply, ?array $request = null, ?array $modelConfig = null): string
{
    $mask = (int) $conv['klipy_formats'];
    if ($mask === -1) {
        $mask = (int) $persona['klipy_formats'];
    }
    $formats = array_filter(KLIPY_FORMATS, static fn (int $bit): bool => ($bit & $mask) !== 0);
    if ($request !== null && !isset($formats[$request[0]])) {
        throw new RuntimeException(t('chat.klipy_format_disabled'));
    }
    $key = setting('klipy_api_key');
    if ($request !== null) {
        if ($key === '') {
            throw new RuntimeException(t('settings.chat.klipy_test_missing'));
        }
        $url = klipy_search_media($request[0], $request[1], $key, chat_seen_klipy($cid), true);
        if ($url === '') {
            throw new RuntimeException(t('chat.klipy_no_media'));
        }
        return $url;
    }
    if (!$formats || $key === '') {
        return '';
    }
    $stmt = db()->prepare("SELECT klipy_url FROM messages WHERE conversation_id = ? AND role = 'assistant'
        AND reaction_reply = 0 ORDER BY id DESC LIMIT 4");
    $stmt->execute([$cid]);
    $recent = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (array_filter($recent)) {
        return '';
    }
    try {
        $result = llm_request([
            ['role' => 'system', 'content' => 'Choisis un média KLIPY facultatif qui accompagne cette réponse et convient au style du persona. '
                . 'Prends cette initiative même si l’utilisateur ne demande pas de média. '
                . 'Pour un moment drôle, une bonne nouvelle, une surprise ou un échange chaleureux, choisis un média si cette réaction paraît naturelle. '
                . 'Réponds uniquement par none ou par format:émotion. Formats autorisés : ' . implode(', ', array_keys($formats))
                . '. Émotions : ' . implode(', ', array_keys(CHAT_GIF_QUERIES))
                . '. Réponds none si le média n’apporte rien, notamment pour une demande pratique, un sujet intime ou une situation grave.'],
            ['role' => 'user', 'content' => json_encode([
                'persona' => $persona['name'],
                'style' => mb_substr((string) $conv['directive'], 0, 400),
                'message' => mb_substr($message, 0, 800),
                'réponse' => mb_substr($reply, 0, 800),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)],
        ], $modelConfig ?? chat_llm_config_for($conv), [
            'max_tokens' => 32, 'temperature' => 0, 'timeout' => 8, 'reasoning_effort' => 'none', 'purpose' => 'gif',
        ]);
        if (!preg_match('/\A\s*(?:```(?:text)?\s*)?([a-z]+)\s*:\s*([a-z]+)\s*(?:```\s*)?\z/i', $result['content'], $choice)) {
            return '';
        }
        $format = strtolower($choice[1]);
        $mood = strtolower($choice[2]);
        if (!isset($formats[$format], CHAT_GIF_QUERIES[$mood])) {
            return '';
        }
        return klipy_search_media($format, $mood, $key, chat_seen_klipy($cid));
    } catch (Throwable) {
        return '';
    }
}
