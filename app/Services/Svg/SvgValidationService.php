<?php

namespace App\Services\Svg;

use App\Exceptions\PicToSvgException;
use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Verifies an export before it is stored or served: well-formed XML, an allow-list of
 * elements and attributes (no scripts, images, links, styles or external references),
 * real path geometry, and physical dimensions that match the viewBox aspect ratio.
 * Counts are measured from the document itself.
 */
class SvgValidationService
{
    private const ELEMENTS = [
        'svg' => ['xmlns', 'version', 'width', 'height', 'viewBox'],
        'g' => ['fill', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linejoin'],
        'path' => ['d'],
        'rect' => ['width', 'height', 'fill'],
    ];

    /**
     * @return array{paths: int, subpaths: int, nodes: int, open_subpaths: int, pixel_width: int, pixel_height: int, width: float, height: float, unit: string}
     */
    public function validate(string $svg): array
    {
        if (stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<!ENTITY') !== false) {
            throw PicToSvgException::invalidSvg();
        }
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->documentElement;
        if (! $ok || ! $root instanceof DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            throw PicToSvgException::invalidSvg();
        }

        if (preg_match('/\A0 0 ([1-9]\d*) ([1-9]\d*)\z/', $root->getAttribute('viewBox'), $vb) !== 1
            || preg_match('/\A(\d+(?:\.\d+)?)(mm|in)\z/', $root->getAttribute('width'), $w) !== 1
            || preg_match('/\A(\d+(?:\.\d+)?)(mm|in)\z/', $root->getAttribute('height'), $h) !== 1
            || $w[2] !== $h[2] || (float) $w[1] <= 0 || (float) $h[1] <= 0) {
            throw PicToSvgException::invalidSvg();
        }
        $expectedHeight = (float) $w[1] * (int) $vb[2] / (int) $vb[1];
        if (abs($expectedHeight - (float) $h[1]) > max(0.001, $expectedHeight * 0.001)) {
            throw PicToSvgException::invalidSvg();
        }

        $paths = 0;
        $subpathCount = 0;
        $nodes = 0;
        $open = 0;
        foreach ($doc->getElementsByTagName('*') as $el) {
            /** @var DOMElement $el */
            $allowed = self::ELEMENTS[$el->localName] ?? null;
            if ($allowed === null || $el->namespaceURI !== 'http://www.w3.org/2000/svg') {
                throw PicToSvgException::invalidSvg();
            }
            foreach ($el->attributes as $attr) {
                if (! in_array($attr->nodeName, $allowed, true) || preg_match('/url\s*\(|javascript:|data:|&/i', $attr->value) === 1) {
                    throw PicToSvgException::invalidSvg();
                }
            }
            if ($el->localName === 'path') {
                try {
                    $subpaths = PathGeometry::parse($el->getAttribute('d'));
                } catch (Throwable) {
                    throw PicToSvgException::invalidSvg();
                }
                if ($subpaths === []) {
                    throw PicToSvgException::invalidSvg();
                }
                $paths++;
                foreach ($subpaths as $sp) {
                    $subpathCount++;
                    $nodes += PathGeometry::nodeCount($sp);
                    $open += $sp['closed'] ? 0 : 1;
                }
            }
        }
        if ($paths === 0) {
            throw PicToSvgException::invalidSvg();
        }

        return [
            'paths' => $paths,
            'subpaths' => $subpathCount,
            'nodes' => $nodes,
            'open_subpaths' => $open,
            'pixel_width' => (int) $vb[1],
            'pixel_height' => (int) $vb[2],
            'width' => (float) $w[1],
            'height' => (float) $h[1],
            'unit' => $w[2],
        ];
    }
}
