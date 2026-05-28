<?php
/**
 * Admin settings page and security log viewer.
 * Full implementation delivered in Phase 6.
 */

namespace CodePros\SVGSecureSupport\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {

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
	 * Register hooks. Implemented fully in Phase 6.
	 */
	public function init(): void {
		// Phase 6: register admin_menu, admin_init, admin_notices hooks.
	}
}
