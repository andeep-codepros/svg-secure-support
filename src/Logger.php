<?php
namespace CodePros\SVGSecureSupport;

defined( 'ABSPATH' ) || exit;

class Logger {

	/** @var self|null */
	private static ?self $instance = null;

	private const SEVERITY_LEVELS = [ 'info' => 0, 'warning' => 1, 'critical' => 2 ];

	private function __construct() {}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Core log method. Dispatches to WP debug log and/or DB based on settings.
	 *
	 * Format: [SVG Secure Support][SEVERITY][event_type] File: name — details. User: login (ID:n) IP: x.x.x.x
	 */
	public function log( string $event_type, string $severity, string $filename, string $details ): void {
		if ( ! $this->is_enabled() || ! $this->meets_level( $severity ) ) {
			return;
		}

		$user       = wp_get_current_user();
		$user_id    = $user->ID > 0 ? $user->ID : 0;
		$user_login = $user->ID > 0 ? $user->user_login : 'guest';
		$ip         = $this->get_ip();

		$message = sprintf(
			'[SVG Secure Support][%s][%s] File: %s — %s. User: %s (ID:%d) IP: %s',
			strtoupper( $severity ),
			$event_type,
			$filename,
			$details,
			$user_login,
			$user_id,
			$ip
		);

		if ( get_option( 'cpsvgss_log_to_wp_debug', 1 ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
		}

		if ( get_option( 'cpsvgss_log_to_database', 1 ) ) {
			Database::get_instance()->insert_log( [
				'event_type' => $event_type,
				'severity'   => $severity,
				'filename'   => $filename,
				'details'    => $details,
				'user_id'    => $user_id,
				'user_login' => $user_login,
				'ip_address' => $ip,
			] );
		}
	}

	/**
	 * Log a failed pre-upload capability check.
	 */
	public function log_capability_blocked( string $filename ): void {
		$this->log(
			'upload_blocked',
			'warning',
			$filename,
			'Upload blocked: user lacks required SVG upload capability'
		);
	}

	/**
	 * Log a validation pipeline failure.
	 *
	 * @param array<string,bool> $checks
	 */
	public function log_validation_failure( string $filename, string $error, array $checks ): void {
		$failed = array_keys( array_filter( $checks, static fn( $v ) => false === $v ) );
		$detail = $error;
		if ( ! empty( $failed ) ) {
			$detail .= ' (failed checks: ' . implode( ', ', $failed ) . ')';
		}
		$this->log( 'upload_blocked', 'warning', $filename, $detail );
	}

	/**
	 * Log the outcome of the sanitization pass.
	 *
	 * Emits a critical event for surviving payloads, warning for sanitization
	 * failure, and info entries for XML issues + final allow.
	 *
	 * @param array{success: bool, xml_issues: array, suspicious_payloads: array, error: string} $result
	 */
	public function log_sanitization_report( string $filename, array $result ): void {
		if ( ! empty( $result['suspicious_payloads'] ) ) {
			$this->log(
				'suspicious_payload',
				'critical',
				$filename,
				'Suspicious payloads survived sanitization: ' . implode( ', ', $result['suspicious_payloads'] )
			);
			return;
		}

		if ( ! $result['success'] ) {
			$this->log(
				'upload_blocked',
				'warning',
				$filename,
				'Sanitization failed: ' . ( $result['error'] ?? 'unknown error' )
			);
			return;
		}

		foreach ( $result['xml_issues'] as $issue ) {
			$msg = is_array( $issue ) ? ( $issue['message'] ?? wp_json_encode( $issue ) ) : (string) $issue;
			$this->log( 'upload_sanitized', 'info', $filename, 'XML issue resolved during sanitization: ' . $msg );
		}

		$this->log( 'upload_allowed', 'info', $filename, 'SVG passed all validation and sanitization checks' );
	}

	// -------------------------------------------------------------------------

	private function is_enabled(): bool {
		return (bool) get_option( 'cpsvgss_logging_enabled', 1 );
	}

	private function meets_level( string $severity ): bool {
		$min_key   = (string) get_option( 'cpsvgss_log_level', 'warning' );
		$min_level = self::SEVERITY_LEVELS[ $min_key ] ?? 1;
		$level     = self::SEVERITY_LEVELS[ $severity ] ?? 0;
		return $level >= $min_level;
	}

	private function get_ip(): string {
		$candidates = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			// HTTP_X_FORWARDED_FOR may be a comma-separated list — use the first entry.
			$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}
}
