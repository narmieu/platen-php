# platen-chromium: platen's Chromium engine (server.mjs), a small service around
# Playwright. The image's Playwright version matches playwright-core in package.json,
# so its Chromium is the one playwright-core expects; it also brings the fonts.
FROM mcr.microsoft.com/playwright:v1.63.0-noble

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force
COPY server.mjs ./

USER pwuser
ENV NODE_ENV=production \
    PLATEN_CHROMIUM_LISTEN=0.0.0.0:3000
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s \
    CMD ["node", "-e", "fetch('http://127.0.0.1:3000/health').then((r) => process.exit(r.ok ? 0 : 1), () => process.exit(1))"]
CMD ["node", "server.mjs"]
