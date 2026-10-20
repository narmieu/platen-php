# syntax=docker/dockerfile:1

# platen with WeasyPrint. The Chromium engine runs in a Gotenberg container next to it;
# see compose.yaml.

ARG FRANKENPHP_IMAGE=dunglas/frankenphp:1.12-php8.4-trixie

FROM ${FRANKENPHP_IMAGE} AS base

# The version platen-weasyprint supports (docker/weasyprint/pyproject.toml pins it).
ARG WEASYPRINT_VERSION=70.0

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        tini \
        qpdf \
        python3 \
        python3-venv \
        libpango-1.0-0 \
        libpangoft2-1.0-0 \
        libharfbuzz-subset0 \
        fonts-dejavu-core \
        fonts-liberation \
        fonts-crosextra-carlito \
        fonts-crosextra-caladea \
        fonts-noto-core \
    ; \
    rm -rf /var/lib/apt/lists/*; \
    install-php-extensions pcntl; \
    python3 -m venv /opt/weasyprint; \
    /opt/weasyprint/bin/pip install --no-cache-dir "weasyprint==${WEASYPRINT_VERSION}"; \
    ln -s /opt/weasyprint/bin/weasyprint /usr/local/bin/weasyprint; \
    weasyprint --version; \
    qpdf --version

# weasyprint-platen: WeasyPrint with platen's quality fixes, plus the Prince compatibility
# fixes when given --platen-prince.
COPY docker/weasyprint /opt/platen-weasyprint
RUN set -eux; \
    /opt/weasyprint/bin/pip install --no-cache-dir /opt/platen-weasyprint; \
    ln -s /opt/weasyprint/bin/weasyprint-platen /usr/local/bin/weasyprint-platen; \
    weasyprint-platen --version

WORKDIR /app


FROM base AS build

# Composer needs unzip to unpack the packages it downloads.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends unzip; \
    rm -rf /var/lib/apt/lists/*
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    COMPOSER_ALLOW_SUPERUSER=1

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

COPY . .
RUN set -eux; \
    composer dump-autoload --no-dev --classmap-authoritative; \
    php bin/console cache:warmup; \
    rm -rf var/data var/log/*


FROM base AS app

ARG VERSION=dev
LABEL org.opencontainers.image.title="platen" \
      org.opencontainers.image.description="Self-hosted HTML to PDF server with a DocRaptor-compatible API" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.version="${VERSION}"

# Gotenberg's Chromium reaches the egress proxy from its own container, hence 0.0.0.0.
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    PLATEN_DATA_DIR=/var/lib/platen \
    PLATEN_EGRESS_LISTEN=0.0.0.0:3128 \
    PLATEN_MAX_REQUEST_MB=100 \
    PLATEN_PHP_MEMORY_LIMIT=512M

COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-platen.ini
COPY --from=build /app /app

RUN set -eux; \
    useradd --uid 10001 --user-group --home-dir /var/lib/platen --shell /usr/sbin/nologin platen; \
    mkdir -p /var/lib/platen; \
    chown -R platen:platen /var/lib/platen /app/var /config/caddy /data/caddy

USER platen
VOLUME /var/lib/platen
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s \
    CMD curl -fsS "http://127.0.0.1:${PLATEN_PORT:-8080}/healthz" || exit 1

ENTRYPOINT ["tini", "--"]
CMD ["php", "bin/console", "platen:serve"]
