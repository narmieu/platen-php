<?php

declare(strict_types=1);

namespace App\Rendering;

use App\Api\ApiException;

/**
 * Stylesheets both engines add to every document.
 */
final class PageStyles
{
    /**
     * Prince's initial page: US Letter with 54pt margins. Documents that set their own
     * page size or margins override it, exactly as they do in Prince.
     */
    public const string PRINCE_DEFAULTS = '@page { size: letter; margin: 54pt; }';

    public const string WATERMARK_TEXT = 'TEST DOCUMENT';

    public const string WATERMARK = <<<'CSS'
        html::after {
          content: "TEST DOCUMENT" !important;
          display: block !important;
          position: fixed !important;
          top: 40% !important;
          left: 0 !important;
          right: 0 !important;
          margin: 0 !important;
          padding: 0 !important;
          text-align: center !important;
          font: bold 42pt/1 "DejaVu Sans", "Liberation Sans", Arial, sans-serif !important;
          letter-spacing: 2pt !important;
          white-space: nowrap !important;
          color: rgba(200, 30, 30, 0.2) !important;
          background: none !important;
          transform: rotate(-30deg) !important;
          z-index: 2147483647 !important;
          pointer-events: none !important;
        }
        CSS;

    private const string LENGTH = '-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|pt|pc|mm|cm|in|q|em|rem|%)?';

    /**
     * The page defaults, plus DocRaptor's page_margin option when it is set.
     *
     * @throws ApiException when page_margin is not one to four CSS lengths
     */
    public static function page(?string $pageMargin): string
    {
        if (null === $pageMargin) {
            return self::PRINCE_DEFAULTS;
        }
        $length = self::LENGTH;
        if (1 !== preg_match("/^\\s*{$length}(?:\\s+{$length}){0,3}\\s*$/i", $pageMargin)) {
            throw ApiException::badRequest('prince_options.page_margin must be one to four CSS lengths');
        }

        return self::PRINCE_DEFAULTS."\n@page { margin: ".trim($pageMargin).'; }';
    }
}
