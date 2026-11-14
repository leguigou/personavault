<?php
declare(strict_types=1);

/*
 * Export d'une conversation : fonctions de mise en forme (texte brut, Markdown, page imprimable),
 * sans accès à la base ni au serveur — testées par bin/selftest.php.
 * Les routes sont dans export.php.
 *
 * Choix : les messages « user » et « assistant » sont exportés en entier ; les traces
 * « memory » (mémoire mise à jour) et « notice » (modèle de secours…) sont gardées, mais
 * discrètement (ligne entre parenthèses, sans nom ni date) : elles font partie de ce que
 * l'utilisateur a vu dans la conversation, sans gêner la lecture.
 */

/** Date et heure locales d'une date SQL (UTC en base) : « 8 nov. 2026 14:03 ». */
function export_datetime(?string $sqlDate): string
{
    if (!$sqlDate) {
        return '';
    }
    try {
        $tz    = new DateTimeZone((string) config('timezone', 'Europe/Paris'));
        $local = (new DateTimeImmutable($sqlDate, new DateTimeZone('UTC')))->setTimezone($tz);
    } catch (Throwable $e) {
        return fr_date($sqlDate);
    }
    // fr_date() lit une date UTC : midi UTC tombe le même jour quel que soit le fuseau du serveur
    return t('export.datetime', [
        'date' => fr_date($local->format('Y-m-d') . ' 12:00:00'),
        'time' => $local->format(t('export.time_format')),
    ]);
}

/** Nom de fichier propre (ASCII) à partir du titre : « week-end-a-lisbonne-2026-11-08.md ». */
function export_filename(string $title, string $ext, ?string $day = null): string
{
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(
        (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title)
    )), '-');
    if ($slug === '') {
        $slug = 'conversation';
    }
    return substr($slug, 0, 60) . '-' . ($day ?? date('Y-m-d')) . '.' . $ext;
}

/** Nom affiché pour un message : « Moi » ou le nom du persona. */
function export_author(string $role, string $personaName): string
{
    return $role === 'user' ? t('export.me') : $personaName;
}

/** Échappe le Markdown qui changerait la structure (HTML, titres, citations, listes en début de ligne). */
function export_md_escape(string $text): string
{
    $text = str_replace(['\\', '<', '>'], ['\\\\', '&lt;', '&gt;'], $text);
    $lines = preg_split('/\R/u', $text) ?: [];
    foreach ($lines as &$line) {
        // un début de ligne en « # », « - », « + », « 1. » ou « --- » deviendrait titre / liste / séparateur
        $line = (string) preg_replace('/^(\s*)([#+\-=|]|\d+[.)])/u', '$1\\\\$2', $line);
    }
    unset($line);
    // retour à la ligne simple conservé (deux espaces en fin de ligne)
    return implode("  \n", $lines);
}

/**
 * Export complet, morceau par morceau (pour l'envoyer en flux sans tout garder en mémoire).
 *
 * @param iterable<array> $rows   messages du plus ancien au plus récent (role, content, image, created_at)
 * @param array           $meta   title, persona, count, exported_at (date SQL UTC)
 * @param string          $format 'txt' ou 'md'
 * @return Generator<string>
 */
function export_chunks(iterable $rows, array $meta, string $format): Generator
{
    $md      = $format === 'md';
    $title   = (string) $meta['title'];
    $persona = (string) $meta['persona'];
    $when    = export_datetime((string) $meta['exported_at']);
    $count   = t('export.count', (int) $meta['count']);

    if ($md) {
        yield '# ' . export_md_escape($title) . "\n\n"
            . '- ' . t('export.with', ['name' => export_md_escape($persona)]) . "\n"
            . '- ' . t('export.exported_at', ['date' => $when]) . "\n"
            . '- ' . $count . "\n\n---\n\n";
    } else {
        $head = $title . "\n" . t('export.with', ['name' => $persona]) . "\n"
              . t('export.exported_at', ['date' => $when]) . ' · ' . $count;
        yield $head . "\n" . str_repeat('=', 40) . "\n\n";
    }

    foreach ($rows as $row) {
        $role    = (string) $row['role'];
        $content = trim(str_replace("\r\n", "\n", (string) $row['content']));

        if ($role === 'memory' || $role === 'notice') {
            if ($content === '') {
                continue;
            }
            yield $md ? '> *' . export_md_escape($content) . "*\n\n" : '  (' . $content . ")\n\n";
            continue;
        }
        if ($role !== 'user' && $role !== 'assistant') {
            continue;
        }

        $date   = export_datetime((string) ($row['created_at'] ?? ''));
        $author = export_author($role, $persona);
        $photo  = (string) ($row['image'] ?? '') !== '' ? t('export.photo') : '';

        if ($md) {
            $out = '**' . export_md_escape($author) . '**' . ($date !== '' ? ' · *' . $date . '*' : '') . "\n\n";
            if ($photo !== '') {
                $out .= '*' . $photo . "*\n\n";
            }
            if ($content !== '') {
                $out .= export_md_escape($content) . "\n\n";
            }
            yield $out;
        } else {
            $body = trim($photo . ($photo !== '' && $content !== '' ? ' ' : '') . $content);
            yield ($date !== '' ? '[' . $date . '] ' : '') . t('export.says', ['name' => $author]) . ' ' . $body . "\n\n";
        }
    }
}

/** Export complet en une chaîne (tests, petites conversations). */
function export_render(iterable $rows, array $meta, string $format): string
{
    return implode('', iterator_to_array(export_chunks($rows, $meta, $format), false));
}

/** Bulles d'un message (découpage enregistré dans split_points), comme dans views/chat.php. */
function export_bubbles(array $row): array
{
    return array_values(array_filter(array_map('trim', chat_message_bubbles($row)), static fn (string $part): bool => $part !== ''));
}
