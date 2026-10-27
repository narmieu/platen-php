<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Rendering\Pdf\InfoDictionary;

final readonly class EngineOutput
{
    public function __construct(
        /** The PDF, inside the render's workspace. */
        public string $pdf,
        /** A replacement for the PDF's information dictionary, if it needs one. */
        public ?InfoDictionary $info = null,
        /** Decompress the PDF afterwards: no_compress was asked for, and the engine cannot do it itself. */
        public bool $decompress = false,
    ) {
    }
}
