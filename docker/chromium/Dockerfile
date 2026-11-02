# platen-chromium: platen's Chromium engine (server.mjs), a small service around
# Playwright. The image's Playwright version matches playwright-core in package.json,
# so its Chromium is the one playwright-core expects.
#
# It is built from the repository root, like platen's image, so that the two share
# docker/fontconfig:
#
#   docker build -f docker/chromium/Dockerfile -t platen-chromium .
FROM mcr.microsoft.com/playwright:v1.63.0-noble

# platen's fonts and font rule, so that Chromium picks the font WeasyPrint picks for a
# family name. Playwright's image prefers its Chinese font for the generic families
# (monospace text came out in WenQuanYi Zen Hei Mono); the rule puts Liberation first.
# Playwright's own fonts stay, for the scripts only they cover.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        fonts-dejavu-core \
        fonts-liberation \
        fonts-crosextra-carlito \
        fonts-crosextra-caladea \
        fonts-noto-core \
    ; \
    rm -rf /var/lib/apt/lists/*
COPY docker/fontconfig/45-platen.conf /etc/fonts/conf.d/45-platen.conf
RUN fc-cache -f

WORKDIR /app
COPY docker/chromium/package.json docker/chromium/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force
COPY docker/chromium/server.mjs ./

USER pwuser
ENV NODE_ENV=production \
    PLATEN_CHROMIUM_LISTEN=0.0.0.0:3000
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s \
    CMD ["node", "-e", "fetch('http://127.0.0.1:3000/health').then((r) => process.exit(r.ok ? 0 : 1), () => process.exit(1))"]
CMD ["node", "server.mjs"]
