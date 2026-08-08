# A named stage (not COPY --from=image) so dependabot's docker ecosystem, which
# only reads FROM lines, keeps this pin updated too.
FROM composer:2.10.2 AS composer

FROM php:8.5.9-fpm-bookworm

# git + unzip let `composer install` fall back to a git source checkout (and extract dist
# archives) when GitHub's dist zipballs are temporarily unavailable (504). The slim php-fpm
# base ships neither, so without them a flaky dist download aborts the entrypoint install and
# the container exits before php-fpm starts.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*

# Use install-php-extensions to install Laravel-required extensions. exif is load-bearing for
# thumbnails: intervention/image reads EXIF Orientation only when exif_read_data exists, and
# silently skips auto-rotation otherwise (phone photos would render sideways). curl is
# load-bearing for outbound safety: without it Guzzle falls back to the PHP stream handler,
# where SafeHttpFetcher's CURLOPT_* connection pinning silently does nothing. The base image
# happens to ship curl, but naming it here keeps that from being a base-image accident.
# imagick is the one driver that can color-manage: GD's ProfileModifier throws NotSupported, so
# a wide-gamut photo keeps its numbers and loses its profile, and is then read as sRGB.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl bcmath zip curl exif gd imagick pdo_mysql pdo_sqlite opcache

# composer
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Drop the default www pool; the compose file runs the whole container as the bind-mount
# uid, so the replacement pool sets no user/group (a non-root master cannot setuid anyway).
# The official php:*-fpm image inlines a [www] pool inside docker.conf, so it must be removed too.
RUN rm -f /usr/local/etc/php-fpm.d/www.conf \
          /usr/local/etc/php-fpm.d/www.conf.default \
          /usr/local/etc/php-fpm.d/docker.conf \
          /usr/local/etc/php-fpm.d/zz-docker.conf

WORKDIR /var/www/html

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.conf
COPY docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/app.conf
COPY docker/php-upload.ini /usr/local/etc/php/conf.d/openpne-upload.ini

COPY docker/entrypoint.sh /usr/local/bin/openpne-entrypoint
RUN chmod 0755 /usr/local/bin/openpne-entrypoint

EXPOSE 9000

ENTRYPOINT ["openpne-entrypoint"]
CMD ["php-fpm"]
