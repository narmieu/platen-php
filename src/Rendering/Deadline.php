<?php

declare(strict_types=1);

namespace App\Rendering;

/**
 * The time budget of one render, shared by every step that can block.
 */
final readonly class Deadline
{
    private function __construct(
        public float $seconds,
        private float $expiresAt,
    ) {
    }

    public static function in(float $seconds): self
    {
        return new self($seconds, self::now() + $seconds);
    }

    public function remaining(): float
    {
        return max(0.0, $this->expiresAt - self::now());
    }

    /**
     * @throws RenderException when the budget is spent
     */
    public function check(): void
    {
        if ($this->remaining() <= 0.0) {
            throw $this->exceeded();
        }
    }

    public function exceeded(): RenderException
    {
        return new RenderException(\sprintf('Rendering timed out after %d seconds', (int) round($this->seconds)));
    }

    private static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
