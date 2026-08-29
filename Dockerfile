# Test runner for the package. Not a production image — this exists so the suite
# runs identically on any machine without a local PHP install, against any of
# the three supported database engines.
FROM php:8.3-cli-alpine

RUN apk add --no-cache git unzip libzip-dev sqlite sqlite-dev postgresql-dev \
    && docker-php-ext-install zip pdo pdo_sqlite pdo_pgsql pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1

CMD ["sh", "-c", "composer install --no-interaction --prefer-dist && vendor/bin/pest"]
