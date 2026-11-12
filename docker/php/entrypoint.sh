#!/bin/sh
# Avvio del container app: migrazioni (solo dove RUN_MIGRATIONS=1), poi il comando (php-fpm o lo scheduler).
set -e
mkdir -p /var/www/var/log /var/www/var/images /var/www/var/backups /var/www/var/cache
if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php /var/www/bin/migrate.php
fi
exec "$@"
