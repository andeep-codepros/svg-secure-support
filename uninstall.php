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
	'cpsvgss_upload_capability',
	'cpsvgss_allowed_roles',
	'cpsvgss_max_file_size_kb',
	'cpsvgss_max_xml_nodes',
	'cpsvgss_max_dimension_px',
	'cpsvgss_strip_style_tags',
	'cpsvgss_strip_xml_comments',
	'cpsvgss_logging_enabled',
	'cpsvgss_log_to_wp_debug',
	'cpsvgss_log_to_database',
	'cpsvgss_log_retention_days',
	'cpsvgss_log_level',
	'cpsvgss_csp_enabled',
	'cpsvgss_csp_header',
	'cpsvgss_db_version',
);

foreach ( $option_keys as $key ) {
	delete_option( $key );
}

// Drop the security log table.
$table_name = esc_sql( $wpdb->prefix . 'cpsvgss_security_log' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
