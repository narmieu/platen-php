<?php

declare(strict_types=1);

namespace App\Rendering\WeasyPrint;

/**
 * The process rendering the document ended without an answer: its timeout killed it, or
 * it crashed or ran out of memory.
 */
final class WeasyPrintServerStopped extends \RuntimeException
{
}
