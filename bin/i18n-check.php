<?php
/**
 * Contrôle des traductions (lang/*.php), sans base ni serveur.
 *
 * Erreurs (code de sortie 1) :
 *   - clé utilisée dans le code (t('…'), t_html('…'), tr("…") en JS) absente de lang/fr.php ;
 *   - clé de lang/fr.php absente d'une autre langue, ou clé en trop ;
 *   - paramètres {…} différents entre le français et la traduction ;
 *   - pluriel incomplet (formes « one » et « other » attendues) ou forme incohérente.
 * Avertissement : clé de lang/fr.php jamais utilisée dans le code.
 *
 * Usage : php bin/i18n-check.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$ref  = 'fr';
$langs = [];
foreach (glob($root . '/lang/*.php') ?: [] as $f) {
    $langs[basename($f, '.php')] = (array) require $f;
}
$errors = [];
$warnings = [];

if (!isset($langs[$ref])) {
    fwrite(STDERR, "lang/$ref.php introuvable\n");
    exit(1);
}

$params = static function (string|array $v): array {
    $all = is_array($v) ? implode(' ', $v) : $v;
    preg_match_all('/\{(\w+)\}/', $all, $m);
    $p = array_unique($m[1]);
    sort($p);
    return $p;
};

// --- cohérence des langues avec la référence
foreach ($langs as $code => $messages) {
    if ($code === $ref) {
        continue;
    }
    foreach ($langs[$ref] as $key => $frText) {
        if (!array_key_exists($key, $messages)) {
            $errors[] = "[$code] clé manquante : $key";
            continue;
        }
        $tr = $messages[$key];
        if (is_array($frText) !== is_array($tr)) {
            $errors[] = "[$code] $key : pluriel d'un côté, texte simple de l'autre";
            continue;
        }
        if ($params($frText) !== $params($tr)) {
            $errors[] = "[$code] $key : paramètres {" . implode('}, {', $params($frText)) . "} attendus, trouvés {"
                . implode('}, {', $params($tr)) . '}';
        }
    }
    foreach (array_keys($messages) as $key) {
        if (!array_key_exists($key, $langs[$ref])) {
            $errors[] = "[$code] clé en trop (absente du français) : $key";
        }
    }
}
foreach ($langs as $code => $messages) {
    foreach ($messages as $key => $v) {
        if (is_array($v) && (!isset($v['one'], $v['other']) || count($v) !== 2)) {
            $errors[] = "[$code] $key : un pluriel doit avoir exactement les formes « one » et « other »";
        }
    }
}

// --- clés utilisées dans le code
$used = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = $file->getPathname();
    $rel  = substr($path, strlen($root) + 1);
    if (!preg_match('/\.(php|js)$/', $rel) || preg_match('#^(lang/|tests/|\.git/|storage/|bin/i18n-check\.php)#', $rel)) {
        continue;
    }
    $code = (string) file_get_contents($path);
    // les commentaires (exemples de documentation) ne comptent pas
    if (str_ends_with($rel, '.php')) {
        $stripped = '';
        foreach (token_get_all($code) as $tok) {
            if (!is_array($tok) || !in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $stripped .= is_array($tok) ? $tok[1] : $tok;
            }
        }
        $code = $stripped;
    } else {
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);
    }
    if (str_ends_with($rel, '.php')) {
        preg_match_all('/\bt(?:_html)?\(\s*\'([a-z0-9_.-]+)\'/', $code, $m);
        foreach ($m[1] as $k) {
            $used[$k][] = $rel;
        }
    } else {
        preg_match_all('/\btr\(\s*"([a-z0-9_.-]+)"/', $code, $m);
        foreach ($m[1] as $k) {
            $used['js.' . $k][] = $rel;
        }
    }
}
foreach ($used as $key => $files) {
    if (!array_key_exists($key, $langs[$ref])) {
        $errors[] = "clé utilisée mais absente de lang/$ref.php : $key (" . implode(', ', array_unique($files)) . ')';
    }
}
foreach (array_keys($langs[$ref]) as $key) {
    if ($key !== '_name' && !isset($used[$key])) {
        $warnings[] = "clé jamais utilisée dans le code : $key";
    }
}

// --- rapport
$n = count($langs[$ref]) - 1;
echo 'Langues : ' . implode(', ', array_keys($langs)) . " — $n textes de référence, " . count($used) . " clés utilisées dans le code\n";
foreach ($warnings as $w) {
    echo "  attention : $w\n";
}
foreach ($errors as $e) {
    echo "  ERREUR : $e\n";
}
echo $errors ? count($errors) . " erreur(s)\n" : "Traductions OK\n";
exit($errors ? 1 : 0);
