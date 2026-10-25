<?php

namespace App\Email\Templates;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Allowlist policy for e-mail HTML. issues() reports violations of a
 * template source (publishing is refused while there are any);
 * sanitize() removes everything outside the allowlist from rendered HTML
 * (defense in depth: preview and send use the same output).
 *
 * Refused: script, iframe, frame, object, embed, form and inputs, base,
 * link, svg/math, meta http-equiv; on* event handlers; URLs other than
 * https:, mailto:, tel:, "#" (no javascript:, data:, http:); CSS with
 * expression(), javascript:/vbscript:, behavior, -moz-binding, @import or
 * a non-https url(). A URL attribute may be a single template URL
 * variable ({{ unsubscribe_url }}), which the renderer forces to https.
 */
final class EmailHtmlPolicy
{
    private const TAGS = ['html', 'head', 'body', 'meta', 'title', 'style', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'col', 'colgroup',
        'div', 'span', 'p', 'a', 'img', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'small', 'big', 'center', 'font',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'sup', 'sub', 'section', 'header', 'footer', 'article', 'main', 'nav', 'picture', 'source'];

    // Removed with their content.
    private const DROP_WITH_CONTENT = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea',
        'base', 'link', 'svg', 'math', 'noscript', 'template', 'video', 'audio', 'canvas'];

    private const ATTRIBUTES = ['style', 'class', 'id', 'align', 'valign', 'width', 'height', 'bgcolor', 'background', 'border', 'cellpadding', 'cellspacing',
        'colspan', 'rowspan', 'alt', 'title', 'href', 'src', 'srcset', 'target', 'dir', 'lang', 'role', 'color', 'face', 'size', 'charset', 'name', 'content',
        'media', 'type', 'span', 'start', 'scope', 'aria-label', 'aria-hidden'];

    private const URL_ATTRIBUTES = ['href', 'src', 'background', 'srcset'];

    /**
     * @return list<string> violation codes (empty = allowed)
     */
    public function issues(string $html): array
    {
        $issues = [];
        $doc = $this->parse($html);
        foreach ((new DOMXPath($doc))->query('//*') ?: [] as $el) {
            /** @var DOMElement $el */
            $tag = strtolower($el->nodeName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $issues[] = 'TAG_'.strtoupper($tag);

                continue;
            }
            if (! in_array($tag, self::TAGS, true)) {
                $issues[] = 'DISALLOWED_TAG';
            }
            if ($tag === 'meta' && $el->hasAttribute('http-equiv')) {
                $issues[] = 'META_HTTP_EQUIV';
            }
            if ($tag === 'style' && ! $this->safeCss($el->textContent)) {
                $issues[] = 'UNSAFE_CSS';
            }
            foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                $name = strtolower($attr->nodeName);
                if (str_starts_with($name, 'on')) {
                    $issues[] = 'EVENT_HANDLER';
                } elseif (! in_array($name, self::ATTRIBUTES, true) && $name !== 'http-equiv') {
                    $issues[] = 'DISALLOWED_ATTRIBUTE';
                } elseif (in_array($name, self::URL_ATTRIBUTES, true) && ! $this->safeUrlAttribute($attr->nodeValue ?? '')) {
                    $issues[] = preg_match('/^\s*(javascript|vbscript|data):/i', (string) $attr->nodeValue) === 1 ? 'UNSAFE_URL' : 'INSECURE_URL';
                } elseif ($name === 'style' && ! $this->safeCss((string) $attr->nodeValue)) {
                    $issues[] = 'UNSAFE_CSS';
                }
            }
        }

        return array_values(array_unique($issues));
    }

    public function sanitize(string $html): string
    {
        $doc = $this->parse($html);
        $xpath = new DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//*') ?: []) as $el) {
            /** @var DOMElement $el */
            if ($el->parentNode === null) {
                continue;
            }
            $tag = strtolower($el->nodeName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true) || ($tag === 'meta' && $el->hasAttribute('http-equiv')) || ($tag === 'style' && ! $this->safeCss($el->textContent))) {
                $el->parentNode->removeChild($el);

                continue;
            }
            if (! in_array($tag, self::TAGS, true)) {
                $this->unwrap($el);

                continue;
            }
            foreach (iterator_to_array($el->attributes ?? []) as $attr) {
                $name = strtolower($attr->nodeName);
                $value = (string) $attr->nodeValue;
                $keep = ! str_starts_with($name, 'on') && in_array($name, self::ATTRIBUTES, true)
                    && (! in_array($name, self::URL_ATTRIBUTES, true) || $this->safeUrl($value))
                    && ($name !== 'style' || $this->safeCss($value));
                if (! $keep) {
                    $el->removeAttribute($attr->nodeName);
                }
            }
            if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
                $el->setAttribute('rel', 'noopener noreferrer');
            }
        }

        $out = (string) $doc->saveHTML();

        return (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $out);
    }

    public function safeUrl(string $url): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#')) {
            return true;
        }
        if (str_contains($url, ',') && preg_match('/\s\d+[wx]/', $url) === 1) { // srcset
            foreach (explode(',', $url) as $part) {
                if (! $this->safeUrl(strtok(trim($part), ' ') ?: '')) {
                    return false;
                }
            }

            return true;
        }

        return preg_match('/[\x00-\x1f]/', $url) !== 1
            && (str_starts_with(strtolower($url), 'https://') || preg_match('/^(mailto|tel):[^\s]+$/i', $url) === 1);
    }

    private function safeUrlAttribute(string $value): bool
    {
        $urlVariables = implode('|', array_map(fn ($v) => preg_quote((string) $v, '/'), (array) config('email.templates.url_variables')));

        return $this->safeUrl($value) || preg_match('/^\s*\{\{\s*('.$urlVariables.')\s*\}\}\s*$/', $value) === 1;
    }

    private function safeCss(string $css): bool
    {
        $css = strtolower(html_entity_decode($css, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $css = (string) preg_replace('/\/\*.*?\*\//s', '', $css);
        $compact = (string) preg_replace('/[\s\\\\]+/', '', $css);
        if (preg_match('/expression\(|javascript:|vbscript:|behavior:|-moz-binding|@import/', $compact) === 1) {
            return false;
        }
        preg_match_all('/url\(\s*[\'"]?([^\'")]*)/', $css, $m);
        foreach ($m[1] as $url) {
            if (! str_starts_with(trim($url), 'https://')) {
                return false;
            }
        }

        return true;
    }

    private function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        while ($el->firstChild !== null) {
            $parent?->insertBefore($el->firstChild, $el);
        }
        $parent?->removeChild($el);
    }

    private function parse(string $html): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach (iterator_to_array($doc->childNodes) as $node) {
            /** @var DOMNode $node */
            if ($node->nodeType === XML_PI_NODE) {
                $doc->removeChild($node);
            }
        }

        return $doc;
    }
}
