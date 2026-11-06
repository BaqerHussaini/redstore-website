FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n</Directory>\n' \
        > /etc/apache2/conf-available/redstore.conf \
    && a2enconf redstore \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

COPY docker/entrypoint.sh /usr/local/bin/redstore-entrypoint
RUN chmod +x /usr/local/bin/redstore-entrypoint

COPY docker/php.ini /usr/local/etc/php/conf.d/redstore-uploads.ini

WORKDIR /var/www/html
COPY . /var/www/html

ENTRYPOINT ["redstore-entrypoint"]
CMD ["apache2-foreground"]
