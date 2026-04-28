FROM php:8.5.5-cli

# Install MySQL PDO extension
RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /app

COPY src/ .

CMD ["php", "/app/controller.php"]