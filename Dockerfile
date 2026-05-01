FROM fullstorydev/grpcurl:latest AS grpcurl

FROM composer:2 AS composer-bin

FROM php:8.5.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev ca-certificates git unzip \
    && docker-php-ext-install pdo pdo_mysql curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --from=grpcurl /bin/grpcurl /usr/local/bin/grpcurl

WORKDIR /app

COPY src/ .

RUN composer require --no-interaction --no-progress art-of-wifi/unifi-api-client

CMD ["php", "/app/controller.php"]