<?php

declare(strict_types=1);

namespace App\Tests\Unit\Network;

use App\Network\ProxyTicket;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProxyTicketTest extends TestCase
{
    #[Test]
    public function anEmptyTicketLeavesTheProxyUrlUntouched(): void
    {
        $ticket = new ProxyTicket();
        self::assertTrue($ticket->isEmpty());
        self::assertSame('http://127.0.0.1:3128', $ticket->proxyUrl('http://127.0.0.1:3128'));
    }

    #[Test]
    public function itRoundTripsThroughTheProxyUsername(): void
    {
        $ticket = new ProxyTicket(
            userAgentSuffix: 'platen-token',
            referrer: 'https://ref.example/',
            authorization: 'Basic dXNlcjpwYXNz',
            authorizationOrigin: 'https://assets.example:443',
        );
        $url = $ticket->proxyUrl('http://127.0.0.1:3128');
        self::assertStringStartsWith('http://', $url);
        self::assertStringContainsString(':x@127.0.0.1:3128', $url);

        $decoded = ProxyTicket::fromProxyAuthorization(self::authorizationHeaderFrom($url));
        self::assertInstanceOf(ProxyTicket::class, $decoded);
        self::assertSame('platen-token', $decoded->userAgentSuffix);
        self::assertSame('https://ref.example/', $decoded->referrer);
        self::assertSame('Basic dXNlcjpwYXNz', $decoded->authorization);
        self::assertSame('https://assets.example:443', $decoded->authorizationOrigin);
    }

    #[Test]
    public function itAcceptsTheBasicSchemeCaseInsensitivelyWithSurroundingSpace(): void
    {
        $url = new ProxyTicket(userAgentSuffix: 'ua')->proxyUrl('http://p:1');
        $username = self::usernameFrom($url);

        $decoded = ProxyTicket::fromProxyAuthorization('  basic   '.base64_encode($username.':x').'  ');
        self::assertInstanceOf(ProxyTicket::class, $decoded);
        self::assertSame('ua', $decoded->userAgentSuffix);
    }

    #[Test]
    public function valuesWithControlCharactersAreDropped(): void
    {
        // A CRLF in a value would let a forged ticket inject its own headers.
        $ticket = new ProxyTicket(referrer: "https://x/\r\nX-Evil: 1", userAgentSuffix: 'kept');
        $url = $ticket->proxyUrl('http://p:1');

        $decoded = ProxyTicket::fromProxyAuthorization('Basic '.base64_encode(self::usernameFrom($url).':x'));
        self::assertInstanceOf(ProxyTicket::class, $decoded);
        self::assertNull($decoded->referrer); // dropped for the control characters
        self::assertSame('kept', $decoded->userAgentSuffix);
    }

    #[Test]
    public function malformedHeadersYieldNull(): void
    {
        self::assertNull(ProxyTicket::fromProxyAuthorization(null));
        self::assertNull(ProxyTicket::fromProxyAuthorization('Bearer abc'));
        self::assertNull(ProxyTicket::fromProxyAuthorization('Basic !!!not-base64!!!'));
        // Valid outer base64, but the payload is not a JSON object.
        $notObject = base64_encode(rtrim(strtr(base64_encode('"a string"'), '+/', '-_'), '=').':x');
        self::assertNull(ProxyTicket::fromProxyAuthorization('Basic '.$notObject));
    }

    #[Test]
    public function applyAppendsTheUserAgentSuffix(): void
    {
        $ticket = new ProxyTicket(userAgentSuffix: 'platen-token');
        $headers = $ticket->apply(['user-agent' => 'WeasyPrint/66'], 'https://any.example/');
        self::assertSame('WeasyPrint/66 platen-token', $headers['user-agent']);
    }

    #[Test]
    public function applySetsTheReferrerOnlyWhenAbsent(): void
    {
        $ticket = new ProxyTicket(referrer: 'https://ref.example/');
        self::assertSame('https://ref.example/', $ticket->apply([], 'https://any/')['referer']);
        // An existing referer is not overwritten.
        self::assertSame('mine', $ticket->apply(['referer' => 'mine'], 'https://any/')['referer']);
    }

    #[Test]
    public function applyScopesTheAuthorizationHeaderToItsOrigin(): void
    {
        $ticket = new ProxyTicket(
            authorization: 'Basic dXNlcjpwYXNz',
            authorizationOrigin: 'https://assets.example:443',
        );
        // Same origin (default https port) gets the header.
        $matched = $ticket->apply([], 'https://ASSETS.example:443/file.png');
        self::assertSame('Basic dXNlcjpwYXNz', $matched['authorization']);

        // A different origin does not.
        self::assertArrayNotHasKey('authorization', $ticket->apply([], 'http://assets.example/file.png'));

        // An existing authorization is never overwritten.
        self::assertSame('Bearer x', $ticket->apply(['authorization' => 'Bearer x'], 'https://assets.example:443/')['authorization']);
    }

    #[Test]
    public function anUnscopedAuthorizationAppliesToEveryUrl(): void
    {
        $ticket = new ProxyTicket(authorization: 'Basic dXNlcjpwYXNz');
        self::assertSame('Basic dXNlcjpwYXNz', $ticket->apply([], 'http://anywhere.example/')['authorization']);
    }

    private static function usernameFrom(string $proxyUrl): string
    {
        $at = strpos($proxyUrl, ':x@');
        self::assertNotFalse($at);
        $scheme = strpos($proxyUrl, '://');
        self::assertNotFalse($scheme);

        return substr($proxyUrl, $scheme + 3, $at - ($scheme + 3));
    }

    private static function authorizationHeaderFrom(string $proxyUrl): string
    {
        return 'Basic '.base64_encode(self::usernameFrom($proxyUrl).':x');
    }
}
