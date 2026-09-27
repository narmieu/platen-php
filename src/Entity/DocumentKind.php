<?php

declare(strict_types=1);

namespace App\Entity;

enum DocumentKind: string
{
    /** Rendered in the request and returned directly; only logged. */
    case Sync = 'sync';
    /** Rendered in the request and stored for download. */
    case Hosted = 'hosted';
    /** Rendered in the background, downloadable for a while. */
    case Async = 'async';
    /** Rendered in the background and stored for download. */
    case HostedAsync = 'hosted_async';

    public static function of(bool $async, bool $hosted): self
    {
        return match (true) {
            $async && $hosted => self::HostedAsync,
            $async => self::Async,
            $hosted => self::Hosted,
            default => self::Sync,
        };
    }

    public function isAsync(): bool
    {
        return self::Async === $this || self::HostedAsync === $this;
    }

    public function isHosted(): bool
    {
        return self::Hosted === $this || self::HostedAsync === $this;
    }
}
