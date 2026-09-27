<?php

declare(strict_types=1);

namespace App\Rendering\Source;

use Dom\Element;
use Dom\HTMLDocument as DomDocument;
use Dom\Node;

/**
 * An HTML document parsed with PHP's HTML5 parser, re-serialized as UTF-8.
 */
final readonly class HtmlDocument
{
    private function __construct(public DomDocument $dom)
    {
    }

    /**
     * @param string|null $encoding the transport encoding (e.g. from a Content-Type header);
     *                              null lets the parser sniff <meta charset> and fall back to UTF-8
     */
    public static function parse(string $html, ?string $encoding = 'UTF-8'): self
    {
        try {
            $dom = DomDocument::createFromString($html, \LIBXML_NOERROR, $encoding);
        } catch (\ValueError) {
            $dom = DomDocument::createFromString($html, \LIBXML_NOERROR);
        }

        return new self($dom);
    }

    public function head(): Element
    {
        $head = $this->dom->head;
        if (null === $head) {
            $head = $this->dom->createElement('head');
            $this->dom->documentElement?->prepend($head);
        }

        return $head;
    }

    /**
     * The body, or the root element of a frameset document.
     */
    public function body(): Element
    {
        return $this->dom->body ?? $this->dom->documentElement ?? throw new \LogicException('The HTML parser always creates a root element');
    }

    /**
     * @param array<string, string> $attributes
     */
    public function element(string $name, string $text = '', array $attributes = []): Element
    {
        $element = $this->dom->createElement($name);
        foreach ($attributes as $attribute => $value) {
            $element->setAttribute($attribute, $value);
        }
        if ('' !== $text) {
            $element->textContent = $text;
        }

        return $element;
    }

    public function hasBase(): bool
    {
        return null !== $this->dom->querySelector('base[href]');
    }

    public function setTitle(string $title): void
    {
        $this->dom->title = $title;
    }

    /**
     * Replaces each matching element with its children.
     */
    public function unwrap(string $selector): void
    {
        foreach ($this->dom->querySelectorAll($selector) as $element) {
            $element->replaceWith(...iterator_to_array($element->childNodes));
        }
    }

    /**
     * Serializes as UTF-8, declared by a <meta charset> that replaces any other declaration.
     * A document without a doctype gets one, so Chromium lays it out in standards mode like
     * Prince and WeasyPrint instead of quirks mode.
     */
    public function toHtml(): string
    {
        foreach ($this->dom->querySelectorAll('meta[charset], meta[http-equiv]') as $meta) {
            $httpEquiv = strtolower(trim((string) $meta->getAttribute('http-equiv')));
            if ($meta->hasAttribute('charset') || 'content-type' === $httpEquiv) {
                $meta->remove();
            }
        }
        $this->prependToHead($this->element('meta', attributes: ['charset' => 'utf-8']));
        $this->dom->charset = 'UTF-8';
        $html = $this->dom->saveHtml();

        return null === $this->dom->doctype ? "<!DOCTYPE html>\n".$html : $html;
    }

    public function prependToHead(Node ...$nodes): void
    {
        $this->head()->prepend(...$nodes);
    }
}
