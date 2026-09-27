<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Api\ApiException;

/**
 * DocRaptor's `strict: html`: report HTML parse errors instead of rendering.
 */
final class StrictHtmlValidator
{
    private const int LIMIT = 20;

    /**
     * @throws ApiException with status 422 listing the parse errors
     */
    public function validate(string $html): void
    {
        $errors = [];
        set_error_handler(static function (int $level, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });
        try {
            \Dom\HTMLDocument::createFromString($html, overrideEncoding: 'UTF-8');
        } finally {
            restore_error_handler();
        }
        if ([] === $errors) {
            return;
        }

        $messages = array_map(self::describe(...), \array_slice($errors, 0, self::LIMIT));
        throw new ApiException(422, ...$messages);
    }

    /**
     * "…: tree error unexpected-token-in-initial-mode in Entity, line: 1, column: 2-5"
     * becomes "line 1, column 2: unexpected-token-in-initial-mode".
     */
    private static function describe(string $warning): string
    {
        if (1 === preg_match('/(?:tree|tokenizer) error ([\w-]+) in \S+, line: (\d+), column: (\d+)/', $warning, $match)) {
            return "line {$match[2]}, column {$match[3]}: {$match[1]}";
        }

        return preg_replace('/^[\w\\\\]+::\w+\(\): /', '', $warning) ?? $warning;
    }
}
