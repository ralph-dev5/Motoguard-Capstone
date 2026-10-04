#!/bin/sh
set -e
cd /var/www/html

# The host tells us which port to listen on.
: "${PORT:=10000}"
sed "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# Render gives the public address as RENDER_EXTERNAL_URL; use it unless APP_URL was set by hand.
if [ -z "$APP_URL" ] && [ -n "$RENDER_EXTERNAL_URL" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache || echo "[start] route cache skipped"
# The database already exists (Supabase); this only applies migrations added since the last deploy.
php artisan migrate --force || echo "[start] migrate failed - continuing so the site still comes up"

exec /usr/bin/supervisord -c /etc/supervisord.conf
