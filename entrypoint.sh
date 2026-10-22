#!/usr/bin/env bash
set -e

ROLE=${CONTAINER_ROLE:-app}

echo "Starting container with role: ${ROLE}"

# Install composer dependencies only if vendor/autoload.php is missing
if [ ! -f "/var/www/html/vendor/autoload.php" ]; then
    echo "Installing Composer dependencies..."
    COMPOSER_PROCESS_TIMEOUT=2000 composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Create .env from .env.example if missing
if [ ! -f "/var/www/html/.env" ] && [ -f "/var/www/html/.env.example" ]; then
    echo "Creating .env file from .env.example..."
    cp /var/www/html/.env.example /var/www/html/.env
fi

# Wait for MySQL to be ready if DB_HOST is set
if [ -n "${DB_HOST}" ]; then
    echo "Waiting for MySQL (${DB_HOST}:${DB_PORT:-3306})..."
    until nc -z "${DB_HOST}" "${DB_PORT:-3306}"; do
        sleep 1
    done
    echo "MySQL is up!"
fi

# Wait for Redis to be ready if REDIS_HOST is set
if [ -n "${REDIS_HOST}" ]; then
    echo "Waiting for Redis (${REDIS_HOST}:${REDIS_PORT:-6379})..."
    until nc -z "${REDIS_HOST}" "${REDIS_PORT:-6379}"; do
        sleep 1
    done
    echo "Redis is up!"
fi

# Wait for RabbitMQ to be ready if RABBITMQ_HOST is set
if [ -n "${RABBITMQ_HOST}" ]; then
    echo "Waiting for RabbitMQ (${RABBITMQ_HOST}:${RABBITMQ_PORT:-5672})..."
    until nc -z "${RABBITMQ_HOST}" "${RABBITMQ_PORT:-5672}"; do
        sleep 1
    done
    echo "RabbitMQ is up!"
fi

# Ensure storage and cache permissions
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true

if [ "${ROLE}" = "app" ]; then
    # Generate app key if not set
    if ! grep -q "^APP_KEY=base64:" /var/www/html/.env 2>/dev/null; then
        php artisan key:generate --force --no-interaction || true
    fi

    # Generate JWT secret if not set
    if ! grep -q "^JWT_SECRET=.\+" /var/www/html/.env 2>/dev/null; then
        php artisan jwt:secret --force --no-interaction || true
    fi

    echo "Running database migrations..."
    php artisan migrate --force --no-interaction

    echo "Starting PHP-FPM..."
    exec "$@"
elif [ "${ROLE}" = "queue" ]; then
    echo "Declaring RabbitMQ queues (default, payment_webhooks, payments)..."
    php artisan rabbitmq:queue-declare default || true
    php artisan rabbitmq:queue-declare payment_webhooks || true
    php artisan rabbitmq:queue-declare payments || true

    echo "Starting RabbitMQ Queue Worker..."
    exec php artisan queue:work rabbitmq --queue=default,payment_webhooks,payments --sleep=3 --tries=3 --max-time=3600
else
    exec "$@"
fi
