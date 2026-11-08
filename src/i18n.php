<?php
declare(strict_types=1);

/*
 * Traductions de l'interface.
 *
 * Un dossier par langue dans lang/ (lang/fr/ = référence), un fichier par domaine
 * (common.php, chat.php, settings.php…) : chacun renvoie un tableau « clé => texte ».
 *   - paramètres : 'persona.deleted' => '{name} a été supprimé.'   → t('persona.deleted', ['name' => $n])
 *   - pluriels   : 'count.photos' => ['one' => '{n} photo', 'other' => '{n} photos'] → t('count.photos', 3)
 *   - la clé '_name' (dans common.php) donne le nom de la langue, affiché dans les réglages ;
 *   - une clé qui finit par « _html » contient du HTML de confiance (écrit dans lang/) :
 *     on l'affiche sans e(), ex. <?= t('settings.info.backup_html') ?> ; toutes les autres sont échappées.
 * Une clé absente dans la langue choisie retombe sur le français, puis sur la clé elle-même :
 * l'interface reste complète pendant que la traduction avance.
 * Les clés « js.* » sont aussi envoyées au JavaScript (voir i18n_js()).
 */

const I18N_REFERENCE = 'fr';

/** Langues installées : code => nom (d'après les fichiers de lang/). */
function i18n_available(): array
{
    static $langs = null;
    if ($langs === null) {
        $langs = [];
        foreach (glob(PV_ROOT . '/lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $code = basename($dir);
            if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $code)) {
                $langs[$code] = (string) (i18n_messages($code)['_name'] ?? $code);
            }
        }
        ksort($langs);
        // la langue de référence en premier
        if (isset($langs[I18N_REFERENCE])) {
            $langs = [I18N_REFERENCE => $langs[I18N_REFERENCE]] + $langs;
        }
    }
    return $langs;
}

/** Textes d'une langue : tous les fichiers de lang/<code>/, lus une seule fois par requête. */
function i18n_messages(string $lang): array
{
    static $cache = [];
    if (!array_key_exists($lang, $cache)) {
        $messages = [];
        if (preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $lang)) {
            $files = glob(PV_ROOT . '/lang/' . $lang . '/*.php') ?: [];
            sort($files);
            foreach ($files as $file) {
                $messages += (array) require $file; // doublons signalés par bin/i18n-check.php
            }
        }
        if ($lang === I18N_REFERENCE && !$messages) {
            // sans lang/fr/, l'interface afficherait les clés au lieu des textes
            error_log('PersonaVault : ' . PV_ROOT . '/lang/' . $lang . '/ introuvable — le dossier lang/ a-t-il été déployé ?');
        }
        $cache[$lang] = $messages;
    }
    return $cache[$lang];
}

/**
 * Langue de l'interface : réglage « ui_lang » (auto = langue du navigateur), sinon français.
 * Modifiable pour la durée de la requête avec i18n_set_locale() (tests, scripts).
 */
function locale(): string
{
    if (isset($GLOBALS['pv_locale'])) {
        return $GLOBALS['pv_locale'];
    }
    $available = i18n_available();
    $chosen    = '';
    try {
        $chosen = setting('ui_lang', 'auto');
    } catch (Throwable $e) {
        $chosen = 'auto'; // base indisponible : on ne bloque pas l'affichage
    }
    if ($chosen === 'auto' || !isset($available[$chosen])) {
        $chosen = i18n_from_header((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), array_keys($available));
    }
    return $GLOBALS['pv_locale'] = $chosen;
}

function i18n_set_locale(?string $lang): void
{
    if ($lang === null) {
        unset($GLOBALS['pv_locale']);
    } else {
        $GLOBALS['pv_locale'] = $lang;
    }
}

/** Meilleure langue disponible d'après l'en-tête Accept-Language (« en-GB,en;q=0.8,fr;q=0.5 »). */
function i18n_from_header(string $header, array $available): string
{
    $wanted = [];
    foreach (explode(',', $header) as $i => $part) {
        $bits = explode(';', trim($part));
        $tag  = strtolower(trim($bits[0]));
        if ($tag === '' || $tag === '*') {
            continue;
        }
        $q = 1.0;
        foreach (array_slice($bits, 1) as $b) {
            if (preg_match('/^\s*q=([0-9.]+)/', $b, $m)) {
                $q = (float) $m[1];
            }
        }
        $wanted[] = [$tag, $q, $i];
    }
    // q décroissant, puis ordre d'apparition
    usort($wanted, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
    foreach ($wanted as [$tag]) {
        foreach ([$tag, substr($tag, 0, 2)] as $try) {
            foreach ($available as $code) {
                if (strtolower($code) === $try) {
                    return $code;
                }
            }
        }
    }
    return I18N_REFERENCE;
}

/** Forme de pluriel d'un nombre selon la langue : 'one' ou 'other'. */
function i18n_plural_form(string $lang, int|float $n): string
{
    return match (substr($lang, 0, 2)) {
        // français (et langues romanes proches) : 0 et 1 au singulier
        'fr', 'pt' => abs($n) < 2 ? 'one' : 'other',
        // anglais, allemand, espagnol, italien… : seul 1 est au singulier
        default    => abs($n) == 1 ? 'one' : 'other',
    };
}

/**
 * Texte traduit.
 * @param array|int $params paramètres {nom} ; un entier seul = nombre {n} (pluriels)
 */
function t(string $key, array|int $params = []): string
{
    if (is_int($params)) {
        $params = ['n' => $params];
    }
    $lang = locale();
    $text = i18n_messages($lang)[$key] ?? i18n_messages(I18N_REFERENCE)[$key] ?? $key;

    if (is_array($text)) {
        $n    = $params['n'] ?? 0;
        $form = i18n_plural_form(isset(i18n_messages($lang)[$key]) ? $lang : I18N_REFERENCE, is_numeric($n) ? $n + 0 : 0);
        $text = (string) ($text[$form] ?? $text['other'] ?? reset($text));
    }
    if ($params) {
        $repl = [];
        foreach ($params as $k => $v) {
            $repl['{' . $k . '}'] = (string) $v;
        }
        $text = strtr($text, $repl);
    }
    return $text;
}

/** Textes « js.* » de la langue courante (repli sur le français), pour le JavaScript. */
function i18n_js(): array
{
    $out = [];
    foreach ([I18N_REFERENCE, locale()] as $lang) {
        foreach (i18n_messages($lang) as $k => $v) {
            if (str_starts_with((string) $k, 'js.')) {
                $out[substr((string) $k, 3)] = $v;
            }
        }
    }
    return $out;
}

/**
 * Texte traduit, échappé pour le HTML, dans lequel on insère des fragments HTML de confiance.
 * Ex. : t_html('login.no_password', ['example' => '<code>config.local.example.php</code>'])
 */
function t_html(string $key, array $htmlParams = []): string
{
    $placeholders = [];
    foreach (array_keys($htmlParams) as $k) {
        $placeholders[$k] = '{' . $k . '}'; // on garde les marqueurs pendant l'échappement
    }
    $repl = [];
    foreach ($htmlParams as $k => $v) {
        $repl['{' . $k . '}'] = (string) $v;
    }
    return strtr(e(t($key, $placeholders)), $repl);
}
