<?php
namespace CodePros\SVGSecureSupport\Admin;

use CodePros\SVGSecureSupport\Database;

defined( 'ABSPATH' ) || exit;

class Admin {

	private const PAGE_SLUG    = 'codepros-svg-secure-support';
	private const OPTION_GROUP = 'cpsvgss_settings';
	private const DEFAULT_CSP   = "default-src 'self'; script-src 'none'; object-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:;";

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
		add_action( 'admin_menu',                     [ $this, 'register_menus' ] );
		add_action( 'admin_init',                     [ $this, 'register_settings' ] );
		add_action( 'admin_post_svgss_purge_logs',    [ $this, 'handle_purge_logs' ] );
	}

	// -------------------------------------------------------------------------
	// Menus
	// -------------------------------------------------------------------------

	public function register_menus(): void {
		add_options_page(
			__( ' SVG Secure Support', 'codepros-svg-secure-support' ),
			__( 'SVG Secure Support', 'codepros-svg-secure-support' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		switch ( $tab ) {
			case 'logs':
				require CPSVGSS_PLUGIN_DIR . 'src/Admin/templates/page-logs.php';
				break;
			default:
				require CPSVGSS_PLUGIN_DIR . 'src/Admin/templates/page-settings.php';
		}
	}

	// -------------------------------------------------------------------------
	// Log purge action
	// -------------------------------------------------------------------------

	public function handle_purge_logs(): void {
		check_admin_referer( 'cpsvgss_purge_logs' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'codepros-svg-secure-support' ) );
		}

		$days    = (int) get_option( 'cpsvgss_log_retention_days', 30 );
		$deleted = Database::get_instance()->purge_old_logs( $days );

		wp_safe_redirect( add_query_arg(
			[ 'page' => self::PAGE_SLUG, 'tab' => 'logs', 'cpsvgss_purged' => $deleted ],
			admin_url( 'options-general.php' )
		) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Settings registration
	// -------------------------------------------------------------------------

	public function register_settings(): void {

		// --- Upload Restrictions -----------------------------------------------
		add_settings_section(
			'cpsvgss_upload',
			__( 'Upload Restrictions', 'codepros-svg-secure-support' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Control who can upload SVG files and what file constraints apply.', 'codepros-svg-secure-support' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$roles = [];
		foreach ( wp_roles()->roles as $role_slug => $role_data ) {
			$roles[ $role_slug ] = translate_user_role( $role_data['name'] );
		}

		$this->field( 'cpsvgss_allowed_roles', __( 'Roles Allowed to Upload SVGs', 'codepros-svg-secure-support' ), 'cpsvgss_upload', [
			'type'        => 'multicheck',
			'default'     => [ 'administrator' ],
			'options'     => $roles,
			'description' => __( 'Select one or more roles whose members may upload SVG files. If none are selected, only administrators can upload.', 'codepros-svg-secure-support' ),
			'sanitize'    => static function ( $v ) use ( $roles ): array {
				if ( ! is_array( $v ) ) {
					return [ 'administrator' ];
				}
				$valid = array_values( array_filter( $v, static fn( $r ) => array_key_exists( $r, $roles ) ) );
				return $valid ?: [ 'administrator' ];
			},
		] );

		$this->field( 'cpsvgss_max_file_size_kb', __( 'Max File Size (KB)', 'codepros-svg-secure-support' ), 'cpsvgss_upload', [
			'type'        => 'number',
			'default'     => 1024,
			'min'         => 1,
			'max'         => 10240,
			'description' => __( 'Maximum allowed SVG file size in kilobytes.', 'codepros-svg-secure-support' ),
			'sanitize'    => 'absint',
		] );

		$this->field( 'cpsvgss_max_xml_nodes', __( 'Max XML Nodes', 'codepros-svg-secure-support' ), 'cpsvgss_upload', [
			'type'        => 'number',
			'default'     => 5000,
			'min'         => 100,
			'max'         => 50000,
			'description' => __( 'Maximum DOM node count in an SVG (guards against node-flood DoS attacks).', 'codepros-svg-secure-support' ),
			'sanitize'    => 'absint',
		] );

		$this->field( 'cpsvgss_max_dimension_px', __( 'Max Dimension (px)', 'codepros-svg-secure-support' ), 'cpsvgss_upload', [
			'type'        => 'number',
			'default'     => 10000,
			'min'         => 100,
			'max'         => 100000,
			'description' => __( 'Maximum width or height declared in the SVG root element.', 'codepros-svg-secure-support' ),
			'sanitize'    => 'absint',
		] );

		// --- Sanitization -------------------------------------------------------
		add_settings_section(
			'cpsvgss_sanitization',
			__( 'Sanitization', 'codepros-svg-secure-support' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Additional passes applied to SVG content after the main sanitizer runs.', 'codepros-svg-secure-support' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->field( 'cpsvgss_strip_style_tags', __( 'Strip &lt;style&gt; Tags', 'codepros-svg-secure-support' ), 'cpsvgss_sanitization', [
			'type'        => 'checkbox',
			'default'     => 1,
			'label'       => __( 'Remove all <style> blocks from SVG files', 'codepros-svg-secure-support' ),
			'description' => __( 'CSS in <style> blocks can carry expression() or javascript: payloads. Disable only if your SVGs need embedded styles.', 'codepros-svg-secure-support' ),
			'sanitize'    => static fn( $v ) => $v ? 1 : 0,
		] );

		$this->field( 'cpsvgss_strip_xml_comments', __( 'Strip XML Comments', 'codepros-svg-secure-support' ), 'cpsvgss_sanitization', [
			'type'        => 'checkbox',
			'default'     => 1,
			'label'       => __( 'Remove all <!-- XML comments --> from SVG files', 'codepros-svg-secure-support' ),
			'description' => __( 'Comments can conceal payloads and are never needed for display.', 'codepros-svg-secure-support' ),
			'sanitize'    => static fn( $v ) => $v ? 1 : 0,
		] );

		// --- Security Headers ---------------------------------------------------
		add_settings_section(
			'cpsvgss_headers',
			__( 'Security Headers', 'codepros-svg-secure-support' ),
			static function (): void {
				echo '<p>' . esc_html__( 'HTTP headers sent when SVG attachment pages are served by WordPress.', 'codepros-svg-secure-support' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->field( 'cpsvgss_csp_enabled', __( 'Enable CSP Header', 'codepros-svg-secure-support' ), 'cpsvgss_headers', [
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Send Content-Security-Policy on SVG attachment pages', 'codepros-svg-secure-support' ),
			'sanitize' => static fn( $v ) => $v ? 1 : 0,
		] );

		$this->field( 'cpsvgss_csp_header', __( 'CSP Header Value', 'codepros-svg-secure-support' ), 'cpsvgss_headers', [
			'type'        => 'textarea',
			'default'     => self::DEFAULT_CSP,
			'description' => __( 'Full Content-Security-Policy directive string. Leave blank to restore the secure default.', 'codepros-svg-secure-support' ),
			'sanitize'    => static function ( $v ): string {
				$clean = sanitize_textarea_field( $v );
				return $clean ?: self::DEFAULT_CSP;
			},
		] );

		// --- Logging ------------------------------------------------------------
		add_settings_section(
			'cpsvgss_logging',
			__( 'Security Logging', 'codepros-svg-secure-support' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Configure how and where security events are recorded.', 'codepros-svg-secure-support' ) . '</p>';
			},
			self::PAGE_SLUG
		);

		$this->field( 'cpsvgss_logging_enabled', __( 'Enable Logging', 'codepros-svg-secure-support' ), 'cpsvgss_logging', [
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Record security events', 'codepros-svg-secure-support' ),
			'sanitize' => static fn( $v ) => $v ? 1 : 0,
		] );

		$this->field( 'cpsvgss_log_to_wp_debug', __( 'Log to WP Debug', 'codepros-svg-secure-support' ), 'cpsvgss_logging', [
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Write entries to wp-content/debug.log', 'codepros-svg-secure-support' ),
			'sanitize' => static fn( $v ) => $v ? 1 : 0,
		] );

		$this->field( 'cpsvgss_log_to_database', __( 'Log to Database', 'codepros-svg-secure-support' ), 'cpsvgss_logging', [
			'type'    => 'checkbox',
			'default' => 1,
			'label'   => __( 'Write entries to the svgss_security_log database table', 'codepros-svg-secure-support' ),
			'sanitize' => static fn( $v ) => $v ? 1 : 0,
		] );

		$this->field( 'cpsvgss_log_level', __( 'Minimum Log Level', 'codepros-svg-secure-support' ), 'cpsvgss_logging', [
			'type'    => 'select',
			'default' => 'warning',
			'options' => [
				'info'     => __( 'Info — all events including allowed uploads', 'codepros-svg-secure-support' ),
				'warning'  => __( 'Warning — blocked uploads and sanitization failures', 'codepros-svg-secure-support' ),
				'critical' => __( 'Critical — suspicious payloads only', 'codepros-svg-secure-support' ),
			],
			'sanitize' => static fn( $v ) => in_array( $v, [ 'info', 'warning', 'critical' ], true ) ? $v : 'warning',
		] );

		$this->field( 'cpsvgss_log_retention_days', __( 'Log Retention (days)', 'codepros-svg-secure-support' ), 'cpsvgss_logging', [
			'type'        => 'number',
			'default'     => 30,
			'min'         => 1,
			'max'         => 365,
			'description' => __( 'Entries older than this many days are removed when logs are purged.', 'codepros-svg-secure-support' ),
			'sanitize'    => 'absint',
		] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Register a setting option and wire a settings field for it in one call.
	 *
	 * @param array{
	 *   type:        string,
	 *   default:     mixed,
	 *   sanitize:    callable|string,
	 *   description?: string,
	 *   label?:      string,
	 *   options?:    array<string,string>,
	 *   min?:        int,
	 *   max?:        int,
	 * } $args
	 */
	private function field( string $option, string $label, string $section, array $args ): void {
		register_setting( self::OPTION_GROUP, $option, [
			'type'              => 'multicheck' === ( $args['type'] ?? '' ) ? 'array' : ( $args['type'] ?? 'string' ),
			'sanitize_callback' => $args['sanitize'] ?? 'sanitize_text_field',
		] );

		add_settings_field(
			$option,
			$label,
			[ $this, 'render_field' ],
			self::PAGE_SLUG,
			$section,
			array_merge( $args, [ 'option' => $option, 'label_for' => $option ] )
		);
	}

	/**
	 * Generic field renderer — handles text, number, checkbox, select, textarea.
	 *
	 * @param array{option: string, type?: string, default?: mixed, label?: string, description?: string, options?: array<string,string>, min?: int, max?: int} $args
	 */
	public function render_field( array $args ): void {
		$option = $args['option'];
		$type   = $args['type']    ?? 'text';
		$value  = get_option( $option, $args['default'] ?? '' );

		switch ( $type ) {
			case 'multicheck':
				$saved = (array) get_option( $option, $args['default'] ?? [] );
				foreach ( $args['options'] ?? [] as $opt_val => $opt_label ) {
					printf(
						'<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>',
						esc_attr( $option ),
						esc_attr( $opt_val ),
						in_array( $opt_val, $saved, true ) ? ' checked' : '',
						esc_html( $opt_label )
					);
				}
				break;

			case 'checkbox':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s> %3$s</label>',
					esc_attr( $option ),
					checked( 1, (int) $value, false ),
					isset( $args['label'] ) ? esc_html( $args['label'] ) : ''
				);
				break;

			case 'select':
				printf( '<select id="%1$s" name="%1$s">', esc_attr( $option ) );
				foreach ( $args['options'] ?? [] as $opt_val => $opt_label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $opt_val ),
						selected( $value, $opt_val, false ),
						esc_html( $opt_label )
					);
				}
				echo '</select>';
				break;

			case 'textarea':
				printf(
					'<textarea id="%1$s" name="%1$s" rows="3" class="large-text code">%2$s</textarea>',
					esc_attr( $option ),
					esc_textarea( (string) $value )
				);
				break;

			case 'number':
				printf(
					'<input type="number" id="%1$s" name="%1$s" value="%2$s" class="small-text"%3$s%4$s>',
					esc_attr( $option ),
					esc_attr( (string) $value ),
					isset( $args['min'] ) ? ' min="' . absint( $args['min'] ) . '"' : '',
					isset( $args['max'] ) ? ' max="' . absint( $args['max'] ) . '"' : ''
				);
				break;

			default:
				printf(
					'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text">',
					esc_attr( $option ),
					esc_attr( (string) $value )
				);
		}

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	// -------------------------------------------------------------------------
	// Public accessors for templates
	// -------------------------------------------------------------------------

	public static function tab_url( string $tab ): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG . '&tab=' . rawurlencode( $tab ) );
	}

	public static function settings_page_slug(): string {
		return self::PAGE_SLUG;
	}

	public static function option_group(): string {
		return self::OPTION_GROUP;
	}
}
