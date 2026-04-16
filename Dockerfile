FROM php:8.4-cli-alpine

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/tmp/composer

RUN apk add --no-cache $PHPIZE_DEPS sqlite-dev \
    && docker-php-ext-install pdo_sqlite \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

COPY . .

RUN mkdir -p storage/runtime

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]