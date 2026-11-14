<?php
declare(strict_types=1);

const CHAT_GIF_QUERIES = [
    'celebrate' => 'happy dance', 'laugh' => 'laughing reaction', 'surprise' => 'surprised reaction',
    'comfort' => 'comforting hug', 'excited' => 'excited reaction', 'facepalm' => 'funny facepalm',
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

function klipy_search_media(string $format, string $mood, string $key, array $seen): string
{
    if (!isset(CHAT_GIF_QUERIES[$mood])) {
        return '';
    }
    $params = ['q' => CHAT_GIF_QUERIES[$mood], 'per_page' => 8, 'content_filter' => 'high'];
    if ($format !== 'clip') {
        $params['format_filter'] = $format === 'gif' ? 'gif' : 'gif,webp';
    }
    $base = (string) config('klipy_api_base', 'https://api.klipy.com/api/v1');
    $ch = curl_init(rtrim($base, '/') . '/' . rawurlencode($key) . '/' . $format . 's/search?' . http_build_query($params));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4]);
    $body = curl_exec($ch);
    if (!is_string($body) || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
        return '';
    }
    $data = json_decode($body, true);
    $results = $data['data']['data'] ?? null;
    if (!is_array($results)) {
        return '';
    }
    foreach ($results as $result) {
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
    return '';
}

function chat_spontaneous_klipy(int $cid, array $persona, array $conv, string $message, string $reply): string
{
    $mask = (int) $conv['klipy_formats'];
    if ($mask === -1) {
        $mask = (int) $persona['klipy_formats'];
    }
    $formats = array_filter(KLIPY_FORMATS, static fn (int $bit): bool => ($bit & $mask) !== 0);
    if (!$formats || ($key = setting('klipy_api_key')) === '') {
        return '';
    }
    $stmt = db()->prepare("SELECT klipy_url FROM messages WHERE conversation_id = ? AND role = 'assistant'
        AND reaction_reply = 0 ORDER BY id DESC LIMIT 8");
    $stmt->execute([$cid]);
    $recent = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (array_filter($recent)) {
        return '';
    }
    try {
        $result = llm_request([
            ['role' => 'system', 'content' => 'Choisis un média KLIPY facultatif qui accompagne cette réponse et convient au style du persona. '
                . 'Réponds par none ou par format:émotion. Formats autorisés : ' . implode(', ', array_keys($formats))
                . '. Émotions : ' . implode(', ', array_keys(CHAT_GIF_QUERIES))
                . '. Choisis none par défaut, notamment pour une demande pratique, un sujet intime ou une situation grave.'],
            ['role' => 'user', 'content' => json_encode([
                'persona' => $persona['name'],
                'style' => mb_substr((string) $conv['directive'], 0, 400),
                'message' => mb_substr($message, 0, 800),
                'réponse' => mb_substr($reply, 0, 800),
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)],
        ], chat_llm_config_for($conv), [
            'max_tokens' => 32, 'temperature' => 0, 'timeout' => 5, 'reasoning_effort' => 'none', 'purpose' => 'gif',
        ]);
        [$format, $mood] = array_pad(explode(':', strtolower(trim($result['content'])), 2), 2, '');
        if (!isset($formats[$format], CHAT_GIF_QUERIES[$mood])) {
            return '';
        }
        $stmt = db()->prepare("SELECT klipy_url FROM messages WHERE conversation_id = ? AND klipy_url <> '' ORDER BY id DESC LIMIT 20");
        $stmt->execute([$cid]);
        return klipy_search_media($format, $mood, $key, $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return '';
    }
}
