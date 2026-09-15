FROM php:8.4-fpm-alpine

ARG UID=1000
ARG GID=1000

WORKDIR /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# MacOS staff group's gid is 20, so is the dialout group in alpine linux.
RUN delgroup dialout \
    && addgroup -g ${GID} --system laravel \
    && adduser -G laravel --system -D -s /bin/sh -u ${UID} laravel

# posix is enabled in the base image; Horizon also needs pcntl.
# Build dependencies are removed in the same layer; only runtime libraries stay.
RUN apk add --no-cache git unzip libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl opcache \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

COPY php/app.ini /usr/local/etc/php/conf.d/zz-app.ini

USER laravel

# Packagist lookups fail on the default build network (it queries the LAN router directly),
# so only this step uses the host network and its resolver.
RUN --network=host composer global require laravel/installer

ENV PATH="/home/laravel/.composer/vendor/bin:$PATH"

CMD ["php-fpm"]
