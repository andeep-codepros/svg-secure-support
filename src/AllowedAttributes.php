<?php
namespace CodePros\SVGSecureSupport;

use enshrined\svgSanitize\data\AttributeInterface;

defined( 'ABSPATH' ) || exit;

class AllowedAttributes implements AttributeInterface {

	/**
	 * @return array<string>
	 */
	public static function getAttributes(): array {
		return [
			// Presentation
			'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
			'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
			'fill-opacity', 'stroke-opacity', 'opacity',
			// Dimensions / viewport
			'width', 'height', 'viewBox', 'preserveAspectRatio',
			// Geometry
			'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'points',
			// Transform / identity
			'transform', 'id', 'class', 'style',
			// Gradient / pattern
			'offset', 'stop-color', 'stop-opacity',
			'gradientUnits', 'gradientTransform', 'spreadMethod',
			'patternUnits', 'patternTransform', 'fx', 'fy',
			// References — enshrined's removeRemoteReferences(true) blocks external values
			'href', 'xlink:href',
			// Clipping / masking / filter
			'clip-path', 'mask', 'filter',
			// Text
			'font-size', 'font-family', 'font-weight', 'text-anchor',
			'letter-spacing', 'word-spacing',
			// Markers
			'marker-start', 'marker-mid', 'marker-end',
		];
	}
}
