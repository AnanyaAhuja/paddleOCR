# ============================================================================
# Global build arguments
# ============================================================================
ARG NODEJS_VERSION=24
ARG COMPOSER_VERSION=2.10.2
ARG PHP_VERSION=8.4

# ============================================================================
# Base Stage - Runtime dependencies only
# ============================================================================
FROM ubuntu:24.04 AS base

# Re-declare ARGs to use them in this stage
ARG PHP_VERSION

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC

# Kept in the environment so the entrypoint and any `docker exec` can find the
# versioned PHP paths (/etc/php/${PHP_VERSION}/...) without hardcoding them.
ENV PHP_VERSION=${PHP_VERSION}

WORKDIR /app

# Install packages.
#
# PHP comes from ppa:ondrej/php, which builds every architecture of an upload
# together and ships php${PHP_VERSION}-swoole as a package, so Octane's server
# needs no pecl build toolchain in the runtime image.
RUN apt update \
    && apt install -y --no-install-recommends \
    ca-certificates \
    curl \
    wget \
    gnupg2 \
    && . /etc/os-release \
    && curl -fsSL "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xB8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6" | gpg --dearmor > /usr/share/keyrings/ondrej-php.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${VERSION_CODENAME} main" > /etc/apt/sources.list.d/ondrej-php.list \
    && curl -fsSL https://nginx.org/keys/nginx_signing.key | gpg --dearmor > /usr/share/keyrings/nginx-archive-keyring.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/nginx-archive-keyring.gpg] https://nginx.org/packages/mainline/ubuntu ${VERSION_CODENAME} nginx" > /etc/apt/sources.list.d/nginx.list \
    && printf "Package: *\nPin: origin nginx.org\nPin: release o=nginx\nPin-Priority: 900\n" > /etc/apt/preferences.d/99nginx \
    && apt update \
    && apt install -y --no-install-recommends \
    vim \
    unzip \
    zip \
    git \
    gettext-base \
    cron \
    # Ubuntu >= 24.04 splits the backward-compatibility zone names into their own
    # package, and PHP here uses the system tzdata. Without it, names browsers
    # still send (Asia/Calcutta, Europe/Kiev, US/Eastern) do not exist at all.
    tzdata-legacy \
    # Web server and process management
    nginx \
    supervisor \
    # PHP and extensions. exif/pcntl/sockets/posix ship inside -cli on Debian/Ubuntu.
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-opcache \
    php${PHP_VERSION}-readline \
    php${PHP_VERSION}-redis \
    php${PHP_VERSION}-swoole \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-zip \
    && apt clean \
    && rm -rf /var/lib/apt/lists/*

# Configure PHP
COPY docker/php/php-cli.ini /etc/php/${PHP_VERSION}/cli/conf.d/99-app.ini

# Configure Nginx
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/nginx/templates/ /etc/nginx/templates/

# The official nginx.org package omits the sites-available/sites-enabled layout
# Debian's package provides; the entrypoint renders templates into it.
RUN mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled

# Configure Supervisor (one config per container role)
COPY docker/supervisor/api.conf /etc/supervisor/conf.d/api.conf
COPY docker/supervisor/worker.conf /etc/supervisor/conf.d/worker.conf
COPY docker/supervisor/scheduler.conf /etc/supervisor/conf.d/scheduler.conf

# Copy cron configuration
COPY docker/cron/scheduler /etc/cron.d/scheduler

# Create application directories with proper permissions
RUN mkdir -p /app/storage /app/bootstrap/cache \
    && chown -R www-data:www-data /app \
    && chmod -R ug+rwx /app/storage /app/bootstrap/cache

# Open ports
EXPOSE 80

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
ENTRYPOINT ["docker-entrypoint.sh"]


# ============================================================================
# Local Stage - Build tools and dev dependencies (source is bind-mounted)
# ============================================================================
FROM base AS local

# Re-declare ARGs to use them in this stage
ARG PHP_VERSION
ARG NODEJS_VERSION
ARG COMPOSER_VERSION

# Set build target environment variable
ENV BUILD_TARGET=local

RUN apt update && apt install -y --no-install-recommends \
    php${PHP_VERSION}-xdebug \
    && apt clean \
    && rm -rf /var/lib/apt/lists/*

# Install Node.js LTS (the app builds its Vite assets in-container)
RUN curl -fsSL https://deb.nodesource.com/setup_${NODEJS_VERSION}.x | bash - \
    && apt install -y nodejs \
    && apt clean \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --version=${COMPOSER_VERSION} --install-dir=/usr/local/bin --filename=composer \
    && chmod +x /usr/local/bin/composer

# Configure Xdebug. Loaded with mode=off (docker/php/xdebug.ini), so it costs
# nothing until a shell opts in with XDEBUG_MODE=debug.
COPY docker/php/xdebug.ini /etc/php/${PHP_VERSION}/mods-available/xdebug.ini


# ============================================================================
# Builder Stage - Build production artifacts
# ============================================================================
FROM base AS builder

# Re-declare ARGs for this stage
ARG NODEJS_VERSION
ARG COMPOSER_VERSION

# Install Composer and Node (Node is only needed to build the Vite assets; it is
# not carried into the release image)
RUN curl -sS https://getcomposer.org/installer | php -- \
    --version=${COMPOSER_VERSION} \
    --install-dir=/usr/local/bin \
    --filename=composer \
    && chmod +x /usr/local/bin/composer \
    && curl -fsSL https://deb.nodesource.com/setup_${NODEJS_VERSION}.x | bash - \
    && apt install -y nodejs \
    && apt clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy application source
COPY --chown=www-data:www-data . /app

# The writable skeleton Laravel expects. .dockerignore drops storage/ from the
# context, so the image creates it itself.
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/testing storage/framework/views \
    storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Install production dependencies (NO dev packages)
RUN composer install \
    --no-dev \
    --optimize-autoloader \
    --no-interaction \
    --prefer-dist

# Build front-end assets
RUN npm ci && npm run build

# Laravel's config/route/view/event caches are built by the entrypoint at
# container start, not here: they need the environment the container runs with.

# Remove unnecessary files before copying to release stage
RUN rm -rf /app/.git \
    && rm -rf /app/node_modules \
    && rm -rf /app/tests


# ============================================================================
# Release Stage - Minimal production image
# ============================================================================
FROM base AS release

ENV BUILD_TARGET=release

# Copy built application from builder (optimized vendor + compiled assets)
COPY --from=builder --chown=www-data:www-data /app /app

# All runtime configs inherited from base:
# - Nginx fronting Octane, logging to stdout/stderr
# - Supervisor for api/worker/scheduler roles
# - Cron for the Laravel scheduler
# - Entrypoint with role-based startup
