<?php
declare(strict_types=1);

/*
 * Historique des prompts d'une fiche : 10 dernières modifications du texte,
 * avec le nombre de lignes ajoutées et supprimées (diff ligne à ligne).
 */


const PROMPT_VERSIONS_KEEP = 10;

/**
 * Différence ligne à ligne (plus longue sous-suite commune).
 * @return list<array{0:string,1:string}> opérations ['=', ligne] | ['+', ligne] | ['-', ligne]
 */
function line_diff(string $old, string $new): array
{
    $a = $old === '' ? [] : preg_split('/\R/u', $old);
    $b = $new === '' ? [] : preg_split('/\R/u', $new);
    $n = count($a);
    $m = count($b);
    if ($n * $m > 4000000) { // texte énorme : diff grossier, tout retiré puis tout ajouté
        return array_merge(array_map(static fn ($l) => ['-', $l], $a), array_map(static fn ($l) => ['+', $l], $b));
    }
    // table des longueurs (de la fin vers le début)
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) {
        for ($j = $m - 1; $j >= 0; $j--) {
            $L[$i][$j] = $a[$i] === $b[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
        }
    }
    $ops = [];
    $i = 0;
    $j = 0;
    while ($i < $n && $j < $m) {
        if ($a[$i] === $b[$j]) {
            $ops[] = ['=', $a[$i]];
            $i++;
            $j++;
        } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
            $ops[] = ['-', $a[$i++]];
        } else {
            $ops[] = ['+', $b[$j++]];
        }
    }
    while ($i < $n) {
        $ops[] = ['-', $a[$i++]];
    }
    while ($j < $m) {
        $ops[] = ['+', $b[$j++]];
    }
    return $ops;
}

/** Nombre de lignes ajoutées / supprimées entre deux textes. */
function line_diff_counts(string $old, string $new): array
{
    $added = $removed = 0;
    foreach (line_diff($old, $new) as [$op]) {
        if ($op === '+') {
            $added++;
        } elseif ($op === '-') {
            $removed++;
        }
    }
    return ['added' => $added, 'removed' => $removed];
}

/** Enregistre une modification du texte d'un prompt (10 dernières gardées). */
function prompt_version_record(int $promptId, string $oldBody, string $newBody): void
{
    if ($oldBody === $newBody) {
        return;
    }
    $c   = line_diff_counts($oldBody, $newBody);
    $pdo = db();
    $pdo->prepare('INSERT INTO prompt_versions (prompt_id, old_body, new_body, added, removed) VALUES (?, ?, ?, ?, ?)')
        ->execute([$promptId, $oldBody, $newBody, $c['added'], $c['removed']]);
    $pdo->prepare('DELETE FROM prompt_versions WHERE prompt_id = ? AND id NOT IN
                   (SELECT id FROM prompt_versions WHERE prompt_id = ? ORDER BY id DESC LIMIT ' . PROMPT_VERSIONS_KEEP . ')')
        ->execute([$promptId, $promptId]);
}

/** Modifications d'un prompt, de la plus récente à la plus ancienne. */
function prompt_versions(int $promptId): array
{
    $st = db()->prepare('SELECT * FROM prompt_versions WHERE prompt_id = ? ORDER BY id DESC LIMIT ' . PROMPT_VERSIONS_KEEP);
    $st->execute([$promptId]);
    return $st->fetchAll();
}
