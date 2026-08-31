# Two stages off one base, so the CLI container and the workers container always carry
# the same PHP, the same extensions and the same sconcur.so. The base has no composer
# and no supervisor: those belong to exactly one stage each.
FROM php:8.4.15-cli AS php_base

WORKDIR /app

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      curl \
      git \
      jq \
      libcurl4-openssl-dev \
      librabbitmq-dev \
      libssl-dev \
      libzip-dev \
      pkg-config \
      unzip \
      wget \
      zip && \
    rm -rf /var/lib/apt/lists/*

# PIE (PHP Installer for Extensions) — the replacement for PECL.
RUN curl -fSL --connect-timeout 10 --retry 3 \
        https://github.com/php/pie/releases/latest/download/pie.phar \
        -o /usr/local/bin/pie && \
    chmod +x /usr/local/bin/pie

# msgpack carries every payload across the PHP↔Go boundary and is what the library
# itself requires (ext-msgpack: 3.0.1). Pinned exactly, like that constraint.
RUN pie install msgpack/msgpack-php:3.0.1

# pcntl — signal handling of the master and of every long-lived worker; pdo_mysql — the
# PDO connection sconcur_mysql is compared against; sockets — required by the library's
# transport dependencies.
RUN docker-php-ext-install \
        bcmath \
        pcntl \
        pdo \
        pdo_mysql \
        sockets \
        zip

# The sconcur Go extension, pinned to the sconcur/sconcur version composer.lock
# resolved. The two cross a protocol boundary that changes with the version, so the .so
# and the PHP side must never be picked independently — hence reading the lock rather
# than an ARG somebody can forget to bump.
COPY composer.lock ./

RUN set -eux; \
    version="$(jq -r '.packages[] | select(.name=="sconcur/sconcur") | .version' composer.lock | sed 's/^v//')"; \
    test -n "$version"; \
    curl -fSL -4 \
        --connect-timeout 10 --max-time 300 \
        --speed-limit 10240 --speed-time 30 \
        --retry 5 --retry-delay 3 --retry-all-errors \
        "https://github.com/sprust/sconcur/releases/download/v${version}/sconcur.so" \
        -o "$(php-config --extension-dir)/sconcur.so"; \
    echo "extension=sconcur.so" > /usr/local/etc/php/conf.d/docker-php-ext-sconcur.ini; \
    php -m | grep -qx sconcur

# CLI container: composer, artisan, phpunit, the analyzers. No php-fpm anywhere in this
# repository — HTTP is served by the SConcur master in the workers container.
FROM php_base AS php_cli

RUN curl -sS https://getcomposer.org/installer \
    | php -- --install-dir=/usr/local/bin --filename=composer

# The repository is bind-mounted and owned by the host user, which git refuses to touch.
RUN git config --global --add safe.directory /app

ARG USER_ID
ARG GROUP_ID

# Check if user and group doesn't exist before creating
RUN getent group "$GROUP_ID" || addgroup --gid "$GROUP_ID" user
RUN getent passwd "$USER_ID" || adduser --disabled-password --gecos '' --uid "$USER_ID" --gid "$GROUP_ID" user

USER "$USER_ID"

# Workers container: supervisor keeps the SConcur master up, the master keeps its own
# worker pools up.
FROM php_base AS php_workers

RUN apt-get update && \
    apt-get install -y --no-install-recommends supervisor && \
    rm -rf /var/lib/apt/lists/* && \
    chmod 777 -R /var/log/supervisor

ARG USER_ID
ARG GROUP_ID

# Check if user and group doesn't exist before creating
RUN getent group "$GROUP_ID" || addgroup --gid "$GROUP_ID" user
RUN getent passwd "$USER_ID" || adduser --disabled-password --gecos '' --uid "$USER_ID" --gid "$GROUP_ID" user

USER "$USER_ID"
