<?php

namespace App\Sandbox;

use DOMDocument;
use DOMElement;

/**
 * Keeps only drawing from an SVG: shapes, groups and gradients, without scripts, event handlers,
 * styles, embedded documents or links to anything outside the image.
 */
class SvgSanitizer
{
    public const MAX_BYTES = 100_000;

    protected const NAMESPACE = 'http://www.w3.org/2000/svg';

    protected const ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
        'defs', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'title', 'desc',
    ];

    protected const ATTRIBUTES = [
        'xmlns', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
        'd', 'points', 'transform', 'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width',
        'stroke-linecap', 'stroke-linejoin', 'stroke-opacity', 'stroke-dasharray', 'stroke-miterlimit', 'opacity',
        'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'fx', 'fy', 'id',
        'clip-path', 'mask', 'clippathunits', 'maskunits', 'preserveaspectratio',
    ];

    /**
     * The cleaned SVG, or null if it isn't a usable SVG.
     */
    public function clean(string $svg): ?string
    {
        if (strlen($svg) > self::MAX_BYTES || preg_match('/<!(DOCTYPE|ENTITY)/i', $svg)) {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            return null;
        }

        if (! in_array($root->namespaceURI, [null, self::NAMESPACE], true)) {
            return null;
        }

        $this->cleanElement($root);

        $clean = new DOMDocument;
        $svgRoot = $clean->createElementNS(self::NAMESPACE, 'svg');
        $clean->appendChild($svgRoot);
        $this->copyInto($clean, $root, $svgRoot);

        if (! $svgRoot->hasAttribute('viewBox')) {
            $width = (float) $svgRoot->getAttribute('width') ?: 64;
            $height = (float) $svgRoot->getAttribute('height') ?: 64;
            $svgRoot->setAttribute('viewBox', "0 0 {$width} {$height}");
        }

        return $clean->saveXML($svgRoot) ?: null;
    }

    /**
     * Drop disallowed children and attributes, depth first.
     */
    protected function cleanElement(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI !== $element->namespaceURI || ! in_array(strtolower($child->localName), self::ELEMENTS, true)) {
                    $element->removeChild($child);

                    continue;
                }

                $this->cleanElement($child);
            } elseif ($child->nodeType !== XML_TEXT_NODE || strtolower($element->localName) !== 'title') {
                $element->removeChild($child);
            }
        }
    }

    /**
     * Rebuild the tree in a fresh document with only allowed attributes, so nothing else carries over.
     */
    protected function copyInto(DOMDocument $document, DOMElement $from, DOMElement $to): void
    {
        foreach (iterator_to_array($from->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = trim($attribute->nodeValue ?? '');

            if ($attribute->namespaceURI !== null || ! in_array($name, self::ATTRIBUTES, true) || $name === 'xmlns') {
                continue;
            }

            if (preg_match('/javascript:|data:|url\(\s*[\'"]?(?!#)/i', $value)) {
                continue;
            }

            $to->setAttribute($attribute->nodeName, $value);
        }

        foreach ($from->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $copy = $document->createElementNS(self::NAMESPACE, $child->localName);
                $to->appendChild($copy);
                $this->copyInto($document, $child, $copy);
            } elseif ($child->nodeType === XML_TEXT_NODE) {
                $to->appendChild($document->createTextNode((string) $child->nodeValue));
            }
        }
    }
}
