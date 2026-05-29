FROM php:8.3-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libcurl4-openssl-dev \
        libgmp-dev \
        libicu-dev \
        libonig-dev \
        libzip-dev \
    && docker-php-ext-install \
        bcmath \
        curl \
        gmp \
        intl \
        mbstring \
        pcntl \
        sockets \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .

ENV APP_SESSION_DIR=/tmp/telegrammovies-sessions
EXPOSE 10000

CMD ["sh", "-c", "mkdir -p \"$APP_SESSION_DIR\" && if [ \"$AUTO_SET_WEBHOOK\" = \"true\" ]; then php /app/scripts/set_webhook.php || true; fi && php -S 0.0.0.0:${PORT:-10000} -t /app /app/router.php"]
