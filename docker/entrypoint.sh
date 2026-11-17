#!/bin/sh
set -eu
cd /var/www/html
export PORT="${PORT:-10000}"
export PGCONNECT_TIMEOUT="${PGCONNECT_TIMEOUT:-10}"
case "$PORT" in ''|*[!0-9]*) echo 'PORT must be a number.' >&2; exit 1 ;; esac
if [ "$PORT" -lt 1024 ] || [ "$PORT" -gt 65535 ]; then
    echo 'PORT must be between 1024 and 65535.' >&2
    exit 1
fi
if [ "${DB_CONNECTION:-pgsql}" = "sqlite" ]; then
    if [ "${DEPLOY_EPHEMERAL_SQLITE:-false}" != "true" ]; then
        echo 'SQLite hosting requires DEPLOY_EPHEMERAL_SQLITE=true.' >&2
        exit 1
    fi
    export DB_DATABASE="${DB_DATABASE:-/var/www/html/storage/app/branchflow.sqlite}"
    case "$DB_DATABASE" in /*) ;; *) echo 'DB_DATABASE must be an absolute path.' >&2; exit 1 ;; esac
    mkdir -p "$(dirname "$DB_DATABASE")"
    touch "$DB_DATABASE"
    chown www-data:www-data "$DB_DATABASE" "$(dirname "$DB_DATABASE")"
fi
php artisan config:clear --no-interaction
php artisan branchflow:deploy --no-interaction
php artisan config:cache --no-interaction
php artisan view:cache --no-interaction
chown -R www-data:www-data storage bootstrap/cache
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/branchflow.conf
