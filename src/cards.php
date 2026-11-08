<?php
/**
 * Fiches « Character Card » : format ouvert d'échange de personnages.
 *
 *   V1 : objet à plat { name, description, personality, scenario, first_mes, mes_example }
 *   V2 : { "spec": "chara_card_v2", "spec_version": "2.0", "data": { … } }
 *   V3 : { "spec": "chara_card_v3", "spec_version": "3.0", "data": { … } } (champs V2 + ajouts, ignorés)
 *   PNG : une image dont un bloc tEXt « chara » (ou « ccv3 » pour la V3) contient la fiche
 *         en JSON encodé en base64.
 *
 * Export : V2 (JSON ou PNG). Ce qui n'a pas d'équivalent dans la carte (accroche, attributs,
 * tous les prompts, réglages IA) va dans data.extensions.personavault : réimportée ici,
 * la fiche est restituée sans perte. Correspondance détaillée : README, « Fiches ».
 *
 * Import : ZIP PersonaVault (via backup_import_zip), JSON ou PNG. Les données sont validées
 * (types, longueurs bornées) puis insérées par backup_insert_persona() : slug inédit, jamais
 * d'écrasement ; le portrait du PNG est contrôlé puis ré-encodé comme toute image.
 */

const CARD_EXTENSION = 'personavault';

/** Taille maximale d'une fiche JSON (fichier, ou bloc texte d'un PNG une fois décodé). */
function card_max_json(): int
{
    return (int) config('card_max_json', 2 * 1024 * 1024);
}

/** Intitulés des prompts « Autre » créés à l'import pour les champs de la carte sans équivalent. */
function card_field_labels(): array
{
    return [
        'personality'         => t('cards.field.personality'),
        'scenario'            => t('cards.field.scenario'),
        'first_mes'           => t('cards.field.first_mes'),
        'alternate_greetings' => t('cards.field.alternate_greetings'),
        'mes_example'         => t('cards.field.mes_example'),
        'creator_notes'       => t('cards.field.creator_notes'),
        'creator'             => t('cards.field.creator'),
        'character_version'   => t('cards.field.character_version'),
    ];
}

/**
 * Champ de carte correspondant à un intitulé (prompt ou attribut), dans n'importe quelle
 * langue installée : une fiche importée en anglais se réexporte pareil depuis l'interface française.
 */
function card_field_for_label(string $label): ?string
{
    static $map = null;
    if ($map === null) {
        $map  = [];
        $prev = $GLOBALS['pv_locale'] ?? null;
        foreach (array_keys(i18n_available()) as $code) {
            i18n_set_locale($code);
            foreach (card_field_labels() as $field => $text) {
                $map[mb_strtolower(trim($text))] = $field;
            }
        }
        i18n_set_locale($prev);
    }
    return $map[mb_strtolower(trim($label))] ?? null;
}

/* ------------------------------------------------------------------- export */

/** Fiche V2 d'un persona (tableau prêt pour json_encode). */
function card_build(array $persona): array
{
    $prompts = fetch_prompts((int) $persona['id']);
    $meta    = json_list($persona['meta'] ?? '[]');

    $fields = ['personality' => [], 'scenario' => [], 'first_mes' => [], 'alternate_greetings' => [], 'mes_example' => [], 'creator_notes' => []];
    $system = [];
    foreach ($prompts as $pr) {
        if ($pr['kind'] === 'system') {
            $system[] = (string) $pr['body'];
        } elseif ($pr['kind'] === 'other' && ($f = card_field_for_label((string) $pr['title'])) !== null && isset($fields[$f])) {
            $fields[$f][] = (string) $pr['body'];
        }
    }

    $creator = '';
    $version = '';
    $attrs   = [];
    foreach ($meta as $row) {
        if (!is_array($row) || !isset($row['k'])) {
            continue;
        }
        $f = card_field_for_label((string) $row['k']);
        if ($f === 'creator') {
            $creator = (string) ($row['v'] ?? '');
        } elseif ($f === 'character_version') {
            $version = (string) ($row['v'] ?? '');
        } else {
            $attrs[] = $row;
        }
    }

    // sans champ « personnalité » explicite, les attributs (« Âge : 29 »…) en tiennent lieu
    $personality = $fields['personality'] ? implode("\n\n", $fields['personality']) : meta_to_text($attrs);
    $greetings   = array_merge($fields['first_mes'], $fields['alternate_greetings']);
    $notes       = trim(implode("\n\n", array_filter([(string) $persona['tagline'], ...$fields['creator_notes']], static fn (string $s): bool => trim($s) !== '')));

    return [
        'spec'         => 'chara_card_v2',
        'spec_version' => '2.0',
        'data'         => [
            'name'                      => (string) $persona['name'],
            'description'               => (string) $persona['description'],
            'personality'               => $personality,
            'scenario'                  => implode("\n\n", $fields['scenario']),
            'first_mes'                 => (string) ($greetings[0] ?? ''),
            'mes_example'               => implode("\n", $fields['mes_example']),
            'creator_notes'             => $notes,
            'system_prompt'             => implode("\n\n", $system),
            'post_history_instructions' => (string) $persona['directive'],
            'alternate_greetings'       => array_values(array_slice($greetings, 1)),
            'tags'                      => array_values(array_map('strval', json_list($persona['tags'] ?? '[]'))),
            'creator'                   => $creator,
            'character_version'         => $version,
            'extensions'                => [
                CARD_EXTENSION => [
                    'format'         => 1,
                    'tagline'        => (string) $persona['tagline'],
                    'meta'           => $meta,
                    'prompts'        => array_map(static fn (array $pr): array => [
                        'kind'  => (string) $pr['kind'],
                        'title' => (string) $pr['title'],
                        'body'  => (string) $pr['body'],
                    ], $prompts),
                    'directive'      => (string) $persona['directive'],
                    'favorite'       => (int) $persona['favorite'],
                    'archived'       => (int) $persona['archived'],
                    'model'          => (string) $persona['model'],
                    'temperature'    => (float) $persona['temperature'],
                    'max_tokens'     => (int) $persona['max_tokens'],
                    'context_window' => (int) $persona['context_window'],
                ],
            ],
        ],
    ];
}

function card_json(array $persona): string
{
    return backup_json(card_build($persona));
}

/** PNG de la fiche : le portrait (ou une image aux initiales) + bloc tEXt « chara ». */
function card_png(array $persona): string
{
    $img = card_portrait($persona);
    ob_start();
    imagepng($img, null, 6);
    $png = (string) ob_get_clean();
    if ($png === '') {
        throw new RuntimeException(t('cards.error.png_write'));
    }
    return png_add_text($png, 'chara', base64_encode((string) json_encode(card_build($persona), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
}

/** Portrait du persona (1024 px max), ou image 512 × 768 générée avec ses initiales. */
function card_portrait(array $persona): GdImage
{
    $src = (string) config('uploads') . '/' . $persona['avatar'] . '.webp';
    if (trim((string) $persona['avatar']) !== '' && is_file($src) && ($img = @imagecreatefromwebp($src))) {
        $w = imagesx($img);
        $h = imagesy($img);
        $r = min(1, 1024 / max($w, $h));
        if ($r < 1) {
            $nw  = max(1, (int) round($w * $r));
            $nh  = max(1, (int) round($h * $r));
            $dst = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $img = $dst;
        }
        return $img;
    }
    return card_initials_image((string) $persona['name']);
}

function card_initials_image(string $name): GdImage
{
    $W = 512;
    $H = 768;
    $img = imagecreatetruecolor($W, $H);
    $hue = hue_for($name);
    // dégradé vertical, teinte du persona (comme le portrait par défaut de l'interface)
    [$r1, $g1, $b1] = card_hsl($hue, .62, .46);
    [$r2, $g2, $b2] = card_hsl(($hue + 40) % 360, .55, .22);
    for ($y = 0; $y < $H; $y++) {
        $k = $y / ($H - 1);
        $c = imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $k), (int) ($g1 + ($g2 - $g1) * $k), (int) ($b1 + ($b2 - $b1) * $k));
        imageline($img, 0, $y, $W - 1, $y, $c);
    }

    $text  = initials($name);
    $white = imagecolorallocate($img, 255, 255, 255);
    $font  = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    if (function_exists('imagettftext') && is_file($font)) {
        $size = 150;
        $box  = imagettfbbox($size, 0, $font, $text) ?: [0, 0, 0, 0, 0, 0, 0, 0];
        $tw   = $box[2] - $box[0];
        $th   = $box[1] - $box[7];
        imagettftext($img, $size, 0, (int) (($W - $tw) / 2 - $box[0]), (int) (($H + $th) / 2 - $box[1]), $white, $font, $text);
        return $img;
    }

    // sans police TrueType : police bitmap intégrée, agrandie
    $ascii = (string) (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: '?');
    $ascii = $ascii !== '' ? $ascii : '?';
    $sw    = imagefontwidth(5) * strlen($ascii);
    $sh    = imagefontheight(5);
    $small = imagecreatetruecolor($sw, $sh);
    imagealphablending($small, false);
    imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
    imagesavealpha($small, true);
    imagestring($small, 5, 0, 0, $ascii, imagecolorallocate($small, 255, 255, 255));
    $scale = (int) floor(min(($W * .6) / $sw, ($H * .3) / $sh));
    imagealphablending($img, true);
    imagecopyresampled($img, $small, (int) (($W - $sw * $scale) / 2), (int) (($H - $sh * $scale) / 2), 0, 0, $sw * $scale, $sh * $scale, $sw, $sh);
    return $img;
}

/** @return array{int,int,int} */
function card_hsl(int $h, float $s, float $l): array
{
    $c = (1 - abs(2 * $l - 1)) * $s;
    $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
    $m = $l - $c / 2;
    [$r, $g, $b] = match (intdiv($h % 360, 60)) {
        0 => [$c, $x, 0], 1 => [$x, $c, 0], 2 => [0, $c, $x],
        3 => [0, $x, $c], 4 => [$x, 0, $c], default => [$c, 0, $x],
    };
    return [(int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255)];
}

/* --------------------------------------------------------------------- PNG */

/** Insère un bloc tEXt (mot-clé latin-1, texte ASCII) juste avant IEND. */
function png_add_text(string $png, string $keyword, string $text): string
{
    if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException(t('cards.error.png_invalid'));
    }
    $data  = $keyword . "\0" . $text;
    $chunk = pack('N', strlen($data)) . 'tEXt' . $data . pack('N', crc32('tEXt' . $data));
    $pos   = strrpos($png, 'IEND');
    if ($pos === false || $pos < 12) {
        throw new RuntimeException(t('cards.error.png_invalid'));
    }
    $pos -= 4; // longueur du bloc IEND
    return substr($png, 0, $pos) . $chunk . substr($png, $pos);
}

/**
 * Blocs texte d'un PNG (tEXt, et iTXt non compressés) : mot-clé en minuscules => texte.
 * Lecture bornée : on ne suit jamais une longueur qui dépasse le fichier.
 * @return array<string,string>
 */
function png_text_chunks(string $png): array
{
    if (!str_starts_with($png, "\x89PNG\r\n\x1a\n")) {
        throw new RuntimeException(t('cards.error.png_invalid'));
    }
    $out = [];
    $len = strlen($png);
    $pos = 8;
    $n   = 0;
    while ($pos + 12 <= $len && $n++ < 100000) {
        $size = unpack('N', substr($png, $pos, 4))[1];
        $type = substr($png, $pos + 4, 4);
        if ($size < 0 || $pos + 12 + $size > $len) {
            break; // bloc tronqué : on s'arrête
        }
        if ($type === 'tEXt' || $type === 'iTXt') {
            $data = substr($png, $pos + 8, $size);
            $nul  = strpos($data, "\0");
            if ($nul !== false && $nul > 0 && $nul < 80) {
                $key  = strtolower(substr($data, 0, $nul));
                $text = substr($data, $nul + 1);
                if ($type === 'iTXt') {
                    // drapeau de compression, méthode, langue\0, mot-clé traduit\0, texte
                    $parts = explode("\0", substr($text, 2), 3);
                    $text  = ($text[0] ?? "\1") === "\0" && count($parts) === 3 ? $parts[2] : null;
                }
                if ($text !== null && !isset($out[$key])) {
                    $out[$key] = $text;
                }
            }
        }
        if ($type === 'IEND') {
            break;
        }
        $pos += 12 + $size;
    }
    return $out;
}

/* ------------------------------------------------------------------- import */

/** Texte borné d'un champ de carte (les valeurs non scalaires sont ignorées). */
function card_str(array $d, string $key, int $max = 20000): string
{
    return trim(backup_str($d[$key] ?? '', $max));
}

/** Décode une fiche JSON, taille et structure contrôlées. */
function card_decode_json(string $json): array
{
    if (strlen($json) > card_max_json()) {
        throw new RuntimeException(t('cards.error.too_big', ['max' => human_bytes(card_max_json())]));
    }
    $json = (string) preg_replace('/^\xEF\xBB\xBF/', '', $json); // BOM
    $data = json_decode($json, true, 32);
    if (!is_array($data)) {
        throw new RuntimeException(t('cards.error.invalid_json'));
    }
    return $data;
}

/**
 * Convertit une fiche (V1, V2, V3) en persona PersonaVault.
 * @return array{persona:array, prompts:array}
 */
function card_parse(array $card): array
{
    $spec = $card['spec'] ?? null;
    if ($spec === null) {
        $d = $card; // V1 : champs à la racine
        // anciens noms (TavernAI / Pygmalion)
        foreach (['char_name' => 'name', 'char_persona' => 'personality', 'world_scenario' => 'scenario',
                  'char_greeting' => 'first_mes', 'example_dialogue' => 'mes_example'] as $old => $new) {
            if (!isset($d[$new]) && isset($d[$old])) {
                $d[$new] = $d[$old];
            }
        }
    } elseif ($spec === 'chara_card_v2' || $spec === 'chara_card_v3') {
        if (!is_array($card['data'] ?? null)) {
            throw new RuntimeException(t('cards.error.not_a_card'));
        }
        $d = $card['data'];
    } else {
        throw new RuntimeException(t('cards.error.unsupported', ['spec' => backup_str($spec, 40, '?')]));
    }

    $name = card_str($d, 'name', 200);
    if ($name === '') {
        throw new RuntimeException(t('cards.error.no_name'));
    }

    $tags = [];
    foreach (is_array($d['tags'] ?? null) ? $d['tags'] : [] as $tag) {
        if (is_scalar($tag)) {
            $tags[] = (string) $tag; // bornés et dédoublonnés par backup_insert_persona()
        }
    }

    $persona = [
        'name'        => $name,
        'description' => card_str($d, 'description'),
        'tags'        => array_slice($tags, 0, 50),
    ];

    // fiche exportée par PersonaVault : restitution sans perte
    $ext = $d['extensions'][CARD_EXTENSION] ?? null;
    if (is_array($ext)) {
        $prompts = [];
        foreach (is_array($ext['prompts'] ?? null) ? array_slice($ext['prompts'], 0, 200) : [] as $pr) {
            if (is_array($pr)) {
                $prompts[] = ['kind' => backup_str($pr['kind'] ?? '', 20), 'title' => backup_str($pr['title'] ?? '', 120), 'body' => card_str($pr, 'body')];
            }
        }
        $persona += [
            'tagline'        => card_str($ext, 'tagline', 1000),
            'meta'           => is_array($ext['meta'] ?? null) ? array_slice($ext['meta'], 0, 20) : [],
            'directive'      => card_str($ext, 'directive', 5000),
            'favorite'       => !empty($ext['favorite']),
            'archived'       => !empty($ext['archived']),
            'model'          => card_str($ext, 'model', 200),
            'temperature'    => $ext['temperature'] ?? 0.85,
            'max_tokens'     => $ext['max_tokens'] ?? 400,
            'context_window' => $ext['context_window'] ?? 8192,
        ];
        return ['persona' => $persona, 'prompts' => $prompts];
    }

    // fiche d'une autre application
    $labels  = card_field_labels();
    $prompts = [];
    $system  = card_str($d, 'system_prompt');
    if ($system !== '') {
        $prompts[] = ['kind' => 'system', 'title' => '', 'body' => $system];
    }
    foreach (['personality', 'scenario', 'first_mes'] as $f) {
        if (($v = card_str($d, $f)) !== '') {
            $prompts[] = ['kind' => 'other', 'title' => $labels[$f], 'body' => $v];
        }
    }
    foreach (is_array($d['alternate_greetings'] ?? null) ? array_slice($d['alternate_greetings'], 0, 30) : [] as $g) {
        if (($v = trim(backup_str($g, 20000))) !== '') {
            $prompts[] = ['kind' => 'other', 'title' => $labels['alternate_greetings'], 'body' => $v];
        }
    }
    if (($v = card_str($d, 'mes_example')) !== '') {
        $prompts[] = ['kind' => 'other', 'title' => $labels['mes_example'], 'body' => $v];
    }

    // notes du créateur : une seule ligne courte → accroche ; sinon un prompt « Autre »
    $tagline = '';
    $notes   = card_str($d, 'creator_notes');
    if ($notes === '' && $spec === null) {
        $notes = card_str($card, 'creatorcomment');
    }
    if ($notes !== '' && mb_strlen($notes) <= 200 && !preg_match('/\R/u', $notes)) {
        $tagline = $notes;
    } elseif ($notes !== '') {
        $prompts[] = ['kind' => 'other', 'title' => $labels['creator_notes'], 'body' => $notes];
    }

    $meta = [];
    foreach (['creator', 'character_version'] as $f) {
        if (($v = card_str($d, $f, 200)) !== '') {
            $meta[] = ['k' => $labels[$f], 'v' => $v];
        }
    }

    $persona += [
        'tagline'   => $tagline,
        'meta'      => $meta,
        'directive' => card_str($d, 'post_history_instructions', 5000),
    ];
    return ['persona' => $persona, 'prompts' => $prompts];
}

/**
 * Importe une fiche déjà décodée (+ portrait PNG facultatif, contrôlé puis ré-encodé).
 * @return array{slug:string, prompts:int, errors:array<string>}
 */
function card_import_array(array $card, ?string $imageData = null): array
{
    $parsed = card_parse($card); // valide avant d'écrire la moindre image
    $errors = [];
    $avatar = '';
    if ($imageData !== null) {
        try {
            $avatar = store_gd_image(decode_image_string($imageData));
        } catch (Throwable $e) {
            $errors[] = t('error.backup.avatar_skipped', ['error' => public_error($e, t('error.internal_short'))]);
        }
    }
    $res = backup_insert_persona($parsed['persona'], $parsed['prompts'], $avatar);
    return ['slug' => $res['slug'], 'prompts' => $res['prompts'], 'errors' => $errors];
}

/** Fiche contenue dans un PNG (bloc « ccv3 » de préférence, sinon « chara »). */
function card_from_png(string $png): array
{
    $chunks  = png_text_chunks($png);
    $payload = $chunks['ccv3'] ?? $chunks['chara'] ?? null;
    if ($payload === null) {
        throw new RuntimeException(t('cards.error.png_no_card'));
    }
    if (strlen($payload) > (int) ceil(card_max_json() * 4 / 3) + 4) {
        throw new RuntimeException(t('cards.error.too_big', ['max' => human_bytes(card_max_json())]));
    }
    $json = base64_decode(trim($payload), true);
    if ($json === false) {
        throw new RuntimeException(t('cards.error.invalid_json'));
    }
    return card_decode_json($json);
}

/**
 * Importe un fichier envoyé depuis la bibliothèque : ZIP PersonaVault (une fiche ou une
 * sauvegarde complète), fiche JSON ou PNG. Le type est déduit du contenu, pas du nom.
 * @return array{personas:int, photos:int, prompts:int, errors:array<string>, slugs:array<string>}
 */
function card_import_file(string $path): array
{
    $size = (int) @filesize($path);
    $fh   = @fopen($path, 'rb');
    $head = $fh ? (string) fread($fh, 256) : '';
    if ($fh) {
        fclose($fh);
    }
    if ($size <= 0 || $head === '') {
        throw new RuntimeException(t('cards.error.unknown_format'));
    }

    if (str_starts_with($head, "PK\x03\x04")) {
        return backup_import_zip($path);
    }

    if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
        $max = (int) config('max_upload', 15 * 1024 * 1024);
        if ($size > $max) {
            throw new RuntimeException(t('error.image.too_heavy', ['max' => human_bytes($max)]));
        }
        $png = (string) file_get_contents($path);
        $res = card_import_array(card_from_png($png), $png);
    } elseif (preg_match('/^(\xEF\xBB\xBF)?\s*\{/', $head)) {
        if ($size > card_max_json()) {
            throw new RuntimeException(t('cards.error.too_big', ['max' => human_bytes(card_max_json())]));
        }
        $res = card_import_array(card_decode_json((string) file_get_contents($path)));
    } else {
        throw new RuntimeException(t('cards.error.unknown_format'));
    }

    return ['personas' => 1, 'photos' => 0, 'prompts' => $res['prompts'], 'errors' => $res['errors'], 'slugs' => [$res['slug']]];
}
