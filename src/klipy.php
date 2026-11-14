<?php
declare(strict_types=1);

const CHAT_GIF_QUERIES = [
    'celebrate' => 'happy dance', 'laugh' => 'laughing reaction', 'surprise' => 'surprised reaction',
    'comfort' => 'comforting hug', 'excited' => 'excited reaction', 'facepalm' => 'funny facepalm',
];

function klipy_gif_url(mixed $url): string
{
    return is_string($url) && preg_match('~\Ahttps://static\.klipy\.com/[a-z0-9/_\-.%]+\.gif\z~i', $url) ? $url : '';
}

function klipy_search_gif(string $mood, string $key, array $seen): string
{
    if (!isset(CHAT_GIF_QUERIES[$mood])) {
        return '';
    }
    $query = http_build_query(['q' => CHAT_GIF_QUERIES[$mood], 'per_page' => 8, 'content_filter' => 'high', 'format_filter' => 'gif']);
    $base = (string) config('klipy_api_base', 'https://api.klipy.com/api/v1');
    $ch = curl_init(rtrim($base, '/') . '/' . rawurlencode($key) . '/gifs/search?' . $query);
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
        if (!is_array($result) || ($result['type'] ?? 'gif') !== 'gif') {
            continue;
        }
        $gif = klipy_gif_url($result['file']['md']['gif']['url'] ?? $result['file']['sm']['gif']['url'] ?? '');
        if ($gif !== '' && !in_array($gif, $seen, true)) {
            return $gif;
        }
    }
    return '';
}

function chat_spontaneous_gif(int $cid, array $persona, array $conv, string $message, string $reply): string
{
    if (empty($persona['spontaneous_gifs']) || ($key = setting('klipy_api_key')) === '') {
        return '';
    }
    $stmt = db()->prepare("SELECT gif_url FROM messages WHERE conversation_id = ? AND role = 'assistant'
        AND reaction_reply = 0 ORDER BY id DESC LIMIT 8");
    $stmt->execute([$cid]);
    $recent = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (array_filter($recent)) {
        return '';
    }
    try {
        $result = llm_request([
            ['role' => 'system', 'content' => 'Choisis une réaction GIF facultative qui accompagne cette réponse et convient au style du persona. '
                . 'Réponds par un seul code parmi none, ' . implode(', ', array_keys(CHAT_GIF_QUERIES))
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
        $mood = strtolower(trim($result['content']));
        if (!isset(CHAT_GIF_QUERIES[$mood])) {
            return '';
        }
        $stmt = db()->prepare("SELECT gif_url FROM messages WHERE conversation_id = ? AND gif_url <> '' ORDER BY id DESC LIMIT 20");
        $stmt->execute([$cid]);
        return klipy_search_gif($mood, $key, $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable) {
        return '';
    }
}
