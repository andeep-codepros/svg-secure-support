<?php
namespace CodePros\SVGSecureSupport;

use enshrined\svgSanitize\data\TagInterface;

defined( 'ABSPATH' ) || exit;

class AllowedTags implements TagInterface {

	/**
	 * Safe SVG tag whitelist.
	 *
	 * Excluded (never safe): script, iframe, object, embed, foreignObject,
	 * a, style, link, meta, base, form — all XSS / resource-load vectors.
	 *
	 * image: safe because removeRemoteReferences(true) strips external href values
	 * and scan_for_payloads() blocks embedded SVG data URIs.
	 * filter / fe* tags: safe — pixel-manipulation only, no code execution surface.
	 * animation tags: safe — motion/transform only, no script execution.
	 *
	 * @return array<string>
	 */
	public static function getTags(): array {
		return [
			// Core structure
			'svg', 'g', 'defs', 'symbol', 'use', 'switch',

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
			'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology',
			'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight',
			'feTile', 'feTurbulence',

			// Animation (transform/motion only — no script surface)
			'animateMotion', 'animateTransform', 'mpath',

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
