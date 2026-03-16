ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-alpine

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        cmake \
        linux-headers \
        openssl-dev \
        zlib-dev \
        libpq-dev \
    && apk add --no-cache \
        git \
        unzip \
        libpq \
        libstdc++ \
        openssl \
        zlib \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        pdo_pgsql \
    && pecl install mongodb redis couchbase \
    && docker-php-ext-enable mongodb redis couchbase \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/cache/apk/* \
    && php -m | grep couchbase

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
