<?php
// Copie ce fichier en config.local.php et renseigne TON mot de passe.
//   cp config.local.example.php config.local.php
//   php -r "echo password_hash('MonMotDePasse', PASSWORD_DEFAULT);"

return [
    // hash généré avec password_hash(..., PASSWORD_DEFAULT)
    'password_hash' => '$2y$12$remplace.moi',

    // facultatif : autre base, autre dossier d'images
    // 'db'      => __DIR__ . '/storage/personavault.sqlite',
    // 'uploads' => __DIR__ . '/storage/uploads',
];
