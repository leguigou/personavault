<?php
/**
 * Données de test reproductibles pour le parcours e2e.
 * Usage : php tests/e2e/seed.php <dossier de l'app copiée> <port du faux modèle>
 * (la copie contient un config.local.php qui pointe vers une base temporaire)
 */
declare(strict_types=1);

[$_, $appDir, $llmPort] = $argv + [null, null, '18235'];
require $appDir . '/src/bootstrap.php';

$tmp = sys_get_temp_dir() . '/pv-seed-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$image = static function (array $rgb) use ($tmp): array {
    $img = imagecreatetruecolor(400, 500);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    imagefilledellipse($img, 200, 190, 150, 180, imagecolorallocate($img, 240, 220, 200));
    $f = $tmp . '/' . bin2hex(random_bytes(4)) . '.png';
    imagepng($img, $f);
    return ['tmp_name' => $f, 'error' => UPLOAD_ERR_OK, 'size' => filesize($f)];
};

$pdo = db();
$personas = [
    ['camille-lefebvre', 'Camille Lefebvre', 'Cheffe de produit, 32 ans', 'Curieuse et directe.', ['romance', 'studio'], [167, 139, 250]],
    ['ines-morel', 'Inès Morel', 'Photographe argentique', 'Rêveuse.', ['art'], [244, 114, 182]],
    ['hugo-bernard', 'Hugo Bernard', 'Ingénieur du son', 'Calme.', ['musique'], [34, 211, 238]],
];
$ids = [];
foreach ($personas as [$slug, $name, $tag, $desc, $tags, $rgb]) {
    $avatar = store_upload($image($rgb));
    $pdo->prepare('INSERT INTO personas (slug, name, tagline, description, avatar, tags, meta) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$slug, $name, $tag, $desc, $avatar, json_encode($tags), json_encode([['k' => 'Âge', 'v' => '32']])]);
    $ids[$slug] = (int) $pdo->lastInsertId();
}
// dates distinctes : l'ordre de la bibliothèque (tri par date de mise à jour) ne dépend plus
// du hasard de la seconde à laquelle chaque fiche a été créée
$age = 0;
foreach (array_reverse($ids) as $id) {
    $pdo->prepare("UPDATE personas SET created_at = datetime('now', ?), updated_at = datetime('now', ?) WHERE id = ?")
        ->execute(['-' . (10 + $age) . ' minutes', '-' . (10 + $age) . ' minutes', $id]);
    $age += 5;
}
$c = $ids['camille-lefebvre'];
$pdo->prepare('INSERT INTO photos (persona_id, file) VALUES (?, ?)')->execute([$c, store_upload($image([90, 90, 120]))]);
$pdo->prepare("INSERT INTO prompts (persona_id, title, kind, body) VALUES (?, 'Portrait', 'image', 'portrait 85mm, lumière douce'), (?, 'Système', 'system', 'Tu es Camille.')")
    ->execute([$c, $c]);

$pdo->prepare('INSERT INTO conversations (persona_id, title) VALUES (?, ?)')->execute([$c, 'Week-end à Lisbonne']);
$cid = (int) $pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO messages (conversation_id, role, content) VALUES (?, ?, ?)');
for ($i = 1; $i <= 12; $i++) {
    $ins->execute([$cid, $i % 2 ? 'user' : 'assistant', $i % 2
        ? "Message $i : j'ai pris le train de nuit pour Lisbonne et mangé des pastéis au bord du Tage. "
          . str_repeat("On a marché dans l'Alfama, écouté du fado et regardé le coucher de soleil depuis le miradouro. ", 3)
        : "Réponse $i : quelle belle journée, raconte-moi la suite !"]);
}
$pdo->prepare('UPDATE messages SET image = ? WHERE id = ?')->execute([fetch_photos($c)[0]['file'], (int) $pdo->lastInsertId() - 1]);
$pdo->prepare('UPDATE conversations SET unread = 1 WHERE id = ?')->execute([$cid]);
$pdo->prepare('INSERT INTO conversations (persona_id, title) VALUES (?, ?)')->execute([$ids['ines-morel'], 'Pellicule']);
$pdo->prepare("INSERT INTO prompts (persona_id, title, kind, body) VALUES (?, 'Premier message', 'other', 'J’ai retrouvé une vieille pellicule.')")
    ->execute([$ids['ines-morel']]);
facts_add($c, 'Prend souvent le train', 'fait', 'confirme', $cid);

foreach ([
    'chat_llm_provider' => 'lmstudio', 'chat_llm_base_url' => "http://127.0.0.1:$llmPort/v1", 'chat_llm_model' => 'fake-model',
    'chat_split_enabled' => '0', 'chat_title_model' => 'chat', 'chat_followup_enabled' => '0',
    'chat_memory_threshold' => '600', 'chat_memory_target' => '300',
    // modèle des Réglages › IA (assistant de création)
    'llm_provider' => 'lmstudio', 'llm_base_url' => "http://127.0.0.1:$llmPort/v1", 'llm_model' => 'fake-model', 'llm_api_key' => 'test',
] as $k => $v) {
    set_setting($k, $v);
}

array_map('unlink', glob($tmp . '/*') ?: []);
rmdir($tmp);
echo 'données de test : ' . count($ids) . " personas, 2 discussions\n";
