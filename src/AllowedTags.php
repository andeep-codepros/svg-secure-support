<?php
namespace CodePros\SVGSecureSupport;

use enshrined\svgSanitize\data\TagInterface;

defined( 'ABSPATH' ) || exit;

class AllowedTags implements TagInterface {

	/**
	 * Strict whitelist — intentionally excludes script, iframe, object, embed,
	 * foreignObject, style, link, meta, base, image, a, and form.
	 *
	 * @return array<string>
	 */
	public static function getTags(): array {
		return [
			'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line',
			'polyline', 'polygon', 'text', 'tspan', 'textPath',
			'defs', 'clipPath', 'linearGradient', 'radialGradient',
			'stop', 'use', 'symbol', 'title', 'desc',
		];
	}
}
