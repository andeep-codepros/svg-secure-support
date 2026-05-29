<?php
namespace CodePros\SVGSecureSupport;

defined( 'ABSPATH' ) || exit;

class Rasterizer {

	// Render at 2× the SVG's natural size (HiDPI-ready).
	private const RENDER_SCALE       = 2;
	// Minimum pixels on the shorter axis when natural size is tiny.
	private const MIN_RENDER_PX      = 512;
	// Hard cap on either axis — prevents runaway memory on huge SVGs.
	private const MAX_RENDER_PX      = 4096;
	// Fallback size when the SVG declares no dimensions at all.
	private const FALLBACK_SIZE      = 1024;
	// Render internally at this multiple of the target, then scale down.
	// Lanczos downscaling acts as a spatial anti-aliasing filter —
	// this is what eliminates jagged edges on curves and diagonal lines.
	private const SUPERSAMPLE_FACTOR = 2;

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
	 * Detect whether a rasterization engine is available on this server.
	 * Priority: Imagick → GD + rsvg-convert → GD + inkscape.
	 */
	public function is_available(): bool {
		if ( extension_loaded( 'imagick' ) ) {
			return true;
		}

		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' ) ) {
			return $this->find_cli_tool( 'rsvg-convert' ) || $this->find_cli_tool( 'inkscape' );
		}

		return false;
	}

	/**
	 * Convert a sanitized SVG file to a raster image.
	 *
	 * Quality strategy:
	 *   1. Read the SVG's natural pixel dimensions from its attributes/viewBox.
	 *   2. Compute a target render size (2× natural, floored at MIN, capped at MAX).
	 *   3. Set resolution BEFORE decode so the rasteriser renders at full quality.
	 *   4. Resize to exact target with Lanczos filtering as a final step.
	 *
	 * @param  string $svg_path      Absolute path to the sanitized SVG file.
	 * @param  string $output_format 'png' or 'webp'.
	 * @return array{success: bool, output_path: string, mime: string, error: string}
	 */
	public function rasterize( string $svg_path, string $output_format = 'png' ): array {
		$output_format = ( 'webp' === $output_format ) ? 'webp' : 'png';
		$output_path   = (string) preg_replace( '/\.svg$/i', '.' . $output_format, $svg_path );

		if ( $output_path === $svg_path ) {
			$output_path = $svg_path . '.' . $output_format;
		}

		$natural = $this->get_svg_natural_size( $svg_path );
		$target  = $this->calculate_render_size( $natural );

		if ( extension_loaded( 'imagick' ) ) {
			return $this->rasterize_imagick( $svg_path, $output_path, $output_format, $natural, $target );
		}

		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromstring' ) ) {
			return $this->rasterize_cli( $svg_path, $output_path, $output_format, $target );
		}

		return $this->fail( 'No rasterization engine available (requires Imagick or GD + rsvg-convert/inkscape).' );
	}

	// -------------------------------------------------------------------------
	// Imagick path (preferred — handles SVG via librsvg when available)
	// -------------------------------------------------------------------------

	/**
	 * @param  array{width: int, height: int} $natural
	 * @param  array{width: int, height: int} $target
	 * @return array{success: bool, output_path: string, mime: string, error: string}
	 */
	private function rasterize_imagick(
		string $svg_path,
		string $output_path,
		string $output_format,
		array $natural,
		array $target
	): array {
		try {
			$imagick = new \Imagick();
			$imagick->setBackgroundColor( new \ImagickPixel( 'transparent' ) );

			// Supersample: decode at SUPERSAMPLE_FACTOR × the target resolution,
			// then scale down. Lanczos downscaling averages the extra pixels into
			// each output pixel — this is what produces smooth, anti-aliased edges
			// on curves and diagonal lines regardless of Imagick's SVG backend.
			$render_w = $target['width']  * self::SUPERSAMPLE_FACTOR;
			$render_h = $target['height'] * self::SUPERSAMPLE_FACTOR;

			// Resolution MUST be set before readImage — Imagick rasterises SVG at
			// decode time. SVG spec: 1 user unit = 1/96 inch → 96 DPI = 1:1 render.
			if ( $natural['width'] > 0 ) {
				$dpi = (int) round( ( $render_w / $natural['width'] ) * 96 );
				$dpi = max( 72, min( $dpi, 2880 ) );
			} else {
				$dpi = 384; // 4× screen density fallback for dimensionless SVGs
			}
			$imagick->setResolution( $dpi, $dpi );

			$imagick->readImage( $svg_path );
			$imagick->setImageFormat( $output_format );
			$imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );

			// Scale from supersampled render size down to final target.
			// FILTER_LANCZOS is the highest-quality resampling filter available —
			// it preserves sharpness while eliminating aliasing artefacts.
			$imagick->resizeImage( $target['width'], $target['height'], \Imagick::FILTER_LANCZOS, 1 );

			if ( 'webp' === $output_format ) {
				$imagick->setImageCompressionQuality( 90 );
			} else {
				// PNG is lossless; compression level only affects file size, not quality.
				$imagick->setImageCompressionQuality( 95 );
			}

			$imagick->writeImage( $output_path );
			$imagick->clear();
			$imagick->destroy();

		} catch ( \ImagickException $e ) {
			return $this->fail( 'Imagick conversion failed: ' . $e->getMessage() );
		}

		if ( ! file_exists( $output_path ) ) {
			return $this->fail( 'Imagick wrote no output file.' );
		}

		return $this->ok( $output_path, $output_format );
	}

	// -------------------------------------------------------------------------
	// GD + CLI fallback path — delegates to rsvg-convert or inkscape
	// -------------------------------------------------------------------------

	/**
	 * @param  array{width: int, height: int} $target
	 * @return array{success: bool, output_path: string, mime: string, error: string}
	 */
	private function rasterize_cli(
		string $svg_path,
		string $output_path,
		string $output_format,
		array $target
	): array {
		$svg_esc = escapeshellarg( $svg_path );
		// Render to a temp PNG at supersampled size; GD resamples to final target.
		$tmp_png = $output_path . '.ss.png';
		$tmp_esc = escapeshellarg( $tmp_png );
		$rw      = $target['width']  * self::SUPERSAMPLE_FACTOR;
		$rh      = $target['height'] * self::SUPERSAMPLE_FACTOR;

		$rsvg = $this->find_cli_tool( 'rsvg-convert' );
		if ( $rsvg ) {
			$cmd = escapeshellcmd( $rsvg )
				. " --format=png --width={$rw} --height={$rh} --keep-aspect-ratio"
				. " --output={$tmp_esc} {$svg_esc} 2>&1";

			exec( $cmd, $cli_output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

			if ( 0 !== $exit_code || ! file_exists( $tmp_png ) ) {
				return $this->fail(
					'rsvg-convert failed (exit ' . $exit_code . '): ' . implode( ' ', $cli_output )
				);
			}

			return $this->gd_resample_down( $tmp_png, $output_path, $output_format, $target );
		}

		$inkscape = $this->find_cli_tool( 'inkscape' );
		if ( $inkscape ) {
			$cmd = escapeshellcmd( $inkscape )
				. " --export-type=png --export-width={$rw} --export-height={$rh}"
				. " --export-filename={$tmp_esc} {$svg_esc} 2>&1";

			exec( $cmd, $cli_output, $exit_code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

			if ( 0 !== $exit_code || ! file_exists( $tmp_png ) ) {
				return $this->fail(
					'inkscape failed (exit ' . $exit_code . '): ' . implode( ' ', $cli_output )
				);
			}

			return $this->gd_resample_down( $tmp_png, $output_path, $output_format, $target );
		}

		return $this->fail( 'GD available but no CLI SVG renderer found (install rsvg-convert or inkscape).' );
	}

	/**
	 * Scale a supersampled PNG down to the target size using GD's bicubic resampling,
	 * then encode as PNG or WebP. Cleans up the temp file on completion.
	 *
	 * @param  array{width: int, height: int} $target
	 * @return array{success: bool, output_path: string, mime: string, error: string}
	 */
	private function gd_resample_down(
		string $tmp_png,
		string $output_path,
		string $output_format,
		array $target
	): array {
		$src = imagecreatefrompng( $tmp_png );
		@unlink( $tmp_png );

		if ( false === $src ) {
			return $this->fail( 'GD could not load the supersampled PNG for resampling.' );
		}

		$dst = imagecreatetruecolor( $target['width'], $target['height'] );
		if ( false === $dst ) {
			imagedestroy( $src );
			return $this->fail( 'GD could not create the output canvas.' );
		}

		// Preserve transparency.
		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );
		$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
		if ( false !== $transparent ) {
			imagefill( $dst, 0, 0, $transparent );
		}

		imagecopyresampled(
			$dst, $src,
			0, 0, 0, 0,
			$target['width'], $target['height'],
			imagesx( $src ), imagesy( $src )
		);
		imagedestroy( $src );

		$saved = false;
		if ( 'webp' === $output_format && function_exists( 'imagewebp' ) ) {
			$saved = imagewebp( $dst, $output_path, 90 );
		} else {
			// PNG compression 9 = smallest file; quality is lossless regardless.
			$saved = imagepng( $dst, $output_path, 9 );
			$output_format = 'png'; // normalise if webp was requested but unavailable
		}

		imagedestroy( $dst );

		if ( ! $saved || ! file_exists( $output_path ) ) {
			return $this->fail( 'GD failed to write the output image.' );
		}

		return $this->ok( $output_path, $output_format );
	}

	// -------------------------------------------------------------------------
	// Dimension helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse the natural pixel size from the SVG root element.
	 * Falls back to viewBox when explicit width/height are absent.
	 *
	 * @return array{width: int, height: int}
	 */
	private function get_svg_natural_size( string $svg_path ): array {
		$zero = [ 'width' => 0, 'height' => 0 ];

		if ( PHP_VERSION_ID < 80000 ) {
			// phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions.libxml_disable_entity_loaderDeprecated
			libxml_disable_entity_loader( true );
		}

		$prev    = libxml_use_internal_errors( true );
		$dom     = new \DOMDocument();
		$content = file_get_contents( $svg_path );

		if ( false === $content || ! $dom->loadXML( $content, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			return $zero;
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$svg = $dom->documentElement;
		if ( ! $svg ) {
			return $zero;
		}

		$width  = $this->parse_dimension( $svg->getAttribute( 'width' ) );
		$height = $this->parse_dimension( $svg->getAttribute( 'height' ) );

		if ( 0 === $width || 0 === $height ) {
			$view_box = $svg->getAttribute( 'viewBox' );
			if ( $view_box ) {
				$parts = preg_split( '/[\s,]+/', trim( $view_box ) );
				if ( is_array( $parts ) && count( $parts ) >= 4 ) {
					$width  = $width  ?: (int) $parts[2];
					$height = $height ?: (int) $parts[3];
				}
			}
		}

		return [ 'width' => $width, 'height' => $height ];
	}

	/**
	 * Compute the target raster size from the SVG's natural dimensions.
	 *
	 * Rules (applied in order):
	 *   1. Start at RENDER_SCALE × natural.
	 *   2. Scale up so the shorter axis reaches MIN_RENDER_PX.
	 *   3. Scale down so the longer axis stays within MAX_RENDER_PX.
	 *
	 * Aspect ratio is preserved throughout.
	 *
	 * @param  array{width: int, height: int} $natural
	 * @return array{width: int, height: int}
	 */
	private function calculate_render_size( array $natural ): array {
		$w = $natural['width'];
		$h = $natural['height'];

		if ( 0 === $w && 0 === $h ) {
			return [ 'width' => self::FALLBACK_SIZE, 'height' => self::FALLBACK_SIZE ];
		}

		// Fill in a missing axis by treating the SVG as square.
		if ( 0 === $w ) { $w = $h; }
		if ( 0 === $h ) { $h = $w; }

		$tw = (float) $w * self::RENDER_SCALE;
		$th = (float) $h * self::RENDER_SCALE;

		// Scale up if shorter axis is below the minimum.
		$shorter = min( $tw, $th );
		if ( $shorter < self::MIN_RENDER_PX ) {
			$scale = self::MIN_RENDER_PX / $shorter;
			$tw   *= $scale;
			$th   *= $scale;
		}

		// Scale down if longer axis exceeds the maximum.
		$longer = max( $tw, $th );
		if ( $longer > self::MAX_RENDER_PX ) {
			$scale = self::MAX_RENDER_PX / $longer;
			$tw   *= $scale;
			$th   *= $scale;
		}

		return [
			'width'  => max( 1, (int) round( $tw ) ),
			'height' => max( 1, (int) round( $th ) ),
		];
	}

	/** Strip units and return integer pixels; percentages return 0 (unresolvable). */
	private function parse_dimension( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || str_ends_with( $value, '%' ) ) {
			return 0;
		}
		return (int) $value;
	}

	// -------------------------------------------------------------------------

	/** Locate a CLI binary via `which`. Only accepts safe tool names. */
	private function find_cli_tool( string $name ): string {
		if ( ! preg_match( '/^[a-z0-9_-]+$/i', $name ) ) {
			return '';
		}
		$path = trim( (string) shell_exec( 'which ' . escapeshellarg( $name ) . ' 2>/dev/null' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		return ( $path && file_exists( $path ) ) ? $path : '';
	}

	private function mime_for_format( string $format ): string {
		return ( 'webp' === $format ) ? 'image/webp' : 'image/png';
	}

	/** @return array{success: bool, output_path: string, mime: string, error: string} */
	private function ok( string $output_path, string $format ): array {
		return [
			'success'     => true,
			'output_path' => $output_path,
			'mime'        => $this->mime_for_format( $format ),
			'error'       => '',
		];
	}

	/** @return array{success: bool, output_path: string, mime: string, error: string} */
	private function fail( string $error ): array {
		return [
			'success'     => false,
			'output_path' => '',
			'mime'        => '',
			'error'       => $error,
		];
	}
}
