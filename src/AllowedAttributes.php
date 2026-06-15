<?php
namespace CodePros\SVGSecureSupport;

use enshrined\svgSanitize\data\AttributeInterface;

defined( 'ABSPATH' ) || exit;

class AllowedAttributes implements AttributeInterface {

	/**
	 * Safe SVG attribute whitelist.
	 *
	 * Excluded: on* event handlers (script execution), href/xlink:href pointing
	 * to javascript: URIs (blocked by enshrined + our scan_for_payloads backstop),
	 * and HTML-specific attributes with no SVG meaning.
	 *
	 * @return array<string>
	 */
	public static function getAttributes(): array {
		return [
			// Identity / structure
			'id', 'class', 'style', 'lang', 'tabindex',
			'xmlns', 'xmlns:xlink', 'xml:id', 'xml:space',

			// Viewport & dimensions
			'width', 'height', 'viewBox', 'preserveAspectRatio',
			'x', 'y', 'x1', 'y1', 'x2', 'y2',
			'cx', 'cy', 'r', 'rx', 'ry',
			'dx', 'dy',

			// Geometry
			'd', 'points', 'pathLength',

			// Transform
			'transform',

			// References — removeRemoteReferences(true) blocks external URLs;
			// scan_for_payloads() blocks javascript: and data:image/svg URIs.
			'href', 'xlink:href', 'xlink:title',

			// Fill & stroke
			'fill', 'fill-opacity', 'fill-rule',
			'stroke', 'stroke-width', 'stroke-opacity',
			'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
			'stroke-dasharray', 'stroke-dashoffset',
			'paint-order', 'color',

			// Opacity & visibility
			'opacity', 'visibility', 'display', 'overflow',

			// Gradients
			'offset', 'stop-color', 'stop-opacity',
			'gradientUnits', 'gradientTransform', 'spreadMethod', 'fx', 'fy',

			// Patterns
			'patternUnits', 'patternContentUnits', 'patternTransform',

			// Markers
			'marker-start', 'marker-mid', 'marker-end',
			'markerWidth', 'markerHeight', 'markerUnits',
			'refX', 'refY', 'orient',

			// Clipping / masking
			'clip-path', 'clip-rule', 'mask',
			'maskUnits', 'maskContentUnits',

			// Filters
			'filter', 'filterUnits',
			'in', 'in2', 'result', 'mode',
			'stdDeviation', 'edgeMode', 'bias', 'divisor',
			'order', 'kernelMatrix', 'kernelUnitLength',
			'targetX', 'targetY', 'preserveAlpha',
			'xChannelSelector', 'yChannelSelector',
			'scale', 'operator', 'radius',
			'k1', 'k2', 'k3', 'k4',
			'azimuth', 'elevation',
			'diffuseConstant', 'specularConstant', 'specularExponent',
			'surfaceScale', 'lightingColor',
			'flood-color', 'flood-opacity',
			'baseFrequency', 'numOctaves', 'seed', 'stitchTiles',
			'type', 'values', 'slope',

			// Text / font
			'font-family', 'font-size', 'font-size-adjust', 'font-stretch',
			'font-style', 'font-variant', 'font-weight',
			'text-anchor', 'text-decoration', 'text-rendering',
			'letter-spacing', 'word-spacing', 'writing-mode',
			'direction', 'dominant-baseline', 'baseline-shift',
			'alignment-baseline', 'lengthAdjust', 'textLength',

			// Legacy SVG font attributes (rendering only)
			'accent-height', 'ascent', 'g1', 'g2', 'glyph-name', 'glyphRef',
			'u1', 'u2', 'unicode', 'horiz-adv-x', 'vert-adv-y',
			'vert-origin-x', 'vert-origin-y', 'k', 'kerning',

			// Colour rendering
			'color-interpolation', 'color-interpolation-filters',
			'color-profile', 'color-rendering',
			'image-rendering', 'shape-rendering',

			// Animation (transform/motion, no script surface)
			'attributeName', 'attributeType',
			'begin', 'end', 'dur', 'min', 'max',
			'repeatCount', 'repeatDur', 'restart',
			'calcMode', 'keyPoints', 'keySplines', 'keyTimes',
			'accumulate', 'additive', 'by', 'from', 'to',
			'rotate', 'origin', 'path',

			// Viewport / view
			'viewTarget', 'zoomAndPan',

			// Metadata / accessibility
			'title', 'role', 'name', 'local',
			'media', 'version',

			// Vector effect
			'vector-effect',
		];
	}
}
