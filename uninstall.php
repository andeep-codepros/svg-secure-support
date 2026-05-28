<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Removes all plugin options and the security log database table.
 * 
 */

// Only run when WordPress triggers uninstall; abort if called directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove all plugin options.
$option_keys = array(
	'svgss_upload_capability',
	'svgss_allowed_roles',
	'svgss_max_file_size_kb',
	'svgss_max_xml_nodes',
	'svgss_max_dimension_px',
	'svgss_strip_style_tags',
	'svgss_strip_xml_comments',
	'svgss_logging_enabled',
	'svgss_log_to_wp_debug',
	'svgss_log_to_database',
	'svgss_log_retention_days',
	'svgss_log_level',
	'svgss_csp_enabled',
	'svgss_csp_header',
	'svgss_clamav_enabled',
	'svgss_clamav_path',
	'svgss_rasterize_mode',
	'svgss_rasterize_format',
	'svgss_db_version',
);

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// Drop the security log table.
$table_name = esc_sql( $wpdb->prefix . 'svgss_security_log' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
