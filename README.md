# platen

A self-hosted HTML to PDF server that speaks [DocRaptor](https://docraptor.com)'s API.
Point DocRaptor's official client libraries at it and your existing code keeps working.

platen is a Symfony application. It renders with two open-source engines:

- **WeasyPrint** (default) implements CSS paged media: `@page` rules, margin boxes,
  running headers, page counters, footnotes, `target-counter()`, leaders, bookmarks,
  PDF/A and PDF/UA. Of the open-source engines it is the closest to Prince, which
  DocRaptor runs.
- **Chromium**, run by [Gotenberg](https://gotenberg.dev) in a container next to platen,
  runs your JavaScript and produces browser-exact output when a document needs it.

Nothing is sent to a third party, there is no per-document fee, and documents never
leave your network.

## Quick start

```sh
PLATEN_API_KEYS=change-me docker compose up -d --build

curl -u change-me: -H 'Content-Type: application/json' \
  -d '{"document_type": "pdf", "test": false, "document_content": "<h1>Hello</h1>"}' \
  http://localhost:8080/docs -o hello.pdf
```

`compose.yaml` runs two containers: `platen` (the API, the render workers and
WeasyPrint) and `gotenberg` (Chromium). Copy both services into your own Compose file to
run them next to your app, which then reaches platen at `http://platen:8080`.

## Switching from DocRaptor

Keep using DocRaptor's client library and change its host:

```php
$config = new DocRaptor\Configuration();
$config->setUsername($_ENV['DOCRAPTOR_API_KEY']); // a key from PLATEN_API_KEYS
$config->setHost($_ENV['DOCRAPTOR_HOST']);        // e.g. http://platen:8080
$docraptor = new DocRaptor\DocApi(null, $config);
```

With Symfony's service container:

```yaml
# config/services.yaml
services:
    docraptor.configuration:
        class: DocRaptor\Configuration
        calls:
            - setUsername: ['%env(DOCRAPTOR_API_KEY)%']
            - setHost: ['%env(DOCRAPTOR_HOST)%']

    DocRaptor\DocApi:
        arguments:
            $config: '@docraptor.configuration'
```

The end-to-end tests run DocRaptor's PHP client (4.2) against platen: `createDoc`,
`createAsyncDoc`, `getAsyncDocStatus`, `getAsyncDoc`, `createHostedDoc` and `expire` work
unchanged. DocRaptor's other client libraries are generated from the same API
description and have the same host setting.

## Choosing an engine

| You send | Rendered by |
| --- | --- |
| nothing special | WeasyPrint (or `PLATEN_DEFAULT_ENGINE`) |
| `"pipeline": "weasyprint"` or `"pipeline": "chromium"` | that engine |
| a DocRaptor pipeline number such as `"10.1"` | the default engine, with the Prince layer when that is WeasyPrint |
| `"javascript": true` (or `prince_options.javascript`) | Chromium, the engine that runs scripts |

Like DocRaptor, platen waits for `docraptorJavaScriptFinished()` to return `true` when a
page defines it, and console output stops rendering unless `ignore_console_messages` is
set.

With a DocRaptor pipeline number and Prince compatibility on, `javascript: true` documents
without scripts render with WeasyPrint: DocRaptor's clients always send `javascript: true`,
and only a browser runs scripts. A `document_content` with no `<script>`, `<template>` or
`on…` event-handler attribute counts as script-free; `document_url` documents keep
Chromium.

## Rendering quality and Prince compatibility

platen runs WeasyPrint through `weasyprint-platen`, a wrapper (`docker/weasyprint`) that
patches WeasyPrint 70.0 in-process with two layers.

| Layer | Applies to | Contains |
| --- | --- | --- |
| Quality | every WeasyPrint render | fixes to WeasyPrint's output that are right whatever the document was written for |
| Prince | only when `pipeline` is a number such as `"10"` or `"10.1"` and `PLATEN_PRINCE_COMPAT` is `true` | behaviour that makes WeasyPrint lay out like Prince 15.1, DocRaptor's engine |

The quality layer, like browsers and the CSS specifications:

- inline SVG inherits the page font and resolves `var()`;
- Prince's `flow: static()` / `content: flow()` running elements and its full-width `@top`
  / `@bottom` page regions work;
- pages that use transparency and ask for `-prince-pdf-page-colorspace: cmyk` get a CMYK
  transparency group;
- with no matching family the default font is serif; `serif`, `sans-serif` and `monospace`
  are Liberation Serif, Sans and Mono (metric twins of Times New Roman, Arial, Courier New);
- `line-height: normal` includes the font's line gap, and fallback glyphs do not make lines
  taller;
- `sup` and `sub` inherit the line-height and grow the line as CSS 2.1 says;
- hyphenation follows the language and the script, and the hyphen is U+2010 only in fonts
  that have it;
- `@font-face` weights match by CSS Fonts Level 4, with weight ranges and `unicode-range`
  subsets;
- justified text may shrink word spaces to 5/6 of their width before wrapping.

The Prince layer, measured against DocRaptor:

- script-free `javascript: true` documents stay on WeasyPrint (see above);
- pages that use transparency get an RGB transparency group, and grey colours are written
  as DeviceGray;
- `@font-face` weights match as Prince was measured to: CSS Fonts Level 3, except that 500
  tries the heavier faces before the lighter ones once 400 is missing;
- superscripts and subscripts are raised and lowered by the parent font's ascender and
  descender, `sub`/`sup` are 83%, `smaller`/`larger` divide/multiply by 1.2;
- hyphenation uses Prince's dictionaries (en-US for every language Prince lacks), with
  `-` as the hyphen;
- list markers have no trailing space and sit 8pt from the content;
- justified paragraphs break where Prince breaks them (the whole paragraph is optimised,
  not line by line), and no-break spaces keep their width.

Requests without a pipeline number never get Prince-layer behaviour. Set
`PLATEN_PRINCE_COMPAT=false` to turn the Prince layer off for everyone, or
`PLATEN_WEASYPRINT_BINARY=weasyprint` to run WeasyPrint with neither patch layer (platen's
font configuration, `docker/fontconfig/45-platen.conf`, stays in the image). platen asks
for the Prince layer with the environment variable `PLATEN_WEASYPRINT_PRINCE=1`, which
plain `weasyprint` ignores, so requests with a pipeline number render with it too.
`weasyprint-platen` only runs with WeasyPrint 70.0, pydyf 0.13.0 and pyphen 0.18.1, the
versions in the Docker image. `tools/prince-calibration` holds the scripts that measured
Prince (free fonts only, DocRaptor's free test mode).

## Compatibility

### Endpoints

| Endpoint | Status |
| --- | --- |
| `POST /docs` | Supported, including the legacy `async` and `hosted` flags, the `{"doc": {...}}` wrapper and form-encoded `doc[...]` parameters |
| `POST /async_docs`, `POST /hosted_docs`, `POST /hosted_async_docs` | Supported |
| `GET /status/{id}` | Supported (`queued`, `working`, `completed`, `failed`) |
| `GET /download/{id}` | Supported; like DocRaptor's, download URLs need no credentials |
| `PATCH /expire/{id}` | Supported |
| `GET /docs.json` | Supported (document log); the exact field list may differ from DocRaptor's |
| Referrer-based documents | Not supported |

Authentication works like DocRaptor's: the API key as the HTTP Basic username, or a
`user_credentials` parameter. Errors come back as `<errors><error>…</error></errors>`
XML with DocRaptor's status codes (400, 401, 403, 422). PDFs carry the
`X-DocRaptor-Num-Pages` header.

### Document options

| Option | Status |
| --- | --- |
| `name`, `document_content`, `document_url`, `tag` | Supported |
| `document_type` | `pdf` only; `xls` and `xlsx` return an error |
| `test` | Supported; test documents get a "TEST DOCUMENT" watermark (defaults to `true`, as on DocRaptor; `PLATEN_TEST_WATERMARK=false` turns the watermark off) |
| `javascript`, `ignore_console_messages` | Supported |
| `ignore_resource_errors` | Supported |
| `strict: html` | Supported (HTML5 parse errors are returned with status 422) |
| `pipeline` | Selects the engine (see above) |
| `referrer`, `user_agent_token` | Supported (with WeasyPrint, for `http://` assets only) |
| `callback_url` | Supported; receives `download_url`, `download_id`, `status_id` and `number_of_pages`, and is retried twice if it fails |
| `hosted_download_limit`, `hosted_expires_at` | Supported |

### `prince_options`

| Option | Status |
| --- | --- |
| `baseurl`, `media`, `no_network`, `http_user`, `http_password` | Supported |
| `http_timeout` | Supported with WeasyPrint and for `document_url` |
| `insecure` | Supported for `document_url` |
| `encrypt`, `key_bits`, `user_password`, `owner_password`, `disallow_print`, `disallow_copy`, `disallow_annotate`, `disallow_modify` | Supported (40-bit RC4 or 128-bit AES) |
| `pdf_title`, `page_margin`, `javascript` | Supported |
| `profile` | PDF/A-1b, 2b, 3b, 1a, 2a, 3a, 2u, 3u, 4u, 4e, 4f, PDF/UA-1 and 2, PDF/X-1a, 3, 4 and 5g with the WeasyPrint engine |
| `pdf_forms`, `no_compress`, `no_subset_fonts` | Supported with WeasyPrint (`no_compress` also with Chromium) |
| `http_proxy`, `no_author_style`, `no_default_style`, `no_embed_fonts`, `css_dpi`, `iframes`, `input`, `debug`, `no_xinclude`, `no_parallel_downloads`, `version` | Accepted and ignored |

`http_user` and `http_password` are only sent to the document's own origin (the host of
`baseurl` or `document_url`), so a document cannot pass them to a third party.

### Rendering differences

- Prince-only CSS and JavaScript (`prince-*` properties, `prince-script()`, Prince's
  JavaScript APIs) are not supported, except `flow: static()` (also `-prince-flow`),
  `content: flow()`, the `@top` / `@bottom` page regions and
  `-prince-pdf-page-colorspace`. Standard paged-media CSS is.
- Without an `@page` size, pages are US Letter with 54pt margins, Prince's defaults.
- Web fonts load from any server, as with Prince. Because Gotenberg loads each page from
  a file, Chromium would only load fonts whose server sends
  `Access-Control-Allow-Origin: *`, so platen downloads the others and embeds them in the
  page. It finds fonts in `<style>` elements and stylesheets (up to 20 stylesheets and
  60 fonts a document), but not fonts that scripts add.
- Line breaking, font fallback and hyphenation can differ slightly from Prince. Render
  your own templates with both and compare before switching.

## Configuration

All settings are environment variables; `.env` holds the defaults.

| Variable | Default | Meaning |
| --- | --- | --- |
| `PLATEN_API_KEYS` | empty | Comma-separated keys. Empty accepts any key (platen warns at start) |
| `PLATEN_HOST`, `PLATEN_PORT` | `0.0.0.0`, `8080` | Where to listen |
| `PLATEN_PUBLIC_URL` | request URL | Base of `download_url` links, e.g. `https://pdf.example.com` |
| `PLATEN_DATA_DIR` | `var/data` (`/var/lib/platen` in Docker) | SQLite database and stored PDFs |
| `DATABASE_URL` | SQLite in `PLATEN_DATA_DIR` | The database for documents and the job queue |
| `PLATEN_DEFAULT_ENGINE` | `weasyprint` | `weasyprint` or `chromium` |
| `PLATEN_GOTENBERG_URL` | `http://127.0.0.1:3000` | Gotenberg, which runs Chromium |
| `PLATEN_WORKERS` | `2` | Processes rendering async and hosted-async documents |
| `PLATEN_HTTP_WORKERS` | `8` | Requests served at once (FrankenPHP workers, or PHP's built-in server's processes). A synchronous document holds one for its whole render |
| `FRANKENPHP_LOOP_MAX` | `500` | Requests a FrankenPHP worker serves before it is replaced |
| `PLATEN_SYNC_TIMEOUT`, `PLATEN_ASYNC_TIMEOUT` | `60`, `600` | Seconds per document (DocRaptor's limits) |
| `PLATEN_JS_TIMEOUT` | `30` | Seconds to wait for `docraptorJavaScriptFinished()` |
| `PLATEN_FETCH_TIMEOUT` | `10` | Seconds per asset request |
| `PLATEN_TEST_WATERMARK` | `true` | Watermark test documents |
| `PLATEN_ASYNC_RETENTION_HOURS` | `24` | How long async (non-hosted) PDFs stay downloadable |
| `PLATEN_LOG_RETENTION_DAYS` | `30` | How long document log entries are kept |
| `PLATEN_BLOCK_PRIVATE_NETWORKS` | `false` | Refuse assets on private and loopback addresses |
| `PLATEN_BLOCKED_NETWORKS` | empty | Extra CIDR ranges to refuse |
| `PLATEN_ALLOW_FILE_URLS` | `false` | Allow `file://` assets |
| `PLATEN_MAX_REQUEST_MB` | `100` | Largest accepted request body |
| `PLATEN_EGRESS_LISTEN` | `127.0.0.1:3128` (`0.0.0.0:3128` in Docker) | Where the egress proxy listens |
| `PLATEN_EGRESS_PROXY` | `http://127.0.0.1:3128` | How WeasyPrint and callbacks reach the egress proxy |
| `PLATEN_WEASYPRINT_BINARY` | `weasyprint-platen` in the Docker image, `weasyprint` otherwise | The WeasyPrint command platen runs. `weasyprint` runs WeasyPrint with neither patch layer; platen's font configuration stays |
| `PLATEN_QPDF_BINARY` | `qpdf` | The qpdf command platen runs |
| `PLATEN_PRINCE_COMPAT` | `true` | Apply the Prince compatibility layer to requests that send a pipeline number |
| `PLATEN_PHP_MEMORY_LIMIT` | `512M` | PHP's memory limit in the Docker image |
| `TRUSTED_PROXIES` | empty | Reverse proxies trusted for `X-Forwarded-*` headers |

Fonts: the image ships DejaVu, Liberation (metric-compatible with Arial, Times New Roman
and Courier New), Carlito, Caladea and Noto. Add your own by mounting them under
`/usr/local/share/fonts`, or load them with `@font-face`. The Gotenberg image has its
own, similar set for Chromium.

## Security

- Every asset request from either engine, including each redirect hop, goes through
  platen's egress proxy, which checks the destination address and then connects to the
  address it checked. Link-local addresses (cloud metadata endpoints such as
  `169.254.169.254`) are always refused. Set `PLATEN_BLOCK_PRIVATE_NETWORKS=true` if
  documents come from untrusted sources and should not reach your internal network.
- Chromium connects to loopback addresses directly rather than through a proxy, so
  `compose.yaml` denies them with Gotenberg's `--chromium-deny-list`. Keep that flag if
  you run Gotenberg another way.
- The egress proxy listens on `0.0.0.0:3128` inside the Docker network so that
  Gotenberg can reach it. Do not publish that port.
- `file://` URLs are refused unless `PLATEN_ALLOW_FILE_URLS=true`.
- If platen's environment sets `HTTP_PROXY`, `HTTPS_PROXY` and `NO_PROXY`, the egress
  proxy forwards through that proxy after checking the destination.
- Download URLs work without credentials, like DocRaptor's; the id is a random
  256-bit token. Put the server behind HTTPS if links leave your network.

## Performance

A one-page A4 certificate with two web fonts, on a 4-CPU machine, measured at the client
(the HTTP round trip and fetching the fonts included):

| Engine | One at a time | Four at a time |
| --- | --- | --- |
| WeasyPrint | 760 ms per document | 4.6 documents per second (about 16,500 an hour) |
| Chromium | 310 ms per document | 7.4 documents per second (about 26,500 an hour) |

platen starts WeasyPrint for each document, and Python loading WeasyPrint takes about
0.55 s of those 760 ms; the rendering itself takes about 0.1 s. Chromium's time is
mostly Gotenberg's work per document, plus about 30 ms to embed the two fonts, whose
server sends no CORS header. Beyond the documents rendering at once, requests
wait for a free HTTP worker rather than failing.

## How it works

`bin/console platen:serve`, the container's command, runs and supervises:

- the HTTP server: [FrankenPHP](https://frankenphp.dev) in the Docker image, PHP's
  built-in server otherwise. FrankenPHP runs in worker mode, which boots Symfony once
  per worker rather than once per request (with `APP_DEBUG=1` it runs in classic mode,
  so code changes apply at once). Synchronous documents render inside the request;
- `PLATEN_WORKERS` Symfony Messenger workers, which render async documents and send
  callbacks. One of them also runs the schedule that deletes expired documents;
- the egress proxy (`platen:egress-proxy`), a small ReactPHP server.

A child that exits is started again. On `SIGTERM` the workers get 8 seconds to finish
the document they are rendering. Documents are stored as files in `PLATEN_DATA_DIR`,
and their records and the job queue in SQLite, set up by Doctrine migrations on start.

## Running in production

- Keep `PLATEN_DATA_DIR` on a volume. Async and hosted documents live on the instance
  that rendered them, so with several replicas, send status and download requests to
  the instance that created the document, or use synchronous documents.
- An async document whose worker was killed (a crash, or a stop that outlasted the
  grace period) is rendered again 30 seconds after platen is back.
- Gotenberg renders six Chromium documents at a time and queues the rest.
  `compose.yaml` turns off its restart of Chromium every 100 documents: that restart
  waits for every running document, so one slow page would hold up all others.
  Restart the `gotenberg` container from time to time instead if its memory grows.

## Known limitations

- A script stuck in an endless loop keeps a Chromium tab busy until Gotenberg's
  `--api-timeout`, even after platen has answered with a timeout error.
- JavaScript documents from `document_url` run from a local copy, so their scripts see a
  `file://` origin rather than the original one.
- With WeasyPrint, `user_agent_token`, `referrer` and `http_user` reach `https://` assets
  without the extra headers: the proxy only sees the encrypted connection.

## Development

You need PHP 8.4 with the `dom`, `pcntl` and `pdo_sqlite` extensions, Composer, qpdf 11
or later, Docker for Gotenberg, and WeasyPrint 70.0 ([its system
libraries](https://doc.courtbouillon.org/weasyprint/stable/first_steps.html)) with platen's
wrapper and font configuration, installed as CI does:

```sh
pipx install --include-deps ./docker/weasyprint   # weasyprint-platen, and WeasyPrint's weasyprint
sudo cp docker/fontconfig/45-platen.conf /etc/fonts/conf.d/
composer install
composer check        # code style, PHPStan at its strictest level, unit tests
```

The end-to-end tests start platen themselves and talk to it over HTTP only. They run
`weasyprint-platen` (and `weasyprint` for the escape hatch), and need Gotenberg on the host
network, with its Chromium pointed at the egress proxy the tests start:

```sh
docker run -d --name gotenberg --network host gotenberg/gotenberg:8.37 \
  gotenberg --api-timeout=620s --chromium-proxy-server=http://127.0.0.1:3128 \
  --chromium-restart-after=0
composer test:e2e
```

They use `172.17.0.1` (Docker's bridge address on Linux) as a blocked internal address;
set `PLATEN_TEST_BLOCKED_HOST` to another non-loopback address of the machine if yours
differs.

The WeasyPrint wrapper has its own tests. Each layer patches WeasyPrint for the whole
process, so each layer's tests run in their own pytest process; the oracle tests compare
quality-layer behaviour with Gotenberg's Chromium:

```sh
pip install './docker/weasyprint[test]'
pytest docker/weasyprint/tests/test_cli.py docker/weasyprint/tests/quality
pytest docker/weasyprint/tests/prince
GOTENBERG_URL=http://127.0.0.1:3000 pytest docker/weasyprint/tests/oracle
```

`tests/Docker/smoke-test.sh` checks the Docker image and `compose.yaml`; CI runs all of
these on every push and publishes the image to GitHub's registry when a `v*` tag is
pushed.

## License

MIT. WeasyPrint is BSD-licensed, Gotenberg is MIT, Chromium is BSD-licensed, and
FrankenPHP is MIT. DocRaptor and Prince are trademarks of their owners; platen is not
affiliated with them.
