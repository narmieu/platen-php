// platen-chromium: platen's Chromium engine, a small HTTP service around Playwright.
//
// One browser stays running; each document gets a fresh browser context (its own
// cookies, cache and storage), which is closed when the PDF is written. A crashed
// browser is started again for the next document.
//
//   POST /render  JSON (below)  ->  200 application/pdf, or an error as JSON {"error": "..."}
//                                    X-Resource-Errors: base64 of a JSON list of the requests
//                                    that failed or got an HTTP error status
//   GET  /health                ->  200 {"status": "up"}
//
// The request:
//
//   html                  the page, served at PAGE_URL (relative URLs need a <base>)
//   ready                 the name of a global function of the page; the page is printed
//                         once it returns true, or once loaded if it has no such function
//   timeout               seconds for the whole document
//   media                 "print" (the default) or "screen"
//   userAgent             the User-Agent of every request
//   headers               extra headers for every request, such as Referer
//   authorization         {"value": "Basic ...", "origin": "https://host:443" or null}:
//                         the Authorization header, for requests to that origin only
//   paper                 {"width": "8.5in", "height": "11in"}, for pages without @page size
//
// Every request leaves through PLATEN_CHROMIUM_PROXY, platen's egress proxy, which
// enforces the network policy; loopback is not exempt.

import http from 'node:http';
import { chromium } from 'playwright-core';

// A name that never resolves: the page itself is answered from memory.
export const PAGE_URL = 'http://platen.invalid/';

const MAX_BODY_BYTES = 256 * 1024 * 1024;

export function launchOptions(env = process.env) {
  const args = [
    // Without this, WebRTC could send UDP around the proxy.
    '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
    // The page's made-up origin counts as public, so Chromium's own private and local
    // network checks would refuse intranet assets that platen's network policy allows.
    '--disable-features=BlockInsecurePrivateNetworkRequests,PrivateNetworkAccessSendPreflights,LocalNetworkAccessChecks',
  ];
  if (env.PLATEN_CHROMIUM_PROXY) {
    // Chromium sends loopback addresses around a proxy unless told not to.
    args.push(`--proxy-server=${env.PLATEN_CHROMIUM_PROXY}`, '--proxy-bypass-list=<-loopback>');
  }

  return { args, executablePath: env.PLATEN_CHROMIUM_EXECUTABLE || undefined };
}

/** "scheme://host:port", as platen writes an origin, or null for a URL without one. */
export function origin(url) {
  try {
    const parsed = new URL(url);
    if (!['http:', 'https:'].includes(parsed.protocol)) return null;
    const port = parsed.port || (parsed.protocol === 'https:' ? '443' : '80');
    return `${parsed.protocol}//${parsed.hostname}:${port}`;
  } catch {
    return null;
  }
}

export class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

/** Runs at most `limit` tasks at once; the rest wait their turn. */
export function limiter(limit) {
  let running = 0;
  const waiting = [];
  const next = () => {
    if (running >= limit || waiting.length === 0) return;
    running += 1;
    const { task, resolve, reject } = waiting.shift();
    task().then(resolve, reject).finally(() => {
      running -= 1;
      next();
    });
  };

  return (task) => new Promise((resolve, reject) => {
    waiting.push({ task, resolve, reject });
    next();
  });
}

export class Renderer {
  constructor(options = launchOptions()) {
    this.options = options;
    this.browser = null;
  }

  async browserInstance() {
    if (this.browser === null) {
      this.browser = chromium.launch(this.options).then((browser) => {
        browser.on('disconnected', () => { this.browser = null; });
        return browser;
      }, (error) => {
        this.browser = null;
        throw error;
      });
    }

    return this.browser;
  }

  async close() {
    if (this.browser !== null) await (await this.browser).close();
  }

  /**
   * @param {object} request the JSON request
   * @param {AbortSignal} signal aborted when the caller goes away
   * @returns {Promise<{pdf: Buffer, failed: string[]}>} the PDF and the failed requests
   */
  async render(request, signal) {
    const deadline = Date.now() + Math.max(1, Number(request.timeout) || 60) * 1000;
    const remaining = () => Math.max(1, deadline - Date.now());
    const browser = await this.browserInstance();
    const context = await browser.newContext({
      userAgent: request.userAgent || undefined,
      extraHTTPHeaders: request.headers || {},
      serviceWorkers: 'block',
      acceptDownloads: false,
    });
    const closed = context.close.bind(context);
    signal.addEventListener('abort', closed);
    try {
      const page = await context.newPage();
      const failed = [];
      await page.route(PAGE_URL, (route) => route.fulfill({
        status: 200,
        contentType: 'text/html; charset=utf-8',
        body: request.html,
      }));
      const authorization = request.authorization;
      if (authorization && authorization.value) {
        await context.route('**/*', (route) => {
          const url = route.request().url();
          const scoped = authorization.origin === null || origin(url) === origin(authorization.origin);
          if (!scoped || origin(url) === null) return route.fallback();
          return route.fallback({ headers: { ...route.request().headers(), authorization: authorization.value } });
        });
      }
      const fail = (entry) => {
        if (failed.length < 20 && !failed.includes(entry)) failed.push(entry);
      };
      page.on('response', (response) => {
        if (response.status() >= 400) fail(`${response.url()} (HTTP ${response.status()})`);
      });
      page.on('requestfailed', (failure) => {
        const reason = failure.failure()?.errorText ?? 'failed';
        // Requests the page itself cancelled, or that its content security policy
        // blocked on purpose (JavaScript off, no_network), are not missing resources.
        if (!/ERR_ABORTED|ERR_BLOCKED_BY/.test(reason)) fail(`${failure.url()} (${reason})`);
      });
      await page.emulateMedia({ media: request.media === 'screen' ? 'screen' : 'print' });

      try {
        await page.goto(PAGE_URL, { waitUntil: 'commit', timeout: remaining() });
        // A function rather than an expression: the page's content security policy may
        // forbid evaluating strings.
        await page.waitForFunction(
          (name) => (typeof window[name] === 'function' ? window[name]() === true : document.readyState === 'complete'),
          request.ready || null,
          { polling: 100, timeout: remaining() },
        );
      } catch (error) {
        if (error.name === 'TimeoutError') throw new HttpError(504, 'Rendering timed out');
        throw error;
      }
      const pdf = await page.pdf({
        width: request.paper?.width ?? '8.5in',
        height: request.paper?.height ?? '11in',
        margin: { top: '0', right: '0', bottom: '0', left: '0' },
        preferCSSPageSize: true,
        printBackground: true,
        outline: true,
        tagged: true,
      });

      return { pdf, failed };
    } finally {
      signal.removeEventListener('abort', closed);
      await context.close().catch(() => {});
    }
  }
}

async function readJson(request) {
  const chunks = [];
  let size = 0;
  for await (const chunk of request) {
    size += chunk.length;
    if (size > MAX_BODY_BYTES) throw new HttpError(413, 'The request is too large');
    chunks.push(chunk);
  }
  let body;
  try {
    body = JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new HttpError(400, 'The request is not JSON');
  }
  if (typeof body !== 'object' || body === null || typeof body.html !== 'string') {
    throw new HttpError(400, 'html is missing');
  }

  return body;
}

export function createServer(renderer, concurrency) {
  const limit = limiter(concurrency);

  return http.createServer(async (request, response) => {
    const answer = (status, body, headers = {}) => {
      if (response.headersSent || response.destroyed) return;
      const json = !Buffer.isBuffer(body);
      response.writeHead(status, { 'Content-Type': json ? 'application/json' : 'application/pdf', ...headers });
      response.end(json ? JSON.stringify(body) : body);
    };
    try {
      if (request.method === 'GET' && request.url === '/health') return answer(200, { status: 'up' });
      if (request.method !== 'POST' || request.url !== '/render') return answer(404, { error: 'Not found' });

      const body = await readJson(request);
      const abort = new AbortController();
      response.on('close', () => { if (!response.writableFinished) abort.abort(); });
      const { pdf, failed } = await limit(() => (abort.signal.aborted ? Promise.reject(new HttpError(499, 'Gone')) : renderer.render(body, abort.signal)));
      answer(200, pdf, { 'X-Resource-Errors': Buffer.from(JSON.stringify(failed)).toString('base64') });
    } catch (error) {
      if (error instanceof HttpError) return answer(error.status, { error: error.message });
      console.error(error);
      answer(500, { error: String(error.message ?? error).split('\n')[0] });
    }
  });
}

export async function main(env = process.env) {
  if (!env.PLATEN_CHROMIUM_PROXY) {
    console.error('PLATEN_CHROMIUM_PROXY is not set: Chromium reaches the network without platen\'s egress proxy');
  }
  const [host, port] = (env.PLATEN_CHROMIUM_LISTEN || '0.0.0.0:3000').split(/:(?=\d+$)/);
  const renderer = new Renderer(launchOptions(env));
  const server = createServer(renderer, Math.max(1, Number(env.PLATEN_CHROMIUM_CONCURRENCY) || 6));
  await renderer.browserInstance();
  server.listen(Number(port), host, () => console.error(`platen-chromium is listening on ${host}:${port}`));
  const stop = () => server.close(() => renderer.close().finally(() => process.exit(0)));
  process.on('SIGTERM', stop);
  process.on('SIGINT', stop);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((error) => {
    console.error(error);
    process.exit(1);
  });
}
