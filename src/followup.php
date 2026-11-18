<?php
declare(strict_types=1);

/*
 * Messages spontanés (bin/followup.php) : quel genre de message le persona envoie.
 *
 * Sans indication, le modèle prolonge le dernier sujet. Chaque réveil reçoit donc un type
 * imposé, choisi au hasard (avec des poids) parmi ceux qui n'ont pas servi aux 3 derniers
 * réveils de la conversation : prendre des nouvelles de l'utilisateur à partir d'un souvenir
 * sur lui, une anecdote de sa journée, un souvenir partagé, une proposition, son humeur,
 * une découverte, ou plus rarement un rebond sur le dernier sujet (le seul type où le
 * « dernier sujet » est rappelé).
 */

const FOLLOWUP_MEMORY = 3; // types récents à éviter

/**
 * Types de réveil : poids du tirage et consigne (« {fact} », « {date} » remplacés).
 * @return array<string, array{weight:int, needs:string, text:string}>
 */
function followup_kinds(): array
{
    return [
        'nouvelles'   => ['weight' => 3, 'needs' => 'user',
            'text' => "Cette fois, prends de ses nouvelles à propos de ce qu'il ou elle t'a confié ({date}) : « {fact} ». "
                . "Pose une question naturelle, comme quelqu'un qui s'en souvient, sans recopier la phrase."],
        'anecdote'    => ['weight' => 3, 'needs' => '',
            'text' => 'Cette fois, raconte un petit moment de ta journée, cohérent avec ta fiche (ton métier, ton quotidien, '
                . 'ton entourage) : un détail concret, pas une généralité.'],
        'souvenir'    => ['weight' => 2, 'needs' => 'story',
            'text' => 'Cette fois, évoque ce moment de votre histoire, comme une pensée qui te revient : « {fact} ».'],
        'proposition' => ['weight' => 2, 'needs' => '',
            'text' => 'Cette fois, propose quelque chose : une sortie, une activité, un projet ou un petit jeu, '
                . "en lien avec ce que tu sais de lui ou d'elle."],
        'humeur'      => ['weight' => 2, 'needs' => '',
            'text' => 'Cette fois, partage ton humeur du moment et ce qui la provoque, en une phrase sincère.'],
        'decouverte'  => ['weight' => 2, 'needs' => '',
            'text' => "Cette fois, partage une découverte qui t'a fait penser à lui ou à elle : une chanson, un lieu, "
                . "un film, une photo que tu viens de prendre, une info."],
        'rebond'      => ['weight' => 1, 'needs' => '',
            'text' => 'Cette fois, reviens sur le dernier sujet de votre conversation, avec une idée ou une question nouvelle.'],
    ];
}

/**
 * Un souvenir pour le réveil : sur l'utilisateur ($subject 'user') ou sur votre histoire
 * ('story'). Parmi les 8 plus récents, au hasard, en écartant ceux que les 3 derniers
 * réveils ont déjà évoqués.
 */
function followup_fact(int $cid, int $pid, string $subject): ?array
{
    $stmt = db()->prepare("SELECT * FROM user_memories WHERE subject = ? AND confidence <> 'hypothese'
        AND (persona_id = ? OR (persona_id IS NULL AND subject = 'user')) ORDER BY updated_at DESC, id DESC LIMIT 8");
    $stmt->execute([$subject, $pid]);
    $facts = $stmt->fetchAll();
    $stmt = db()->prepare("SELECT content FROM messages WHERE conversation_id = ? AND spontaneous = 1 ORDER BY id DESC LIMIT 3");
    $stmt->execute([$cid]);
    $recent = array_map(static fn ($c): array => text_words((string) $c, 4), $stmt->fetchAll(PDO::FETCH_COLUMN));
    $facts = array_values(array_filter($facts, static function (array $f) use ($recent): bool {
        foreach ($recent as $words) {
            if (duo_overlap(text_words((string) $f['content'], 4), $words) >= 0.3) {
                return false;
            }
        }
        return true;
    }));
    return $facts ? $facts[random_int(0, count($facts) - 1)] : null;
}

/**
 * Type du prochain réveil et sa consigne.
 * Réglage caché chat_followup_force_kind : impose un type (banc de tests).
 * @return array{kind:string, text:string, topic:bool}
 */
function followup_pick(array $conv, int $pid): array
{
    $kinds = followup_kinds();
    $cid = (int) $conv['id'];
    $recent = array_slice(array_filter(explode(',', (string) ($conv['followup_kinds'] ?? ''))), -FOLLOWUP_MEMORY);
    $material = ['user' => followup_fact($cid, $pid, 'user'), 'story' => followup_fact($cid, $pid, 'story')];

    $forced = setting('chat_followup_force_kind', '');
    $pool = [];
    foreach ($kinds as $key => $k) {
        if (($k['needs'] === '' || $material[$k['needs']]) && !in_array($key, $recent, true)) {
            $pool[$key] = $k['weight'];
        }
    }
    if (isset($kinds[$forced])) {
        $kind = $forced;
    } elseif (!$pool) {
        $kind = 'anecdote';
    } else {
        $roll = random_int(1, array_sum($pool));
        foreach ($pool as $kind => $weight) {
            if (($roll -= $weight) <= 0) {
                break;
            }
        }
    }

    $fact = $kinds[$kind]['needs'] !== '' ? $material[$kinds[$kind]['needs']] : null;
    $text = $kinds[$kind]['text'];
    if ($fact) {
        $text = str_replace(['{fact}', '{date}'], [trim((string) $fact['content']), chat_llm_date((string) $fact['updated_at'])], $text);
    } elseif ($kinds[$kind]['needs'] !== '') {
        $kind = 'anecdote'; // type imposé sans souvenir disponible
        $text = $kinds['anecdote']['text'];
    }
    if ($kind !== 'rebond') {
        $text .= ' Ne reprends pas le dernier sujet de la conversation.';
    }
    return ['kind' => $kind, 'text' => $text, 'topic' => $kind === 'rebond'];
}

/** Retient le type utilisé (les FOLLOWUP_MEMORY derniers). */
function followup_remember(int $cid, string $previous, string $kind): void
{
    $kinds = array_slice([...array_filter(explode(',', $previous)), $kind], -FOLLOWUP_MEMORY);
    db()->prepare('UPDATE conversations SET followup_kinds = ? WHERE id = ?')->execute([implode(',', $kinds), $cid]);
}
