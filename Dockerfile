FROM php:8.5-fpm-bookworm

# Use install-php-extensions to install Laravel-required extensions.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl bcmath zip gd pdo_mysql pdo_sqlite

# composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Drop the default www pool; entrypoint runs FPM as root master + www-data worker on TCP 9000.
# The official php:*-fpm image inlines a [www] pool inside docker.conf, so it must be removed too.
RUN rm -f /usr/local/etc/php-fpm.d/www.conf \
          /usr/local/etc/php-fpm.d/www.conf.default \
          /usr/local/etc/php-fpm.d/docker.conf \
          /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/html

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/app.conf

COPY docker/entrypoint.sh /usr/local/bin/openpne-entrypoint
RUN chmod 0755 /usr/local/bin/openpne-entrypoint

EXPOSE 9000

ENTRYPOINT ["openpne-entrypoint"]
CMD ["php-fpm"]
