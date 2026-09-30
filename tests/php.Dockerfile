FROM php:8.3-cli
RUN pecl install pcov >/dev/null 2>&1 && docker-php-ext-enable pcov && apt-get update -qq && apt-get install -y -qq git unzip >/dev/null 2>&1
