<?php

declare(strict_types=1);

namespace App\Rendering\Chromium;

use App\Rendering\PageStyles;
use App\Rendering\RenderJob;
use App\Rendering\Source\HtmlDocument;

/**
 * Turns a document into the page Chromium prints: Prince's page defaults, the test
 * watermark, JavaScript switched off or on, and the hook that reports back to platen.
 */
final class PagePreparer
{
    /** How long a JavaScript page may keep loading data before it is printed anyway. */
    private const int SETTLE_MS = 10_000;

    /** How long a JavaScript page must be quiet to count as loaded. */
    private const int QUIET_MS = 500;

    private static ?string $hook = null;

    public function __construct(private readonly float $javascriptTimeout)
    {
    }

    /**
     * @param string $token   random per render: the CSP nonce and the report marker
     * @param string $fontCss @font-face rules that embed fonts (see FontEmbedder)
     */
    public function prepare(RenderJob $job, string $token, string $fontCss = ''): string
    {
        $document = HtmlDocument::parse($job->html);
        if (!$job->javascript) {
            // Prince shows <noscript> content when JavaScript is off; Chromium, which
            // still runs platen's own script, would not.
            $document->unwrap('noscript');
            // A page's CSP does not reach into frames, a sandbox does. Its tokens are
            // case-insensitive.
            foreach ($document->dom->querySelectorAll('iframe') as $frame) {
                $tokens = preg_split('/\s+/', trim((string) $frame->getAttribute('sandbox')), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
                $tokens = array_filter($tokens, static fn (string $token): bool => 'allow-scripts' !== strtolower($token));
                $frame->setAttribute('sandbox', implode(' ', $tokens));
            }
        }

        $head = [];
        $policy = self::contentSecurityPolicy($job, $token);
        if (null !== $policy) {
            $head[] = $document->element('meta', attributes: ['http-equiv' => 'Content-Security-Policy', 'content' => $policy]);
        }
        $head[] = $document->element('script', $this->hook($job, $token), $job->javascript ? [] : ['nonce' => $token]);
        if (null !== $job->baseUrl && !$document->hasBase()) {
            $head[] = $document->element('base', attributes: ['href' => $job->baseUrl]);
        }
        $head[] = $document->element('style', $job->pageCss, ['data-platen' => 'defaults']);
        $document->prependToHead(...$head);

        if (null !== $job->options->pdfTitle) {
            $document->setTitle($job->options->pdfTitle);
        }
        if ('' !== $fontCss) {
            // Last, so these rules win over the document's own for the same faces. The
            // CSS comes from stylesheets, so "<" is escaped to keep it from closing the
            // <style> element.
            $document->body()->append($document->element('style', str_replace('<', '\3c ', $fontCss), ['data-platen' => 'fonts']));
        }
        if ($job->watermark) {
            $document->body()->append($document->element('style', PageStyles::WATERMARK, ['data-platen' => 'watermark']));
        }

        return $document->toHtml();
    }

    public static function marker(string $token): string
    {
        return "[platen:{$token}] ";
    }

    /**
     * JavaScript "off" means only platen's nonce-bearing script runs. no_network allows
     * nothing but inline and data: resources. A nonce would disable 'unsafe-inline', so
     * pages that run their own scripts get none.
     */
    private static function contentSecurityPolicy(RenderJob $job, string $token): ?string
    {
        $scripts = $job->javascript ? "'unsafe-inline' 'unsafe-eval' data: blob:" : "'nonce-{$token}'";
        if ($job->allowsNetwork()) {
            return $job->javascript ? null : "script-src {$scripts}";
        }

        $directives = [
            "default-src 'none'",
            "script-src {$scripts}",
            "style-src 'unsafe-inline' data:",
            'img-src data: blob:',
            'font-src data:',
            'media-src data: blob:',
        ];
        if ($job->javascript) {
            $directives[] = 'connect-src data: blob:';
        }

        return implode('; ', $directives);
    }

    private function hook(RenderJob $job, string $token): string
    {
        $config = json_encode([
            'marker' => self::marker($token),
            'javascript' => $job->javascript,
            'javascriptTimeout' => (int) ($this->javascriptTimeout * 1000),
            // Finish a little before the deadline so the report still arrives.
            'renderTimeout' => (int) max(1000, ($job->deadline->remaining() - 1.5) * 1000),
            'settleTimeout' => self::SETTLE_MS,
            'quietPeriod' => self::QUIET_MS,
        ], \JSON_THROW_ON_ERROR | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_UNESCAPED_SLASHES);

        self::$hook ??= (string) file_get_contents(__DIR__.'/hook.js');

        return str_replace('__PLATEN_CONFIG__', $config, self::$hook);
    }
}
