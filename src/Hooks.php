<?php

namespace CodePros\SVGSecureSupport;

defined( 'ABSPATH' ) || exit;

class Hooks {

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
		// Allow SVG MIME type through WordPress's allowed upload list.
		add_filter( 'upload_mimes', array( $this, 'allow_svg_mime' ) );

		// Fix WordPress's broken SVG MIME detection (getimagesize() returns false for SVG).
		add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_svg_mime_check' ), 10, 5 );

		// Capability gate — runs at priority 1 so it fires before any other prefilter.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'check_upload_capability' ), 1 );

		// Validation + sanitization pipeline — runs after the capability gate.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'handle_upload_prefilter' ), 10 );

		// SVG preview in the media library modal.
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'prepare_svg_for_js' ), 10, 3 );

		// Generate minimal metadata (dimensions) for SVG attachments.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_svg_metadata' ), 10, 2 );

		// Daily cron: purge log rows older than the configured retention period.
		add_action( 'cpsvgss_purge_logs_cron', array( $this, 'run_log_purge' ) );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'cpsvgss_purge_logs_cron' );
	}

	public function run_log_purge(): void {
		$days = (int) get_option( 'cpsvgss_log_retention_days', 30 );
		if ( $days > 0 ) {
			Database::get_instance()->purge_old_logs( $days );
		}
	}

	/**
	 * Add image/svg+xml to WordPress's allowed upload MIME types.
	 *
	 * Only adds the MIME type when the current user holds the required capability,
	 * so the WP uploader never even presents SVG as an option to unauthorized users.
	 *
	 * @param  array<string,string> $mimes  Existing allowed MIME types.
	 * @return array<string,string>
	 */
	public function allow_svg_mime( array $mimes ): array {
		if ( current_user_can( $this->upload_capability() ) ) {
			$mimes['svg'] = 'image/svg+xml';
			// svgz (gzip-compressed SVG) is intentionally excluded: the sanitization
			// pipeline operates on plain XML bytes and cannot safely inspect compressed
			// content without a full decompress/recompress cycle that creates its own
			// attack surface. Plain SVG covers all practical use cases.
		}
		return $mimes;
	}

	/**
	 * Correct WordPress's MIME type detection for SVG files.
	 *
	 * wp_check_filetype_and_ext() uses getimagesize() / exif_imagetype() internally,
	 * both of which return false for SVG. This filter restores the correct ext/type
	 * so WordPress does not reject the file after our sanitization pass.
	 *
	 * @param  array<string,string|false> $data      Detected ext and type.
	 * @param  string                     $file      Full path to the uploaded tmp file.
	 * @param  string                     $filename  Original filename from the client.
	 * @param  array<string,string>|null  $mimes     Allowed MIME types (may be null in older WP).
	 * @param  string|false               $real_mime Server-detected MIME (may differ from $data['type']).
	 * @return array<string,string|false>
	 */
	public function fix_svg_mime_check( array $data, string $file, string $filename, $mimes, $real_mime ): array {
		if ( 'svg' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Block SVG uploads from users who lack the required capability.
	 *
	 * Runs at priority 1 so it short-circuits before the sanitization prefilter.
	 * Non-SVG files are returned unchanged immediately.
	 *
	 * @param  array<string,string> $file  WordPress upload array (name, type, tmp_name, error, size).
	 * @return array<string,string>
	 */
	public function check_upload_capability( array $file ): array {
		// Fast path: only act on SVG uploads.
		if ( 'svg' !== strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) ) ) {
			return $file;
		}

		if ( ! current_user_can( $this->upload_capability() ) ) {
			$file['error'] = esc_html__(
				'You do not have permission to upload SVG files.',
				'codepros-svg-secure-support'
			);
			Logger::get_instance()->log_capability_blocked( $file['name'] ?? '' );
		}

		return $file;
	}

	/**
	 * Run the validation and sanitization pipeline on SVG uploads.
	 *
	 * Fires at priority 10, after the capability gate (priority 1).
	 * Non-SVG files are returned immediately. On any failure the error key is
	 * set so WordPress surfaces the message to the user and discards the file.
	 *
	 * @param  array<string,string> $file  WordPress upload array.
	 * @return array<string,string>
	 */
	public function handle_upload_prefilter( array $file ): array {
		if ( 'svg' !== strtolower( pathinfo( $file['name'] ?? '', PATHINFO_EXTENSION ) ) ) {
			return $file;
		}

		// Abort early if a prior filter already set an error (e.g. capability gate).
		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		$tmp      = $file['tmp_name'] ?? '';
		$name     = $file['name']     ?? '';
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		// --- Validation -------------------------------------------------------
		$validation = Validator::get_instance()->validate( $tmp, $name, $size );

		if ( ! $validation['valid'] ) {
			$file['error'] = $validation['error'];
			Logger::get_instance()->log_validation_failure( $name, $validation['error'], $validation['checks'] );
			return $file;
		}

		// --- Sanitization -----------------------------------------------------
		$sanitization = Sanitizer::get_instance()->sanitize_file( $tmp );

		Logger::get_instance()->log_sanitization_report( $name, $sanitization );

		if ( ! $sanitization['success'] ) {
			$file['error'] = esc_html__(
				'SVG file was rejected because it could not be safely sanitized.',
				'codepros-svg-secure-support'
			);
			return $file;
		}
		
		return $file;
	}

	/**
	 * Ensure SVG attachments render correctly in the media library JS modal.
	 *
	 * WordPress uses an img tag in the modal whose src is derived from attachment
	 * metadata. For SVGs there are no generated image sizes, so we point the
	 * thumb src directly at the SVG URL.
	 *
	 * @param  array<string,mixed> $response    Attachment data prepared for JavaScript.
	 * @param  \WP_Post            $attachment  The attachment post object.
	 * @param  mixed               $meta        Attachment metadata array (or false).
	 * @return array<string,mixed>
	 */
	public function prepare_svg_for_js( array $response, \WP_Post $attachment, $meta ): array {
		if ( 'image/svg+xml' !== $response['mime'] ) {
			return $response;
		}

		$svg_url = wp_get_attachment_url( $attachment->ID );

		if ( ! $svg_url ) {
			return $response;
		}

		$dimensions = $this->read_svg_dimensions( get_attached_file( $attachment->ID ) );

		$response['sizes'] = array(
			'full' => array(
				'url'         => $svg_url,
				'width'       => $dimensions['width'],
				'height'      => $dimensions['height'],
				'orientation' => $dimensions['height'] > $dimensions['width'] ? 'portrait' : 'landscape',
			),
		);

		$response['width']  = $dimensions['width'];
		$response['height'] = $dimensions['height'];
		$response['icon']   = $svg_url;
		$response['url']    = $svg_url;

		return $response;
	}

	/**
	 * Generate minimal metadata for SVG attachments so they behave like images.
	 *
	 * @param  array<string,mixed> $metadata       Generated metadata (empty for SVG by default).
	 * @param  int                 $attachment_id  Attachment post ID.
	 * @return array<string,mixed>
	 */
	public function generate_svg_metadata( array $metadata, int $attachment_id ): array {
		$file = get_attached_file( $attachment_id );

		if ( ! $file || 'image/svg+xml' !== get_post_mime_type( $attachment_id ) ) {
			return $metadata;
		}

		$dimensions = $this->read_svg_dimensions( $file );

		$metadata['width']  = $dimensions['width'];
		$metadata['height'] = $dimensions['height'];
		$metadata['file']   = _wp_relative_upload_path( $file );
		$metadata['sizes']  = array();

		return $metadata;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function upload_capability(): string {
		$cap = get_option( 'cpsvgss_upload_capability', 'manage_options' );
		return is_string( $cap ) && ! empty( $cap ) ? $cap : 'manage_options';
	}

	/**
	 * Parse width and height from an SVG file's root element.
	 *
	 * Reads only the root <svg> attributes — no full DOM parse needed here.
	 * Falls back to 0 when dimensions cannot be determined.
	 *
	 * @param  string|false $file  Absolute path to the SVG file.
	 * @return array{width: int, height: int}
	 */
	private function read_svg_dimensions( $file ): array {
		$fallback = array( 'width' => 0, 'height' => 0 );

		if ( ! $file || ! is_readable( $file ) ) {
			return $fallback;
		}

		// Disable external entity loading before any XML parse.
		if ( PHP_VERSION_ID < 80000 ) {
			// phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated
			libxml_disable_entity_loader( true );
		}

		$xml = simplexml_load_file( $file, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		if ( false === $xml ) {
			return $fallback;
		}

		$attrs  = $xml->attributes();
		$width  = isset( $attrs['width'] )  ? (int) $attrs['width']  : 0;
		$height = isset( $attrs['height'] ) ? (int) $attrs['height'] : 0;

		// Try viewBox as fallback when explicit width/height are absent.
		if ( ( 0 === $width || 0 === $height ) && isset( $attrs['viewBox'] ) ) {
			$parts = preg_split( '/[\s,]+/', (string) $attrs['viewBox'] );
			if ( is_array( $parts ) && count( $parts ) >= 4 ) {
				$width  = $width  ?: (int) $parts[2];
				$height = $height ?: (int) $parts[3];
			}
		}

		return array( 'width' => $width, 'height' => $height );
	}
}
