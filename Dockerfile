# Obraz CRM do instalacji lokalnej (docker-compose.yml, APP_MODE=local).
# Kod, .env i storage/ są podmontowane z katalogu instalacji, więc obraz zawiera tylko PHP.
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip gd intl \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite headers

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/crm.ini
COPY docker/entrypoint.sh /usr/local/bin/crm-entrypoint
RUN chmod +x /usr/local/bin/crm-entrypoint

WORKDIR /var/www/crm
ENTRYPOINT ["crm-entrypoint"]
CMD ["apache2-foreground"]
