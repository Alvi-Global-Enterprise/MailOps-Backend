#!/bin/sh
set -e

# Agar vendor folder missing hai, composer install chalao
if [ ! -d "/var/www/vendor" ]; then
    echo "vendor/ not found, running composer install..."
    composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Laravel writable directories
# Ownership host par ubuntu ke paas rahegi.
# Sirf write permissions ensure karo.
chmod -R 777 /var/www/storage /var/www/bootstrap/cache

# Agar .env hai lekin APP_KEY empty hai, key generate karo
if [ -f "/var/www/.env" ] && ! grep -q "APP_KEY=base64" /var/www/.env; then
    echo "Generating APP_KEY..."
    php artisan key:generate --force
fi

echo "Waiting for database connection..."

until php artisan migrate:status > /dev/null 2>&1; do
    echo "Database not ready yet, retrying in 2s..."
    sleep 2
done

echo "Database is ready."

# Migrations automatically chalao
echo "Running migrations..."
php artisan migrate --force

# Config cache rebuild karo (staging/prod ke liye important)
if [ "$APP_ENV" != "local" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Container ka asal CMD chalao
exec "$@"