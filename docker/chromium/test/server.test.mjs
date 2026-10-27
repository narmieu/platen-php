// node --test: the Chromium service against a real browser and a local asset server.
// PLATEN_CHROMIUM_EXECUTABLE (or Playwright's own browser path) says which Chromium to run.

import assert from 'node:assert/strict';
import http from 'node:http';
import { after, before, describe, test } from 'node:test';

import { createServer, launchOptions, limiter, origin, Renderer } from '../server.mjs';

/** A server on loopback that records each request; /missing answers 404. */
async function assetServer() {
  const requests = [];
  const server = http.createServer((request, response) => {
    requests.push({ url: request.url, headers: request.headers });
    if (request.url.startsWith('/missing')) {
      response.writeHead(404).end();
      return;
    }
    response.writeHead(200, { 'Content-Type': 'image/svg+xml' });
    response.end('<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>');
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));

  return { server, requests, url: `http://127.0.0.1:${server.address().port}` };
}

const signal = () => new AbortController().signal;

describe('rendering', () => {
  let renderer;
  let assets;

  before(async () => {
    renderer = new Renderer(launchOptions({ PLATEN_CHROMIUM_EXECUTABLE: process.env.PLATEN_CHROMIUM_EXECUTABLE }));
    assets = await assetServer();
  });

  after(async () => {
    await renderer.close();
    assets.server.close();
  });

  test('a page becomes a PDF once it is ready', async () => {
    const started = Date.now();
    const { pdf } = await renderer.render({
      // The policy of a page with JavaScript off: no eval.
      html: `<meta http-equiv="Content-Security-Policy" content="script-src 'nonce-n'">
        <p>x</p><script nonce="n">let done = false; window.isReady = () => done; setTimeout(() => { done = true; }, 300)</script>`,
      ready: 'isReady',
    }, signal());
    assert.ok(Date.now() - started >= 300);
    assert.equal(pdf.subarray(0, 5).toString(), '%PDF-');
  });

  test('a page that is never ready times out', async () => {
    await assert.rejects(
      renderer.render({ html: '<script>window.never = () => false</script>', ready: 'never', timeout: 1 }, signal()),
      { status: 504, message: 'Rendering timed out' },
    );
  });

  test('failed requests are reported', async () => {
    const html = `<img src="${assets.url}/missing.png"><img src="${assets.url}/ok.svg">`;
    const { pdf, failed } = await renderer.render({ html }, signal());
    assert.equal(pdf.subarray(0, 5).toString(), '%PDF-');
    assert.deepEqual(failed, [`${assets.url}/missing.png (HTTP 404)`]);
  });

  test('requests carry the user agent and headers, and credentials only to their origin', async () => {
    const other = await assetServer();
    try {
      assets.requests.length = 0;
      await renderer.render({
        html: `<img src="${assets.url}/a.svg"><img src="${other.url}/b.svg">`,
        userAgent: 'TestAgent/1.0',
        headers: { Referer: 'https://example.com/page' },
        authorization: { value: 'Basic dXNlcjpwYXNz', origin: assets.url },
      }, signal());
      const [mine] = assets.requests;
      const [theirs] = other.requests;
      assert.equal(mine.headers['user-agent'], 'TestAgent/1.0');
      assert.equal(mine.headers.referer, 'https://example.com/page');
      assert.equal(mine.headers.authorization, 'Basic dXNlcjpwYXNz');
      assert.equal(theirs.headers.authorization, undefined);
    } finally {
      other.server.close();
    }
  });

  test('a caller that goes away ends its render', async () => {
    const abort = new AbortController();
    const render = renderer.render({ html: '<script>window.never = () => false</script>', ready: 'never', timeout: 30 }, abort.signal);
    setTimeout(() => abort.abort(), 300);
    const started = Date.now();
    await assert.rejects(render);
    assert.ok(Date.now() - started < 5000);
  });
});

test('loopback goes through the proxy too', async () => {
  const assets = await assetServer();
  const proxied = [];
  const proxy = http.createServer((request, response) => {
    proxied.push(request.url);
    response.writeHead(403).end();
  });
  await new Promise((resolve) => proxy.listen(0, '127.0.0.1', resolve));
  const renderer = new Renderer(launchOptions({
    PLATEN_CHROMIUM_PROXY: `http://127.0.0.1:${proxy.address().port}`,
    PLATEN_CHROMIUM_EXECUTABLE: process.env.PLATEN_CHROMIUM_EXECUTABLE,
  }));
  try {
    const { failed } = await renderer.render({ html: `<img src="${assets.url}/secret.svg">` }, signal());
    assert.deepEqual(failed, [`${assets.url}/secret.svg (HTTP 403)`]);
    assert.deepEqual(proxied, [`${assets.url}/secret.svg`]);
    assert.deepEqual(assets.requests, []);
  } finally {
    await renderer.close();
    proxy.close();
    assets.server.close();
  }
});

test('the HTTP API', async () => {
  const fake = { render: async (request) => ({ pdf: Buffer.from(`%PDF-${request.html}`), failed: ['x (HTTP 404)'] }) };
  const server = createServer(fake, 2);
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  const base = `http://127.0.0.1:${server.address().port}`;
  try {
    assert.deepEqual(await (await fetch(`${base}/health`)).json(), { status: 'up' });
    const rendered = await fetch(`${base}/render`, { method: 'POST', body: JSON.stringify({ html: 'x' }) });
    assert.equal(rendered.headers.get('content-type'), 'application/pdf');
    assert.equal(await rendered.text(), '%PDF-x');
    assert.deepEqual(JSON.parse(atob(rendered.headers.get('x-resource-errors'))), ['x (HTTP 404)']);
    const bad = await fetch(`${base}/render`, { method: 'POST', body: '{"no": "html"}' });
    assert.equal(bad.status, 400);
    assert.deepEqual(await bad.json(), { error: 'html is missing' });
    assert.equal((await fetch(`${base}/other`)).status, 404);
  } finally {
    server.close();
  }
});

test('limiter runs at most its limit at once', async () => {
  const limit = limiter(2);
  let running = 0;
  let most = 0;
  const task = () => limit(async () => {
    running += 1;
    most = Math.max(most, running);
    await new Promise((resolve) => setTimeout(resolve, 20));
    running -= 1;
  });
  await Promise.all([task(), task(), task(), task(), task()]);
  assert.equal(most, 2);
});

test('origin is written as platen writes it', () => {
  assert.equal(origin('https://example.com/a?b'), 'https://example.com:443');
  assert.equal(origin('http://example.com:8080/'), 'http://example.com:8080');
  assert.equal(origin('data:text/plain,x'), null);
});
