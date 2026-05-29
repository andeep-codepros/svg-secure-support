<?php
namespace CodePros\SVGSecureSupport;

use enshrined\svgSanitize\Sanitizer as EnshrinedSanitizer;

defined( 'ABSPATH' ) || exit;

class Sanitizer {

	/** @var self|null */
	private static ?self $instance = null;

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Sanitize an SVG file in place.
	 */
	public function sanitize_file( string $file_path ): array {
		$raw = file_get_contents( $file_path );
		if ( false === $raw ) {
			return $this->fail( 'Could not read SVG file.', [], [] );
		}

		$lib = new EnshrinedSanitizer();
		$lib->removeRemoteReferences( true );
		$lib->setAllowedTags( new AllowedTags() );
		$lib->setAllowedAttrs( new AllowedAttributes() );

		$clean      = $lib->sanitize( $raw );
		$xml_issues = $lib->getXmlIssues();

		if ( false === $clean || '' === $clean ) {
			return $this->fail(
				'SVG sanitization failed — file could not be parsed as valid XML.',
				$xml_issues,
				[]
			);
		}

		// Final string-level safety net — catches anything that survived DOM traversal.
		$suspicious = $this->scan_for_payloads( $clean );
		if ( ! empty( $suspicious ) ) {
			return $this->fail(
				'SVG contains suspicious payloads after sanitization.',
				$xml_issues,
				$suspicious
			);
		}

		if ( false === file_put_contents( $file_path, $clean ) ) {
			return $this->fail( 'Could not write sanitized SVG.', $xml_issues, [] );
		}

		return [
			'success'             => true,
			'xml_issues'          => $xml_issues,
			'suspicious_payloads' => [],
			'error'               => '',
		];
	}

	/**
	 * Scan sanitized SVG content for known XSS payload patterns.
	 * These run after library sanitization as a defense-in-depth backstop.
	 */
	private function scan_for_payloads( string $content ): array {
		$found = [];

		$patterns = [
			'/javascript\s*:/i'  => 'javascript: URI',
			'/<script[\s>\/]/i'  => '<script> tag',
			'/on\w+\s*=/i'       => 'event handler attribute',
			'/expression\s*\(/i' => 'CSS expression()',
		];

		foreach ( $patterns as $pattern => $label ) {
			if ( preg_match( $pattern, $content ) ) {
				$found[] = $label;
			}
		}

		return $found;
	}
	
	private function fail( string $error, array $xml_issues, array $suspicious ): array {
		return [
			'success'             => false,
			'xml_issues'          => $xml_issues,
			'suspicious_payloads' => $suspicious,
			'error'               => $error,
		];
	}
}
