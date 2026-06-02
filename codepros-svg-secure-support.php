<?php
/**
 * Plugin Name:       CodePros SVG Secure Support
 * Plugin URI:        https://wordpress.org/plugins/codepros-svg-secure-support/
 * Description:       Highly secure SVG upload support for WordPress. Validates, sanitizes, and optionally rasterizes SVG files through a multi-layer security pipeline.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      7.0
 * Author:            CodePros
 * Author URI:        https://codepros.ai
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       codepros-svg-secure-support
 * Domain Path:       /languages
 * 
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'CODEPROS_SVGSS_VERSION',       '1.0.0' );
define( 'CODEPROS_SVGSS_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'CODEPROS_SVGSS_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'CODEPROS_SVGSS_PLUGIN_FILE',   __FILE__ );
define( 'CODEPROS_SVGSS_MAX_FILE_SIZE', 1048576 );  // 1 MB in bytes.
define( 'CODEPROS_SVGSS_MAX_XML_NODES', 5000 );
define( 'CODEPROS_SVGSS_MAX_DIMENSION', 10000 );

// Composer autoloader.
$svgss_autoloader = CODEPROS_SVGSS_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! file_exists( $svgss_autoloader ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p><strong>CodePros SVG Secure Support:</strong> ' .
				esc_html__( 'Composer dependencies are missing. Please run `composer install` in the plugin directory.', 'codepros-svg-secure-support' ) .
				'</p></div>';
		}
	);
	return;
}

require_once $svgss_autoloader;

// Activation: create DB log table.
register_activation_hook( __FILE__, array( CodePros\SVGSecureSupport\Database::class, 'install' ) );

// Deactivation
register_deactivation_hook( __FILE__, array( CodePros\SVGSecureSupport\Hooks::class, 'deactivate' ) );


// Bootstrap on plugins_loaded (after all plugins are loaded, text domain available).
add_action(
	'plugins_loaded',
	static function () {
		CodePros\SVGSecureSupport\Hooks::get_instance()->init();
		CodePros\SVGSecureSupport\Headers::get_instance()->init();
		CodePros\SVGSecureSupport\Admin\Admin::get_instance()->init();
	}
);

// Settings link on the Plugins list screen.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	static function ( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=codepros-svg-secure-support' ) ),
			esc_html__( 'Settings', 'codepros-svg-secure-support' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}
);
