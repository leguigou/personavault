<?php
declare(strict_types=1);

/** Sujets récents fournis à l'analyse mémoire, avec les sujets déjà clos pour éviter les redites. */
function chat_topics_for_analysis(int $cid): array
{
    $stmt = db()->prepare('SELECT id, content, due_on, closed FROM conversation_topics
        WHERE conversation_id = ? ORDER BY closed, id DESC LIMIT 20');
    $stmt->execute([$cid]);
    return $stmt->fetchAll();
}

function chat_topic_date(mixed $value): string
{
    if (!is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
        return '';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

/** Appelé dans la transaction de l'analyse, une fois ses messages sources vérifiés. */
function chat_topics_save_analysis(PDO $pdo, int $cid, array $json, array $sources, array $known): void
{
    $open = array_column(array_filter($known, static fn (array $topic): bool => !(int) $topic['closed']), null, 'id');
    foreach ((array) ($json['closed_topics'] ?? []) as $rawId) {
        $id = filter_var($rawId, FILTER_VALIDATE_INT);
        if (isset($open[$id])) {
            chat_topic_close($pdo, $cid, (int) $id);
        }
    }

    $seen = array_fill_keys(array_map('facts_norm', array_column($known, 'content')), true);
    $insert = $pdo->prepare('INSERT OR IGNORE INTO conversation_topics (conversation_id, source_message_id, content, due_on)
        VALUES (?, ?, ?, ?)');
    foreach (array_slice((array) ($json['open_topics'] ?? []), 0, 3) as $candidate) {
        if (!is_array($candidate) || !is_string($candidate['content'] ?? null)) {
            continue;
        }
        $source = filter_var($candidate['source'] ?? null, FILTER_VALIDATE_INT);
        $message = $sources[$source - 1] ?? null;
        $content = mb_substr(trim($candidate['content']), 0, 240);
        $key = facts_norm($content);
        if (!$message || $key === '' || isset($seen[$key])) {
            continue;
        }
        $sourceDay = (new DateTimeImmutable((string) $message['created_at'], new DateTimeZone('UTC')))
            ->setTimezone(chat_now()->getTimezone());
        $due = chat_topic_date($candidate['due_on'] ?? null) ?: $sourceDay->modify('+1 day')->format('Y-m-d');
        $insert->execute([$cid, $message['id'], $content, max($due, $sourceDay->format('Y-m-d'))]);
        $seen[$key] = true;
    }
}

function chat_topic_due(array $conv, ?DateTimeImmutable $now = null): ?array
{
    if ((int) ($conv['memory_enabled'] ?? 0) !== 1 || (int) ($conv['facts_enabled'] ?? 0) !== 1) {
        return null;
    }
    $day = ($now ?? chat_now())->format('Y-m-d');
    $stmt = db()->prepare('SELECT id, content FROM conversation_topics
        WHERE conversation_id = ? AND closed = 0 AND due_on <= ? AND last_offered_on < ?
        ORDER BY due_on, id LIMIT 1');
    $stmt->execute([$conv['id'], $day, $day]);
    return $stmt->fetch() ?: null;
}

function chat_topic_close(PDO $pdo, int $cid, int $topicId): bool
{
    $stmt = $pdo->prepare('UPDATE conversation_topics SET closed = 1 WHERE conversation_id = ? AND id = ? AND closed = 0');
    $stmt->execute([$cid, $topicId]);
    return $stmt->rowCount() === 1;
}

/** Une proposition ignorée par le modèle peut redevenir pertinente un autre jour. */
function chat_topic_mark_offered(PDO $pdo, int $cid, int $topicId, ?DateTimeImmutable $now = null): bool
{
    $day = ($now ?? chat_now())->format('Y-m-d');
    $stmt = $pdo->prepare('UPDATE conversation_topics SET last_offered_on = ?
        WHERE conversation_id = ? AND id = ? AND closed = 0 AND last_offered_on < ?');
    $stmt->execute([$day, $cid, $topicId, $day]);
    return $stmt->rowCount() === 1;
}

function chat_topic_context(array $topic): string
{
    return "Sujet laissé en suspens dans cette discussion : " . json_encode($topic['content'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)
        . "\nIl est temps d'y revenir si c'est naturel. S'il est déjà abordé ou réglé dans les échanges récents ou par le dernier message, laisse la discussion avancer. "
        . "Respecte la demande actuelle et n'invente aucun dénouement : si tu ne connais pas la suite, demande simplement des nouvelles.";
}
