<?php
declare(strict_types=1);

/*
 * Planning d'un persona (prompt de type « planning ») : son emploi du temps de la semaine.
 *
 * C'est l'application qui le lit, pas le modèle : seule l'activité du moment (et la suivante)
 * est transmise dans le bloc <contexte> du dernier message. Une ligne par activité :
 *
 *   Lun–Ven 9h–18h : travail à la galerie
 *   Sam matin : marché avec Léa / footing 30% / grasse matinée
 *   Ven soir : parfois apéro avec les collègues
 *   Mer soir : 30% cours de cuisine
 *   Nuit 0h–7h : dort
 *
 * - Jours : Lun, Mardi, Lun–Ven, Sam, Dim, Week-end, En semaine, Tous les jours (ou rien = tous).
 *   Aussi en anglais et en espagnol.
 * - Heures : 9h–18h, 19h30-21h, 9:00-18:00, 19h (= une heure), ou matin, midi, après-midi,
 *   soir, nuit, journée (ou rien = toute la journée). Une plage qui passe minuit (23h–2h)
 *   continue le lendemain matin.
 * - Choix : séparés par « / », « | » ou « ou », avec un poids facultatif (« sport 50% »).
 * - Activité occasionnelle : « parfois », « souvent », « rarement » ou un pourcentage seul.
 *
 * Les tirages sont stables pour la journée (même persona, même jour, même ligne : même
 * résultat), et la ligne la plus précise l'emporte (une plage horaire avant « toute la journée »,
 * puis moins de jours, puis la plage la plus courte).
 * Un petit imprévu, une fois de temps en temps, laisse le modèle bousculer un peu la journée.
 */

const PLANNING_SURPRISE = 15;  // % de jours avec un petit imprévu
const PLANNING_HORIZON = 480;  // minutes examinées pour trouver l'activité suivante

/** Jours de la semaine (1 = lundi … 7 = dimanche) : mots reconnus en français, anglais, espagnol. */
function planning_day_words(): array
{
    return [
        'lundi' => 1, 'lun' => 1, 'monday' => 1, 'mon' => 1, 'lunes' => 1,
        'mardi' => 2, 'mar' => 2, 'tuesday' => 2, 'tues' => 2, 'tue' => 2, 'martes' => 2,
        'mercredi' => 3, 'mer' => 3, 'wednesday' => 3, 'wed' => 3, 'miércoles' => 3, 'miercoles' => 3, 'mié' => 3, 'mie' => 3,
        'jeudi' => 4, 'jeu' => 4, 'thursday' => 4, 'thurs' => 4, 'thur' => 4, 'thu' => 4, 'jueves' => 4, 'jue' => 4,
        'vendredi' => 5, 'ven' => 5, 'friday' => 5, 'fri' => 5, 'viernes' => 5, 'vie' => 5,
        'samedi' => 6, 'sam' => 6, 'saturday' => 6, 'sat' => 6, 'sábado' => 6, 'sabado' => 6, 'sáb' => 6, 'sab' => 6,
        'dimanche' => 7, 'dim' => 7, 'sunday' => 7, 'sun' => 7, 'domingo' => 7, 'dom' => 7,
    ];
}

/** Moments de la journée : [début, fin] en minutes (fin < début : passe minuit). */
function planning_periods(): array
{
    return [
        'fin de matinée' => [600, 720], 'début de soirée' => [1080, 1260], 'fin d\'après-midi' => [960, 1140],
        'fin d’après-midi' => [960, 1140], 'fin de journée' => [1020, 1200],
        'après-midi' => [840, 1080], 'apres-midi' => [840, 1080], 'aprem' => [840, 1080], 'aprèm' => [840, 1080],
        'matinée' => [420, 720], 'matin' => [420, 720], 'midi' => [720, 840], 'soirée' => [1080, 1380], 'soir' => [1080, 1380],
        'journée' => [540, 1080], 'nuit' => [1380, 420],
        'afternoon' => [840, 1080], 'morning' => [420, 720], 'noon' => [720, 840], 'lunch' => [720, 840],
        'evening' => [1080, 1380], 'night' => [1380, 420], 'daytime' => [540, 1080],
        'mediodía' => [720, 840], 'mediodia' => [720, 840], 'mañana' => [420, 720], 'tarde' => [840, 1260], 'noche' => [1260, 420],
    ];
}

/** Mots reliant ou accompagnant les jours et les heures, sans effet sur le sens. */
function planning_fillers(): array
{
    return ['le', 'les', 'la', 'l', 'du', 'au', 'aux', 'de', 'des', 'd', 'à', 'a', 'et', 'en', 'chaque', 'tous', 'toutes',
        'from', 'to', 'the', 'and', 'on', 'in', 'every', 'each', 'del', 'al', 'y', 'el', 'los', 'las', 'por', 'cada', 'todos'];
}

/**
 * Analyse un planning.
 * @return array{entries: list<array>, errors: list<array{line:int, text:string}>}
 */
function planning_parse(string $body): array
{
    $entries = [];
    $errors = [];
    foreach (preg_split('/\R/u', $body) ?: [] as $i => $raw) {
        $line = trim((string) preg_replace('/^\s*[-•*·]+\s*/u', '', $raw));
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
            continue;
        }
        // « quand : quoi » : le premier deux-points qui n'est pas celui d'une heure (19:30)
        if (!preg_match('/^(.*?)\s*:(?!\d{2})\s*(.+)$/u', $line, $m)) {
            $errors[] = ['line' => $i + 1, 'text' => $line];
            continue;
        }
        $when = planning_parse_when($m[1]);
        $what = planning_parse_what($m[2]);
        if ($when === null || $what === null) {
            $errors[] = ['line' => $i + 1, 'text' => $line];
            continue;
        }
        $entries[] = $when + $what + ['line' => $i + 1, 'raw' => $line];
    }
    return ['entries' => $entries, 'errors' => $errors];
}

/** « Lun–Ven 9h–18h » → jours, début, fin ; null si rien n'est reconnu ou s'il reste des mots inconnus. */
function planning_parse_when(string $when): ?array
{
    $q = ' ' . mb_strtolower(trim($when)) . ' ';
    $q = str_replace(['–', '—', '‑', '’'], ['-', '-', '-', '\''], $q);
    $days = [];
    $found = false;
    $take = static function (string $pattern, callable $fn) use (&$q, &$found): void {
        $q = (string) preg_replace_callback($pattern, static function (array $m) use ($fn, &$found): string {
            $fn($m);
            $found = true;
            return ' ';
        }, $q);
    };

    // groupes de jours
    $groups = [
        '(?:tous les jours|chaque jour|tlj|every ?day|daily|todos los d[ií]as|cada d[ií]a|toute la semaine|all week)' => [1, 2, 3, 4, 5, 6, 7],
        '(?:week-?ends?|we|fins? de semana)' => [6, 7],
        '(?:(?:en |entre |la )?semaine|weekdays?|entre semana|d[ií]as laborables)' => [1, 2, 3, 4, 5],
    ];
    foreach ($groups as $pattern => $set) {
        $take('/(?<![\p{L}])' . $pattern . '(?![\p{L}])/u', static function () use (&$days, $set): void {
            $days = array_merge($days, $set);
        });
    }

    // plages et listes de jours
    $words = planning_day_words();
    uksort($words, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $dayRe = '(' . implode('|', array_map(static fn ($w) => preg_quote($w, '/'), array_keys($words))) . ')\.?';
    $take('/(?<![\p{L}])(?:du |de |from |del )?' . $dayRe . '\s*(?:-|à|a|au|to|al|through|hasta)\s*' . $dayRe . '(?![\p{L}])/u',
        static function (array $m) use (&$days, $words): void {
            $a = $words[$m[1]];
            $b = $words[$m[2]];
            for ($d = $a, $n = 0; $n < 7; $d = $d % 7 + 1, $n++) {
                $days[] = $d;
                if ($d === $b) {
                    break;
                }
            }
        });
    $take('/(?<![\p{L}])' . $dayRe . '(?:s)?(?![\p{L}])/u', static function (array $m) use (&$days, $words): void {
        $days[] = $words[$m[1]];
    });

    // heures : plage, puis heure seule (une heure)
    $from = $to = null;
    $hm = '(\d{1,2})\s*(?:h|:|heures?)?\s*(\d{2})?';
    $take('/(?:de |from |de las |entre )?' . $hm . '\s*(?:-|à|a|to|until|jusqu\'à|a las|et)\s*' . $hm . '\s*(?:h|heures?)?(?!\d)/u',
        static function (array $m) use (&$from, &$to): void {
            $from = min(1439, (int) $m[1] * 60 + (int) ($m[2] ?? 0));
            $to = min(1440, (int) $m[3] * 60 + (int) ($m[4] ?? 0));
        });
    if ($from === null) {
        $take('/(?:à |vers |at |a las )?(\d{1,2})\s*(?:h|:)\s*(\d{2})?(?![\d])/u', static function (array $m) use (&$from, &$to): void {
            $from = min(1439, (int) $m[1] * 60 + (int) ($m[2] ?? 0));
            $to = min(1440, $from + 60);
        });
    }
    // moments de la journée (toujours retirés du texte ; ignorés si une heure est donnée)
    {
        $periods = planning_periods();
        uksort($periods, static fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($periods as $word => [$a, $b]) {
            $take('/(?<![\p{L}])(?:le |la |en |du |dans la |in the |por la |a |the )?' . preg_quote($word, '/') . 's?(?![\p{L}])/u',
                static function () use (&$from, &$to, $a, $b): void {
                    if ($from === null) {
                        [$from, $to] = [$a, $b];
                    }
                });
        }
    }

    // il ne doit rester que des mots de liaison
    $rest = preg_split('/[^\p{L}]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (array_diff($rest, planning_fillers()) || (!$found && trim($q, " ,;-./") !== '')) {
        return null;
    }
    $days = $days ? array_values(array_unique($days)) : [1, 2, 3, 4, 5, 6, 7];
    sort($days);
    $from ??= 0;
    $to ??= 1440;
    if ($from === $to) {
        $to = $from + 60;
    }
    return ['days' => $days, 'from' => $from, 'to' => $to];
}

/** « parfois apéro / sport 50% » → choix pondérés et chance ; null si vide. */
function planning_parse_what(string $what): ?array
{
    $what = trim($what);
    $chance = 100;
    $modifiers = [
        'de temps en temps' => 30, 'de temps à autre' => 30, 'parfois' => 30, 'souvent' => 65, 'rarement' => 15,
        'occasionnellement' => 20, 'sometimes' => 30, 'often' => 65, 'rarely' => 15, 'occasionally' => 20,
        'a veces' => 30, 'a menudo' => 65, 'rara vez' => 15,
    ];
    foreach ($modifiers as $word => $value) {
        if (preg_match('/^' . preg_quote($word, '/') . '\s*[,:]?\s+/iu', $what, $m)) {
            $chance = $value;
            $what = mb_substr($what, mb_strlen($m[0]));
            break;
        }
    }
    if (preg_match('/^(\d{1,3})\s*%\s*(?:du temps|des fois|of the time)?\s*[,:]?\s*/u', $what, $m)) {
        $chance = max(1, min(100, (int) $m[1]));
        $what = mb_substr($what, mb_strlen($m[0]));
    }

    $parts = preg_split('/\s*(?<!\d)\/(?!\d)\s*|\s*\|\s*|\s+ou\s+|\s+or\s+/iu', $what) ?: [];
    $options = [];
    foreach ($parts as $part) {
        $part = trim($part, " \t,;");
        $weight = null;
        if (preg_match('/\s*\(?\s*(\d{1,3})\s*%\s*\)?$/u', $part, $m) && trim(mb_substr($part, 0, -mb_strlen($m[0]))) !== '') {
            $weight = max(1, min(100, (int) $m[1]));
            $part = trim(mb_substr($part, 0, -mb_strlen($m[0])));
        }
        if ($part !== '') {
            $options[] = ['text' => $part, 'weight' => $weight];
        }
    }
    if (!$options) {
        return null;
    }
    // une seule activité avec un pourcentage : c'est la chance qu'elle ait lieu
    if (count($options) === 1 && $options[0]['weight'] !== null) {
        $chance = $options[0]['weight'];
        $options[0]['weight'] = null;
    }
    $given = array_sum(array_map(static fn ($o) => (int) $o['weight'], $options));
    $missing = count(array_filter($options, static fn ($o) => $o['weight'] === null));
    $share = $missing ? max(1, intdiv(max(0, 100 - $given), $missing) ?: intdiv(100, count($options))) : 0;
    foreach ($options as &$o) {
        $o['weight'] ??= $share;
    }
    unset($o);
    return ['options' => $options, 'chance' => $chance];
}

/** Nombre pseudo-aléatoire stable (0 … $mod-1) pour une clé. */
function planning_roll(string $key, int $mod): int
{
    return $mod > 0 ? hexdec(substr(hash('sha256', $key), 0, 7)) % $mod : 0;
}

/**
 * Activité à un instant donné (null : rien de prévu).
 * @return array{text:string, entry:array, count:int}|null
 */
function planning_at(array $entries, int $pid, DateTimeImmutable $at): ?array
{
    $dow = (int) $at->format('N');
    $prev = $dow === 1 ? 7 : $dow - 1;
    $min = (int) $at->format('G') * 60 + (int) $at->format('i');
    $candidates = [];
    foreach ($entries as $e) {
        $wrap = $e['to'] <= $e['from'];
        $date = null;
        if (in_array($dow, $e['days'], true) && $min >= $e['from'] && ($wrap || $min < $e['to'])) {
            $date = $at->format('Y-m-d');
        } elseif ($wrap && $min < $e['to'] && in_array($prev, $e['days'], true)) {
            $date = $at->modify('-1 day')->format('Y-m-d'); // suite de la veille (après minuit)
        }
        if ($date === null) {
            continue;
        }
        $duration = $wrap ? 1440 - $e['from'] + $e['to'] : $e['to'] - $e['from'];
        // la plus précise d'abord : une plage horaire avant « toute la journée », puis moins de jours, puis plus courte
        $candidates[] = ['e' => $e, 'date' => $date, 'score' => ($duration < 1440 ? 20000 : 0) + (7 - count($e['days'])) * 2000 + (1440 - $duration)];
    }
    usort($candidates, static fn ($a, $b) => [$b['score'], $a['e']['line']] <=> [$a['score'], $b['e']['line']]);
    foreach ($candidates as $c) {
        $e = $c['e'];
        $key = $pid . '|' . $c['date'] . '|' . $e['line'] . '|' . $e['raw'];
        if ($e['chance'] < 100 && planning_roll($key . '|chance', 100) >= $e['chance']) {
            continue;
        }
        $roll = planning_roll($key . '|choix', array_sum(array_column($e['options'], 'weight')));
        foreach ($e['options'] as $o) {
            if (($roll -= $o['weight']) < 0) {
                return ['text' => $o['text'], 'entry' => $e, 'count' => count($e['options'])];
            }
        }
    }
    return null;
}

/**
 * Le moment présent d'après le planning : activité, activité suivante, petit imprévu du jour.
 * @return array{now:?string, choices:int, next:?string, next_at:?string, surprise:bool, sleeping:bool}|null
 */
function planning_now(string $body, int $pid, DateTimeImmutable $at): ?array
{
    $entries = planning_parse($body)['entries'];
    if (!$entries) {
        return null;
    }
    $now = planning_at($entries, $pid, $at);
    $current = $now['text'] ?? null;
    $next = null;
    $nextAt = null;
    $start = $at->setTime((int) $at->format('G'), intdiv((int) $at->format('i'), 15) * 15);
    for ($step = 15; $step <= PLANNING_HORIZON; $step += 15) {
        $t = $start->modify('+' . $step . ' minutes');
        $later = planning_at($entries, $pid, $t)['text'] ?? null;
        if ($later !== $current) {
            $next = $later;
            $nextAt = $t->format('G\hi');
            $nextAt = str_ends_with($nextAt, 'h00') ? substr($nextAt, 0, -2) : $nextAt;
            break;
        }
    }
    return [
        'now'      => $current,
        'choices'  => (int) ($now['count'] ?? 0),
        'next'     => $next,
        'next_at'  => $nextAt,
        'surprise' => planning_roll($pid . '|' . $at->format('Y-m-d') . '|imprévu', 100) < PLANNING_SURPRISE,
        'sleeping' => $current !== null && (bool) preg_match('/(?<![\p{L}])(dort|dors|dormir|sommeil|couch[ée]e?s?|sieste|sleep(?:ing)?|asleep|duerme|durmiendo)(?![\p{L}])/iu', $current),
    ];
}

/** Texte de tous les prompts « planning » d'un persona ('' s'il n'y en a pas). */
function planning_body(array $personaPrompts): string
{
    $body = '';
    foreach ($personaPrompts as $p) {
        if ((string) ($p['kind'] ?? '') === 'planning') {
            $body .= trim((string) $p['body']) . "\n";
        }
    }
    return trim($body);
}

/** Pour le bloc <contexte> : ce que fait le persona en ce moment ('' sans planning). */
function planning_context(array $personaPrompts, int $pid, ?DateTimeImmutable $at = null): string
{
    $body = planning_body($personaPrompts);
    $state = $body !== '' ? planning_now($body, $pid, $at ?? chat_now()) : null;
    if ($state === null) {
        return '';
    }
    $lines = ['Ton emploi du temps (vis-le, sans le réciter ni dire que tu as un planning) :'];
    $lines[] = $state['now'] !== null
        ? '- En ce moment : ' . $state['now'] . '.'
        : '- En ce moment : rien de prévu, temps libre.';
    if ($state['next_at'] !== null) {
        $lines[] = '- Ensuite, vers ' . $state['next_at'] . ' : ' . ($state['next'] ?? 'temps libre') . '.';
    }
    if ($state['sleeping']) {
        $lines[] = "- Tu dormais : si tu réponds, c'est en te réveillant, à moitié endormi(e), en quelques mots.";
    }
    if ($state['surprise']) {
        $lines[] = "- Aujourd'hui, un petit imprévu a un peu bousculé ta journée : invente-le, banal et crédible, "
            . "et n'en parle que si ça vient naturellement.";
    }
    return implode("\n", $lines);
}
