<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Api\ApiException;

/**
 * The document could not be rendered: bad input, a failed resource or a timeout.
 */
final class RenderException extends ApiException
{
    public function __construct(string $message, string ...$more)
    {
        parent::__construct(400, $message, ...$more);
    }
}
