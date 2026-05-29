<?php
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
	 * Create (or upgrade) the security log table via dbDelta().
	 * Called by register_activation_hook.
	 */
	public static function install(): void {
		global $wpdb;

		$table      = $wpdb->prefix . 'svgss_security_log';
		$charset_db = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_type  VARCHAR(40)     NOT NULL,
			severity    VARCHAR(10)     NOT NULL DEFAULT 'info',
			filename    VARCHAR(255)    NOT NULL DEFAULT '',
			details     TEXT            NOT NULL,
			user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_login  VARCHAR(60)     NOT NULL DEFAULT '',
			ip_address  VARCHAR(45)     NOT NULL DEFAULT '',
			created_at  DATETIME        NOT NULL,
			PRIMARY KEY  (id),
			KEY severity (severity),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) {$charset_db};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a security log row.
	 *
	 * @param array{
	 *   event_type: string,
	 *   severity:   string,
	 *   filename:   string,
	 *   details:    string,
	 *   user_id:    int,
	 *   user_login: string,
	 *   ip_address: string,
	 * } $data
	 */
	public function insert_log( array $data ): bool {
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->prefix . 'svgss_security_log',
			[
				'event_type' => $data['event_type'],
				'severity'   => $data['severity'],
				'filename'   => $data['filename'],
				'details'    => $data['details'],
				'user_id'    => $data['user_id'],
				'user_login' => $data['user_login'],
				'ip_address' => $data['ip_address'],
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Delete log rows older than the given number of days.
	 *
	 * @return int Number of rows deleted.
	 */
	public function purge_old_logs( int $days ): int {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}svgss_security_log WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
				$days
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
}
