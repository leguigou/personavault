#!/bin/sh
# Démarrage PersonaVault : prépare le volume de données, écrit la config
# locale depuis l'environnement, puis laisse Apache prendre la main.
set -e

APP_DIR=/var/www/html

# Le volume nommé peut arriver vide (ou appartenir à root) : on s'assure que
# www-data peut écrire dans storage/ (SQLite, uploads, sessions, sauvegardes).
mkdir -p "$APP_DIR/storage/uploads" "$APP_DIR/storage/sessions" \
         "$APP_DIR/storage/tmp" "$APP_DIR/storage/backups"
chown -R www-data:www-data "$APP_DIR/storage"

# config.local.php : mot de passe et trust_proxy, jamais versionnés.
php /usr/local/bin/pv-make-config
chown www-data:www-data "$APP_DIR/config.local.php"
chmod 640 "$APP_DIR/config.local.php"

exec "$@"
