FROM php:8.3-cli
RUN pecl install pcov >/dev/null 2>&1 && docker-php-ext-enable pcov && apt-get update -qq && apt-get install -y -qq git unzip libicu-dev >/dev/null 2>&1 && docker-php-ext-install intl >/dev/null 2>&1
