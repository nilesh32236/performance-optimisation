<?php
/**
 * Img_Converter Class
 *
 * A class to handle image format conversions (WebP and AVIF) for performance optimization.
 * This class performs image conversion based on the configuration options provided,
 * allowing optimization of images for improved website performance.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Img_Converter' ) ) {
	/**
	 * Img_Converter Class
	 *
	 * A class to handle image format conversions (WebP and AVIF) for performance optimization.
	 *
	 * @since 1.0.0
	 */
	class Img_Converter {

		/**
		 * Deferred in-memory image info state.
		 *
		 * @var array|null
		 * @since 1.5.1
		 */
		private static $deferred_img_info = null;

		/**
		 * Flag to check if shutdown hook is registered.
		 *
		 * @var bool
		 * @since 1.5.1
		 */
		private static $img_info_shutdown_registered = false;

		/**
		 * Flag to check if image info has already been persisted in this request.
		 *
		 * @var bool
		 * @since 1.5.1
		 */
		private static $img_info_persisted = false;

		/**
		 * Cached client-side media processing state, keyed by blog ID.
		 *
		 * Prevents repeated core-option reads on frontend hot paths.
		 *
		 * @var array<int, bool>|null
		 * @since 1.9.0
		 */
		private static $client_side_processing_state = null;

		/**
		 * Option key used for the image info cache salt.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const SALT_KEY = 'wppo_img_info_salt';

		/**
		 * Configuration options for image optimization.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private $options;

		/**
		 * Available formats for image conversion.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private array $available_format = array(
			'webp',
			'avif',
			'both',
		);

		/**
		 * The format to convert images to (webp, avif, or both).
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private $format;

		/**
		 * List of images to exclude from conversion.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private $exclude_imgs = array();

		/**
		 * Img_Converter constructor.
		 *
		 * @param array $options Options for configuring image optimization.
		 * @since 1.0.0
		 */
		public function __construct( $options ) {
			$this->options = $options;

			if ( ! empty( $this->options['image_optimisation']['excludeWebPImages'] ) ) {
				$this->exclude_imgs = Util::process_urls( $this->options['image_optimisation']['excludeWebPImages'] );
			}

			$this->format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';

			// If WP 6.7+ natively generates next-gen formats, skip plugin's own conversion.
			if ( self::core_handles_next_gen() ) {
				if ( 'webp' === $this->format ) {
					$this->format = 'none';
				} elseif ( 'both' === $this->format ) {
					$this->format = self::core_handles_both_next_gen() ? 'none' : 'avif';
				}
			}
		}

		/**
		 * Check if WordPress core (6.7+) natively handles next-gen format generation (WebP/AVIF).
		 *
		 * @since NEXT
		 *
		 * @return bool True if core handles next-gen formats natively.
		 */
		public static function core_handles_next_gen(): bool {
			return function_exists( 'wp_image_quality' );
		}

		/**
		 * Check if WordPress core (7.1+) natively handles both WebP and AVIF generation.
		 *
		 * @since NEXT
		 *
		 * @return bool True if core can generate both WebP and AVIF natively.
		 */
		public static function core_handles_both_next_gen(): bool {
			return function_exists( 'wp_image_quality' )
				&& null !== wp_image_quality( 'image/webp' )
				&& null !== wp_image_quality( 'image/avif' );
		}

		/**
		 * Get the current conversion format.
		 *
		 * @since NEXT
		 *
		 * @return string The format ('webp', 'avif', 'both', or 'none').
		 */
		public function get_format(): string {
			return $this->format;
		}

		/**
		 * Resolve the encode quality for an output MIME type.
		 *
		 * Prefers WordPress 7.1+'s size-aware `wp_get_image_encode_quality()`
		 * (which honors the `wp_editor_set_quality` / `jpeg_quality` filters
		 * against the source dimensions), falls back to the flat
		 * `wp_image_quality()` API on WP 6.7-7.0, and finally to the supplied
		 * fallback on older cores or when core reports no registered quality.
		 *
		 * A null or zero value returned by either core helper is treated as
		 * "no registered quality" and causes resolution to continue down the
		 * chain (flat helper, then the plugin fallback) rather than encoding at
		 * quality 0. This means an invalid result from the size-aware helper can
		 * be satisfied by a valid flat `wp_image_quality()` value before the
		 * plugin fallback is reached. Size-aware: $size is passed through to
		 * wp_get_image_encode_quality() (WP 7.1+) so per-size quality filters
		 * are honoured.
		 *
		 * @since NEXT
		 *
		 * @param string $mime     The output MIME type (e.g. 'image/webp').
		 * @param int    $fallback Fallback quality (1-100) used when no core API provides a value.
		 * @param array  $size     Optional dimensions of the source image ('width'/'height').
		 * @return int The encode quality to use (1-100).
		 */
		private function resolve_encode_quality( string $mime, int $fallback, array $size = array() ): int {
			if ( function_exists( 'wp_get_image_encode_quality' ) ) {
				$quality = wp_get_image_encode_quality( $mime, $size, $fallback );
				if ( null !== $quality && $quality > 0 ) {
					return (int) $quality;
				}
			}

			if ( function_exists( 'wp_image_quality' ) ) {
				$quality = wp_image_quality( $mime );
				if ( null !== $quality && $quality > 0 ) {
					return (int) $quality;
				}
			}

			return $fallback;
		}

		/**
		 * Resolve the effective target format using core's centralized
		 * `image_editor_output_format` mapping when available.
		 *
		 * WP 6.7+ exposes `wp_get_image_editor_output_format()`, which applies
		 * the `image_editor_output_format` filter (e.g. HEIC -> JPEG, JPEG ->
		 * WebP). When core maps the source MIME to a next-gen format, the
		 * plugin converts to that format so both pipelines produce the same
		 * output; when core maps it to a legacy format, core owns the
		 * conversion and the plugin returns 'none' (skip). On older cores
		 * without the helper, or when core provides no mapping for the
		 * source MIME, the requested format is returned unchanged (legacy
		 * fallback intact).
		 *
		 * Format authority lives here: `get_smart_quality()` owns only the
		 * numeric quality mapping (AVIF = WebP − 20) and stays fail-open when
		 * core maps the source MIME elsewhere.
		 *
		 * @since NEXT
		 *
		 * @param string $source_image     Filesystem path to the source image.
		 * @param string $requested_format The format requested by the plugin ('webp', 'avif', or 'both').
		 * @return string The effective target format ('webp', 'avif', 'both', or 'none').
		 */
		private function resolve_output_format( string $source_image, string $requested_format ): string {
			if ( ! function_exists( 'wp_get_image_editor_output_format' ) ) {
				return $requested_format;
			}

			$source_mime = Util::get_image_mime_type( $source_image );
			if ( empty( $source_mime ) ) {
				return $requested_format;
			}

			$mapping = wp_get_image_editor_output_format( $source_image, $source_mime );
			if ( ! is_array( $mapping ) || empty( $mapping[ $source_mime ] ) ) {
				return $requested_format;
			}

			switch ( $mapping[ $source_mime ] ) {
				case 'image/webp':
					return 'webp';
				case 'image/avif':
					return 'avif';
				default:
					return 'none';
			}
		}

		/**
		 * Infer the dimensions of a source image from its file name.
		 *
		 * Core-generated sub-sizes use a `-{width}x{height}` suffix (e.g.
		 * `sample-300x200.jpg`); the original/full-size file has no suffix.
		 * Used to feed `wp_get_image_encode_quality()` so per-size quality
		 * tuning matches what core would apply.
		 *
		 * @since NEXT
		 *
		 * @param string $source_image Filesystem path to the source image.
		 * @return array The dimensions array ('width'/'height'), empty for full-size originals.
		 */
		private function get_source_image_dimensions( string $source_image ): array {
			if ( 1 === preg_match( '/-(\d+)x(\d+)(?:\.[a-z0-9]+)?$/i', basename( $source_image ), $matches ) ) {
				return array(
					'width'  => (int) $matches[1],
					'height' => (int) $matches[2],
				);
			}

			return array();
		}

		/**
		 * Whether an AVIF encoder is available on this host.
		 *
		 * The AVIF conversion path only boots when this returns true: GD
		 * `imageavif()` (PHP 8.2+) or Imagick with AVIF delegate support.
		 * All callers fail open to WebP, else the original, when false.
		 *
		 * @since NEXT
		 *
		 * @return bool True when AVIF encoding is supported.
		 */
		public static function is_avif_encoder_available(): bool {
			static $memo = null;
			if ( null !== $memo ) {
				return $memo;
			}
			if ( function_exists( 'imageavif' ) && version_compare( PHP_VERSION, '8.2', '>=' ) ) {
				$memo = true;
				return true;
			}

			if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
				try {
					$imagick = new \Imagick();
					try {
						if ( method_exists( $imagick, 'queryFormats' ) ) {
							$formats = $imagick->queryFormats( 'AVIF*' );
							if ( ! empty( $formats ) ) {
								$memo = true;
								return true;
							}
						}
					} finally {
						if ( method_exists( $imagick, 'clear' ) ) {
							$imagick->clear();
						}
						if ( method_exists( $imagick, 'destroy' ) ) {
							$imagick->destroy();
						}
					}
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Probe only; absence means no AVIF support.
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Probe only; absence means no AVIF support.
				}
			}

			$memo = false;
			return false;
		}

		/**
		 * Get the skip-small byte threshold for image conversion.
		 *
		 * Files at or under this size are skipped: re-encoding them wastes CPU
		 * for negligible byte savings. Filterable via
		 * `wppo_skip_small_threshold_bytes`. Defaults to 5120 bytes.
		 *
		 * @since NEXT
		 *
		 * @return int Threshold in bytes (>= 0).
		 */
		public function get_skip_small_threshold(): int {
			$threshold = $this->options['image_optimisation']['skipSmallThresholdBytes'] ?? 5120;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the skip-small byte threshold.
				 *
				 * @since NEXT
				 * @param int $threshold Threshold in bytes.
				 */
				$threshold = apply_filters( 'wppo_skip_small_threshold_bytes', $threshold );
			}

			$threshold = (int) $threshold;
			if ( $threshold < 0 ) {
				$threshold = 0;
			}

			return $threshold;
		}

		/**
		 * Whether a source file should be skipped as too small to convert.
		 *
		 * Fail-open (returns false) when the file is unreadable so behaviour
		 * is unchanged for missing files — downstream guards still apply.
		 *
		 * @since NEXT
		 *
		 * @param string $path Filesystem path to the source image.
		 * @return bool True when the file is at or under the threshold.
		 */
		public function should_skip_small_file( string $path ): bool {
			$threshold = $this->get_skip_small_threshold();
			if ( $threshold <= 0 ) {
				return false;
			}

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return false;
			}

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesize() emits warnings on unreadable files; guarded above, silenced for race safety.
			$size = @filesize( $path );
			if ( false === $size ) {
				return false;
			}

			return (int) $size <= $threshold;
		}

		/**
		 * Resolve the longest-edge downscale cap in pixels.
		 *
		 * Oversized uploads are downscaled in-memory so the generated
		 * `wppo/` WebP/AVIF outputs never exceed this edge; the original
		 * upload file is never modified. `0` disables the cap (fail-open
		 * default path keeps full-size output). Filterable via
		 * `wppo_max_longest_edge_px`. Defaults to 2560 px.
		 *
		 * @since NEXT
		 *
		 * @return int Cap in pixels (>= 0). `0` means disabled.
		 */
		public function get_longest_edge_cap(): int {
			$cap = $this->options['image_optimisation']['maxLongestEdgePx'] ?? 2560;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the longest-edge downscale cap in pixels.
				 *
				 * @since NEXT
				 * @param int $cap Cap in pixels. `0` disables downscaling.
				 */
				$cap = apply_filters( 'wppo_max_longest_edge_px', $cap );
			}

			// Guard against non-scalar options/filter returns (an array would
			// coerce to 0/1 and silently disable or over-clamp the cap).
			if ( ! is_scalar( $cap ) ) {
				$cap = 2560;
			}

			$cap = (int) $cap;
			if ( $cap < 0 ) {
				$cap = 0;
			}

			return $cap;
		}

		/**
		 * Maximum source pixel count decodable before GD risks a fatal OOM.
		 *
		 * GD loads full-resolution pixels before any downscale, so this bounds
		 * decompression bombs independently of the longest-edge cap. The
		 * default is derived from the PHP memory limit (roughly 5 bytes per
		 * pixel, half the limit reserved for the decoded image).
		 *
		 * @since NEXT
		 *
		 * @return int Pixel budget (>= 1).
		 */
		public function get_max_source_pixels(): int {
			/**
			 * Filter the pre-decode source pixel budget.
			 *
			 * @since NEXT
			 *
			 * @param int $pixels Maximum decodable source pixels. `0` uses the memory-derived default.
			 */
			$override = (int) apply_filters( 'wppo_max_source_pixels', 0 );
			if ( $override > 0 ) {
				return $override;
			}

			$limit = $this->get_php_memory_limit_bytes();
			if ( $limit <= 0 ) {
				return 5000 * 5000;
			}

			$budget = (int) ( ( $limit * 0.5 ) / 5 );
			return max( 4000000, min( $budget, 80000000 ) );
		}

		/**
		 * Parse PHP's memory_limit into bytes.
		 *
		 * @since NEXT
		 *
		 * @return int Bytes, or `0` when unlimited or unknown.
		 */
		protected function get_php_memory_limit_bytes(): int {
			$raw = trim( (string) ini_get( 'memory_limit' ) );
			if ( '' === $raw || '-1' === $raw ) {
				return 0;
			}
			// `is_callable()` (not just `function_exists()`) so a broad test
			// stub that reports the helper as present cannot trigger a fatal
			// call to an undefined function; real WP satisfies both.
			if ( function_exists( 'wp_convert_hr_to_bytes' ) && is_callable( 'wp_convert_hr_to_bytes' ) ) {
				return max( 0, (int) wp_convert_hr_to_bytes( $raw ) );
			}
			$unit   = strtolower( substr( $raw, -1 ) );
			$number = (int) $raw;
			if ( 'g' === $unit ) {
				$number *= 1024 * 1024 * 1024;
			} elseif ( 'm' === $unit ) {
				$number *= 1024 * 1024;
			} elseif ( 'k' === $unit ) {
				$number *= 1024;
			}
			return $number > 0 ? $number : 0;
		}

		/**
		 * Check whether an absolute filesystem path stays inside the plugin's read allowlist.
		 *
		 * Normalizes with `wp_normalize_path()` when available, rejects NUL
		 * bytes and URL-encoded/parent traversal, then requires containment
		 * inside `ABSPATH` (outer bound) or `WP_CONTENT_DIR`. Never returns
		 * true for relative paths, URLs, or empty strings. Multisite-safe:
		 * uses only constants, no per-site state.
		 *
		 * @since NEXT
		 *
		 * @param string $path Absolute filesystem path to check.
		 * @return bool True when the path is inside the allowlist.
		 */
		public static function is_path_in_allowlist( string $path ): bool {
			if ( '' === $path || false !== strpos( $path, "\0" ) ) {
				return false;
			}
			// Reject path-segment traversal (`..` as a full segment) after
			// URL-decoding, so legitimate names like `my..photo.jpg` keep
			// working while `a/../b`, `../x`, `%2e%2e/` are refused.
			$decoded = str_replace( '\\', '/', rawurldecode( $path ) );
			if ( 1 === preg_match( '#(^|/)\.\.(/|$)#', $decoded ) ) {
				return false;
			}
			$normalized = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : str_replace( '\\', '/', $path );
			if ( 1 === preg_match( '#(^|/)\.\.(/|$)#', $normalized ) ) {
				return false;
			}
			// Only absolute filesystem paths qualify — never URLs or relative paths.
			if ( false !== strpos( $normalized, '://' ) ) {
				return false;
			}
			if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CONTENT_DIR' ) ) {
				return false;
			}
			$abspath = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( ABSPATH ) : str_replace( '\\', '/', ABSPATH );
			$content = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( WP_CONTENT_DIR ) : str_replace( '\\', '/', WP_CONTENT_DIR );
			$abspath = rtrim( $abspath, '/' ) . '/';
			$content = rtrim( $content, '/' ) . '/';
			$roots   = array( $abspath, $content );
			// Canonicalize roots too: on hosts with a symlinked docroot
			// (e.g. /var/www/html -> /data/www) a realpath-resolved source
			// would otherwise fail against the lexical roots.
			foreach ( array( ABSPATH, WP_CONTENT_DIR ) as $root ) {
				$real_root = realpath( $root );
				if ( false !== $real_root ) {
					$norm_root = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $real_root ) : str_replace( '\\', '/', $real_root );
					$roots[]   = rtrim( $norm_root, '/' ) . '/';
				}
			}
			$candidate = rtrim( $normalized, '/' );
			foreach ( $roots as $root ) {
				if ( 0 === strpos( $candidate . '/', $root ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Check whether a path is safe to unlink (strict delete allowlist).
		 *
		 * Delete targets must live inside the per-site uploads directory
		 * (blog-aware via `get_current_blog_id()`, guarded) or the plugin's
		 * `wppo` output directory under `WP_CONTENT_DIR`. Anything else —
		 * including other `ABSPATH` locations — is refused so a traversal or
		 * passthrough value from `get_img_path()` can never reach
		 * `wp_delete_file()`.
		 *
		 * @since NEXT
		 *
		 * @param string $path Absolute filesystem path proposed for deletion.
		 * @return bool True when deletion is allowed.
		 */
		public static function is_safe_delete_path( string $path ): bool {
			if ( ! self::is_path_in_allowlist( $path ) ) {
				return false;
			}
			$normalized = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : str_replace( '\\', '/', $path );
			$candidate  = rtrim( $normalized, '/' ) . '/';
			if ( ! defined( 'WP_CONTENT_DIR' ) ) {
				return false;
			}
			$content = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( WP_CONTENT_DIR ) : str_replace( '\\', '/', WP_CONTENT_DIR );
			$wppo    = rtrim( $content, '/' ) . '/wppo/';
			if ( 0 === strpos( $candidate, $wppo ) ) {
				return true;
			}
			if ( function_exists( 'wp_upload_dir' ) ) {
				$blog_id            = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				static $upload_dirs = array();
				if ( ! isset( $upload_dirs[ $blog_id ] ) ) {
					$dir  = wp_upload_dir();
					$base = isset( $dir['basedir'] ) ? (string) $dir['basedir'] : '';
					$base = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $base ) : str_replace( '\\', '/', $base );
					// Only cache non-empty basedirs: a transient
					// wp_upload_dir() failure must not poison the rest of
					// the request; retry on the next call instead.
					if ( '' !== $base ) {
						$upload_dirs[ $blog_id ] = rtrim( $base, '/' ) . '/';
					} else {
						return false;
					}
				}
				if ( '' !== $upload_dirs[ $blog_id ] && 0 === strpos( $candidate, $upload_dirs[ $blog_id ] ) ) {
					return true;
				}
				// Converted outputs rewritten under `wppo/` keep the uploads
				// sub-path (e.g. `wppo/uploads/2024/01/a.webp`); the prefix
				// check above already covers them.
			}
			return false;
		}

		/**
		 * Check whether a conversion output path is safe to write.
		 *
		 * Write targets must stay inside the strict delete set (per-site
		 * uploads directory or `WP_CONTENT_DIR/wppo`) so a passthrough
		 * value from `get_img_path()` (off-site URL returned unchanged, or
		 * `''` for a local `..` traversal) can never make the GD/Imagick
		 * encoders write into `wp-admin`, themes, or plugins.
		 *
		 * @since NEXT
		 *
		 * @param string $path Absolute filesystem path proposed for writing.
		 * @return bool True when writing is allowed.
		 */
		public static function is_safe_write_path( string $path ): bool {
			if ( '' === $path ) {
				return false;
			}
			return self::is_safe_delete_path( $path );
		}

		/**
		 * Channels-aware pre-decode pixel-budget check against the PHP memory limit.
		 *
		 * Estimates `width * height * channels` bytes (1 byte per channel)
		 * and refuses when it exceeds half the PHP `memory_limit`, leaving
		 * headroom for the GD bitmap plus encoder overhead. Falls back to the
		 * `wppo_max_source_pixels` budget when the limit is unlimited or
		 * unknown. Fail-open direction: oversize returns true (caller skips
		 * the decode and serves the original), never fatal.
		 *
		 * @since NEXT
		 *
		 * @param int $width    Source width in pixels.
		 * @param int $height   Source height in pixels.
		 * @param int $channels Channel count (clamped to 1-4, default 4).
		 * @return bool True when the image exceeds the budget and must be skipped.
		 */
		public function exceeds_pixel_budget( int $width, int $height, int $channels = 4 ): bool {
			if ( $width <= 0 || $height <= 0 ) {
				// Corrupt headers (non-positive dimensions) are not an
				// oversize skip: return false so the caller falls through
				// to the normal `failed` path instead of `skipped`.
				return false;
			}
			$channels = max( 1, min( 4, $channels ) );
			$limit    = $this->get_php_memory_limit_bytes();
			if ( $limit > 0 ) {
				$estimated = (float) $width * (float) $height * (float) $channels;
				return $estimated > ( (float) $limit * 0.5 );
			}
			// Channels-consistent fallback: the limited path budgets
			// w*h*channels bytes, so scale the pixel cap by 4 (channels is
			// always 4, conservative for GD truecolor) to enforce the same
			// budget when memory_limit is unlimited/unknown.
			return ( (float) $width * (float) $height * (float) $channels ) > ( (float) $this->get_max_source_pixels() * 4 );
		}

		/**
		 * Decode channel count for the pixel-budget estimate.
		 *
		 * GD `imagecreatefrom*()` allocates a truecolor (4 bytes/pixel)
		 * bitmap regardless of source type, so the conservative 4-channel
		 * estimate is used for every raster type to avoid underestimating
		 * decode memory near the budget limit.
		 *
		 * @since NEXT
		 *
		 * @return int Channel count (always 4, conservative for GD truecolor).
		 */
		private function get_source_channels(): int {
			return 4;
		}

		/**
		 * Downscale a decoded GD image when its longest edge exceeds the cap.
		 *
		 * Fail-open: returns the original resource unchanged when the cap is
		 * disabled, the image already fits, or any scaling step fails
		 * (missing `imagescale()`, allocation failure, exception). Only ever
		 * shrinks — never enlarges — so the original is inherently retained
		 * whenever downscaling would not reduce dimensions. Uses
		 * `imagescale()` when available with an `imagecopyresampled()`
		 * fallback path. No external HTTP, no DB.
		 *
		 * @since NEXT
		 *
		 * @param resource|\GdImage $image  Decoded GD image resource.
		 * @param int               $width  Source width in pixels.
		 * @param int               $height Source height in pixels.
		 * @return resource|\GdImage Scaled image when it shrinks output, else the original.
		 */
		public function maybe_downscale_gd_image( $image, int $width, int $height ) {
			try {
				$cap = $this->get_longest_edge_cap();
				if ( $cap <= 0 || $width <= 0 || $height <= 0 ) {
					return $image;
				}

				$longest = max( $width, $height );
				if ( $longest <= $cap ) {
					return $image;
				}

				$scale      = $cap / $longest;
				$new_width  = max( 1, (int) round( $width * $scale ) );
				$new_height = max( 1, (int) round( $height * $scale ) );
				if ( $new_width >= $width && $new_height >= $height ) {
					return $image;
				}

				if ( function_exists( 'imagescale' ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- imagescale() emits warnings on allocation failure; fall through to the resample path.
					$scaled = @imagescale( $image, $new_width, $new_height );
					if ( false !== $scaled ) {
						return $scaled;
					}
					// Allocation failure: fall through to imagecopyresampled()
					// (which may still succeed) instead of returning early.
				}

				if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagecopyresampled' ) ) {
					return $image;
				}

				$dst = imagecreatetruecolor( $new_width, $new_height );
				if ( false === $dst ) {
					return $image;
				}

				if ( function_exists( 'imagealphablending' ) ) {
					imagealphablending( $dst, false );
				}
				if ( function_exists( 'imagesavealpha' ) ) {
					imagesavealpha( $dst, true );
				}

				if ( function_exists( 'imagecolortransparent' ) && function_exists( 'imagecolorallocatealpha' ) ) {
					$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
					if ( false !== $transparent ) {
						imagefill( $dst, 0, 0, $transparent );
					}
				}

				if ( imagecopyresampled( $dst, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height ) ) {
					return $dst;
				}

				Util::destroy_gd_image( $dst );
				return $image;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fail-open: keep the original resource on any scaling failure.
				return $image;
			}
		}

		/**
		 * Resolve the smart encode quality for an output MIME type.
		 *
		 * Wraps resolve_encode_quality(): when the `smartQuality` setting is
		 * enabled, AVIF targets a lower numeric quality than WebP at equal
		 * visual quality (AVIF's efficiency). Falls back to the flat 82
		 * default chain otherwise.
		 *
		 * Format authority lives in `resolve_output_format()`, which defers to
		 * core's `image_editor_output_format` filter (guarded by `has_filter()`
		 * and `function_exists()`); this method owns only the numeric mapping
		 * and stays fail-open whatever core decides.
		 *
		 * @since NEXT
		 *
		 * @param string $mime Output MIME type (e.g. 'image/avif').
		 * @param array  $size Optional source dimensions ('width'/'height').
		 * @return int The encode quality to use (1-100).
		 */
		public function get_smart_quality( string $mime, array $size = array() ): int {
			$smart = $this->options['image_optimisation']['smartQuality'] ?? true;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter whether smart quality mapping is applied.
				 *
				 * @since NEXT
				 * @param bool $smart Whether smart quality is enabled.
				 */
				$smart = apply_filters( 'wppo_smart_quality', (bool) $smart );
			}

			if ( $smart && 'image/avif' === $mime ) {
				$webp_quality = $this->resolve_encode_quality( 'image/webp', 82, $size );
				$quality      = max( 1, $webp_quality - 20 );
				return min( 100, max( 1, $quality ) );
			}

			return $this->resolve_encode_quality( $mime, 82, $size );
		}

		/**
		 * Whether the source embeds an UltraHDR gain map.
		 *
		 * Core skips such images end-to-end when applying
		 * image_editor_output_format, because re-encoding destroys the
		 * embedded gain map. The markers live in the XMP packet near the
		 * start of JPEG files, so peeking at the first 64 KB suffices.
		 *
		 * @param string $source_image Filesystem path to the candidate image.
		 * @return bool True when an hdrgm XMP marker is present.
		 * @since NEXT
		 */
		private function is_gain_map_image( string $source_image ): bool {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded header peek; silencing missing-file notices is intentional.
			$header = (string) @file_get_contents( $source_image, false, null, 0, 65536 );

			return false !== stripos( $header, 'hdrgm:' );
		}

		/**
		 * Convert a source image into WebP and/or AVIF and record conversion status.
		 *
		 * Note on HDR / bit depth (WP 6.8+, Trac #62285): Do not hard-clamp
		 * Imagick depth to 8-bit (e.g. setImageDepth(8)). Core's
		 * `image_max_bit_depth` filter preserves HDR up to 12-bit by default
		 * and applies via `apply_filters('image_max_bit_depth', $max_depth, $original_depth)`.
		 * GD paths remain fixed-depth (acceptable). The Imagick GIF→WebP branch
		 * below intentionally does not call setImageDepth()/setDepth().
		 * Per-size quality is resolved via resolve_encode_quality() with the
		 * source dimensions so wp_get_image_encode_quality() (WP 7.1+) is
		 * size-aware. Core's wp_prevent_unsupported_mime_type_uploads handles
		 * AVIF/WebP upload blocking — no custom blocking needed.
		 *
		 * Attempts to create converted files for the requested format(s) and updates the plugin's conversion status store (`wppo_img_info`) to reflect `pending`, `completed`, or `failed` outcomes.
		 *
		 * @param string $source_image Filesystem path to the source image.
		 * @param string $format One of 'webp', 'avif', or 'both' indicating desired target format(s).
		 * @param int    $quality Quality for the converted image (0-100). Use -1 to let underlying library choose defaults.
		 * @return bool `true` if the conversion(s) for the requested format(s) completed successfully, `false` otherwise.
		 * @since 1.0.0
		 */
		public function convert_image( string $source_image, string $format = 'webp', int $quality = -1 ): bool {

			// Allowlist containment: refuse traversal/passthrough sources before
			// any filesystem, GD, or Imagick work. Fail-open with no status
			// write so an attacker-controlled string can never pollute
			// `wppo_img_info` as an option key (option bloat / key injection).
			if ( '' === $source_image || ! self::is_path_in_allowlist( $source_image ) ) {
				return false;
			}

			if ( ! in_array( $format, $this->available_format, true ) ) {
				$this->update_conversion_status( $source_image, 'failed', $format );
				return false;
			}

			// Resolve the effective output format through core's centralized
			// `image_editor_output_format` mapping (WP 6.7+) so the plugin's
			// conversion matches core's choice instead of fighting it.
			$requested_format = $format;
			$format           = $this->resolve_output_format( $source_image, $format );

			if ( 'none' === $format ) {
				// Core maps this source to a non-next-gen output (e.g.
				// HEIC -> JPEG); core owns the conversion, so skip the
				// plugin's entirely and clean the pending queue.
				if ( 'both' === $requested_format ) {
					$this->update_conversion_status( $source_image, 'skipped', 'webp' );
					$this->update_conversion_status( $source_image, 'skipped', 'avif' );
				} else {
					$this->update_conversion_status( $source_image, 'skipped', $requested_format );
				}
				return false;
			}

			if ( $format !== $requested_format ) {
				// Core's mapping overrides the plugin's configured choice (e.g.
				// requested 'avif', core maps the source MIME to WebP): clean
				// the stale pending entry for the requested format before
				// converting the effective one.
				if ( 'both' === $requested_format ) {
					$this->update_conversion_status( $source_image, 'skipped', 'webp' === $format ? 'avif' : 'webp' );
				} else {
					$this->update_conversion_status( $source_image, 'skipped', $requested_format );
				}
			}

			// Skip UltraHDR / gain-map sources: core intentionally preserves
			// them end-to-end, and a server-side re-encode would strip the
			// embedded gain map. Filterable for pipelines that want them.
			if ( ! apply_filters( 'wppo_convert_gain_map_images', false ) && $this->is_gain_map_image( $source_image ) ) {
				$this->update_conversion_status( $source_image, 'skipped', $format );
				return false;
			}

			// Skip WebP conversion when WP 6.7+ core handles it natively.
			if ( in_array( $format, array( 'webp', 'both' ), true ) && self::core_handles_next_gen() ) {
				$this->update_conversion_status( $source_image, 'skipped', $format );
				return false;
			}

			/*
			 * Resolve default quality using core APIs when available:
			 * - WP 7.1+: wp_get_image_encode_quality() (size-aware, honors the
			 *   wp_editor_set_quality/jpeg_quality filters per registered size).
			 * - WP 6.7-7.0: wp_image_quality() (flat per-MIME quality).
			 */
			if ( -1 === $quality ) {
				$size = $this->get_source_image_dimensions( $source_image );

				if ( 'both' === $format ) {
					$avif_quality = $this->get_smart_quality( 'image/avif', $size );
					$webp_quality = $this->get_smart_quality( 'image/webp', $size );
				} else {
					$mime    = in_array( $format, array( 'avif', 'both' ), true ) ? 'image/avif' : 'image/webp';
					$quality = $this->get_smart_quality( $mime, $size );
				}
			}
			if ( -1 === $quality ) {
				$quality = 82;
			}
			if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagecreatefrompng' ) ) {
				$this->update_conversion_status( $source_image, 'failed', $format );
				return false;
			}

			if ( ! file_exists( $source_image ) || ! is_readable( $source_image ) ) {
				$this->update_conversion_status( $source_image, 'failed', $format );
				return false;
			}

			// Symlink canonicalization: a symlink inside uploads pointing
			// outside (e.g. `uploads/evil-link` -> `/etc`) passes the
			// lexical gate, so resolve with `realpath()` and re-assert
			// containment before any decode. Fail-open with no status
			// write on mismatch, keeping `wppo_img_info` unpolluted.
			$real_source = realpath( $source_image );
			if ( false !== $real_source ) {
				$resolved_source = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $real_source ) : str_replace( '\\', '/', $real_source );
				if ( ! self::is_path_in_allowlist( $resolved_source ) ) {
					return false;
				}
				$source_image = $resolved_source;
			}

			// Skip-small threshold: tiny files cost more CPU than they save in
			// bytes. Skipped files keep the restorable original untouched.
			if ( $this->should_skip_small_file( $source_image ) ) {
				$this->update_conversion_status( $source_image, 'skipped', $format );
				return false;
			}

			// Security Fix: Prevent File Size & Memory Bomb DoS.
			$max_bytes = apply_filters( 'wppo_filesize_limit_bytes', 20 * 1024 * 1024 );
			if ( filesize( $source_image ) > $max_bytes ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Error: Image exceeds maximum filesize limit' );
				}
				$this->update_conversion_status( $source_image, 'failed', $format );
				return false;
			}

			// getimagesize() parses the headers without decoding pixel data into memory.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$image_info = @getimagesize( $source_image );

			if ( empty( $image_info ) ) {
				$this->update_conversion_status( $source_image, 'failed', $format );
				return false;
			}

			// Two-tier guard against dimension-based memory exhaustion:
			// Guard 1 bounds the RAW source pixel count against a
			// memory-derived budget before any GD decode, so a decompression
			// bomb cannot reach imagecreatefrom*(). Guard 2 keeps the
			// wppo_max_dimensions policy, compared against the cap-scaled
			// dimensions so an image that would shrink to the longest-edge cap
			// is still converted (issue #985).
			$check_w = (int) $image_info[0];
			$check_h = (int) $image_info[1];

			// Guard 1: pre-decode channels-aware pixel budget vs the PHP
			// memory limit (e.g. a 40MP upload on a 256M host). Oversize
			// sources skip conversion fail-open: the original is served
			// unoptimised and the queue entry is marked `skipped`, never
			// `failed` and never fatal.
			$channels = $this->get_source_channels();
			if ( $this->exceeds_pixel_budget( $check_w, $check_h, $channels ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Error: Source image pixel count exceeds the pre-decode memory budget' );
				}
				$this->update_conversion_status( $source_image, 'skipped', $format );
				return false;
			}

			// Guard 2: cap-scaled wppo_max_dimensions policy.
			$max_dims = apply_filters(
				'wppo_max_dimensions',
				array(
					'width'  => 5000,
					'height' => 5000,
				)
			);
			$cap      = $this->get_longest_edge_cap();
			if ( $cap > 0 ) {
				$longest = max( $check_w, $check_h );
				if ( $longest > $cap ) {
					$scale   = $cap / $longest;
					$check_w = max( 1, (int) round( $check_w * $scale ) );
					$check_h = max( 1, (int) round( $check_h * $scale ) );
				}
			}
			if ( $check_w > $max_dims['width'] || $check_h > $max_dims['height'] ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Error: Image dimensions exceed maximum allowed' );
				}
				$this->update_conversion_status( $source_image, 'failed', $format );
				return false;
			}

			$image_type = $image_info[2];
			$image      = null;

			try {
				switch ( $image_type ) {
					case IMAGETYPE_JPEG:
						$image = imagecreatefromjpeg( $source_image );

						if ( ! $image ) {
							$this->update_conversion_status( $source_image, 'failed', $format );
							return false;
						}

						break;

					case IMAGETYPE_PNG:
						$image = imagecreatefrompng( $source_image );

						if ( ! $image ) {
							$this->update_conversion_status( $source_image, 'failed', $format );
							return false;
						}

						$image = $this->convert_palette_to_truecolor( $image );
						imagealphablending( $image, true ); // For transparency.
						imagesavealpha( $image, true );
						break;

					case IMAGETYPE_WEBP:
						if ( in_array( $format, array( 'avif', 'both' ), true ) ) {
							if ( ! self::is_avif_encoder_available() || ! function_exists( 'imageavif' ) ) {
								$this->update_conversion_status( $source_image, 'failed', $format );
								return false;
							}

							if ( $this->is_animated_webp( $source_image ) ) {
								$this->update_conversion_status( $source_image, 'failed', $format );
								return false;
							}

							if ( ! function_exists( 'imagecreatefromwebp' ) ) {
								$this->update_conversion_status( $source_image, 'failed', $format );
								return false;
							}

							try {
								$image = imagecreatefromwebp( $source_image );
								if ( ! $image ) {
									$this->update_conversion_status( $source_image, 'failed', $format );
									return false;
								}

								// Longest-edge cap also applies to the WebP-source
								// AVIF path (issue #985 follow-up): shrink the
								// in-memory GD resource so the AVIF output
								// respects the cap; fail-open keeps original.
								$downscaled = $this->maybe_downscale_gd_image( $image, (int) $image_info[0], (int) $image_info[1] );
								if ( $downscaled !== $image ) {
									Util::destroy_gd_image( $image );
									$image = $downscaled;
								}

								$avif_path = $this->get_img_path( $source_image, 'avif' );
								if ( ! self::is_safe_write_path( $avif_path ) ) {
									if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
										Util::destroy_gd_image( $image );
									}
									$this->update_conversion_status( $source_image, 'failed', $format );
									return false;
								}
								if ( ! Util::prepare_cache_dir( dirname( $avif_path ) ) ) {
									if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
										Util::destroy_gd_image( $image );
									}
									$this->update_conversion_status( $source_image, 'failed', $format );
									return false;
								}

								if ( imageavif( $image, $avif_path, $avif_quality ?? $quality ) ) {
									$this->update_conversion_status( $source_image, 'completed', 'avif' );
								} else {
									if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
										Util::destroy_gd_image( $image );
									}
									$this->update_conversion_status( $source_image, 'failed', $format );
									return false;
								}
							} catch ( \Exception $e ) {
								if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
									Util::destroy_gd_image( $image );
								}
								$this->update_conversion_status( $source_image, 'failed', $format );
								return false;
							}
						}

						// Extract placeholder data from WebP source GD resource before cleanup.
						if ( null !== $image && $image instanceof \GdImage ) {
							$rel_path       = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source_image ) );
							$dominant_color = $this->extract_dominant_color( $image );
							$lqip           = $this->generate_lqip( $image );
							$this->store_placeholder_data( $rel_path, $dominant_color, $lqip );
						}

						// When $image is null (format is 'webp' and didn't enter the AVIF branch),
						// create a temporary GD resource just for placeholder extraction.
						if ( null === $image && ! $this->is_animated_webp( $source_image ) && function_exists( 'imagecreatefromwebp' ) ) {
							$webp_gd = imagecreatefromwebp( $source_image );
							if ( $webp_gd instanceof \GdImage ) {
								$rel_path       = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source_image ) );
								$dominant_color = $this->extract_dominant_color( $webp_gd );
								$lqip           = $this->generate_lqip( $webp_gd );
								$this->store_placeholder_data( $rel_path, $dominant_color, $lqip );
								Util::destroy_gd_image( $webp_gd );
							}
						}

						// Destroy the decoded WebP GD handle on the AVIF path
						// (the generic path cleans up after encoding; this early
						// return must not leak it).
						if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
							Util::destroy_gd_image( $image );
						}

						return true;
					case IMAGETYPE_GIF:
						if ( ! extension_loaded( 'imagick' ) ) {
							$this->update_conversion_status( $source_image, 'failed', $format );
							return false;
						}

						if ( 'avif' === $format ) {
							$this->update_conversion_status( $source_image, 'failed', $format );
							return false;
						}

						$webp_path = $this->get_img_path( $source_image, 'webp' );

						try {

							// Write-target containment first: a passthrough/empty
							// get_img_path() value must never reach Imagick, and
							// existence alone must never confer `completed`
							// status on an ungated target.
							if ( ! self::is_safe_write_path( $webp_path ) ) {
								$this->update_conversion_status( $source_image, 'failed', $format );
								return false;
							}
							if ( file_exists( $webp_path ) ) {
								$this->update_conversion_status( $source_image, 'completed', 'webp' );
								return true;
							}
							// Initialize Imagick and read the image file.
							// Do not hard-clamp Imagick depth to 8-bit — core's
							// image_max_bit_depth filter (WP 6.8+, Trac #62285)
							// preserves HDR up to 12-bit by default.
							$imagick = new \Imagick();
							// Bound decoded memory + area to the pre-decode pixel
							// budget so multi-frame GIFs cannot OOM the worker.
							// Both calls are guarded together: on Imagick builds
							// without setResourceLimit the caps are best-effort
							// no-ops and the read below still runs fail-open.
							try {
								if ( method_exists( $imagick, 'setResourceLimit' ) ) {
									if ( defined( 'Imagick::RESOURCETYPE_MEMORY' ) ) {
										$imagick->setResourceLimit( \Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
									}
									if ( defined( 'Imagick::RESOURCETYPE_AREA' ) ) {
										$imagick->setResourceLimit( \Imagick::RESOURCETYPE_AREA, $this->get_max_source_pixels() );
									}
								}
							} catch ( \Throwable $area_limit_error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fail-open: resource caps are best-effort hardening.
							}
							$imagick->readImage( $source_image );

							// Longest-edge cap for the GIF-via-Imagick path
							// (issue #985 follow-up): shrink coalesced frames
							// so the WebP output respects the cap. Fail-open:
							// any failure keeps the original frames.
							try {
								$edge_cap = $this->get_longest_edge_cap();
								if ( $edge_cap > 0 ) {
									$gif_w       = $imagick->getImageWidth();
									$gif_h       = $imagick->getImageHeight();
									$gif_longest = max( (int) $gif_w, (int) $gif_h );
									if ( $gif_longest > $edge_cap ) {
										// coalesceImages()/deconstructImages() each
										// return a NEW Imagick; clear the prior handle
										// before reassigning so it is not leaked.
										$coalesced = $imagick->coalesceImages();
										$imagick->clear();
										$imagick = $coalesced;
										foreach ( $imagick as $frame ) {
											$frame->thumbnailImage( $edge_cap, $edge_cap, true );
										}
										$deconstructed = $imagick->deconstructImages();
										$imagick->clear();
										$imagick = $deconstructed;
									}
								}
							} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fail-open: keep original frames on any resize failure.
							}

							// Check if the image has transparency (alpha channel).
							$alpha_channel    = $imagick->getImageAlphaChannel();
							$has_transparency = \Imagick::ALPHACHANNEL_UNDEFINED !== $alpha_channel && \Imagick::ALPHACHANNEL_OPAQUE !== $alpha_channel;

							// Set WebP format.
							$imagick->setImageFormat( 'webp' );

							// If transparent, use lossless compression for WebP to retain transparency.
							if ( $has_transparency ) {
								$imagick->setImageCompressionQuality( $webp_quality ?? $quality );
								$imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_ACTIVATE );
								$imagick->setOption( 'webp:lossless', 'true' );
							} else {
								// For non-transparent images, use lossy compression.
								$imagick->setImageCompressionQuality( $webp_quality ?? $quality );
								$imagick->setOption( 'webp:lossless', 'false' );
							}

							Util::prepare_cache_dir( dirname( $webp_path ) );
							// Write the WebP file.
							if ( $imagick->writeImages( $webp_path, true ) ) {
								$this->update_conversion_status( $source_image, 'completed', 'webp' );
							} else {
								$this->update_conversion_status( $source_image, 'failed', 'webp' );
								return false;
							}

							// Extract placeholder data from GIF via Imagick->GD conversion.
							try {
								$gif_gd = imagecreatefromstring( (string) $imagick->getImageBlob() );
								if ( $gif_gd instanceof \GdImage ) {
									$rel_path       = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source_image ) );
									$dominant_color = $this->extract_dominant_color( $gif_gd );
									$lqip           = $this->generate_lqip( $gif_gd );
									$this->store_placeholder_data( $rel_path, $dominant_color, $lqip );
									Util::destroy_gd_image( $gif_gd );
								}
							} catch ( \Exception $e ) {
								Log::add( __( 'Failed to extract placeholder data from GIF image.', 'performance-optimisation' ) );
								if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
									// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
									error_log( 'WPPO: Failed to extract placeholder data from GIF: ' . $e->getMessage() );
								}
							}

							$imagick->clear();
							return true;
						} catch ( \Exception $e ) {
							if ( isset( $imagick ) && $imagick instanceof \Imagick ) {
								$imagick->clear();
							}
							$this->update_conversion_status( $source_image, 'failed', $format );
							// Strict delete containment: only unlink when the
							// target is inside the uploads/wppo allowlist, so a
							// traversal or passthrough path can never delete
							// outside it. Fail-open keeps the file intact.
							if ( self::is_safe_delete_path( $webp_path ) && function_exists( 'wp_delete_file' ) ) {
								wp_delete_file( $webp_path );
							}
							return false;
						}
					default:
						$this->update_conversion_status( $source_image, 'failed', $format );
						return false; // Unsupported format.
				}

				// Longest-edge cap (issue #985): downscale oversized GD resources
				// in-memory so generated `wppo/` outputs respect the cap. The
				// original upload file is never modified; on any scaling failure
				// the original resource is kept (fail-open, never fatal).
				if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
					$downscaled = $this->maybe_downscale_gd_image( $image, (int) $image_info[0], (int) $image_info[1] );
					if ( $downscaled !== $image ) {
						Util::destroy_gd_image( $image );
						$image = $downscaled;
					}
				}

				$success = true;

				// AVIF-first encode ordering: AVIF is attempted before WebP so the
				// highest-efficiency output wins. On AVIF-encoder-unavailable the
				// path fails open to WebP, else the original is kept (never fatal).
				$avif_first    = ! empty( $this->options['image_optimisation']['avifFirst'] ?? true );
				$avif_encoder  = self::is_avif_encoder_available();
				$encode_format = $format;
				if ( 'avif' === $format && ! $avif_encoder ) {
					$encode_format = 'webp';
					$this->update_conversion_status( $source_image, 'skipped', 'avif' );
				}

				if ( in_array( $encode_format, array( 'avif', 'both' ), true ) && ( $avif_first || 'webp' !== $encode_format ) ) {
					$avif_path = $this->get_img_path( $source_image, 'avif' );

					if ( ! self::is_safe_write_path( $avif_path ) ) {
						$success = false;
						$this->update_conversion_status( $source_image, 'failed', 'avif' );
					} elseif ( ! file_exists( $avif_path ) ) {
						if ( ! $avif_encoder || ! function_exists( 'imageavif' ) || ! Util::prepare_cache_dir( dirname( $avif_path ) ) || ! imageavif( $image, $avif_path, $avif_quality ?? $quality ) ) {
							$success = false;
							$this->update_conversion_status( $source_image, 'failed', 'avif' );
						} else {
							$this->update_conversion_status( $source_image, 'completed', 'avif' );
						}
					} else {
						$this->update_conversion_status( $source_image, 'completed', 'avif' );
					}
				}

				if ( in_array( $encode_format, array( 'webp', 'both' ), true ) ) {
					$webp_path = $this->get_img_path( $source_image, 'webp' );

					if ( ! self::is_safe_write_path( $webp_path ) ) {
						$success = false;
						$this->update_conversion_status( $source_image, 'failed', 'webp' );
					} elseif ( ! file_exists( $webp_path ) ) {
						if ( ! function_exists( 'imagewebp' ) || ! Util::prepare_cache_dir( dirname( $webp_path ) ) || ! imagewebp( $image, $webp_path, $webp_quality ?? $quality ) ) {
							$success = false;
							$this->update_conversion_status( $source_image, 'failed', 'webp' );
						} else {
							$this->update_conversion_status( $source_image, 'completed', 'webp' );
						}
					} else {
						$this->update_conversion_status( $source_image, 'completed', 'webp' );
					}
				}

				// Extract placeholder data (dominant color + LQIP) whenever the source image
				// was successfully decoded, independent of individual WebP/AVIF encode outcomes.
				if ( null !== $image && $image instanceof \GdImage ) {
					$rel_path       = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $source_image ) );
					$dominant_color = $this->extract_dominant_color( $image );
					$lqip           = $this->generate_lqip( $image );
					$this->store_placeholder_data( $rel_path, $dominant_color, $lqip );
				}

				if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
					Util::destroy_gd_image( $image );
				}

				return $success;
			} catch ( \Exception $e ) {

				if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
					Util::destroy_gd_image( $image );
				}

				$this->update_conversion_status( $source_image, 'failed', $format );

				// Log failure for debugging — include details only in debug mode.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Image conversion failed: ' . str_replace( ABSPATH, '', $e->getMessage() ) );
				}
				Log::add( __( 'Image conversion failed.', 'performance-optimisation' ) );

				return false;
			}
		}

		/**
		 * Convert an image palette to true color if it is not already in true color.
		 *
		 * @param \GdImage $image The image resource.
		 * @return \GdImage The true color image resource.
		 * @since 1.0.0
		 */
		private function convert_palette_to_truecolor( $image ) {
			if ( ! imageistruecolor( $image ) ) {
				$width     = imagesx( $image );
				$height    = imagesy( $image );
				$truecolor = imagecreatetruecolor( $width, $height );
				if ( false === $truecolor ) {
					return $image;
				}
				imagealphablending( $truecolor, false );
				imagesavealpha( $truecolor, true );
				$transparent = imagecolorallocatealpha( $truecolor, 255, 255, 255, 127 );
				imagefill( $truecolor, 0, 0, $transparent );
				imagecopy( $truecolor, $image, 0, 0, 0, 0, $width, $height );
				Util::destroy_gd_image( $image );
				return $truecolor;
			}
			return $image;
		}

		/**
		 * Extract dominant color from a GD image resource.
		 *
		 * Samples pixels at a reduced stride to compute the average color.
		 *
		 * @since NEXT
		 *
		 * @param \GdImage $image The GD image resource.
		 * @return string Hex color string (e.g. '#aabbcc').
		 */
		private function extract_dominant_color( $image ): string {
			if ( ! $image instanceof \GdImage ) {
				return '#cfd4db';
			}

			$width  = imagesx( $image );
			$height = imagesy( $image );
			// Ensure a minimum number of samples (~500 pixels) for accuracy
			// across both small and large non-square images.
			$min_samples = 500;
			$sample_rate = max( 1, (int) sqrt( ( $width * $height ) / $min_samples ) );
			$total_r     = 0;
			$total_g     = 0;
			$total_b     = 0;
			$pixel_count = 0;

			// phpcs:ignore Generic.CodeAnalysis.JumbledIncrementer -- $sample_rate is a read-only step value, not a loop incrementer variable.
			for ( $y = 0; $y < $height; $y += $sample_rate ) {
				// phpcs:ignore Generic.CodeAnalysis.JumbledIncrementer
				for ( $x = 0; $x < $width; $x += $sample_rate ) {
					$rgb = imagecolorat( $image, $x, $y );
					if ( false !== $rgb ) {
						$total_r += ( $rgb >> 16 ) & 0xFF;
						$total_g += ( $rgb >> 8 ) & 0xFF;
						$total_b += $rgb & 0xFF;
						++$pixel_count;
					}
				}
			}

			if ( 0 === $pixel_count ) {
				return '#cfd4db';
			}

			$avg_r = round( $total_r / $pixel_count );
			$avg_g = round( $total_g / $pixel_count );
			$avg_b = round( $total_b / $pixel_count );

			return sprintf( '#%02x%02x%02x', $avg_r, $avg_g, $avg_b );
		}

		/**
		 * Generate a Low-Quality Image Placeholder (LQIP) from a GD image resource.
		 *
		 * Creates a 20x20 JPEG thumbnail and returns it as a base64 data URI.
		 *
		 * @since NEXT
		 *
		 * @param \GdImage $image The GD image resource.
		 * @return string Base64-encoded data URI, or empty string on failure.
		 */
		private function generate_lqip( $image ): string {
			if ( ! $image instanceof \GdImage || ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagecopyresampled' ) || ! function_exists( 'imagejpeg' ) ) {
				return '';
			}

			$orig_width  = imagesx( $image );
			$orig_height = imagesy( $image );

			if ( false === $orig_width || false === $orig_height || $orig_width < 1 || $orig_height < 1 ) {
				return '';
			}

			$thumb_width  = 20;
			$thumb_height = (int) round( $orig_height * ( $thumb_width / $orig_width ) );
			if ( $thumb_height < 1 ) {
				$thumb_height = 1;
			}
			// Cap height to prevent overly large LQIP data URIs.
			$thumb_height = min( $thumb_height, 200 );

			$thumb = imagecreatetruecolor( $thumb_width, $thumb_height );
			if ( false === $thumb ) {
				return '';
			}

			// Fill with white to avoid black background for transparent PNG/GIF sources.
			$white = imagecolorallocate( $thumb, 255, 255, 255 );
			imagefill( $thumb, 0, 0, $white );

			imagecopyresampled( $thumb, $image, 0, 0, 0, 0, $thumb_width, $thumb_height, $orig_width, $orig_height );

			if ( ! ob_start() ) {
				Util::destroy_gd_image( $thumb );
				return '';
			}
			// LQIP thumbnails are intentionally low-quality placeholders: a fixed
			// quality keeps the inline base64 payload small. Do NOT route this
			// through core quality resolution (wp_get_image_encode_quality() /
			// wp_image_quality()), which would encode the ~20px placeholder at
			// full JPEG quality (~82) and roughly double every data URI.
			$success = imagejpeg(
				$thumb,
				null,
				40
			);
			$data    = ob_get_clean();

			if ( ! $success || false === $data ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO: Failed to generate LQIP thumbnail' );
				}
			}

			Util::destroy_gd_image( $thumb );

			if ( ! $success || false === $data ) {
				return '';
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return 'data:image/jpeg;base64,' . base64_encode( $data );
		}

		/**
		 * Store dominant color and LQIP data for an image atomically via the
		 * existing deferred-commit pattern (wppo_img_info).
		 *
		 * Idempotent on WP 7.1+ double-fire (`wp_generate_attachment_metadata`
		 * fires on both `create` and `finalize`): when the stored values for
		 * this rel_path already equal the new ones, no atomic update is
		 * scheduled, avoiding a duplicate DB write. The deferred commit also
		 * dedupes via array_merge, but this early bail avoids the shutdown
		 * write entirely.
		 *
		 * @since NEXT
		 *
		 * @param string $rel_path       The relative image path (ABSPATH-stripped).
		 * @param string $dominant_color Hex color string.
		 * @param string $lqip           LQIP data URI (empty string if not generated).
		 * @return void
		 */
		private function store_placeholder_data( string $rel_path, string $dominant_color, string $lqip ): void {
			// Idempotency for WP 7.1+ double-fire (create+finalize): skip when unchanged.
			$existing = self::get_img_info();
			if ( isset( $existing['dominant_color'][ $rel_path ] ) && $existing['dominant_color'][ $rel_path ] === $dominant_color ) {
				$existing_lqip = $existing['lqip'][ $rel_path ] ?? '';
				if ( empty( $lqip ) || $existing_lqip === $lqip ) {
					return;
				}
			}

			self::update_img_info_atomic(
				function ( $img_info ) use ( $rel_path, $dominant_color, $lqip ) {
					if ( ! isset( $img_info['dominant_color'] ) || ! is_array( $img_info['dominant_color'] ) ) {
						$img_info['dominant_color'] = array();
					}
					if ( ! isset( $img_info['lqip'] ) || ! is_array( $img_info['lqip'] ) ) {
						$img_info['lqip'] = array();
					}

					$img_info['dominant_color'][ $rel_path ] = $dominant_color;
					if ( ! empty( $lqip ) ) {
						$img_info['lqip'][ $rel_path ] = $lqip;
					}

					return $img_info;
				}
			);
		}

		/**
		 * Store placeholder data for multiple rel_paths in one atomic update.
		 *
		 * Single read-copy-merge cycle for a whole upload (original + N
		 * sub-sizes) instead of N full get/update cycles over the growing
		 * wppo_img_info array. Idempotent: unchanged entries are skipped
		 * before scheduling the atomic write.
		 *
		 * @since NEXT
		 * @param array<string, array{color: string, lqip: string}> $batch Map of rel_path => data.
		 * @return void
		 */
		private function store_placeholder_data_batch( array $batch ): void {
			if ( empty( $batch ) ) {
				return;
			}
			$existing = self::get_img_info();
			$filtered = array();
			foreach ( $batch as $rel_path => $data ) {
				$rel_path = (string) $rel_path;
				if ( '' === $rel_path || ! is_array( $data ) ) {
					continue;
				}
				$color = isset( $data['color'] ) ? (string) $data['color'] : '';
				$lqip  = isset( $data['lqip'] ) ? (string) $data['lqip'] : '';
				if ( isset( $existing['dominant_color'][ $rel_path ] ) && $existing['dominant_color'][ $rel_path ] === $color ) {
					$existing_lqip = $existing['lqip'][ $rel_path ] ?? '';
					if ( empty( $lqip ) || $existing_lqip === $lqip ) {
						continue;
					}
				}
				$filtered[ $rel_path ] = array(
					'color' => $color,
					'lqip'  => $lqip,
				);
			}
			if ( empty( $filtered ) ) {
				return;
			}
			self::update_img_info_atomic(
				function ( $img_info ) use ( $filtered ) {
					if ( ! isset( $img_info['dominant_color'] ) || ! is_array( $img_info['dominant_color'] ) ) {
						$img_info['dominant_color'] = array();
					}
					if ( ! isset( $img_info['lqip'] ) || ! is_array( $img_info['lqip'] ) ) {
						$img_info['lqip'] = array();
					}
					foreach ( $filtered as $rel_path => $data ) {
						$img_info['dominant_color'][ $rel_path ] = $data['color'];
						if ( '' !== $data['lqip'] ) {
							$img_info['lqip'][ $rel_path ] = $data['lqip'];
						}
					}
					return $img_info;
				}
			);
		}

		/**
		 * Get placeholder data (dominant_color, lqip) from the shared wppo_img_info option.
		 *
		 * @since NEXT
		 *
		 * @return array{dominant_color: array<string, string>, lqip: array<string, string>}
		 */
		public static function get_placeholder_info(): array {
			$info = self::get_img_info();
			return array(
				'dominant_color' => $info['dominant_color'] ?? array(),
				'lqip'           => $info['lqip'] ?? array(),
			);
		}

		/**
		 * Clean up placeholder data (dominant_color, lqip) when an attachment is deleted.
		 *
		 * Removes entries for the main file AND all registered resized versions
		 * from wppo_img_info. On WP 7.1+ HEIC uploads the browser worker (~13 MB
		 * wasm-vips) converts HEIC→JPEG and retains the original HEIC path in
		 * `$metadata['original']['file']` as a companion; that companion path is
		 * also purged here so CDN/Edge purgers (CDN_Purger, Edge_Purger) do not
		 * retain stale placeholder references for the original file.
		 *
		 * Only the plugin's own `wppo_img_info` buckets are ever mutated, and
		 * only through the explicit `$allowed_keys` allowlist below. This
		 * method never calls `delete_post_meta()` / `update_post_meta()` and
		 * explicitly skips any underscore-prefixed key, so protected `_wp_*`
		 * attachment meta is provably untouched.
		 *
		 * @since NEXT
		 *
		 * @param int $post_id The attachment ID.
		 * @return void
		 */
		public static function clean_placeholder_on_delete( int $post_id ): void {
			if ( $post_id <= 0 ) {
				return;
			}
			if ( ! function_exists( 'get_attached_file' ) ) {
				return;
			}
			$file_path = get_attached_file( $post_id );
			if ( ! $file_path ) {
				return;
			}
			$rel_paths   = array();
			$main_rel    = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file_path ) );
			$rel_paths[] = $main_rel;

			// Also clean up resized versions from attachment metadata.
			// Guarded for WP 6.2 compat: without the core helper there is no
			// metadata to expand, and the main file entry above still cleans.
			$metadata = function_exists( 'wp_get_attachment_metadata' ) ? wp_get_attachment_metadata( $post_id ) : false;
			if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$dir = dirname( $file_path );
				foreach ( $metadata['sizes'] as $size_data ) {
					if ( isset( $size_data['file'] ) ) {
						$size_path   = wp_normalize_path( $dir . '/' . $size_data['file'] );
						$size_rel    = str_replace( wp_normalize_path( ABSPATH ), '', $size_path );
						$rel_paths[] = $size_rel;
					}
				}
			}

			// WP 7.1+ HEIC→JPEG companion retention: $metadata['original']['file']
			// holds the original HEIC basename (e.g. 'photo.heic') when the
			// browser worker converts it. Include it for deletion parity with
			// sub-sizes and for CDN/Edge purger documentation.
			$orig_file = '';
			if ( isset( $metadata['original'] ) && is_array( $metadata['original'] ) && ! empty( $metadata['original']['file'] ) && is_string( $metadata['original']['file'] ) ) {
				$orig_file = $metadata['original']['file'];
			} elseif ( isset( $metadata['original'] ) && is_string( $metadata['original'] ) && '' !== $metadata['original'] ) {
				// Fallback: some builds store original as a plain string file name.
				$orig_file = $metadata['original'];
			}
			if ( '' !== $orig_file ) {
				$dir = dirname( $file_path );
				// Core stores the companion as a basename relative to the uploads subdir.
				if ( false === strpos( $orig_file, '/' ) ) {
					$orig_path = wp_normalize_path( $dir . '/' . $orig_file );
				} else {
					$orig_path = wp_normalize_path( $orig_file );
					if ( false === strpos( $orig_path, wp_normalize_path( ABSPATH ) ) ) {
						$orig_path = wp_normalize_path( $dir . '/' . ltrim( $orig_file, '/' ) );
					}
				}
				$orig_rel    = str_replace( wp_normalize_path( ABSPATH ), '', $orig_path );
				$rel_paths[] = $orig_rel;
			}

			// Read the latest state via get_img_info() which may include
			// deferred-but-not-yet-committed entries from the current request.
			// Underscore-meta guard: only the plugin's own non-underscored
			// buckets are pruned; any current or future `_wp_*` / `_`-prefixed
			// key is skipped, and postmeta APIs are never called here.
			$allowed_keys = array( 'dominant_color', 'lqip' );
			$img_info     = self::get_img_info();
			$changed      = false;
			foreach ( $allowed_keys as $key ) {
				if ( ! is_string( $key ) || '' === $key || '_' === $key[0] ) {
					continue;
				}
				foreach ( $rel_paths as $rel ) {
					if ( isset( $img_info[ $key ][ $rel ] ) ) {
						unset( $img_info[ $key ][ $rel ] );
						$changed = true;
					}
				}
			}
			if ( $changed ) {
				self::set_img_info( $img_info );
			}
		}

		/**
		 * Check if a WebP image is animated.
		 *
		 * @param string $file Path to the WebP file.
		 * @return bool True if the WebP image is animated, false otherwise.
		 * @since 1.0.0
		 */
		private function is_animated_webp( $file ) {
			if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $file, 'rb' );
			if ( false === $handle ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$header = fread( $handle, 40 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			if ( false === $header || strlen( $header ) < 12 ) {
				return false;
			}

			if ( 'RIFF' !== substr( $header, 0, 4 ) || 'WEBP' !== substr( $header, 8, 4 ) ) {
				return false;
			}

			return false !== strpos( $header, 'ANIM' );
		}


		/**
		 * Compute the filesystem path where a converted image (WebP or AVIF) should be stored.
		 *
		 * If the source refers to a known local file or can be resolved to one, the returned path
		 * is the same directory and filename with the extension replaced by the requested format,
		 * and rewritten under the plugin's `wppo` directory when the file is inside WP_CONTENT_DIR.
		 * Off-site URLs whose host matches neither the site's content URL nor its home URL are
		 * returned unchanged so a remote origin is never rewritten into a filesystem path.
		 * If the source cannot be resolved to a safe local path, the original source string is returned.
		 *
		 * @param string $source_image Absolute filesystem path or URL of the source image.
		 * @param string $format Desired output format; typically 'webp' or 'avif'.
		 * @return string Filesystem path where the converted image should be saved, or the original
		 *                $source_image if a safe local path cannot be determined.
		 * @since 1.0.0
		 */
		public static function get_img_path( string $source_image, string $format = 'webp' ): string {
			$normalized_source = wp_normalize_path( $source_image );
			$is_already_local  = path_is_absolute( $normalized_source ) && (
			0 === strpos( $normalized_source, wp_normalize_path( ABSPATH ) ) ||
			0 === strpos( $normalized_source, wp_normalize_path( WP_CONTENT_DIR ) )
			);

			if ( $is_already_local ) {
				// Segment-only traversal check (after URL-decoding) so
				// legitimate names like `my..photo.jpg` keep working while
				// `a/../b` and encoded variants are refused.
				$decoded_local = str_replace( '\\', '/', rawurldecode( $normalized_source ) );
				if ( 1 === preg_match( '#(^|/)\.\.(/|$)#', $decoded_local ) || 1 === preg_match( '#(^|/)\.\.(/|$)#', $normalized_source ) ) {
					return '';
				}
				$local_path = $normalized_source;
			} else {
				// Security: only resolve URLs hosted on this site. Off-site
				// URLs (CDNs, hotlinked images) are returned unchanged so a
				// remote origin can never be rewritten into a filesystem
				// path under ABSPATH.
				$source_host = wp_parse_url( $source_image, PHP_URL_HOST );

				if ( $source_host ) {
					$content_host = strtolower( (string) wp_parse_url( Util::cached_content_url( '' ), PHP_URL_HOST ) );
					$home_host    = strtolower( (string) wp_parse_url( Util::cached_home_url(), PHP_URL_HOST ) );
					$source_host  = strtolower( (string) $source_host );

					if ( $source_host !== $content_host && $source_host !== $home_host ) {
						return $source_image;
					}
				}

				// Use Util::get_local_path to get a clean local path from URL or existing path.
				$local_path = Util::get_local_path( $source_image );

				if ( empty( $local_path ) ) {
					// If Util::get_local_path failed, manually resolve if it's a URL.
					$home_url         = Util::cached_home_url();
					$content_url_base = untrailingslashit( Util::cached_content_url( '' ) );

					$local_base = wp_normalize_path( ABSPATH );

					if ( 0 === strpos( $source_image, $content_url_base ) ) {
						$relative_path = substr( $source_image, strlen( $content_url_base ) );
						$local_base    = wp_normalize_path( WP_CONTENT_DIR );
					} elseif ( 0 === strpos( $source_image, $home_url ) ) {
						$relative_path = substr( $source_image, strlen( $home_url ) );
					} else {
						$relative_path = $source_image;
					}

					// Security: block path-segment traversal (`..` as a full
					// segment after URL-decoding) so `my..photo.jpg` stays valid.
					$decoded_rel = str_replace( '\\', '/', rawurldecode( $relative_path ) );
					if ( 1 === preg_match( '#(^|/)\.\.(/|$)#', $decoded_rel ) ) {
						return $source_image;
					}

					$local_path = wp_normalize_path( untrailingslashit( $local_base ) . '/' . ltrim( $relative_path, '/' ) );

					// Ensure it's still within the WP directory or WP_CONTENT_DIR for safety.
					$norm_abspath = wp_normalize_path( ABSPATH );
					$norm_content = wp_normalize_path( WP_CONTENT_DIR );
					if ( 0 !== strpos( $local_path, $norm_abspath ) && 0 !== strpos( $local_path, $norm_content ) ) {
						return $source_image;
					}
				}
			}

			// Replace extension.
			$info       = pathinfo( $local_path );
			$dirname    = isset( $info['dirname'] ) ? $info['dirname'] : dirname( $local_path );
			$local_path = wp_normalize_path( $dirname . '/' . $info['filename'] . '.' . $format );

			// Adjust for the wppo directory inside wp-content.
			$wp_content_path = wp_normalize_path( WP_CONTENT_DIR );
			if ( 0 === strpos( $local_path, $wp_content_path ) ) {
				$local_path = str_replace(
					$wp_content_path,
					wp_normalize_path( WP_CONTENT_DIR . '/wppo' ),
					$local_path
				);
			}

			return $local_path;
		}

		/**
		 * Get the URL of the converted image.
		 *
		 * @param string $source_image The source image URL.
		 * @param string $format The desired format ('webp' or 'avif').
		 * @return string The URL of the converted image.
		 * @since 1.0.0
		 */
		public static function get_img_url( string $source_image, string $format = 'webp' ): string {

			$home_url = untrailingslashit( Util::cached_home_url() );

			if ( 0 === strpos( $source_image, $home_url ) ) {
				// Replace the extension only at the end of the file name.
				$path_info     = pathinfo( $source_image );
				$converted_img = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $format;

				// Adjust for the wppo directory.
				$converted_img = str_replace( WP_CONTENT_URL, WP_CONTENT_URL . '/wppo', $converted_img );

				return $converted_img;
			}

			return $source_image;
		}


		/**
		 * Convert uploaded images to WebP or AVIF format upon attachment upload.
		 *
		 * @param array $metadata The attachment metadata.
		 * @param int   $attachment_id The attachment ID.
		 * @return array|\WP_Error The modified attachment metadata, or WP_Error on failure.
		 * @since 1.0.0
		 */
		public function convert_image_to_next_gen_format( $metadata, $attachment_id ) {

			// Skip server-side conversion when WP 7.1+ client-side media
			// processing handles sub-sizes in-browser, or when WP 6.7+ core
			// natively generates next-gen formats (get_format() returns 'none').
			// In both cases placeholder data (dominant color/LQIP) is still
			// extracted server-side from the uploaded original so frontend
			// lookups keep working. Batch conversion of existing media library
			// items remains unaffected.
			if ( $this->is_client_side_media_processing() || ( self::core_handles_next_gen() && 'none' === $this->format ) ) {
				$this->maybe_extract_placeholder_for_upload( $metadata, (int) $attachment_id );
				return $metadata;
			}

			$upload_dir = wp_upload_dir();

			try {
				// Get the full file path of the original image.
				$file = get_attached_file( $attachment_id );
				if ( ! file_exists( $file ) ) {
					return $metadata;
				}

				// Skip-small threshold: tiny uploads never enter the queue so no
				// CPU is wasted on negligible byte wins. Original stays restorable.
				if ( $this->should_skip_small_file( $file ) ) {
					return $metadata;
				}

				$img_url = wp_get_attachment_url( $attachment_id );
				if ( ! empty( $this->exclude_imgs ) ) {
					foreach ( $this->exclude_imgs as $exclude_img ) {
						if ( false !== strpos( $img_url, $exclude_img ) ) {
							return $metadata;
						}
					}
				}

				if ( in_array( $this->format, array( 'webp', 'both' ), true ) ) {
					self::add_img_into_queue( $file );
				}

				if ( in_array( $this->format, array( 'avif', 'both' ), true ) ) {
					self::add_img_into_queue( $file, 'avif' );
				}

				// Queue additional image sizes for conversion.
				if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
					foreach ( $metadata['sizes'] as $size => $size_data ) {
						$image_path = wp_normalize_path( $upload_dir['path'] . '/' . $size_data['file'] );
						if ( file_exists( $image_path ) ) {
							if ( $this->should_skip_small_file( $image_path ) ) {
								continue;
							}
							if ( in_array( $this->format, array( 'webp', 'both' ), true ) ) {
								self::add_img_into_queue( $image_path );
							}

							if ( in_array( $this->format, array( 'avif', 'both' ), true ) ) {
								self::add_img_into_queue( $image_path, 'avif' );
							}
						}
					}
				}

				return $metadata;

			} catch ( \Exception $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO Image conversion error for attachment ID ' . (int) $attachment_id . ': ' . str_replace( ABSPATH, '', $e->getMessage() ) );
				}
				return $metadata;
			}
		}

		/**
		 * Whether WP 7.1+ client-side media processing is enabled.
		 *
		 * The result is cached per blog ID to avoid re-reading the core option
		 * on every rendered image (frontend hot path).
		 *
		 * When the "Force Server-Side Conversion" toggle is enabled, this returns
		 * false even if core reports client-side processing is available, so the
		 * plugin's own GD/Imagick pipeline is authoritative in every context
		 * (upload metadata, serving, cron, REST) — not only on requests where
		 * Image_Optimisation has already registered the core opt-out filter.
		 *
		 * WP 7.1's in-browser pipeline lazy-loads ~13 MB wasm-vips in a Web
		 * Worker (gated by Document-Isolation-Policy / SharedArrayBuffer, >2 GB
		 * RAM, ≥2 cores). It handles compression/resize/crop/format/EXIF and
		 * HEIC→JPEG companion generation; GD cannot decode HEIC and must not
		 * wastefully attempt it (see maybe_extract_placeholder_for_upload()).
		 * HDR gain-map images are preserved via image_max_bit_depth (Imagick
		 * path not clamped) — already correct.
		 *
		 * @since 1.9.0
		 * @since NEXT Document wasm gating and HDR preservation.
		 *
		 * @return bool True if client-side media processing is enabled.
		 */
		private function is_client_side_media_processing(): bool {
			$blog_id = get_current_blog_id();

			if ( ! is_array( self::$client_side_processing_state ) || ! isset( self::$client_side_processing_state[ $blog_id ] ) ) {
				$client_side_enabled = function_exists( 'wp_is_client_side_media_processing_enabled' ) && wp_is_client_side_media_processing_enabled();

				if ( ! empty( $this->options['image_optimisation']['forceServerSideConversion'] ) ) {
					$client_side_enabled = false;
				}

				self::$client_side_processing_state[ $blog_id ] = $client_side_enabled;
			}

			return self::$client_side_processing_state[ $blog_id ];
		}

		/**
		 * Extract placeholder data for a new upload when server-side conversion
		 * is skipped (WP 7.1+ client-side processing, or WP 6.7+ core-native
		 * next-gen generation).
		 *
		 * Gated on the configured placeholder type actually consuming
		 * dominant-color/LQIP data, and on the image not being excluded from
		 * conversion, to avoid needless file reads and GD decodes.
		 *
		 * WP 7.1 fires `wp_generate_attachment_metadata` twice (create +
		 * finalize/sideload). The store is idempotent via the deferred
		 * wppo_img_info commit (array_merge + dedupe) so the second call is a
		 * no-op when data is unchanged; an early check avoids a second GD decode.
		 *
		 * HEIC early-exit (WP 7.1+): When client-side media processing is enabled
		 * and `forceServerSideConversion` is OFF, HEIC/HEIF uploads are owned by
		 * the browser's wasm-vips worker (~13 MB lazy-loaded, SharedArrayBuffer
		 * + Document-Isolation-Policy). GD cannot decode HEIC (getimagesize()
		 * returns empty, imagecreatefromstring would buffer the whole file
		 * wastefully), so we skip the decode entirely. When forced, the fallback
		 * path still attempts a decode (likely failing silently) for completeness.
		 * The browser's HEIC→JPEG companion is retained in `$metadata['original']`
		 * for CDN/Edge purger parity — see clean_placeholder_on_delete().
		 *
		 * Trac #64876: `client_side_supported_mime_types` remains intersected
		 * with core's list until a public filter lands; this method does not
		 * widen the list additively.
		 *
		 * @since 1.9.0
		 * @since NEXT Add HEIC early-exit and double-fire idempotency guard.
		 *
		 * @param array $metadata      The attachment metadata.
		 * @param int   $attachment_id The attachment ID.
		 * @return void
		 */
		private function maybe_extract_placeholder_for_upload( array $metadata, int $attachment_id ): void {
			$placeholder_type = $this->options['image_optimisation']['placeholderType'] ?? 'svg';
			if ( ! in_array( $placeholder_type, array( 'dominant_color', 'lqip' ), true ) ) {
				return;
			}

			$img_url = wp_get_attachment_url( $attachment_id );
			if ( ! empty( $this->exclude_imgs ) ) {
				foreach ( $this->exclude_imgs as $exclude_img ) {
					if ( false !== strpos( $img_url, $exclude_img ) ) {
						return;
					}
				}
			}

			// HEIC early-exit (WP 7.1+): Skip wasteful GD decode when the browser
			// worker owns HEIC→JPEG. Guarded by function_exists() for <7.1.
			// Cached attached file reused for both mime fallback and idempotency guard to reduce I/O.
			$cached_attached_file = function_exists( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : '';
			if ( function_exists( 'wp_is_client_side_media_processing_enabled' )
				&& wp_is_client_side_media_processing_enabled()
				&& empty( $this->options['image_optimisation']['forceServerSideConversion'] )
			) {
				$mime_for_check = '';
				if ( function_exists( 'get_post_mime_type' ) ) {
					$mime_for_check = (string) get_post_mime_type( $attachment_id );
				}
				if ( '' === $mime_for_check ) {
					// Fallback: extension-based when post mime is not yet set (e.g. direct sideload).
					if ( is_string( $cached_attached_file ) && '' !== $cached_attached_file ) {
						$ext_for_mime = strtolower( (string) pathinfo( $cached_attached_file, PATHINFO_EXTENSION ) );
						$ext_to_mime  = array(
							'heic'          => 'image/heic',
							'heif'          => 'image/heif',
							'heics'         => 'image/heic-sequence',
							'heif-sequence' => 'image/heif-sequence',
							'heic-sequence' => 'image/heic-sequence',
							'heifs'         => 'image/heif-sequence',
						);
						if ( isset( $ext_to_mime[ $ext_for_mime ] ) ) {
							$mime_for_check = $ext_to_mime[ $ext_for_mime ];
						}
					} elseif ( is_string( $img_url ) && '' !== $img_url ) {
						$mime_for_check = Util::get_image_mime_type( $img_url );
					}
				}
				if ( in_array( $mime_for_check, array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' ), true ) ) {
					return;
				}
			}

			// Double-fire idempotency: WP 7.1+ calls this on both `create` and
			// `finalize`; if the main file already has a dominant_color entry the
			// second pass can skip the GD decode when all sub-sizes are also present.
			// Companion placeholder (`metadata['original']`) is intentionally NOT
			// required here: store_placeholder_data_for_upload() never creates a
			// companion entry, so requiring it would force a second GD decode for
			// non-HEIC originals and break HEIC early-exit idempotency.
			if ( is_string( $cached_attached_file ) && '' !== $cached_attached_file && file_exists( $cached_attached_file ) ) {
				$rel_for_idem = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $cached_attached_file ) );
				$existing     = self::get_img_info();
				if ( isset( $existing['dominant_color'][ $rel_for_idem ] ) ) {
					$all_sizes_done = true;
					if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
						$dir_for_idem = dirname( $cached_attached_file );
						foreach ( $metadata['sizes'] as $size_data ) {
							if ( ! empty( $size_data['file'] ) ) {
								$size_path_for_idem = wp_normalize_path( $dir_for_idem . '/' . $size_data['file'] );
								$size_rel_for_idem  = str_replace( wp_normalize_path( ABSPATH ), '', $size_path_for_idem );
								if ( ! isset( $existing['dominant_color'][ $size_rel_for_idem ] ) ) {
									$all_sizes_done = false;
									break;
								}
							}
						}
					}
					if ( $all_sizes_done ) {
						return;
					}
				}
			}

			$this->store_placeholder_data_for_upload( $metadata, $attachment_id );
		}

		/**
		 * Store dominant-color and LQIP placeholder data for a new upload when
		 * server-side conversion is skipped (WP 7.1+ client-side media
		 * processing, or WP 6.7+ core-native next-gen generation).
		 *
		 * Uses a single lightweight GD decode of the uploaded original, mirroring
		 * the placeholder extraction done on the server-side conversion path.
		 * Uploads GD cannot decode (e.g. HEIC/HEIF) are skipped silently.
		 *
		 * @since 1.9.0
		 *
		 * @param array $metadata      The attachment metadata.
		 * @param int   $attachment_id The attachment ID.
		 * @return void
		 */
		private function store_placeholder_data_for_upload( array $metadata, int $attachment_id ): void {
			$file = get_attached_file( $attachment_id );

			if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) ) {
				return;
			}
			// Gate only the fallback string-decode path on imagecreatefromstring; direct GD loaders (imagecreatefromjpeg/png/webp/gif/avif) are independent and may exist even when the string helper is absent.
			$has_string_loader = function_exists( 'imagecreatefromstring' );

			// Security Fix: Prevent File Size & Memory Bomb DoS (same limit as convert_image()).
			$max_bytes = apply_filters( 'wppo_filesize_limit_bytes', 20 * 1024 * 1024 );
			if ( filesize( $file ) > $max_bytes ) {
				return;
			}

			// HEIC early-exit (WP 7.1+): When client-side media processing owns
			// HEIC→JPEG (~13 MB wasm-vips worker, SharedArrayBuffer + DIP), skip
			// wasteful GD decode. GD cannot decode HEIC (getimagesize empty, string
			// fallback would buffer entire file). Guarded for <7.1 (<7.1 no-op).
			// When forceServerSideConversion is ON, we still attempt the decode
			// (likely failing silently) for completeness.
			if ( function_exists( 'wp_is_client_side_media_processing_enabled' )
				&& wp_is_client_side_media_processing_enabled()
				&& empty( $this->options['image_optimisation']['forceServerSideConversion'] )
			) {
				// Primary: explicit extension check (reliable for absolute filesystem path).
				$ext_check = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
				if ( in_array( $ext_check, array( 'heic', 'heif', 'heics', 'heifs', 'heic-sequence', 'heif-sequence' ), true ) ) {
					return;
				}
				// Supplemental: mime helper (uses wp_parse_url, intended for URLs — may still match path).
				$mime_check = Util::get_image_mime_type( $file );
				if ( in_array( $mime_check, array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' ), true ) ) {
					return;
				}
			}

			if ( $this->is_animated_webp( $file ) ) {
				return;
			}

			// Security Fix: Prevent Dimension memory crash limits. Same guard as
			// convert_image(): getimagesize() parses headers without decoding
			// pixel data, so a small but highly-compressed file cannot turn into
			// a multi-gigabyte bitmap during the upload request.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$image_info = @getimagesize( $file );

			if ( empty( $image_info ) ) {
				return;
			}

			// Pixel-budget OOM guard (same channels-aware budget as
			// convert_image()): skip the decode entirely on huge sources so
			// the upload request can never fatal on small hosts. Fail-open:
			// no placeholder stored, original upload unaffected.
			$upload_channels = $this->get_source_channels();
			if ( $this->exceeds_pixel_budget( (int) $image_info[0], (int) $image_info[1], $upload_channels ) ) {
				return;
			}

			$max_dims = apply_filters(
				'wppo_max_dimensions',
				array(
					'width'  => 5000,
					'height' => 5000,
				)
			);
			if ( $image_info[0] > $max_dims['width'] || $image_info[1] > $max_dims['height'] ) {
				return;
			}

			// Use direct GD loaders (imagecreatefromjpeg/png/webp/gif) which stream
			// from disk instead of buffering the whole file via file_get_contents()
			// + imagecreatefromstring(), which doubles memory (raw string + GD bitmap).
			$image_type = $image_info[2] ?? 0;
			$image      = null;
			try {
				switch ( $image_type ) {
					case IMAGETYPE_JPEG:
						if ( function_exists( 'imagecreatefromjpeg' ) ) {
							// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							$image = @imagecreatefromjpeg( $file );
						}
						break;
					case IMAGETYPE_PNG:
						if ( function_exists( 'imagecreatefrompng' ) ) {
							// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							$image = @imagecreatefrompng( $file );
						}
						break;
					case IMAGETYPE_GIF:
						if ( function_exists( 'imagecreatefromgif' ) ) {
							// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							$image = @imagecreatefromgif( $file );
						}
						break;
					case IMAGETYPE_WEBP:
						if ( function_exists( 'imagecreatefromwebp' ) ) {
							// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							$image = @imagecreatefromwebp( $file );
						}
						break;
					default:
						// Fallback for AVIF (IMAGETYPE_AVIF = 19 on PHP 8.1+) or unknown types.
						if ( defined( 'IMAGETYPE_AVIF' ) && IMAGETYPE_AVIF === $image_type && function_exists( 'imagecreatefromavif' ) ) {
							// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
							$image = @imagecreatefromavif( $file );
						} elseif ( $has_string_loader ) {
							// Last resort: buffer + string decode (only when string loader exists).
							// Skipped for large files: raw bytes + GD bitmap
							// simultaneously doubles memory on big uploads.
							$string_fallback_max = (int) apply_filters( 'wppo_placeholder_string_fallback_max_bytes', 2 * 1024 * 1024 );
							$file_bytes          = filesize( $file );
							if ( false !== $file_bytes && $file_bytes <= $string_fallback_max ) {
								// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
								$contents = @file_get_contents( $file );
								if ( false !== $contents ) {
									// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
									$image = @imagecreatefromstring( $contents );
									unset( $contents );
								}
							}
						}
						break;
				}
			} catch ( \Throwable $e ) {
				return;
			}

			if ( ! $image instanceof \GdImage ) {
				return;
			}

			$rel_path       = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
			$dominant_color = $this->extract_dominant_color( $image );
			$lqip           = $this->generate_lqip( $image );

			// Collect all rel_path => data pairs first, then apply them in
			// a single atomic update instead of N read-copy-merge cycles.
			$placeholder_batch = array(
				$rel_path => array(
					'color' => $dominant_color,
					'lqip'  => $lqip,
				),
			);

			// Frontend placeholder lookups key on the resolved path of the
			// actually-rendered img URL, which is usually a sub-size. Store the
			// same values under every registered sub-size rel_path (sub-size
			// files live alongside the original in the uploads dir) so renders
			// at any size hit the cache, mirroring the server-side per-size
			// coverage from convert_image().
			if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$dir = dirname( $file );
				foreach ( $metadata['sizes'] as $size_data ) {
					if ( ! empty( $size_data['file'] ) && file_exists( $dir . '/' . $size_data['file'] ) ) {
						$size_rel                       = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $dir . '/' . $size_data['file'] ) );
						$placeholder_batch[ $size_rel ] = array(
							'color' => $dominant_color,
							'lqip'  => $lqip,
						);
					}
				}
			}

			$this->store_placeholder_data_batch( $placeholder_batch );

			Util::destroy_gd_image( $image );
		}

		/**
		 * Serve WebP or AVIF images if supported by the browser.
		 *
		 * @param array $image The image source array.
		 * @return array Modified image source with WebP/AVIF if applicable, or original image if not.
		 * @since 1.0.0
		 */
		public function maybe_serve_next_gen_image( $image ) {
			if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) || empty( $image[0] ) ) {
				return $image;
			}

			$http_accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );

			// Check if the browser supports WebP.
			$supports_avif = false !== strpos( $http_accept, 'image/avif' );
			$supports_webp = false !== strpos( $http_accept, 'image/webp' );

			$img_path = Util::get_local_path( $image[0] );

			if ( in_array( $this->format, array( 'avif', 'both' ), true ) ) {
				if ( $supports_avif || ( defined( 'DOING_CRON' ) && \DOING_CRON ) ) {
					$avif_path = $this->get_img_path( $img_path, 'avif' );

					if ( file_exists( $avif_path ) ) {
						$image[0] = $this->get_img_url( $image[0], 'avif' );
						return $image;
					} elseif ( ! $this->should_suppress_re_queueing() ) {
						self::add_img_into_queue( $img_path, 'avif' );
					}
				}
			}

			if ( in_array( $this->format, array( 'webp', 'both' ), true ) ) {
				if ( $supports_webp || ( defined( 'DOING_CRON' ) && \DOING_CRON ) ) {
					$webp_path = $this->get_img_path( $img_path, 'webp' );

					if ( file_exists( $webp_path ) ) {
						$image[0] = $this->get_img_url( $image[0] );
						return $image;
					} elseif ( ! $this->should_suppress_re_queueing() ) {
						self::add_img_into_queue( $img_path );
					}
				}
			}

			return $image;
		}

		/**
		 * Whether re-queueing of missing conversions should be suppressed on the
		 * frontend hot path.
		 *
		 * Under WP 7.1+ client-side media processing, uploads are intentionally
		 * never queued for server-side conversion, so a missing converted file is
		 * not a gap — it would just pollute the pending list and trigger duplicate
		 * hourly-cron work on every view. The suppression is additionally gated on
		 * core handling both next-gen formats natively: when core cannot produce
		 * one of the formats (e.g. AVIF via GD), a browser lacking wasm-vips
		 * support silently falls back to server-side processing and the plugin
		 * must still queue conversions or AVIF delivery is stranded.
		 *
		 * @since 1.9.0
		 *
		 * @return bool True when re-queueing should be suppressed.
		 */
		private function should_suppress_re_queueing(): bool {
			return $this->is_client_side_media_processing() && self::core_handles_both_next_gen();
		}

		/**
		 * Update the conversion status of an image.
		 *
		 * @param string $img_path The image path.
		 * @param string $status The status to update ('completed', 'failed', etc.).
		 * @param string $type The image format type ('webp', 'avif').
		 * @since 1.0.0
		 */
		public function update_conversion_status( $img_path, $status = 'completed', $type = 'webp' ) {
			$img_path = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $img_path ) );

			self::update_img_info_atomic(
				function ( $img_info ) use ( $img_path, $status, $type ) {
					if ( 'completed' === $status ) {
						// Check and remove from 'pending' list.
						if ( isset( $img_info['pending'][ $type ] ) ) {
							$key = array_search( $img_path, $img_info['pending'][ $type ], true );
							if ( false !== $key ) {
								unset( $img_info['pending'][ $type ][ $key ] );
							}
						}

						// Check and remove from 'failed' list.
						if ( isset( $img_info['failed'][ $type ] ) ) {
							$key = array_search( $img_path, $img_info['failed'][ $type ], true );
							if ( false !== $key ) {
								unset( $img_info['failed'][ $type ][ $key ] );
							}
						}

						// Record original vs converted byte sizes for the savings report.
						$sizes = self::measure_conversion_sizes( $img_path, $type );
						if ( null !== $sizes ) {
							$img_info['sizes'][ $type ][ $img_path ] = $sizes;
						}
					}

					if ( 'failed' === $status || 'skipped' === $status ) {
						if ( isset( $img_info['pending'][ $type ] ) ) {
							$key = array_search( $img_path, $img_info['pending'][ $type ], true );
							if ( false !== $key ) {
								unset( $img_info['pending'][ $type ][ $key ] );
							}
						}
					}

					if ( ! in_array( $img_path, $img_info[ $status ][ $type ] ?? array(), true ) ) {
						$img_info[ $status ][ $type ][] = $img_path;
					}

					return $img_info;
				}
			);
		}

		/**
		 * Measure original vs converted byte sizes for a completed conversion.
		 *
		 * @param string $img_path Relative source path (ABSPATH-stripped).
		 * @param string $type     Conversion type ('webp' or 'avif').
		 * @return array|null { original: int, converted: int } or null when either file cannot be measured.
		 * @since NEXT
		 */
		private static function measure_conversion_sizes( string $img_path, string $type ): ?array {
			$source = wp_normalize_path( ABSPATH . ltrim( $img_path, '/' ) );
			$dest   = self::get_img_path( $source, $type );

			if ( ! file_exists( $source ) || ! file_exists( $dest ) ) {
				return null;
			}

			$original  = filesize( $source );
			$converted = filesize( $dest );

			if ( false === $original || false === $converted || 0 >= $original ) {
				return null;
			}

			return array(
				'original'  => (int) $original,
				'converted' => (int) $converted,
			);
		}

		/**
		 * Aggregate recorded conversion sizes into a savings summary.
		 *
		 * Only images whose sizes were measured (post-dating this feature)
		 * are counted; legacy completed entries contribute nothing.
		 *
		 * @param array|null $img_info Pre-read img info (null = read here, avoids a second unserialize).
		 * @return array{original_bytes: int, converted_bytes: int, saved_bytes: int, images_counted: int}
		 * @since NEXT
		 */
		public static function get_savings_summary( ?array $img_info = null ): array {
			if ( null === $img_info ) {
				$img_info = self::get_img_info();
			}
			$sizes     = is_array( $img_info['sizes'] ?? null ) ? $img_info['sizes'] : array();
			$original  = 0;
			$converted = 0;
			$counted   = 0;

			foreach ( array( 'webp', 'avif' ) as $type ) {
				foreach ( ( $sizes[ $type ] ?? array() ) as $pair ) {
					if ( ! is_array( $pair ) || ! isset( $pair['original'], $pair['converted'] ) ) {
						continue;
					}

					$original  += (int) $pair['original'];
					$converted += (int) $pair['converted'];
					++$counted;
				}
			}

			return array(
				'original_bytes'  => $original,
				'converted_bytes' => $converted,
				'saved_bytes'     => max( 0, $original - $converted ),
				'images_counted'  => $counted,
			);
		}

		/**
		 * Discover library images missing next-gen versions and queue them.
		 *
		 * Runs inside the hourly cron so images uploaded before activation (or
		 * while conversion was disabled) enter the queue instead of relying on
		 * lazy frontend discovery. Bounded per run. Two rotating windows are
		 * scanned (audit #888 finding 4):
		 *
		 * - a "head" window: attachments newer than the highest ID seen so far
		 *   (`wppo_img_scan_cursor_max`) — new uploads are covered immediately
		 *   without waiting for the rotation;
		 * - a "tail" window: attachments below the cursor
		 *   (`wppo_img_scan_cursor`) — each run continues the rotation downward
		 *   and rewinds once the oldest attachment is reached, so older media
		 *   are eventually inspected. Without the cursor only the newest
		 *   `limit` attachments would ever be revisited.
		 *
		 * @param string[] $formats Conversion formats to ensure ('webp', 'avif').
		 * @param int      $limit   Maximum attachments inspected per window.
		 * @return int Number of files newly queued.
		 * @since NEXT Cursor pagination via wppo_img_scan_cursor.
		 */
		public static function queue_unconverted_library_images( array $formats, int $limit = 50 ): int {
			global $wpdb;

			if ( empty( $formats ) || 1 > $limit || ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'get_col' ) ) || ! is_callable( array( $wpdb, 'prepare' ) ) ) {
				return 0;
			}

			$mime_types   = array( 'image/jpeg', 'image/png', 'image/webp' );
			$placeholders = implode( ',', array_fill( 0, count( $mime_types ), '%s' ) );
			$base_where   = " WHERE post_type = 'attachment' AND post_mime_type IN ( " . $placeholders . ' )';
			$cursor       = (int) get_option( 'wppo_img_scan_cursor', 0 );
			$cursor_max   = (int) get_option( 'wppo_img_scan_cursor_max', 0 );

			// Head window: IDs above the highest-seen ID (new uploads only).
			$head_args  = $cursor_max > 0 ? array_merge( $mime_types, array( $cursor_max, $limit ) ) : array_merge( $mime_types, array( $limit ) );
			$head_where = $cursor_max > 0 ? ' AND ID > %d' : '';
			// phpcs:ignore WordPress.WP.PreparedSQL.NotPrepared -- Placeholder list built from a fixed internal constant set; only the cursor bound and LIMIT are bound.
			$head_sql = 'SELECT ID FROM ' . $wpdb->posts . $base_where . $head_where . ' ORDER BY ID DESC LIMIT %d';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed lookups; placeholder list built from fixed internal constants.
			$head_ids = $wpdb->get_col( $wpdb->prepare( $head_sql, $head_args ) );

			// Tail window: continue the descending rotation below the cursor.
			$tail_ids = array();
			if ( $cursor > 0 ) {
				// phpcs:ignore WordPress.WP.PreparedSQL.NotPrepared -- Placeholder list built from a fixed internal constant set; only cursor and LIMIT are bound.
				$tail_sql = 'SELECT ID FROM ' . $wpdb->posts . $base_where . ' AND ID < %d ORDER BY ID DESC LIMIT %d';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed lookup; placeholder list built from fixed internal constants.
				$tail_ids = $wpdb->get_col( $wpdb->prepare( $tail_sql, array_merge( $mime_types, array( $cursor, $limit ) ) ) );
			} else {
				// First run: the head window IS the newest-$limit scan.
				$tail_ids = $head_ids;
				$head_ids = array();
			}

			if ( ( empty( $tail_ids ) || ! is_array( $tail_ids ) ) && ( empty( $head_ids ) || ! is_array( $head_ids ) ) ) {
				// Tail exhausted — rewind so the next run starts over from the
				// newest non-cursor-max attachments.
				if ( 0 !== $cursor ) {
					update_option( 'wppo_img_scan_cursor', 0, false );
				}
				return 0;
			}

			// Advance the tail cursor below the oldest ID of this batch so the
			// next run continues the rotation, and remember the highest ID seen
			// so the head window only covers genuinely new uploads.
			if ( ! empty( $tail_ids ) && is_array( $tail_ids ) ) {
				update_option( 'wppo_img_scan_cursor', min( array_map( 'intval', $tail_ids ) ), false );
			}
			$all_ids = array_merge( is_array( $head_ids ) ? $head_ids : array(), is_array( $tail_ids ) ? $tail_ids : array() );
			$new_max = max( array_map( 'intval', $all_ids ) );
			if ( $new_max > $cursor_max ) {
				update_option( 'wppo_img_scan_cursor_max', $new_max, false );
			}

			// Same eligibility list as the queue gate itself.
			$convertible = (array) apply_filters(
				'wppo_convertible_image_extensions',
				array( 'jpg', 'jpeg', 'png', 'webp' )
			);

			$queued = 0;

			// Prime post + postmeta caches for the batch so the per-ID
			// get_attached_file() calls below do not each issue post and
			// postmeta lookups.
			$prime_ids = array_map( 'intval', (array) $all_ids );
			if ( ! empty( $prime_ids ) ) {
				try {
					if ( function_exists( '_prime_post_caches' ) ) {
						_prime_post_caches( $prime_ids, false, false );
					}
					if ( function_exists( 'update_postmeta_cache' ) ) {
						update_postmeta_cache( $prime_ids );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			foreach ( $all_ids as $attachment_id ) {
				$file = get_attached_file( (int) $attachment_id );

				if ( empty( $file ) || ! file_exists( $file ) ) {
					continue;
				}

				$extension = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
				if ( ! in_array( $extension, $convertible, true ) ) {
					continue;
				}

				foreach ( $formats as $format ) {
					if ( ! in_array( $format, array( 'webp', 'avif' ), true ) ) {
						continue;
					}

					// Skip files whose next-gen version already exists on disk.
					if ( file_exists( self::get_img_path( $file, $format ) ) ) {
						continue;
					}

					if ( self::add_img_into_queue( $file, $format ) ) {
						++$queued;
					}
				}
			}

			return $queued;
		}

		/**
		 * Add an image to the conversion queue.
		 *
		 * @param string $img_path The image path.
		 * @param string $type The image format type ('webp', 'avif').
		 * @since 1.0.0
		 */
		public static function add_img_into_queue( $img_path, $type = 'webp' ) {
			if ( empty( $img_path ) ) {
				return false;
			}

			// Only raster formats the converters can actually process enter the
			// queue. SVG (vector), GIF (animation safety) and other formats are
			// skipped so sites that allow their upload never queue unconvertible
			// files that would fail at conversion time.
			$extension = strtolower( (string) pathinfo( $img_path, PATHINFO_EXTENSION ) );

			/**
			 * Filters the source image extensions eligible for WebP/AVIF conversion.
			 *
			 * @param string[] $extensions Lowercase source extensions eligible for conversion.
			 * @since NEXT
			 */
			$convertible = apply_filters(
				'wppo_convertible_image_extensions',
				array( 'jpg', 'jpeg', 'png', 'webp' )
			);

			if ( ! in_array( $extension, (array) $convertible, true ) || $extension === $type ) {
				return false;
			}

			$normalized = wp_normalize_path( $img_path );
			// Ensure trailing slash so strpos can't match a same-prefix sibling directory.
			static $upload_dir = array();
			$blog_id           = get_current_blog_id();

			if ( ! isset( $upload_dir[ $blog_id ] ) ) {
				$upload_dir[ $blog_id ] = rtrim( wp_normalize_path( wp_upload_dir()['basedir'] ), '/' ) . '/';
			}

			// Only queue images that live inside wp-content/uploads.
			if ( 0 !== strpos( $normalized, $upload_dir[ $blog_id ] ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO: add_img_into_queue rejected path — not inside uploads directory.' );
				}
				return false;
			}

			static $abspath = array();

			if ( ! isset( $abspath[ $blog_id ] ) ) {
				$abspath[ $blog_id ] = wp_normalize_path( ABSPATH );
			}

			$img_path_rel = str_replace( $abspath[ $blog_id ], '', $normalized );

			self::update_img_info_atomic(
				function ( $img_info ) use ( $img_path_rel, $type ) {
					if ( ! in_array( $img_path_rel, $img_info['pending'][ $type ] ?? array(), true ) ) {
						$img_info['pending'][ $type ][] = $img_path_rel;
					}
					return $img_info;
				}
			);

			return true;
		}

		/**
		 * Returns the current image info from the database.
		 *
		 * @since 1.1.4
		 * @return array
		 */
		public static function get_img_info(): array {
			if ( null !== self::$deferred_img_info ) {
				return self::$deferred_img_info;
			}

			// Salted layer requires a persistent object cache; without one the
			// option read below is the (already persistent) source of truth
			// (issue #882 review).
			$has_salted = function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();

			if ( $has_salted ) {
				$cached = wp_cache_get_salted( 'wppo_img_info', 'wppo', Util::cache_salt( self::SALT_KEY ) );
				if ( false !== $cached ) {
					self::$deferred_img_info = $cached;
					return self::$deferred_img_info;
				}
			}

			self::$deferred_img_info = get_option( 'wppo_img_info', array() );

			if ( $has_salted ) {
				// TTL safety net (issue #882 review): a missed salt bump
				// cannot keep a stale mirror alive indefinitely.
				wp_cache_set_salted( 'wppo_img_info', self::$deferred_img_info, 'wppo', Util::cache_salt( self::SALT_KEY ), DAY_IN_SECONDS );
			}

			return self::$deferred_img_info;
		}

		/**
		 * Manually updates the image info database option.
		 *
		 * @param array $img_info The new image info array.
		 * @since 1.1.4
		 */
		public static function set_img_info( array $img_info ): void {
			self::$deferred_img_info = $img_info;
			update_option( 'wppo_img_info', $img_info, false );
			self::$img_info_persisted = true;
			self::invalidate_img_info_cache();
		}

		/**
		 * Atomically clears completed webp and avif entries from the image info option.
		 *
		 * @since 1.4.0
		 */
		public static function clear_completed_formats(): void {
			self::update_img_info_atomic(
				function ( array $img_info ): array {
					$img_info['completed']['webp'] = array();
					$img_info['completed']['avif'] = array();
					// Drop measured size pairs so the savings report reflects
					// only currently-optimised images.
					$img_info['sizes'] = array(
						'webp' => array(),
						'avif' => array(),
					);
					// Write cleared completed to the DB immediately so that
					// commit_img_info()'s live re-read cannot merge old entries back in.
					update_option( 'wppo_img_info', $img_info, false );
					self::$img_info_persisted = true;
					self::invalidate_img_info_cache();
					return $img_info;
				}
			);
		}

		/**
		 * Performs an atomic-like merge-aware update of the image info option.
		 *
		 * @param callable $callback The callback that receives the current info and returns the updated info.
		 * @since 1.1.4
		 */
		private static function update_img_info_atomic( callable $callback ): void {
			$img_info                = self::get_img_info();
			self::$deferred_img_info = $callback( $img_info );

			if ( ! self::$img_info_shutdown_registered ) {
				add_action( 'shutdown', array( __CLASS__, 'commit_img_info' ) );
				self::$img_info_shutdown_registered = true;
			}
		}

		/**
		 * Commits deferred image info state to the database on shutdown.
		 *
		 * @since 1.4.0
		 */
		public static function commit_img_info(): void {
			if ( null !== self::$deferred_img_info ) {
				if ( self::$img_info_persisted ) {
					self::$img_info_persisted = false; // Reset for potential later use.
					return;
				}

				global $wpdb;
				$lock_acquired = false;

				// Try to acquire MySQL lock to prevent race condition.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$lock = $wpdb->get_var( "SELECT GET_LOCK('wppo_img_info_lock', 5)" );
				if ( 1 === (int) $lock ) {
					$lock_acquired = true;
				}

				$live_info = get_option( 'wppo_img_info', array() );

				// Merge live and deferred info here to avoid dropping queued/completed items from concurrent runs.
				foreach ( array( 'pending', 'completed', 'failed' ) as $status ) {
					foreach ( array( 'webp', 'avif' ) as $type ) {
						$live_items     = $live_info[ $status ][ $type ] ?? array();
						$deferred_items = self::$deferred_img_info[ $status ][ $type ] ?? array();

						self::$deferred_img_info[ $status ][ $type ] = array_unique( array_merge( $live_items, $deferred_items ) );
					}
				}

				// Merge placeholder data arrays (dominant_color, lqip) to prevent
				// concurrent conversion jobs from overwriting each other's data.
				foreach ( array( 'dominant_color', 'lqip' ) as $key ) {
					$live_items     = $live_info[ $key ] ?? array();
					$deferred_items = self::$deferred_img_info[ $key ] ?? array();
					if ( is_array( $live_items ) && is_array( $deferred_items ) ) {
						self::$deferred_img_info[ $key ] = array_merge( $live_items, $deferred_items );
					}
				}

				// Some states, like if an image went from pending -> completed in self::$deferred_img_info
				// but was also concurrently added as pending in $live_info, might need special handling.
				// However, since atomic completion removes from pending explicitly in `update_conversion_status`,
				// doing a clean union of pending arrays is generally safe enough as jobs will process statelessly.
				// Any job completed in our request should definitely not be in our merged 'pending'.
				foreach ( array( 'webp', 'avif' ) as $type ) {
					$completed = self::$deferred_img_info['completed'][ $type ] ?? array();
					$failed    = self::$deferred_img_info['failed'][ $type ] ?? array();

					if ( isset( self::$deferred_img_info['pending'][ $type ] ) && is_array( self::$deferred_img_info['pending'][ $type ] ) ) {
						self::$deferred_img_info['pending'][ $type ] = array_diff(
							self::$deferred_img_info['pending'][ $type ],
							$completed,
							$failed
						);
					}
				}

				update_option( 'wppo_img_info', self::$deferred_img_info, false );
				self::$img_info_persisted = true;
				self::invalidate_img_info_cache();

				if ( $lock_acquired ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->query( "SELECT RELEASE_LOCK('wppo_img_info_lock')" );
				}
			}
		}

		/**
		 * Invalidate the image info cache by bumping the salt.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function invalidate_img_info_cache(): void {
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				// Monotonic increment: same-second mutations must produce
				// distinct salts (issue #882 review).
				update_option( self::SALT_KEY, (int) get_option( self::SALT_KEY, 0 ) + 1, false );
			}
		}

		/**
		 * Forces the 'wppo_img_info' option to be non-autoloading.
		 *
		 * Should be called during plugin activation or upgrade to ensure large
		 * image metadata doesn't bloat the 'alloptions' cache.
		 *
		 * @since 1.5.1
		 * @return void
		 */
		public static function migrate_img_info_autoload(): void {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( 'wppo_img_info', false );
			} else {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->options,
					array( 'autoload' => 'no' ),
					array( 'option_name' => 'wppo_img_info' )
				);
				wp_cache_delete( 'wppo_img_info', 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
		}
	}
}
