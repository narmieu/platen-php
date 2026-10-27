#!/usr/bin/env bash
# Smoke test for the Docker image, run against compose.yaml with an asset host added:
#
#   export PLATEN_API_KEYS=smoke-key
#   docker compose -f compose.yaml -f tests/Docker/compose.smoke.yaml up -d --build --wait
#   PLATEN_API_KEY=smoke-key tests/Docker/smoke-test.sh
set -euo pipefail

url=${PLATEN_URL:-http://localhost:8080}
key=${PLATEN_API_KEY:?Set PLATEN_API_KEY to a key from PLATEN_API_KEYS}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

# Posts a document to $1 and prints the HTTP status. The body lands in $work/body, the
# response headers in $work/headers.
post() {
    curl -sS -o "$work/body" -D "$work/headers" -w '%{http_code}' -u "$key:" -H 'Content-Type: application/json' -d "$2" "$url$1"
}

json_value() {
    sed -n "s/.*\"$1\":\"\\([^\"]*\\)\".*/\\1/p" "$2"
}

[ "$(curl -sS -o /dev/null -w '%{http_code}' "$url/healthz")" = 200 ] || fail "GET /healthz"
echo "ok: healthz"

[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Content-Type: application/json' -d '{}' "$url/docs")" = 401 ] || fail "a request without a key was accepted"
echo "ok: a key is required"

for engine in weasyprint chromium; do
    # The asset host sends no CORS headers, without which Chromium refuses the font unless
    # platen embeds it, and a font that fails stops rendering (ignore_resource_errors).
    status=$(post /docs '{"document_type": "pdf", "test": false, "pipeline": "'$engine'", "ignore_resource_errors": false,
        "document_content": "<link rel=\"stylesheet\" href=\"http://assets:8000/smoke/fonts.css\"><h1 style=\"font-family: Platen Test\">Smoke test</h1><img src=\"http://assets:8000/smoke/pixel.png\" width=\"20\" height=\"20\">"}')
    [ "$status" = 200 ] || fail "$engine: $status $(cat "$work/body")"
    [ "$(head -c 5 "$work/body")" = "%PDF-" ] || fail "$engine did not return a PDF"
    echo "ok: $engine renders, fetching an image and a web font through the egress proxy"

    status=$(post /docs '{"document_type": "pdf", "test": false, "pipeline": "'$engine'", "ignore_resource_errors": false,
        "document_content": "<img src=\"http://169.254.169.254/latest/meta-data/\">"}')
    [ "$status" = 400 ] || fail "$engine reached the cloud metadata address: $status $(cat "$work/body")"
    echo "ok: $engine cannot reach the cloud metadata address"
done

# DocRaptor's clients send javascript: true with a pipeline number: a document without
# scripts stays on WeasyPrint, with the Prince layer.
status=$(post /docs '{"document_type": "pdf", "test": false, "pipeline": "10", "javascript": true,
    "document_content": "<p style=\"color: rgba(0, 0, 0, 0.5)\">Prince layer</p>"}')
[ "$status" = 200 ] || fail "pipeline 10: $status $(cat "$work/body")"
[ "$(head -c 5 "$work/body")" = "%PDF-" ] || fail "pipeline 10 did not return a PDF"
tr -d '\r' < "$work/headers" | grep -qix 'x-platen-engine: weasyprint' || fail "pipeline 10 did not render with WeasyPrint: $(cat "$work/headers")"
echo "ok: a script-free JavaScript document with a pipeline number renders with WeasyPrint and the Prince layer"

status=$(post /docs '{"document_type": "pdf", "test": false, "pipeline": "chromium", "ignore_resource_errors": false,
    "document_content": "<img src=\"http://127.0.0.1:3000/health\">"}')
[ "$status" = 400 ] || fail "Chromium reached its own loopback: $status $(cat "$work/body")"
echo "ok: Chromium cannot reach loopback"

status=$(post /async_docs '{"document_type": "pdf", "test": false, "document_content": "<p>async</p>"}')
[ "$status" = 200 ] || fail "POST /async_docs: $status $(cat "$work/body")"
status_id=$(json_value status_id "$work/body")
for _ in $(seq 1 150); do
    curl -fsS -u "$key:" -o "$work/status" "$url/status/$status_id"
    grep -q '"status":"\(completed\|failed\)"' "$work/status" && break
    sleep 0.2
done
grep -q '"status":"completed"' "$work/status" || fail "async document: $(cat "$work/status")"
curl -fsS -o "$work/async.pdf" "$(json_value download_url "$work/status")"
[ "$(head -c 5 "$work/async.pdf")" = "%PDF-" ] || fail "the async download is not a PDF"
echo "ok: async documents render in the workers and download"
