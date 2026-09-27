<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api;

use App\Api\ApiException;
use App\Api\ApiKeyAuthenticator;
use App\Tests\Support\TestConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ApiKeyAuthenticatorTest extends TestCase
{
    #[Test]
    public function enabledReflectsWhetherKeysAreConfigured(): void
    {
        self::assertFalse(new ApiKeyAuthenticator(TestConfig::create(apiKeys: ''))->enabled());
        self::assertTrue(new ApiKeyAuthenticator(TestConfig::create(apiKeys: 'k1,k2'))->enabled());
    }

    #[Test]
    public function itReadsTheKeyFromTheBasicAuthUsername(): void
    {
        $auth = new ApiKeyAuthenticator(TestConfig::create(apiKeys: 'secret1'));
        $request = self::request(['Authorization' => 'Basic '.base64_encode('secret1:')]);
        self::assertSame('secret1', $auth->keyFrom($request));
    }

    #[Test]
    public function aBlankBasicUsernameFallsBackToTheQueryParameter(): void
    {
        $auth = new ApiKeyAuthenticator(TestConfig::create(apiKeys: 'secret1'));
        $request = self::request(['Authorization' => 'Basic '.base64_encode(':password')], ['user_credentials' => 'qkey']);
        self::assertSame('qkey', $auth->keyFrom($request));
    }

    #[Test]
    public function itReadsUserCredentialsFromTheQueryString(): void
    {
        $auth = new ApiKeyAuthenticator(TestConfig::create());
        self::assertSame('qkey', $auth->keyFrom(self::request([], ['user_credentials' => 'qkey'])));
    }

    #[Test]
    public function noCredentialsYieldNull(): void
    {
        $auth = new ApiKeyAuthenticator(TestConfig::create());
        self::assertNull($auth->keyFrom(self::request()));
        self::assertNull($auth->keyFrom(self::request([], ['user_credentials' => ''])));
    }

    #[Test]
    public function aMalformedBasicHeaderIsRejected(): void
    {
        $auth = new ApiKeyAuthenticator(TestConfig::create(apiKeys: 'secret1'));
        try {
            $auth->keyFrom(self::request(['Authorization' => 'Basic @@@not-base64@@@']));
        } catch (ApiException $exception) {
            self::assertSame(401, $exception->status);
            self::assertSame('Malformed Authorization header', $exception->messages[0]);

            return;
        }
        self::fail('Expected an ApiException');
    }

    #[Test]
    public function ownerRequiresAKeyWhenAuthenticationIsEnabled(): void
    {
        $auth = new ApiKeyAuthenticator(TestConfig::create(apiKeys: 'secret1'));

        $missing = self::catchOwner($auth, null);
        self::assertSame(401, $missing->status);
        self::assertSame('Missing API key', $missing->messages[0]);

        $wrong = self::catchOwner($auth, 'nope');
        self::assertSame(401, $wrong->status);
        self::assertSame('Invalid API key', $wrong->messages[0]);
    }

    #[Test]
    public function ownerAcceptsAnyConfiguredKeyAndReturnsAStableHash(): void
    {
        $auth = new ApiKeyAuthenticator(TestConfig::create(apiKeys: 'secret1,secret2'));
        self::assertSame(hash('sha256', 'secret1'), $auth->owner('secret1'));
        self::assertSame(hash('sha256', 'secret2'), $auth->owner('secret2'));
    }

    #[Test]
    public function ownerHashesEvenWhenAuthenticationIsDisabled(): void
    {
        // With no keys configured, any (or no) key is accepted and hashed to an owner id.
        $auth = new ApiKeyAuthenticator(TestConfig::create(apiKeys: ''));
        self::assertSame(hash('sha256', ''), $auth->owner(null));
        self::assertSame(hash('sha256', 'anything'), $auth->owner('anything'));
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $query
     */
    private static function request(array $headers = [], array $query = []): Request
    {
        $request = new Request($query);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request;
    }

    private static function catchOwner(ApiKeyAuthenticator $auth, ?string $key): ApiException
    {
        try {
            $auth->owner($key);
        } catch (ApiException $exception) {
            return $exception;
        }
        self::fail('Expected an ApiException');
    }
}
