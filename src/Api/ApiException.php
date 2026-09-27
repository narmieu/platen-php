<?php

declare(strict_types=1);

namespace App\Api;

/**
 * An error reported to the API client, with DocRaptor's status codes (400, 401, 403, 422).
 */
class ApiException extends \RuntimeException
{
    /** @var non-empty-list<string> */
    public readonly array $messages;

    public function __construct(public readonly int $status, string ...$messages)
    {
        $messages = array_values(array_filter($messages, static fn (string $message): bool => '' !== $message));
        $this->messages = [] === $messages ? ['Unknown error'] : $messages;
        parent::__construct(implode('; ', $this->messages));
    }

    public static function badRequest(string ...$messages): self
    {
        return new self(400, ...$messages);
    }
}
