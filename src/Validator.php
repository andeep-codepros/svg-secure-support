<?php
namespace CodePros\SVGSecureSupport;

defined( 'ABSPATH' ) || exit;

class Validator {

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
	 * Run the 5-check validation pipeline on an uploaded SVG.
	 *
	 * @param  string $tmp_path  Absolute path to the uploaded temp file.
	 * @param  string $filename  Original filename from the client.
	 * @param  int    $filesize  Reported file size in bytes (unused; we stat the real file).
	 * @return array{valid: bool, error: string, checks: array<string, bool>}
	 */
	public function validate( string $tmp_path, string $filename, int $filesize ): array {
		$checks = [];

		// 1. Extension check ---------------------------------------------------
		$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$stem = pathinfo( $filename, PATHINFO_FILENAME );

		if ( 'svg' !== $ext ) {
			$checks['extension'] = false;
			return $this->fail(
				__( 'File must have an .svg extension.', 'codepros-svg-secure-support' ),
				$checks
			);
		}

		// Block double-extension filenames such as payload.php.svg.
		if ( preg_match( '/\.(php[0-9]?|phtml|phar|asp|aspx|jsp|js|html?|xml|sh|py|cgi|pl)/i', $stem ) ) {
			$checks['extension'] = false;
			return $this->fail(
				__( 'Filename contains a disallowed extension pattern.', 'codepros-svg-secure-support' ),
				$checks
			);
		}
		$checks['extension'] = true;

		// 2. MIME check --------------------------------------------------------
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = ( $finfo ) ? finfo_file( $finfo, $tmp_path ) : '';
		if ( $finfo ) {
			finfo_close( $finfo );
		}

		if ( 'image/svg+xml' !== $mime ) {
			// Some environments report text/xml or text/html for valid SVGs.
			// Accept if the first 512 bytes contain an SVG/XML signature.
			$header = file_get_contents( $tmp_path, false, null, 0, 512 );
			if ( false === $header || ! preg_match( '/<svg[\s>]|<\?xml/i', $header ) ) {
				$checks['mime'] = false;
				return $this->fail(
					__( 'File does not appear to be a valid SVG.', 'codepros-svg-secure-support' ),
					$checks
				);
			}
		}
		$checks['mime'] = true;

		// 3. Size check --------------------------------------------------------
		$max_size    = (int) get_option( 'cpsvgss_max_file_size_kb', CODEPROS_SVGSS_MAX_FILE_SIZE / 1024 ) * 1024;
		$actual_size = filesize( $tmp_path );

		if ( false === $actual_size || $actual_size > $max_size ) {
			$checks['size'] = false;
			return $this->fail(
				sprintf(
					/* translators: %d: maximum allowed file size in KB */
					__( 'SVG file exceeds the maximum allowed size of %d KB.', 'codepros-svg-secure-support' ),
					(int) ( $max_size / 1024 )
				),
				$checks
			);
		}
		$checks['size'] = true;

		// Parse the XML once for checks 4 and 5 --------------------------------
		$dom = $this->safe_load_xml( $tmp_path );
		if ( null === $dom ) {
			$checks['parse'] = false;
			return $this->fail(
				__( 'SVG file could not be parsed as valid XML.', 'codepros-svg-secure-support' ),
				$checks
			);
		}
		$checks['parse'] = true;

		// 4. Node count check --------------------------------------------------
		$max_nodes  = (int) get_option( 'cpsvgss_max_xml_nodes', CODEPROS_SVGSS_MAX_XML_NODES );
		$xpath      = new \DOMXPath( $dom );
		$node_list  = $xpath->query( 'descendant-or-self::node()' );
		$node_count = $node_list ? $node_list->length : 0;

		if ( $node_count > $max_nodes ) {
			$checks['node_count'] = false;
			return $this->fail(
				sprintf(
					/* translators: 1: node count found, 2: maximum allowed */
					__( 'SVG contains too many XML nodes (%1$d). Maximum allowed is %2$d.', 'codepros-svg-secure-support' ),
					$node_count,
					$max_nodes
				),
				$checks
			);
		}
		$checks['node_count'] = true;

		// 5. Dimension check ---------------------------------------------------
		$max_dim = (int) get_option( 'cpsvgss_max_dimension_px', CODEPROS_SVGSS_MAX_DIMENSION );
		$svg_el  = $dom->documentElement;
		$width   = $this->parse_dimension( $svg_el->getAttribute( 'width' ) );
		$height  = $this->parse_dimension( $svg_el->getAttribute( 'height' ) );

		// Fall back to viewBox when explicit width/height are absent.
		if ( 0 === $width || 0 === $height ) {
			$view_box = $svg_el->getAttribute( 'viewBox' );
			if ( $view_box ) {
				$parts = preg_split( '/[\s,]+/', trim( $view_box ) );
				if ( is_array( $parts ) && count( $parts ) >= 4 ) {
					$width  = $width  ?: (int) $parts[2];
					$height = $height ?: (int) $parts[3];
				}
			}
		}

		if ( ( $width > 0 && $width > $max_dim ) || ( $height > 0 && $height > $max_dim ) ) {
			$checks['dimensions'] = false;
			return $this->fail(
				sprintf(
					/* translators: %d: maximum dimension in pixels */
					__( 'SVG dimensions exceed the maximum allowed size of %d pixels.', 'codepros-svg-secure-support' ),
					$max_dim
				),
				$checks
			);
		}
		$checks['dimensions'] = true;

		return [ 'valid' => true, 'error' => '', 'checks' => $checks ];
	}

	/**
	 * Load an SVG file as a DOMDocument with safe libxml settings.
	 * Disables external entity loading and suppresses parse errors.
	 */
	private function safe_load_xml( string $path ): ?\DOMDocument {
		
		$prev = libxml_use_internal_errors( true );

		$dom                    = new \DOMDocument();
		$dom->strictErrorChecking = false;

		$content = file_get_contents( $path );

		if ( false === $content ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			return null;
		}

		$loaded = $dom->loadXML( $content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $loaded ? $dom : null;
	}

	/**
	 * Parse a CSS/SVG dimension string to an integer pixel value.
	 * Strips units (px, pt, em, etc.) and returns 0 for percentages or empty values.
	 */
	private function parse_dimension( string $value ): int {
		$value = trim( $value );

		if ( '' === $value ) {
			return 0;
		}

		// Percentages are relative — cannot be resolved to absolute pixels.
		if ( '%' === substr( $value, -1 ) ) {
			return 0;
		}

		return (int) $value;
	}

	/**
	 * @param  string             $error
	 * @param  array<string,bool> $checks
	 * @return array{valid: bool, error: string, checks: array<string, bool>}
	 */
	private function fail( string $error, array $checks ): array {
		return [ 'valid' => false, 'error' => $error, 'checks' => $checks ];
	}
}
