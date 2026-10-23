FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libxml2-dev \
    && docker-php-ext-install pdo_pgsql pgsql pcntl zip soap \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN useradd --create-home --uid 1000 app
WORKDIR /var/www/html
USER app

EXPOSE 8000
