<?php

declare(strict_types=1);

/*
 * The asset host and callback receiver of the end-to-end tests: a router script for
 * PHP's built-in server. Every request is appended to the log file named by
 * ASSET_SERVER_LOG, where the tests read it back.
 */

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/**
 * @param array<string, string> $headers
 */
function send(int $status, string $body = '', string $type = 'text/html; charset=utf-8', array $headers = []): void
{
    http_response_code($status);
    header("Content-Type: {$type}");
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo $body;
}

function png(): string
{
    return (string) base64_decode(PNG, true);
}

$uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
$path = (string) parse_url($uri, \PHP_URL_PATH);
parse_str((string) parse_url($uri, \PHP_URL_QUERY), $query);
$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$headers = getallheaders();
$entry = ['method' => $method, 'path' => $path, 'headers' => is_array($headers) ? array_change_key_case($headers) : [], 'body' => (string) file_get_contents('php://input')];
file_put_contents((string) getenv('ASSET_SERVER_LOG'), json_encode($entry, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND | \LOCK_EX);

if ('POST' === $method) {
    send(200, 'ok', 'text/plain');
} elseif ('/page.html' === $path) {
    send(200, '<html><head><link rel="stylesheet" href="style.css"></head><body><h1>From URL</h1><img src="pixel.png" width="40" height="40"></body></html>');
} elseif ('/style.css' === $path) {
    send(200, 'h1::after { content: " styled"; }', 'text/css');
} elseif ('/fonts.css' === $path) {
    send(200, '@font-face { font-family: "Platen Test"; src: url(fonts/platen-test.woff) format("woff"); }', 'text/css');
} elseif ('/fonts/platen-test.woff' === $path) {
    // Like most sites' own fonts: no Access-Control-Allow-Origin header.
    send(200, (string) file_get_contents(__DIR__.'/fonts/platen-test.woff'), 'font/woff');
} elseif (in_array($path, ['/pixel.png', '/secret', '/ua.png'], true)) {
    send(200, png(), 'image/png');
} elseif ('/redirect' === $path) {
    send(302, '', 'text/plain', ['Location' => is_string($query['to'] ?? null) ? $query['to'] : '/']);
} elseif ('/protected.png' === $path) {
    if ('Basic '.base64_encode('user:pass') === ($_SERVER['HTTP_AUTHORIZATION'] ?? null)) {
        send(200, png(), 'image/png');
    } else {
        send(401, 'no', 'text/plain', ['WWW-Authenticate' => 'Basic realm="assets"']);
    }
} elseif ('/slow.png' === $path) {
    usleep((int) ((is_numeric($query['seconds'] ?? null) ? (float) $query['seconds'] : 20.0) * 1_000_000));
    send(200, png(), 'image/png');
} else {
    send(404, 'not found', 'text/plain');
}
