<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * Prince's http_user and http_password: HTTP Basic credentials for the document's assets.
 */
final readonly class Credentials
{
    public function __construct(
        public string $user,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }

    public function header(): string
    {
        return 'Basic '.base64_encode("{$this->user}:{$this->password}");
    }
}
