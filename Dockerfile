FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        postgresql-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
        icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        pgsql \
        gd \
        zip \
        intl \
        opcache \
        exif \
        bcmath

COPY wordpress-develop/tools/local-env/php-config.ini /usr/local/etc/php/conf.d/wordpress.ini

WORKDIR /var/www

EXPOSE 9000

CMD ["php-fpm"]
