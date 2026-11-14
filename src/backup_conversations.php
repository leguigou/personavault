<?php
declare(strict_types=1);

/** Ajoute les discussions, leurs pièces jointes et les souvenirs au ZIP. */
function backup_add_conversations(ZipArchive $zip, string $root, array &$stats): void
{
    $pdo = db();
    $messages = $pdo->prepare('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id');
    $versions = $pdo->prepare('SELECT * FROM reply_versions WHERE conversation_id = ? ORDER BY id');
    $conversations = $pdo->query('SELECT * FROM conversations ORDER BY id')->fetchAll();
    $media = [];
    foreach ($conversations as &$conversation) {
        $messages->execute([$conversation['id']]);
        $conversation['messages'] = $messages->fetchAll();
        $versions->execute([$conversation['id']]);
        $conversation['reply_versions'] = $versions->fetchAll();

        foreach ($conversation['messages'] as $message) {
            $image = (string) $message['image'];
            if ($image === '' || isset($media[$image])) {
                continue;
            }
            $file = $image . '.webp';
            $path = (string) config('uploads') . '/' . $file;
            if (!preg_match('/^[a-z][0-9a-f]{16}$/D', $image) || !is_file($path)
                || !$zip->addFile($path, $root . '/conversations/media/' . $file)) {
                throw new RuntimeException(t('error.backup.chat_media_missing', ['file' => basename($file)]));
            }
            $media[$image] = true;
        }
        $stats['messages'] += count($conversation['messages']);
    }
    unset($conversation);

    $memories = $pdo->query('SELECT * FROM user_memories ORDER BY id')->fetchAll();
    $stats['conversations'] = count($conversations);
    $stats['memories'] = count($memories);
    $data = backup_json([
        'conversations' => $conversations,
        'memories' => $memories,
        'relationships' => $pdo->query('SELECT persona_id, address, automatic FROM relationships ORDER BY persona_id')->fetchAll(),
        'relationship_entries' => $pdo->query('SELECT * FROM relationship_entries ORDER BY id')->fetchAll(),
    ]);
    $max = (int) config('zip_max_entry_size', 40 * 1024 * 1024);
    if (strlen($data) > $max) {
        throw new RuntimeException(t('error.backup.entry_too_big_size', ['size' => human_bytes(strlen($data))]));
    }
    if (!$zip->addFromString($root . '/conversations/conversations.json', $data)) {
        throw new RuntimeException(t('error.backup.create'));
    }
}

/** Les identifiants du ZIP servent uniquement de références entre éléments importés. */
function backup_id(mixed $id): int
{
    return (is_int($id) || is_string($id)) ? (int) filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 0;
}

/** Insère des champs dont les noms proviennent du code et les valeurs de l'archive. */
function backup_insert_row(PDO $pdo, string $table, array $fields): int
{
    $columns = implode(', ', array_keys($fields));
    $params = implode(', ', array_fill(0, count($fields), '?'));
    $pdo->prepare("INSERT INTO $table ($columns) VALUES ($params)")->execute(array_values($fields));
    return (int) $pdo->lastInsertId();
}

/** Copie les champs attendus et applique les valeurs par défaut des anciennes archives. */
function backup_fields(array $row, array $defaults): array
{
    foreach ($defaults as $key => $default) {
        $value = $row[$key] ?? null;
        if (!is_scalar($value)) {
            continue;
        }
        $defaults[$key] = match (gettype($default)) {
            'integer' => (int) $value,
            'double'  => (float) $value,
            default   => (string) $value,
        };
    }
    return $defaults;
}

function backup_restore_conversations(ZipArchive $zip, array $index, string $root, array $personas, bool $legacy, array &$stats): void
{
    $entry = $root . 'conversations/conversations.json';
    $data = json_decode(backup_read_entry($zip, $index[$entry]), true);
    if (!is_array($data) || !is_array($data['conversations'] ?? null) || !is_array($data['memories'] ?? null)) {
        throw new RuntimeException(t('error.backup.invalid_chat_data'));
    }

    $personaIds = [];
    $ambiguous = [];
    foreach (array_filter($personas) as $persona) {
        $id = $persona['source_id'];
        if (!$id || isset($ambiguous[$id])) {
            continue;
        }
        if (isset($personaIds[$id])) {
            $ambiguous[$id] = true;
            unset($personaIds[$id]);
        } else {
            $personaIds[$id] = $persona['id'];
        }
    }
    // Le premier format ne stockait pas l'identifiant dans la fiche. Le cas
    // d'une seule fiche et d'un seul identifiant référencé est sans ambiguïté.
    if ($legacy && !$personaIds && count($personas) === 1 && ($persona = reset($personas))) {
        $referenced = [];
            foreach ([$data['conversations'], $data['memories'], (array) ($data['relationships'] ?? [])] as $rows) {
            foreach ($rows as $row) {
                $id = backup_id($row['persona_id'] ?? null);
                if ($id) {
                    $referenced[$id] = true;
                }
            }
        }
        if (count($referenced) === 1) {
            $personaIds[array_key_first($referenced)] = $persona['id'];
        }
    }

    $conversationIds = [];
    foreach ($data['conversations'] as $conversation) {
        $title = backup_str($conversation['title'] ?? '', 120, 'conversations.json');
        try {
            $sourceId = backup_id($conversation['id'] ?? null);
            if (!$sourceId || isset($conversationIds[$sourceId]) || !is_array($conversation['messages'] ?? null)
                || (isset($conversation['reply_versions']) && !is_array($conversation['reply_versions']))) {
                throw new RuntimeException(t('error.backup.invalid_chat_data'));
            }
            $personaId = $personaIds[backup_id($conversation['persona_id'] ?? null)] ?? null;
            if (!$personaId) {
                throw new RuntimeException(t('error.backup.chat_persona_missing'));
            }
            $conversationIds[$sourceId] = backup_restore_chat($zip, $index, $root, $conversation, $personaId, $legacy, $stats['errors']);
            $stats['conversations']++;
            $stats['messages'] += count($conversation['messages']);
        } catch (Throwable $e) {
            $stats['errors'][] = t('error.backup.item', ['item' => $title, 'error' => public_error($e, t('error.internal_short'))]);
        }
    }

    $now = gmdate('Y-m-d H:i:s');
    $categories = facts_categories();
    $confidences = facts_confidences();
    $stats['memories'] += db_tx(static function (PDO $pdo) use ($data, $personaIds, $conversationIds, $now, $categories, $confidences, &$stats): int {
        $count = 0;
        foreach ($data['memories'] as $memory) {
            if (!is_array($memory) || !array_key_exists('persona_id', $memory)
                || !is_string($memory['content'] ?? null) || trim($memory['content']) === '') {
                $stats['errors'][] = t('error.backup.invalid_chat_data');
                continue;
            }
            $oldPersona = $memory['persona_id'] ?? null;
            $personaId = $personaIds[backup_id($oldPersona)] ?? null;
            if ($oldPersona !== null && !$personaId) {
                $stats['errors'][] = t('error.backup.chat_persona_missing');
                continue;
            }
            $fields = backup_fields($memory, [
                'category' => 'fait', 'content' => '', 'confidence' => 'confirme',
                'created_at' => $now, 'updated_at' => backup_str($memory['created_at'] ?? $now),
            ]);
            $fields['category'] = isset($categories[$fields['category']]) ? $fields['category'] : 'fait';
            $fields['confidence'] = isset($confidences[$fields['confidence']]) ? $fields['confidence'] : 'confirme';
            $source = $conversationIds[backup_id($memory['source_conversation_id'] ?? null)] ?? null;
            $story = ($memory['subject'] ?? '') === 'story';
            $sourceMessage = $story ? ($source['messages'][backup_id($memory['source_message_id'] ?? null)] ?? null) : null;
            if ($story && (!$personaId || $personaId !== ($source['persona_id'] ?? null) || !$sourceMessage)) {
                $stats['errors'][] = t('error.backup.invalid_chat_data');
                continue;
            }
            backup_insert_row($pdo, 'user_memories', $fields + [
                'persona_id' => $personaId,
                'subject' => $story ? 'story' : 'user',
                'source_conversation_id' => $source['id'] ?? null,
                'source_message_id' => $sourceMessage,
            ]);
            $count++;
        }
        return $count;
    });

    db_tx(static function (PDO $pdo) use ($data, $personaIds, $conversationIds, $now): void {
        $sources = [];
        foreach ($conversationIds as $conversation) {
            foreach ($conversation['messages'] as $oldId => $newId) {
                $sources[$conversation['persona_id']][$oldId] = $newId;
            }
        }
        $settings = $pdo->prepare('INSERT INTO relationships (persona_id, address, automatic) VALUES (?, ?, ?) ON CONFLICT DO NOTHING');
        foreach ((array) ($data['relationships'] ?? []) as $row) {
            $pid = $personaIds[backup_id($row['persona_id'] ?? null)] ?? 0;
            if ($pid) {
                $address = $row['address'] ?? '';
                $settings->execute([$pid, in_array($address, ['tu', 'vous'], true) ? $address : '', empty($row['automatic']) ? 0 : 1]);
            }
        }
        foreach ((array) ($data['relationship_entries'] ?? []) as $row) {
            $pid = $personaIds[backup_id($row['persona_id'] ?? null)] ?? 0;
            $kind = $row['kind'] ?? '';
            $content = $row['content'] ?? '';
            if (!$pid || !in_array($kind, ['agreement', 'change', 'conflict'], true) || !is_string($content) || trim($content) === '') {
                continue;
            }
            backup_insert_row($pdo, 'relationship_entries', [
                'persona_id' => $pid, 'kind' => $kind, 'content' => mb_substr($content, 0, 400),
                'resolved' => $kind === 'conflict' && !empty($row['resolved']) ? 1 : 0,
                'manual' => empty($row['manual']) ? 0 : 1,
                'deleted' => empty($row['deleted']) ? 0 : 1,
                'source_message_id' => $sources[$pid][backup_id($row['source_message_id'] ?? null)] ?? null,
                'updated_at' => backup_str($row['updated_at'] ?? $now, 40),
            ]);
        }
    });
}

/** Les fichiers sont préparés avant la transaction, puis nettoyés si l'insertion échoue. */
function backup_restore_chat(ZipArchive $zip, array $index, string $root, array $conversation, int $personaId, bool $legacy, array &$errors): array
{
    $media = [];
    try {
        foreach ($conversation['messages'] as $message) {
            $image = backup_str($message['image'] ?? '');
            if ($image === '' || isset($media[$image])) {
                continue;
            }
            try {
                $path = $root . 'conversations/media/' . $image . '.webp';
                if (!preg_match('/^[a-z][0-9a-f]{16}$/D', $image) || !isset($index[$path])) {
                    throw new RuntimeException(t('error.backup.chat_media_missing', ['file' => basename($image) . '.webp']));
                }
                $media[$image] = store_gd_image(decode_image_string(backup_read_entry($zip, $index[$path])));
            } catch (Throwable $e) {
                $media[$image] = '';
                $errors[] = t('error.backup.photo_skipped', ['file' => basename($image) . '.webp', 'error' => public_error($e, t('error.internal_short'))]);
            }
        }

        return db_tx(static function (PDO $pdo) use ($conversation, $personaId, $legacy, $media): array {
            $now = gmdate('Y-m-d H:i:s');
            $fields = backup_fields($conversation, [
                'title' => '', 'provider' => '', 'model' => '', 'model_override' => '',
                'temperature' => 0.85, 'max_tokens' => 400, 'context_window' => 8192, 'directive' => '',
                'memory_enabled' => 1, 'summary' => '', 'compressions' => 0, 'facts_enabled' => 1,
                'title_auto' => 1, 'auto_followup' => 1, 'last_followup_at' => '', 'unread' => 0, 'pinned' => 0,
                'opening_questions' => '',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $cid = backup_insert_row($pdo, 'conversations', $fields + [
                'persona_id' => $personaId,
                'reasoning_effort' => chat_reasoning_effort($conversation['reasoning_effort'] ?? ''),
                'klipy_formats' => max(-1, min(7, (int) ($conversation['klipy_formats'] ?? -1))),
            ]);
            $messageIds = [];
            $assistantIds = [];
            $userIds = [];
            $selections = [];
            foreach ($conversation['messages'] as $message) {
                $sourceId = backup_id($message['id'] ?? null);
                if (!is_array($message) || !in_array($message['role'] ?? null, ['user', 'assistant', 'memory', 'notice'], true)
                    || !is_string($message['content'] ?? null) || (!$legacy && !$sourceId) || isset($messageIds[$sourceId])) {
                    throw new RuntimeException(t('error.backup.invalid_chat_data'));
                }
                $fields = backup_fields($message, [
                    'role' => '', 'content' => '', 'model' => '', 'prompt_tokens' => 0,
                    'completion_tokens' => 0, 'latency_ms' => 0, 'split_at' => 0, 'split_points' => '', 'created_at' => $now,
                ]);
                $reactions = chat_bubble_reactions($fields + $message);
                $first = $reactions[0] ?? '';
                unset($reactions[0]);
                $mid = backup_insert_row($pdo, 'messages', $fields + [
                    'conversation_id' => $cid, 'image' => $media[backup_str($message['image'] ?? '')] ?? '',
                    'reaction' => $first, 'bubble_reactions' => chat_encode_reactions($reactions),
                    'klipy_url' => klipy_media_url($message['klipy_url'] ?? $message['gif_url'] ?? ''),
                    'reaction_reply' => $fields['role'] === 'assistant' && !empty($message['reaction_reply']) ? 1 : 0,
                ]);
                if ($sourceId) {
                    $messageIds[$sourceId] = $mid;
                    if ($fields['role'] === 'user') {
                        $userIds[$sourceId] = $mid;
                    } elseif ($fields['role'] === 'assistant') {
                        $assistantIds[$sourceId] = $mid;
                    }
                }
                $versionId = backup_id($message['version_id'] ?? null);
                if ($fields['role'] === 'assistant' && $versionId) {
                    $selections[$mid] = [$versionId, $userIds ? end($userIds) : null];
                }
            }

            $versions = [];
            foreach ($conversation['reply_versions'] ?? [] as $version) {
                $sourceId = backup_id($version['id'] ?? null);
                $userId = $userIds[backup_id($version['user_message_id'] ?? null)] ?? null;
                if (!$sourceId || !$userId || isset($versions[$sourceId]) || !is_string($version['content'] ?? null)) {
                    throw new RuntimeException(t('error.backup.invalid_chat_data'));
                }
                $fields = backup_fields($version, ['content' => '', 'model' => '', 'split_points' => '', 'created_at' => $now]);
                $reactions = chat_bubble_reactions(['role' => 'assistant'] + $fields + $version);
                $first = $reactions[0] ?? '';
                unset($reactions[0]);
                $vid = backup_insert_row($pdo, 'reply_versions', $fields + [
                    'conversation_id' => $cid, 'user_message_id' => $userId,
                    'reaction' => $first, 'bubble_reactions' => chat_encode_reactions($reactions),
                    'klipy_url' => klipy_media_url($version['klipy_url'] ?? $version['gif_url'] ?? ''),
                ]);
                $versions[$sourceId] = ['id' => $vid, 'user_id' => $userId];
            }

            $select = $pdo->prepare('UPDATE messages SET version_id = ? WHERE id = ?');
            foreach ($selections as $mid => [$sourceId, $userId]) {
                if (!$userId || ($versions[$sourceId]['user_id'] ?? null) !== $userId) {
                    throw new RuntimeException(t('error.backup.invalid_chat_data'));
                }
                $select->execute([$versions[$sourceId]['id'], $mid]);
            }

            $pdo->prepare('UPDATE conversations SET last_compressed_id = ?, facts_cursor = ? WHERE id = ?')->execute([
                $messageIds[backup_id($conversation['last_compressed_id'] ?? null)] ?? 0,
                $messageIds[backup_id($conversation['facts_cursor'] ?? null)] ?? 0,
                $cid,
            ]);
            return ['id' => $cid, 'persona_id' => $personaId, 'messages' => $assistantIds];
        });
    } catch (Throwable $e) {
        foreach (array_filter($media) as $image) {
            delete_media($image);
        }
        throw $e;
    }
}
