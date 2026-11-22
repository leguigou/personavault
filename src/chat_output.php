<?php
declare(strict_types=1);

const CHAT_CONTEXT_OPEN = '<contexte>';
const CHAT_CONTEXT_CLOSE = '</contexte>';

/** Filtre le contexte interne même si une balise arrive en plusieurs fragments. */
final class ChatContextOutput
{
    private string $pending = '';
    private bool $hidden = false;

    public function push(string $chunk): string
    {
        $text = $this->pending . $chunk;
        $visible = '';
        while (true) {
            $tag = $this->hidden ? CHAT_CONTEXT_CLOSE : CHAT_CONTEXT_OPEN;
            $position = stripos($text, $tag);
            if ($position === false) {
                break;
            }
            if (!$this->hidden) {
                $visible .= substr($text, 0, $position);
            }
            $text = substr($text, $position + strlen($tag));
            $this->hidden = !$this->hidden;
        }

        $this->pending = '';
        $start = strrpos($text, '<');
        if ($start !== false && str_starts_with($tag, strtolower(substr($text, $start)))) {
            $this->pending = substr($text, $start);
            $text = substr($text, 0, $start);
        }
        return $this->hidden ? $visible : $visible . $text;
    }

    public function finish(): string
    {
        return $this->hidden ? '' : $this->pending;
    }
}

function chat_strip_context(string $text): string
{
    if (stripos($text, CHAT_CONTEXT_OPEN) === false) {
        return $text;
    }
    $filter = new ChatContextOutput();
    return trim($filter->push($text) . $filter->finish());
}
