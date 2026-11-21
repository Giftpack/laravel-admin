FROM composer:2@sha256:919efe8e62179c71dfd31fad035fd671fe7454211c6b2aa30a10af0ab4acedba AS composer
FROM php:8.5-cli-alpine@sha256:b9d7db51a2b83c41420ba04de0b90e6078d4b6440e5cc480fc4dc51b18e0b6db

RUN apk add --no-cache libpq libzip libpng libjpeg-turbo freetype libwebp \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_pgsql zip gd exif \
    && apk del .build-deps \
    && touch /run/laravel-admin-disposable-tests
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /application
CMD ["php", "-v"]
