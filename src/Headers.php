<?php
namespace CodePros\SVGSecureSupport;

defined( 'ABSPATH' ) || exit;

class Headers {

	private const DEFAULT_CSP = "default-src 'self'; script-src 'none'; object-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:;";

	/** @var self|null */
	private static ?self $instance = null;

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		// Sends X-Content-Type-Options: nosniff on every page.
		add_action( 'send_headers', array( $this, 'send_nosniff_header' ) );

		// Sends CSP + X-Frame-Options on SVG attachment pages.
		add_action( 'template_redirect', array( $this, 'maybe_send_svg_headers' ) );
	}

	/**
	 * Send X-Content-Type-Options: nosniff on all page loads.
	 * Prevents browsers from MIME-sniffing a response away from the declared type.
	 */
	public function send_nosniff_header(): void {
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * On SVG attachment pages, send a restrictive CSP and X-Frame-Options header.
	 * These act as a last-resort layer in case any active SVG content somehow
	 * slips through validation and sanitization.
	 */
	public function maybe_send_svg_headers(): void {
		if ( ! $this->is_svg_attachment_page() ) {
			return;
		}

		if ( ! get_option( 'svgss_csp_enabled', 1 ) ) {
			return;
		}

		$csp = (string) get_option( 'svgss_csp_header', self::DEFAULT_CSP );

		if ( ! empty( $csp ) ) {
			header( 'Content-Security-Policy: ' . $csp );
		}

		header( 'X-Frame-Options: SAMEORIGIN' );
	}

	// -------------------------------------------------------------------------

	private function is_svg_attachment_page(): bool {
		if ( ! is_attachment() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post ) {
			return false;
		}

		return 'image/svg+xml' === get_post_mime_type( $post->ID );
	}
}
