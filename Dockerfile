#
# JBZoo Toolbox - Csv-Blueprint.
#
# This file is part of the JBZoo Toolbox project.
# For the full copyright and license information, please view the LICENSE
# file that was distributed with this source code.
#
# @license    MIT
# @copyright  Copyright (C) JBZoo.com, All rights reserved.
# @see        https://github.com/JBZoo/Csv-Blueprint
#

# Every input is pinned: base images by digest, the extension installer by release and checksum, Composer by image
# digest. Composer, git and the build context stay in the build stages; the final image gets the app and vendor only.

ARG ALPINE_IMAGE=alpine:3.24@sha256:f59abe656b692d901e184be3445e084786602108599d2a51d229c0f5a48c71bf
# ZTS is required for ext-parallel (multi-threaded validation).
ARG PHP_IMAGE=php:8.5-zts-alpine@sha256:be4b957d7592ae323d1a42a37beb1ef54418d8268b7e61b1d32be47126707e2e
ARG COMPOSER_IMAGE=composer:2.11.1@sha256:8de2184c6de224f4689e8f80ed5f5edaf0030b6b8b7839a22a2bcb6722ac87d0

FROM ${COMPOSER_IMAGE} AS composer

########################################################################################
# Version stamp. This stage sees .git; nothing from it reaches the final image except .version.
FROM ${ALPINE_IMAGE} AS preparatory
RUN apk add --no-cache make git
WORKDIR /tmp
COPY . /tmp
RUN make build-version

########################################################################################
# Production dependencies, installed by Composer from composer.lock.
FROM ${PHP_IMAGE} AS vendor
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
COPY src ./src
# ext-parallel and opcache are installed in the runtime stage; nothing in composer.json requires them.
RUN composer install --no-dev --classmap-authoritative --no-progress --no-interaction --no-scripts \
    && composer check-platform-reqs --no-dev

########################################################################################
FROM ${PHP_IMAGE}

# ext-parallel is pinned to 1.2.15 (verified stable with opcache on 8.5 ZTS); bump it deliberately.
ADD --chmod=0755 \
    --checksum=sha256:27fa8d80bd4e22feb9ddcadff1ca40c8f3e8ea942eb2dd8f446ed1afd2f2f0ab \
    https://github.com/mlocati/docker-php-extension-installer/releases/download/2.13.2/install-php-extensions \
    /usr/local/bin/
RUN install-php-extensions opcache parallel-1.2.15 \
    && rm /usr/local/bin/install-php-extensions \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/docker-z99-php.ini

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY --from=preparatory /tmp/.version ./.version
COPY composer.json csv-blueprint csv-blueprint.php LICENSE ./
COPY src ./src
COPY schema-examples ./schema-examples
COPY --chmod=0755 docker/entrypoint.sh ./docker/entrypoint.sh
RUN chmod 0755 ./csv-blueprint \
    && chmod -R a+rX /app

# The validator only reads its inputs. GitHub-hosted runners own the workspace as uid 1001, and checked-out files
# are world-readable, so no root is needed.
USER 1001:1001

# Quick test
RUN ./csv-blueprint -V

ENTRYPOINT ["/app/docker/entrypoint.sh"]
