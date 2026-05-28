<?php
/**
 * Database table management for the SVG security log.
 * Full implementation delivered in Phase 3.
 */

namespace CodePros\SVGSecureSupport;

defined( 'ABSPATH' ) || exit;

class Database {

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
	 * Create (or upgrade) the security log table.
	 * Called by register_activation_hook. Implemented fully in Phase 3.
	 */
	public static function install(): void {
		// Phase 3: implement dbDelta() schema creation.
	}
}
