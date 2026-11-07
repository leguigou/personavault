<?php
// Enregistre la clé DeepSeek (lue depuis un fichier temporaire) dans les réglages.
$file = $argv[1] ?? '';
$key  = is_file($file) ? trim((string) file_get_contents($file)) : '';
if ($key === '') {
    fwrite(STDERR, "cle vide\n");
    exit(1);
}
$db = new PDO('sqlite:' . __DIR__ . '/../storage/personavault.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
$db->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
   ->execute(['llm_api_key', $key]);
$db->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
   ->execute(['llm_provider', 'deepseek']);
echo 'cle enregistree (longueur ' . strlen($key) . ', prefixe ' . substr($key, 0, 3) . ")\n";
