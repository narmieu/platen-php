<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Api\ApiException;
use App\Rendering\Deadline;
use App\Rendering\RenderException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    #[Test]
    public function itReportsTheBudgetAndRemainingTime(): void
    {
        $deadline = Deadline::in(10.0);
        self::assertSame(10.0, $deadline->seconds);
        self::assertGreaterThan(0.0, $deadline->remaining());
        self::assertLessThanOrEqual(10.0, $deadline->remaining());
        // A budget with time left does not throw.
        $deadline->check();
    }

    #[Test]
    public function remainingNeverGoesBelowZeroAndCheckThrowsWhenSpent(): void
    {
        $deadline = Deadline::in(0.02);
        usleep(40_000);
        self::assertSame(0.0, $deadline->remaining());

        try {
            $deadline->check();
        } catch (ApiException $exception) {
            // check() throws a RenderException, which is an ApiException with status 400.
            self::assertInstanceOf(RenderException::class, $exception);
            self::assertSame(400, $exception->status);
            self::assertSame('Rendering timed out after 0 seconds', $exception->getMessage());

            return;
        }
        self::fail('Expected a RenderException');
    }

    #[Test]
    public function exceededReportsTheRoundedBudget(): void
    {
        self::assertSame('Rendering timed out after 45 seconds', Deadline::in(45.0)->exceeded()->getMessage());
        self::assertSame('Rendering timed out after 46 seconds', Deadline::in(45.6)->exceeded()->getMessage());
    }
}
