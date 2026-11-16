FROM php:8.1-apache

ENV PHP_MEMORY_LIMIT=256M

RUN a2enmod rewrite

RUN apt-get update && apt-get install -y --no-install-recommends \
    cron \
    libpq-dev \
    libzip-dev \
    libonig-dev \
    libcurl4-openssl-dev \
    unzip \
    && docker-php-ext-install \
    mbstring \
    pdo \
    pdo_pgsql \
    pgsql \
    curl \
    zip \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache/php.ini /usr/local/etc/php/conf.d/php-tirreno.ini
COPY docker/apache/tirreno-cron /etc/cron.d/tirreno-cron

RUN chmod 0644 /etc/cron.d/tirreno-cron \
    && crontab /etc/cron.d/tirreno-cron \
    && touch /var/log/cron.log

COPY . /var/www/html/

COPY docker/apache/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh

RUN touch /var/www/html/assets/logs/error.log \
    && touch /var/www/html/assets/logs/blacklist.log \
    && chown -R www-data:www-data /var/www/html \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

CMD ["sh", "-c", "cron && apache2-foreground"]
