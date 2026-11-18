FROM php:8.3-cli-alpine

ARG USER_ID=1000
ARG GROUP_ID=1000

RUN apk add --no-cache git unzip shadow \
    && groupmod -o -g ${GROUP_ID} www-data \
    && usermod -o -u ${USER_ID} -g www-data www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
RUN chown -R www-data:www-data /app

USER www-data
