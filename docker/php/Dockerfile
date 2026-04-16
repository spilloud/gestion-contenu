FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    curl \
    pkg-config \
    libcurl4-openssl-dev \
    libpq-dev \
    libicu-dev \
    git \
    unzip

RUN docker-php-ext-install pdo pdo_pgsql curl iconv ctype \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin --filename=composer

RUN usermod -u 1000 www-data

USER www-data

COPY ./ /var/www
WORKDIR /var/www

CMD php-fpm

EXPOSE 9000
