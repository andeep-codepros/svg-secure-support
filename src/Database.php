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

		$table      = $wpdb->prefix . 'cpsvgss_security_log';
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

		if ( ! wp_next_scheduled( 'cpsvgss_purge_logs_cron' ) ) {
			wp_schedule_event( time(), 'daily', 'cpsvgss_purge_logs_cron' );
		}
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
			$wpdb->prefix . 'cpsvgss_security_log',
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
	 * Fetch paginated log rows, optionally filtered by severity and/or event_type.
	 *
	 * @param  array{severity?: string, event_type?: string} $filters
	 * @return array{rows: array<int,array<string,string>>, total: int}
	 */
	public function get_logs( array $filters = [], int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'cpsvgss_security_log';
		$clauses = [];
		$params  = [];

		if ( ! empty( $filters['severity'] ) ) {
			$clauses[] = 'severity = %s';
			$params[]  = $filters['severity'];
		}
		if ( ! empty( $filters['event_type'] ) ) {
			$clauses[] = 'event_type = %s';
			$params[]  = $filters['event_type'];
		}

		$where = $clauses ? 'WHERE ' . implode( ' AND ', $clauses ) : '';

		$total = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$params ) )
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );

		$offset     = max( 0, $page - 1 ) * $per_page;
		$row_params = array_merge( $params, [ $per_page, $offset ] );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...$row_params
			),
			ARRAY_A
		) ?: [];

		return [ 'rows' => $rows, 'total' => $total ];
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
