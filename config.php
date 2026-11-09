<?php
// Configuration PersonaVault
//
// Le mot de passe n'est PAS versionné : renseigne-le dans config.local.php
// (copie config.local.example.php).
// Pour générer un hash : php -r "echo password_hash('MonMotDePasse', PASSWORD_DEFAULT);"

$config = [
    'name'          => 'PersonaVault',
    'timezone'      => 'Europe/Paris',
    'tagline'       => 'Ta bibliothèque de personas',
    'password_hash' => '',               // ← défini dans config.local.php
    'session_name'  => 'pv_session',
    'session_lifetime' => 30 * 24 * 3600, // cookie + session valables 30 jours
    'db'            => __DIR__ . '/storage/personavault.sqlite',
    'uploads'       => __DIR__ . '/storage/uploads',
    'max_upload'    => 15 * 1024 * 1024, // 15 Mo par image
    'media_max_bytes' => 500 * 1024,     // poids visé d'une photo enregistrée (grand format)
    'max_pixels'    => 40_000_000,       // 40 Mpx max avant décodage (anti « bombe » d'image)
    'login_max_try' => 5,                // tentatives ratées autorisées par IP…
    'login_window'  => 15 * 60,          // …par fenêtre de 15 minutes
    'login_global_max' => 30,            // tentatives ratées toutes IP confondues, même fenêtre

    // Derrière un reverse proxy (nginx, Caddy) : true pour faire confiance aux en-têtes
    // X-Forwarded-Proto (cookie Secure) et X-Forwarded-For (IP du client).
    // À laisser à false si PHP est joignable directement : ces en-têtes seraient falsifiables.
    'trust_proxy'   => false,

    // Import de sauvegarde ZIP
    'zip_max_entries'    => 5000,              // nombre d'entrées max
    'zip_max_entry_size' => 40 * 1024 * 1024,  // taille décompressée max par entrée
    'zip_max_total'      => 2 * 1024 * 1024 * 1024, // taille décompressée totale max

    // Valeurs par défaut du LLM (surchargées par les réglages enregistrés)
    'llm' => [
        'provider' => 'deepseek',
        'base_url' => 'https://api.deepseek.com/v1',
        'model'    => 'deepseek-chat',
    ],
];

// Surcharges locales (mot de passe, chemins, fournisseur…) : jamais versionnées
$local = __DIR__ . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, (array) require $local);
}

return $config;
