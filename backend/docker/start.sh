#!/bin/sh
set -eu

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

php artisan migrate --force
php artisan config:cache

exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
