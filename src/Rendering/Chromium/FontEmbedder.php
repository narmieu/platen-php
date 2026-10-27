<?php

declare(strict_types=1);

namespace App\Rendering\Chromium;

use App\Config\PlatenConfig;
use App\Rendering\Deadline;
use App\Rendering\Origin;
use App\Rendering\RenderJob;
use App\Rendering\Source\HtmlDocument;
use App\Rendering\Url;
use Dom\Element;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Embeds the web fonts that Chromium would refuse to load.
 *
 * Gotenberg loads every page from a file, so the page's origin is "null", and Chromium
 * then loads a font only from a server that allows any origin with an
 * Access-Control-Allow-Origin header. Prince and WeasyPrint load fonts from anywhere, and
 * fonts on a document's own site rarely send that header, because the site's own pages
 * never needed it. platen reads the @font-face rules of the document's <style> elements
 * and stylesheets, requests each font the way Chromium would, and adds the rules again,
 * after the document's own, with the fonts Chromium would be refused as data: URLs.
 */
final readonly class FontEmbedder
{
    private const int MAX_STYLESHEETS = 20;
    /** Levels of stylesheets: linked or imported by a <style> element, then imported. */
    private const int MAX_DEPTH = 4;
    private const int MAX_FONTS = 60;
    private const int MAX_STYLESHEET_BYTES = 5 * 1024 * 1024;
    private const int MAX_FONT_BYTES = 10 * 1024 * 1024;
    private const int MAX_EMBEDDED_BYTES = 30 * 1024 * 1024;

    public function __construct(
        private PlatenConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return string @font-face rules for the end of the page, or '' when no font needs them
     */
    public function css(RenderJob $job): string
    {
        if (!$job->allowsNetwork()) {
            return '';
        }
        // Downloads may take part of the time left, and at most one asset timeout.
        $deadline = Deadline::in(min((float) $this->config->fetchTimeout, $job->deadline->remaining() / 2));
        $document = HtmlDocument::parse($job->html);
        $base = self::baseUrl($document, $job->baseUrl);

        // The page's stylesheets in order: <style> elements parsed, linked ones by URL.
        $sheets = [];
        foreach ($document->dom->querySelectorAll('style, link[href]') as $element) {
            if ('style' === $element->localName) {
                $sheets[] = StyleSheetFonts::parse($element->textContent ?? '', $base);
            } elseif (self::linksStyleSheet($element)) {
                $url = Url::resolve((string) $element->getAttribute('href'), $base);
                if (null !== $url && null !== Origin::of($url)) {
                    $sheets[] = $url;
                }
            }
        }

        // Download linked stylesheets, then what they import, one level at a time.
        $fetched = [];
        $pending = [];
        foreach ($sheets as $sheet) {
            array_push($pending, ...(\is_string($sheet) ? [$sheet] : $sheet->imports));
        }
        for ($depth = 1; $depth <= self::MAX_DEPTH && [] !== $pending; ++$depth) {
            $pending = \array_slice(array_values(array_diff(array_unique($pending), array_keys($fetched))), 0, max(0, self::MAX_STYLESHEETS - \count($fetched)));
            $fetched += $this->fetchStyleSheets($pending, $job, $deadline);
            $pending = [];
            foreach ($fetched as $sheet) {
                if (null !== $sheet) {
                    array_push($pending, ...$sheet->imports);
                }
            }
        }

        // Their @font-face rules in cascade order: a stylesheet's imports come first.
        $rules = [];
        $visited = [];
        foreach ($sheets as $sheet) {
            $this->collect($sheet, $fetched, $visited, $rules);
        }

        $fonts = [];
        foreach ($rules as $rule) {
            foreach ($rule->urls() as $url) {
                if (null !== Origin::of($url)) {
                    $fonts[$url] = true;
                }
            }
        }
        $dataUrls = $this->fetchFonts(\array_slice(array_keys($fonts), 0, self::MAX_FONTS), $job, $deadline);

        $css = [];
        foreach ($rules as $rule) {
            $css[] = $rule->withDataUrls($dataUrls);
        }

        return implode("\n", array_filter($css));
    }

    /**
     * @param array<string, StyleSheetFonts|null> $fetched stylesheets by URL, null if unavailable
     * @param array<string, true>                 $visited
     * @param list<FontFaceRule>                  $rules
     */
    private function collect(StyleSheetFonts|string $sheet, array $fetched, array &$visited, array &$rules): void
    {
        if (\is_string($sheet)) {
            if (isset($visited[$sheet]) || null === ($fetched[$sheet] ?? null)) {
                return;
            }
            $visited[$sheet] = true;
            $sheet = $fetched[$sheet];
        }
        foreach ($sheet->imports as $import) {
            $this->collect($import, $fetched, $visited, $rules);
        }
        array_push($rules, ...$sheet->fontFaces);
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, StyleSheetFonts|null> by URL, null for those that failed
     */
    private function fetchStyleSheets(array $urls, RenderJob $job, Deadline $deadline): array
    {
        $responses = [];
        foreach ($urls as $url) {
            $responses[$url] = $this->request($url, $job, $deadline, ['Accept' => 'text/css,*/*;q=0.1'], self::MAX_STYLESHEET_BYTES);
        }

        $sheets = [];
        foreach ($responses as $url => $response) {
            try {
                $sheets[$url] = self::succeeded($response) ? StyleSheetFonts::parse($response->getContent(), self::finalUrl($response, $url)) : null;
            } catch (ExceptionInterface) {
                $sheets[$url] = null;
            }
        }

        return $sheets;
    }

    /**
     * @param list<string> $urls
     *
     * @return array<string, string> data: URLs of the fonts Chromium would be refused, by URL
     */
    private function fetchFonts(array $urls, RenderJob $job, Deadline $deadline): array
    {
        $responses = [];
        foreach ($urls as $url) {
            // Chromium asks with this Origin; servers that allow it need no help.
            $responses[$url] = $this->request($url, $job, $deadline, ['Accept' => '*/*', 'Origin' => 'null'], self::MAX_FONT_BYTES);
        }

        $budget = self::MAX_EMBEDDED_BYTES;
        $dataUrls = [];
        foreach ($responses as $url => $response) {
            try {
                if (!self::succeeded($response) || self::allowsAnyOrigin($response)) {
                    $response->cancel();
                    continue;
                }
                $font = $response->getContent();
            } catch (ExceptionInterface) {
                continue;
            }
            if (\strlen($font) <= $budget) {
                $budget -= \strlen($font);
                $dataUrls[$url] = 'data:'.self::fontType($url).';base64,'.base64_encode($font);
            }
        }

        return $dataUrls;
    }

    /**
     * Requests an asset as Chromium would, through the egress proxy.
     *
     * @param array<string, string> $headers
     */
    private function request(string $url, RenderJob $job, Deadline $deadline, array $headers, int $limit): ResponseInterface
    {
        $headers['User-Agent'] = ChromiumEngine::browserUserAgent($job->userAgentToken);
        if (null !== $job->referrer) {
            $headers['Referer'] = $job->referrer;
        }
        // Chromium sends the credentials to the document's own origin only.
        if (null !== $job->credentials && null !== $job->baseOrigin() && Origin::of($url) === $job->baseOrigin()) {
            $headers['Authorization'] = $job->credentials->header();
        }

        return $this->httpClient->request('GET', $url, [
            'proxy' => $this->config->egressProxy,
            'no_proxy' => '',
            'headers' => $headers,
            'timeout' => (float) $this->config->fetchTimeout,
            'max_duration' => max(0.1, $deadline->remaining()),
            'max_redirects' => 10,
            'on_progress' => static function (int $downloaded) use ($limit): void {
                if ($downloaded > $limit) {
                    throw new TransportException('The file is too large to embed');
                }
            },
        ]);
    }

    private static function succeeded(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    private static function allowsAnyOrigin(ResponseInterface $response): bool
    {
        $allowed = trim($response->getHeaders(false)['access-control-allow-origin'][0] ?? '');

        return '*' === $allowed || 'null' === $allowed;
    }

    private static function finalUrl(ResponseInterface $response, string $url): string
    {
        $final = $response->getInfo('url');

        return \is_string($final) ? $final : $url;
    }

    /**
     * Chromium reads the format from the font itself; the type is for anyone reading the page.
     */
    private static function fontType(string $url): string
    {
        return match (strtolower(pathinfo((string) parse_url($url, \PHP_URL_PATH), \PATHINFO_EXTENSION))) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };
    }

    private static function linksStyleSheet(Element $element): bool
    {
        $rel = preg_split('/\s+/', strtolower(trim((string) $element->getAttribute('rel'))), -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return 'link' === $element->localName && \in_array('stylesheet', $rel, true) && !\in_array('alternate', $rel, true);
    }

    /**
     * Where the page's relative URLs resolve: its own <base href>, else the job's base URL.
     */
    private static function baseUrl(HtmlDocument $document, ?string $jobBase): ?string
    {
        $href = $document->dom->querySelector('base[href]')?->getAttribute('href');

        return null === $href ? $jobBase : (Url::resolve($href, $jobBase) ?? $jobBase);
    }
}
