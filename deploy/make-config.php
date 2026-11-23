<?php
// Écrit /var/www/html/config.local.php depuis l'environnement du conteneur.
// PV_PASSWORD_HASH a priorité ; PV_PASSWORD (en clair) est accepté et haché ici.
// Aucun échappement shell : le hash bcrypt contient des « $ ».

$hash = (string) getenv('PV_PASSWORD_HASH');
$password = (string) getenv('PV_PASSWORD');

if ($hash === '' && $password !== '') {
    $hash = password_hash($password, PASSWORD_DEFAULT);
}

$config = [
    'password_hash' => $hash,
    'trust_proxy'   => filter_var(getenv('PV_TRUST_PROXY') ?: 'true', FILTER_VALIDATE_BOOLEAN),
];

file_put_contents(
    '/var/www/html/config.local.php',
    "<?php\n// Généré au démarrage du conteneur — ne pas éditer à la main.\nreturn "
        . var_export($config, true) . ";\n"
);

if ($hash === '') {
    fwrite(STDERR, "[personavault] ATTENTION : aucun mot de passe configuré (PV_PASSWORD / PV_PASSWORD_HASH).\n");
}
