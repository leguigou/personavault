<?php
declare(strict_types=1);

/*
 * MemoryGuard — commun à tous les personas.
 *
 *   « Le persona est libre d'inventer sa vie, mais il n'est pas libre de réécrire celle de
 *     l'utilisateur ni leur histoire commune. »
 *
 * 1. À l'enregistrement : un souvenir doit citer l'extrait EXACT du message qui le prouve ; le code
 *    vérifie que l'extrait existe bien dans ce message (de l'utilisateur pour un fait sur lui, du
 *    persona pour son histoire) et qu'il parle de la même chose. Une citation introuvable = souvenir
 *    rejeté. Origine, confirmations et expiration donnent une fiabilité calculée, jamais déclarée.
 * 2. Avant chaque réponse : quand l'utilisateur évoque un souvenir commun, on le cherche ; trouvé,
 *    le persona s'appuie dessus sans broder ; introuvable, il doute à sa façon au lieu d'inventer.
 */

/** Règle de base, ajoutée aux règles de chaque persona. */
function memory_guard_rule(): string
{
    return "Mémoire : tu es libre d'inventer ta propre vie, à condition de rester cohérent avec ce que tu as déjà raconté ; "
        . "tu n'es pas libre de réécrire celle de l'utilisateur ni votre histoire commune. N'évoque jamais un moment partagé, "
        . "un échange passé ou une parole (« comme je te le disais », « la dernière fois ») qui n'apparaît ni dans la "
        . "conversation ni dans tes souvenirs, et n'attribue jamais à l'utilisateur un fait qu'il n'a pas dit.";
}

/* ---------------------------------------------------------------- preuves */

/** Texte comparable : minuscules, sans accents, apostrophes et ponctuation neutralisées. */
function memory_guard_norm(string $s): string
{
    $s = mb_strtolower($s);
    if (class_exists('Normalizer')) {
        $s = preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D)) ?? $s;
    } else {
        $s = strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'á' => 'a', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? $s;
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** L'extrait figure-t-il mot pour mot dans le message (au moins 2 mots) ? */
function memory_guard_quote_in(string $quote, string $message): bool
{
    $q = memory_guard_norm($quote);
    return $q !== '' && substr_count($q, ' ') >= 1 && str_contains(' ' . memory_guard_norm($message) . ' ', ' ' . $q . ' ');
}

/**
 * L'extrait parle-t-il du même sujet que le souvenir ? Au moins un mot porteur commun
 * (« Guillaume vit à Toulon » / « je vis à Toulon » : « toulon »). Les prénoms et mots vides ne comptent pas.
 */
function memory_guard_supports(string $content, string $quote, array $ignore = []): bool
{
    $words = facts_signature($content, $ignore)['words'] + facts_signature($content, $ignore)['num'];
    $quoteWords = array_flip(explode(' ', memory_guard_norm($quote)));
    foreach (array_keys($words) as $w) {
        $w = memory_guard_norm((string) $w);
        if ($w !== '' && (isset($quoteWords[$w]) || (mb_strlen($w) >= 5 && array_filter(array_keys($quoteWords),
            static fn ($q) => mb_strlen((string) $q) >= 5 && mb_substr((string) $q, 0, 5) === mb_substr($w, 0, 5))))) {
            return true; // même mot, ou même racine (« vit » / « vis » trop courts ; « travaille » / « travail » oui)
        }
    }
    return false;
}

/**
 * Message qui prouve le souvenir, parmi ceux de l'échange analysé ($sources, du rôle attendu).
 * @return array{0:?array, 1:string} [message trouvé ou null, statut : 'ok' | 'none' (pas d'extrait) | 'missing' (introuvable)]
 */
function memory_guard_locate(string $quote, array $sources, string $role, ?array $preferred): array
{
    $preferred = $preferred !== null && ($preferred['role'] ?? '') === $role ? $preferred : null;
    if (trim($quote) === '') {
        return [$preferred, 'none'];
    }
    if ($preferred !== null && memory_guard_quote_in($quote, (string) $preferred['content'])) {
        return [$preferred, 'ok'];
    }
    // numéro de source erroné : l'extrait peut venir d'un autre message du même auteur
    foreach ($sources as $m) {
        if (($m['role'] ?? '') === $role && memory_guard_quote_in($quote, (string) $m['content'])) {
            return [$m, 'ok'];
        }
    }
    return [null, 'missing'];
}

/* ---------------------------------------------------------------- expiration et fiabilité */

/** Date d'expiration : celle donnée (AAAA-MM-JJ, dans les 3 ans), sinon 14 jours pour un fait temporaire. */
function memory_guard_expiry(mixed $raw, string $confidence, ?DateTimeImmutable $today = null): string
{
    $today ??= chat_now();
    if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $today->getTimezone());
        if ($d && $d->format('Y-m-d') === $raw && $d >= $today->setTime(0, 0) && $d <= $today->modify('+3 years')) {
            return $raw;
        }
    }
    return $confidence === 'temporaire' ? $today->modify('+14 days')->format('Y-m-d') : '';
}

/**
 * Fiabilité calculée d'un souvenir :
 *   expire    : date passée, plus jamais envoyée au persona ;
 *   fiable    : ajouté ou demandé par l'utilisateur, ou prouvé par un extrait vérifié, ou confirmé plusieurs fois ;
 *   probable  : ancien souvenir (avant MemoryGuard) ou ajouté par le connecteur ;
 *   incertain : hypothèse, ou extrait automatiquement sans preuve.
 */
function memory_guard_reliability(array $m, ?string $today = null): string
{
    $today ??= chat_now()->format('Y-m-d');
    $expires = (string) ($m['expires_at'] ?? '');
    if ($expires !== '' && $expires < $today) {
        return 'expire';
    }
    $origin = (string) ($m['origin'] ?? '');
    if (in_array($origin, ['manual', 'explicit'], true)) {
        return 'fiable';
    }
    if (($m['confidence'] ?? '') === 'hypothese') {
        return 'incertain';
    }
    if ((string) ($m['evidence'] ?? '') !== '' || (int) ($m['confirmations'] ?? 1) >= 2) {
        return 'fiable';
    }
    return $origin === 'auto' ? 'incertain' : 'probable';
}

/** Libellés de fiabilité (tableau explicite pour le vérificateur i18n). */
function memory_guard_labels(): array
{
    return [
        'fiable'    => t('mguard.fiable'),
        'probable'  => t('mguard.probable'),
        'incertain' => t('mguard.incertain'),
        'expire'    => t('mguard.expire'),
    ];
}

/** Garde-fou SQL : un souvenir expiré n'est plus proposé au persona. */
function memory_guard_sql_alive(string $alias = ''): string
{
    $col = ($alias !== '' ? $alias . '.' : '') . 'expires_at';
    return "({$col} = '' OR {$col} >= date('now', 'localtime'))";
}

/* ---------------------------------------------------------------- avant la réponse */

/**
 * Consigne quand l'utilisateur évoque un souvenir commun (« tu te souviens… ») :
 * $found = la mémoire ou les autres discussions en contiennent quelque chose.
 */
function memory_guard_context(bool $found): string
{
    return $found
        ? "MemoryGuard : l'utilisateur évoque un souvenir commun. Appuie-toi seulement sur ce que tu en sais (conversation, "
            . "souvenirs et extraits ci-dessus) ; n'ajoute aucun détail qu'ils ne contiennent pas."
        : "MemoryGuard : l'utilisateur évoque un souvenir commun que tu ne retrouves dans aucun de tes souvenirs ni aucune autre "
            . "conversation. Si cette conversation-ci n'en parle pas non plus, tu ne t'en souviens pas : dis-le ou montre ton doute "
            . "à ta façon, sans inventer de détail, et invite-le à te le raconter. Ne fais jamais semblant de te souvenir.";
}
