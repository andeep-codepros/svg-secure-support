<?php
namespace CodePros\SVGSecureSupport;

use enshrined\svgSanitize\data\TagInterface;

defined( 'ABSPATH' ) || exit;

class AllowedTags implements TagInterface {

	/**
	 * SVG tag whitelist (permissive mode).
	 *
	 * Always excluded: script, iframe, object, embed, a, style, link, meta,
	 * base, form — direct XSS or resource-load vectors with no safe use in SVG.
	 *
	 * foreignObject: allowed in permissive mode. Any HTML content inside it is
	 * stripped by the whitelist (non-SVG tags are not listed here), and the
	 * Sanitizer's post-scan backstop blocks surviving HTML injection patterns.
	 *
	 * image / feImage: safe — removeRemoteReferences(true) strips external hrefs;
	 * scan_for_payloads() blocks embedded SVG data URIs.
	 * filter / fe*: pixel manipulation only, no code execution surface.
	 * animate / set: attribute / value animation with no script execution path.
	 *
	 * @return array<string>
	 */
	public static function getTags(): array {
		return [
			// Core structure
			'svg', 'g', 'defs', 'symbol', 'use', 'switch',

			// Embedded foreign content (permissive — HTML content stripped by whitelist;
			// surviving injection vectors caught by scan_for_payloads backstop)
			'foreignObject',

			// Shapes
			'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',

			// Text
			'text', 'tspan', 'textPath', 'tref',

			// Images & patterns
			'image', 'pattern',

			// Gradients & paint
			'linearGradient', 'radialGradient', 'stop',
			'marker',

			// Clipping & masking
			'clipPath', 'mask',

			// Filters
			'filter',
			'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite',
			'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap',
			'feDistantLight', 'feFlood',
			'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR',
			'feGaussianBlur', 'feImage', 'feMerge', 'feMergeNode', 'feMorphology',
			'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight',
			'feTile', 'feTurbulence',

			// Animation (motion, transform, attribute value, value-set — no script surface)
			'animate', 'animateMotion', 'animateTransform', 'set', 'mpath',

			// Typography (legacy SVG fonts — rendering only)
			'font', 'glyph', 'glyphRef', 'hkern', 'vkern',
			'altGlyph', 'altGlyphDef', 'altGlyphItem',

			// Metadata / accessibility (no execution surface)
			'title', 'desc', 'metadata',

			// Viewport
			'view',

			// Text content node
			'#text',
		];
	}
}
