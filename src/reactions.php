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
const CHAT_REACTION_MARKER = '⟦reaction:';

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

function chat_reaction_description(array $message): string
{
    $key = chat_reaction_key($message['reaction'] ?? '', (string) ($message['role'] ?? ''));
    return $key === '' ? '' : implode(' — ', CHAT_REACTIONS[$key]);
}

function chat_reaction_context(array $message): string
{
    $quote = trim((string) $message['content']);
    if (mb_strlen($quote) > 900) {
        $quote = mb_substr($quote, 0, 640) . ' […] ' . mb_substr($quote, -240);
    }
    return chat_reaction_description($message) . ' | Message du personnage : '
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
    $text = "Les réactions de l’utilisateur aux messages sont des indices de ton. "
        . "Un 👍 veut dire « ok » ou marque un accord simple. Pour un 👎 ou 😡, accueille calmement le désaccord "
        . "ou la frustration si le sujet s’y prête ; demande ce qui a gêné si c’est utile, sans supposer la cause. "
        . "Un cœur, un bisou ou un visage amoureux indique une affection dont le sens dépend de votre relation. "
        . "Tiens compte de ces signaux naturellement, sans commenter chaque clic ni répéter les émojis. "
        . "Utilise aussi les émojis dans ton texte avec parcimonie, selon ton style habituel.";
    if ($canReact) {
        $options = array_map(static fn (string $key): string => $key . ' = ' . CHAT_REACTIONS[$key][1], CHAT_PERSONA_REACTIONS);
        $text .= " Exceptionnellement, si le dernier message de l’utilisateur mérite vraiment un petit geste "
            . "(mot particulièrement gentil, annonce importante, vraie complicité ou réconfort), tu peux ajouter "
            . "une seule réaction distincte de ta réponse. Termine ta réponse textuelle par " . CHAT_REACTION_MARKER . "identifiant⟧. "
            . "Identifiants autorisés : " . implode('; ', $options) . ". "
            . "Dans un échange ordinaire, écris simplement ta réponse sans marqueur. Ne redouble pas ta réaction dans le texte.";
    }
    return $text;
}

/** Retient uniquement une éventuelle fin de fragment du marqueur avant d'émettre le texte SSE. */
final class ChatReactionStream
{
    public string $content = '';
    private string $pending = '';

    public function __construct(private readonly bool $canReact)
    {
    }

    public function push(string $delta): string
    {
        $text = preg_replace('/⟦reaction:[^⟧\r\n]{0,32}⟧/u', '', $this->pending . $delta);
        $this->pending = '';
        $start = strrpos($text, '⟦');
        if ($start !== false) {
            $tail = substr($text, $start);
            if (str_starts_with(CHAT_REACTION_MARKER, $tail)
                || (str_starts_with($tail, CHAT_REACTION_MARKER) && !str_contains($tail, '⟧') && strlen($tail) <= 48)) {
                $this->pending = $tail;
                $text = substr($text, 0, $start);
            }
        }
        $this->content .= $text;
        return $text;
    }

    public function reaction(string $rawContent): string
    {
        if (!$this->canReact || !preg_match('/⟦reaction:([a-z]+)⟧\s*$/u', $rawContent, $match)) {
            return '';
        }
        return chat_reaction_key($match[1], 'user');
    }
}
