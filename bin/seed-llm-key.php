<?php
/**
 * Enregistre la clé API du fournisseur distant (lue depuis un fichier) dans les réglages.
 * Usage : php bin/seed-llm-key.php /chemin/vers/fichier-cle [fournisseur]
 * Supprime ensuite le fichier : la clé y est en clair.
 */
require __DIR__ . '/../src/bootstrap.php';

$file = $argv[1] ?? '';
$key  = is_file($file) ? trim((string) file_get_contents($file)) : '';
if ($key === '') {
    fwrite(STDERR, "Clé vide ou fichier introuvable.\n");
    exit(1);
}

// même base que l'application (config.php / config.local.php)
set_setting('llm_api_key', $key);
set_setting('llm_provider', (string) ($argv[2] ?? 'deepseek'));

echo 'Clé enregistrée (' . strlen($key) . " caractères). Pense à supprimer le fichier source.\n";
