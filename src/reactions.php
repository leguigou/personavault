<?php
declare(strict_types=1);

/** Les six premières réactions forment la rangée rapide. */
const CHAT_REACTIONS = [
    'like'      => ['👍', 'accord ou simple approbation, sans implication affective'],
    'heart'     => ['❤️', 'affection sincère, amour ou amitié forte selon la relation'],
    'laugh'     => ['😂', 'franc amusement'],
    'hug'       => ['🫂', 'soutien ou réconfort'],
    'dislike'   => ['👎', 'désaccord ou insatisfaction face à ce message'],
    'angry'     => ['😡', 'colère ou frustration ; son motif reste à comprendre'],
    'tender'    => ['🥰', 'tendresse ou complicité'],
    'kiss'      => ['😘', 'bisou affectueux si la relation s’y prête'],
    'love'      => ['😍', 'élan affectueux ou admiration marquée selon la relation'],
    'celebrate' => ['🎉', 'joie pour un moment à célébrer'],
    'smile'     => ['😊', 'plaisir ou sourire chaleureux'],
    'surprise'  => ['😮', 'surprise, dont le ton dépend du contexte'],
    'sad'       => ['😢', 'tristesse ou émotion face à ce message'],
];

const CHAT_AFFECTIONATE_REACTIONS = ['heart', 'tender', 'kiss', 'love', 'celebrate', 'hug'];
const CHAT_PERSONA_REACTIONS = ['heart', 'laugh', 'hug', 'tender', 'kiss', 'love', 'celebrate', 'surprise', 'sad'];

function chat_reaction_options(): array
{
    $labels = [
        'like' => t('chat.emoji.like'),
        'heart' => t('chat.emoji.heart'),
        'laugh' => t('chat.emoji.laugh'),
        'hug' => t('chat.emoji.hug'),
        'dislike' => t('chat.emoji.dislike'),
        'angry' => t('chat.emoji.angry'),
        'tender' => t('chat.emoji.tender'),
        'kiss' => t('chat.emoji.kiss'),
        'love' => t('chat.emoji.love'),
        'celebrate' => t('chat.emoji.celebrate'),
        'smile' => t('chat.emoji.smile'),
        'surprise' => t('chat.emoji.surprise'),
        'sad' => t('chat.emoji.sad'),
    ];
    $options = [];
    foreach (CHAT_REACTIONS as $key => [$emoji]) {
        $options[$key] = ['emoji' => $emoji, 'label' => $labels[$key]];
    }
    return $options;
}

function chat_reaction_key(mixed $key, string $role): string
{
    if (!is_string($key)) {
        return '';
    }
    return match ($role) {
        'assistant' => isset(CHAT_REACTIONS[$key]) ? $key : '',
        'user'      => in_array($key, CHAT_PERSONA_REACTIONS, true) ? $key : '',
        default     => '',
    };
}

function chat_message_split_points(array $message): array
{
    $length = mb_strlen((string) $message['content']);
    $points = array_values(array_unique(array_filter(
        array_map('intval', json_list($message['split_points'] ?? '')),
        static fn (int $point): bool => $point > 0 && $point < $length
    )));
    sort($points, SORT_NUMERIC);
    $legacy = (int) ($message['split_at'] ?? 0);
    return !$points && $legacy > 0 && $legacy < $length ? [$legacy] : $points;
}

function chat_message_bubbles(array $message): array
{
    $text = (string) $message['content'];
    $points = chat_message_split_points($message);
    if (!$points) {
        return [$text];
    }
    $bubbles = [];
    $start = 0;
    foreach ([...$points, mb_strlen($text)] as $end) {
        $part = trim(mb_substr($text, $start, $end - $start));
        if ($part !== '') {
            $bubbles[] = $part;
        }
        $start = $end;
    }
    return $bubbles ?: [$text];
}

/** La première bulle conserve la réaction au format des anciennes discussions. */
function chat_bubble_reactions(array $message, ?array $bubbles = null): array
{
    $role = (string) $message['role'];
    $first = chat_reaction_key($message['reaction'] ?? '', $role);
    $keys = $first === '' ? [] : [0 => $first];
    $stored = $message['bubble_reactions'] ?? '';
    $extra = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;
    if ($role === 'assistant' && is_array($extra) && $extra) {
        $bubbles ??= chat_message_bubbles($message);
        foreach ($extra as $index => $key) {
            $key = chat_reaction_key($key, $role);
            if (is_int($index) && $index > 0 && isset($bubbles[$index]) && $key !== '') {
                $keys[$index] = $key;
            }
        }
    }
    return $keys;
}

function chat_pending_reactions(string $stored): array
{
    if ($stored === '') {
        return [];
    }
    $pending = json_decode($stored, true);
    return is_array($pending) ? $pending : [0 => $stored];
}

function chat_encode_reactions(array $reactions): string
{
    return $reactions ? json_encode((object) $reactions, JSON_THROW_ON_ERROR) : '';
}

function chat_affectionate_bubbles(array $message): array
{
    if ($message['role'] !== 'assistant' || (empty($message['reaction']) && empty($message['bubble_reactions']))) {
        return [];
    }
    $bubbles = chat_message_bubbles($message);
    $reactions = chat_bubble_reactions($message, $bubbles);
    $lines = [];
    foreach ($reactions as $index => $key) {
        if (in_array($key, CHAT_AFFECTIONATE_REACTIONS, true)) {
            $quote = mb_substr(trim($bubbles[$index]), 0, 320);
            $lines[] = 'Réaction de l’utilisateur : ' . implode(' — ', CHAT_REACTIONS[$key]) . ' sur « ' . $quote . ' »';
        }
    }
    return $lines;
}

function chat_reaction_context(array $message, int $maxTokens): string
{
    $quote = chat_shorten_middle(trim($message['content']), $maxTokens);
    return implode(' — ', CHAT_REACTIONS[$message['reaction']]) . ' | Bulle du personnage : '
        . json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Attend trois nouveaux messages avant de permettre une autre réaction du personnage. */
function chat_can_react(int $cid): bool
{
    $stmt = db()->prepare("SELECT reaction FROM messages WHERE conversation_id = ? AND role = 'user' ORDER BY id DESC LIMIT 3");
    $stmt->execute([$cid]);
    return !array_filter($stmt->fetchAll(PDO::FETCH_COLUMN));
}

function chat_reaction_instructions(bool $canReact): string
{
    $text = "Les réactions de l’utilisateur à une bulle de message sont des indices de ton. "
        . "Un 👍 veut dire « ok » ou marque un accord simple. Pour un 👎 ou 😡, accueille calmement le désaccord "
        . "ou la frustration si le sujet s’y prête ; demande ce qui a gêné si c’est utile, sans supposer la cause. "
        . "Un cœur, un bisou ou un visage amoureux indique une affection dont le sens dépend de votre relation. "
        . "Tiens compte de ces signaux naturellement, sans commenter chaque clic ni répéter les émojis. "
        . "Utilise les émojis avec parcimonie et intègre-les à une phrase, selon ton style habituel.";
    if ($canReact) {
        $text .= "\n\nChoisis une réaction à apposer sur le dernier message de ton interlocuteur. "
            . "Commence toujours par une ligne [reaction:choix], puis écris ta réponse normale sur la ligne suivante. "
            . 'Choisis parmi ' . implode(', ', CHAT_PERSONA_REACTIONS) . ", none. "
            . "Un remerciement personnel, une attention ou un compliment sincère appelle heart ou tender. "
            . "Une bonne nouvelle appelle celebrate, une vraie blague laugh, une confidence douloureuse hug ou sad. "
            . "Réserve kiss et love aux relations où c’est déjà naturel. Pour un échange pratique ou banal, choisis none. "
            . "Exemples : « Merci d’avoir été là pour moi, ça m’a vraiment fait du bien » → [reaction:heart]. "
            . "« On se parle à 18 h ? » → [reaction:none]. "
            . "Le code est invisible pour ton interlocuteur. Dans ta réponse, poursuis l’échange sans commenter la réaction ni répéter son émoji.";
    }
    return $text;
}

/** Lit la réaction en tête du flux et émet uniquement le texte de la réponse. */
final class ChatReactionStream
{
    private const HEADER = '[reaction:';
    private const LEGACY_MARKER = '⟦reaction:';

    public string $content = '';
    private ?string $choice = null;
    private ?string $head = '';
    private bool $skipLeadingSpace = false;
    private string $pending = '';

    public function __construct(private readonly bool $canReact)
    {
    }

    public function push(string $delta): string
    {
        if ($this->head !== null) {
            $this->head .= $delta;
            $start = ltrim($this->head);
            $lower = strtolower($start);
            if (strlen($this->head) <= 64 && (str_starts_with(self::HEADER, $lower)
                || (str_starts_with($lower, self::HEADER) && !str_contains($lower, ']') && !str_contains($lower, "\n")))) {
                return '';
            }
            $delta = $this->head;
            $this->head = null;
            if (preg_match('/^\[reaction:\h*([a-z]{1,24})\h*\]/i', $start, $match)) {
                $this->choice = strtolower($match[1]);
                $delta = substr($start, strlen($match[0]));
                $this->skipLeadingSpace = true;
            }
        }
        if ($this->skipLeadingSpace) {
            $delta = ltrim($delta);
            $this->skipLeadingSpace = $delta === '';
        }

        $text = preg_replace('/⟦reaction:[^⟧\r\n]{0,32}⟧/u', '', $this->pending . $delta);
        $this->pending = '';
        $start = strrpos($text, '⟦');
        if ($start !== false) {
            $tail = substr($text, $start);
            if (str_starts_with(self::LEGACY_MARKER, $tail)
                || (str_starts_with($tail, self::LEGACY_MARKER) && !str_contains($tail, '⟧') && strlen($tail) <= 48)) {
                $this->pending = $tail;
                $text = substr($text, 0, $start);
            }
        }
        $this->content .= $text;
        return $text;
    }

    public function reaction(string $rawContent): string
    {
        if (!$this->canReact) {
            return '';
        }
        if ($this->choice !== null) {
            return chat_reaction_key($this->choice, 'user');
        }
        return preg_match('/⟦reaction:([a-z]+)⟧\s*$/u', $rawContent, $match)
            ? chat_reaction_key($match[1], 'user') : '';
    }

    public function finish(): string
    {
        // Une réponse qui commence par un simple « [ » peut s'arrêter avant qu'on puisse distinguer le préfixe.
        $tail = $this->head ?? '';
        $this->head = null;
        if (str_starts_with(strtolower(ltrim($tail)), '[reaction')) {
            return '';
        }
        $this->content .= $tail;
        return $tail;
    }
}
