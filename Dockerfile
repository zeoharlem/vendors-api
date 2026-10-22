# Build against a PHP base image that has the exact compatible Phalcon 5 extension installed.
# Do not deploy this image until the extension build and dependency versions are pinned and scanned.
FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx postgresql-dev icu-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql opcache

WORKDIR /var/www/app
COPY --chown=www-data:www-data . /var/www/app

# Install Phalcon via your approved, reproducible build process before using this image.
# The C extension is not installed by the base image above.
EXPOSE 9000
CMD ["php-fpm", "-F"]
