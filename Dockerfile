FROM php:8.3-fpm-alpine

# Set working directory
WORKDIR /var/www/html

# Install system dependencies and build tools for PHP extensions
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    zip \
    icu-dev \
    libzip-dev \
    oniguruma-dev \
    linux-headers \
    mysql-client \
    netcat-openbsd \
    $PHPIZE_DEPS

# Install PHP extensions required by Laravel 12, Redis, RabbitMQ, and OPcache
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    bcmath \
    intl \
    pcntl \
    sockets \
    zip \
    opcache

# Install Redis PHP extension via PECL
RUN pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Copy custom OPcache and PHP configuration
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom-php.ini

# Install Composer from official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_PROCESS_TIMEOUT=2000

# Install Composer dependencies on native Linux layer for fast build & runtime
COPY composer.json composer.lock* /var/www/html/
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

# Copy entrypoint script and ensure Unix LF line endings
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh

# Copy application files
COPY . /var/www/html

# Generate optimized autoloader
RUN composer dump-autoload --optimize --no-scripts

# Ensure storage and cache directories exist with proper permissions
RUN mkdir -p /var/www/html/storage /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
