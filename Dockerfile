FROM php:8.2-cli-alpine

# Install php extensions and php composer
RUN apk add --no-cache --update --virtual .build-deps \
    postgresql-dev pcre-dev libpng-dev libwebp-dev jpeg-dev libxpm-dev freetype-dev ${PHPIZE_DEPS} \
    && apk add --no-cache libzip-dev postgresql-libs libpng libwebp jpeg libxpm freetype \
    && docker-php-ext-configure gd \
    --enable-gd \
    --with-freetype \
    --with-jpeg \
    --with-xpm \
    --with-webp \
    && docker-php-ext-install -j$(nproc) pdo_mysql pdo_pgsql zip gd \
    && apk del -f .build-deps \
    && EXPECTED_SIGNATURE="$(wget -q -O - https://composer.github.io/installer.sig)" \
    && php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');" \
    && php -r "if (hash_file('sha384', 'composer-setup.php') === '$EXPECTED_SIGNATURE') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;" \
    && php composer-setup.php --install-dir=/usr/bin --filename=composer \
    && php -r "unlink('composer-setup.php');"


ENTRYPOINT ["tail", "-f", "/dev/null"]
