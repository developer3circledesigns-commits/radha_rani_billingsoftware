FROM php:8.2-fpm

# pdo_mysql + fileinfo are the only extensions the portal uses.
# (fileinfo powers finfo() MIME validation for uploaded PDFs.)
RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client \
    && docker-php-ext-install -j$(nproc) pdo_mysql fileinfo \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

EXPOSE 9000

CMD ["php-fpm"]