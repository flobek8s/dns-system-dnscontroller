FROM php:8.5.5-cli

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y libcurl4-openssl-dev && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql curl

WORKDIR /app

COPY src/ .

CMD ["php", "/app/controller.php"]