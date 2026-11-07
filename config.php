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
    'login_max_try' => 5,                // tentatives ratées autorisées
    'login_window'  => 15 * 60,          // par fenêtre de 15 minutes

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
