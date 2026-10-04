# MotoGuard+ web app: one container running nginx, PHP, Reverb (live updates), the scheduler and
# the queue worker. Built for hosts that give a single web service (Render free tier).

# ---- PHP dependencies -------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs --no-interaction
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---- CSS / JS (needs vendor/: app.css imports Flux's stylesheet from it) ------------------------
FROM node:22-alpine AS assets
WORKDIR /app
COPY --from=vendor /app /app
RUN npm install --no-audit --no-fund && npm run build

# ---- Runtime -----------------------------------------------------------------------------------
FROM php:8.3-fpm-alpine
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache nginx supervisor \
    && install-php-extensions pdo_pgsql pgsql pcntl sockets zip intl bcmath opcache

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh docker/queue.sh && rm -rf public/hot node_modules \
    && chown -R www-data:www-data storage bootstrap/cache

ENV PORT=10000
EXPOSE 10000
CMD ["/start.sh"]
