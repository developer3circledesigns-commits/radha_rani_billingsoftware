FROM php:8.2-fpm

# pdo_mysql is the only extension the portal needs installed. fileinfo
# (finfo() MIME validation for uploaded PDFs) is compiled into the base
# image already, so installing it again only produces an "already loaded"
# warning and a redundant build.
RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

EXPOSE 9000

CMD ["php-fpm"]