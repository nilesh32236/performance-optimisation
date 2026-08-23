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
		 * conversion and the plugin returns 'none' (skip). On older cores, or
		 * when core provides no mapping for the source MIME, the requested
		 * format is returned unchanged.
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
					$avif_quality = $this->resolve_encode_quality( 'image/avif', 82, $size );
					$webp_quality = $this->resolve_encode_quality( 'image/webp', 82, $size );
				} else {
					$mime    = in_array( $format, array( 'avif', 'both' ), true ) ? 'image/avif' : 'image/webp';
					$quality = $this->resolve_encode_quality( $mime, 82, $size );
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

			// Security Fix: Prevent Dimension memory crash limits.
			$max_dims = apply_filters(
				'wppo_max_dimensions',
				array(
					'width'  => 5000,
					'height' => 5000,
				)
			);
			if ( $image_info[0] > $max_dims['width'] || $image_info[1] > $max_dims['height'] ) {
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
							if ( ! function_exists( 'imageavif' ) ) {
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

								$avif_path = $this->get_img_path( $source_image, 'avif' );
								Util::prepare_cache_dir( dirname( $avif_path ) );

								if ( imageavif( $image, $avif_path, $avif_quality ?? $quality ) ) {
									$this->update_conversion_status( $source_image, 'completed', 'avif' );
								} else {
									$this->update_conversion_status( $source_image, 'failed', $format );
									return false;
								}
							} catch ( \Exception $e ) {
								if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
									// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- imagedestroy() is still the correct way to free GD resources in PHP 8.x
									imagedestroy( $image );
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
								// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
								imagedestroy( $webp_gd );
							}
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

							if ( file_exists( $webp_path ) ) {
								$this->update_conversion_status( $source_image, 'completed', 'webp' );
								return true;
							}
							// Initialize Imagick and read the image file.
							// Do not hard-clamp Imagick depth to 8-bit — core's
							// image_max_bit_depth filter (WP 6.8+, Trac #62285)
							// preserves HDR up to 12-bit by default.
							$imagick = new \Imagick();
							$imagick->setResourceLimit( \Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
							$imagick->readImage( $source_image );

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
									// phpcs:ignore
									imagedestroy( $gif_gd );
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
							wp_delete_file( $webp_path );
							return false;
						}
					default:
						$this->update_conversion_status( $source_image, 'failed', $format );
						return false; // Unsupported format.
				}

				$success = true;

				if ( in_array( $format, array( 'webp', 'both' ), true ) ) {
					$webp_path = $this->get_img_path( $source_image, 'webp' );

					if ( ! file_exists( $webp_path ) ) {
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

				if ( in_array( $format, array( 'avif', 'both' ), true ) ) {
					$avif_path = $this->get_img_path( $source_image, 'avif' );

					if ( ! file_exists( $avif_path ) ) {
						Util::prepare_cache_dir( dirname( $avif_path ) );
						if ( ! function_exists( 'imageavif' ) || ! imageavif( $image, $avif_path, $avif_quality ?? $quality ) ) {
							$success = false;
							$this->update_conversion_status( $source_image, 'failed', 'avif' );
						} else {
							$this->update_conversion_status( $source_image, 'completed', 'avif' );
						}
					} else {
						$this->update_conversion_status( $source_image, 'completed', 'avif' );
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
					// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- imagedestroy() is still the correct way to free GD resources in PHP 8.x
					imagedestroy( $image );
				}

				return $success;
			} catch ( \Exception $e ) {

				if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
					// phpcs:ignore
					imagedestroy( $image );
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
				// phpcs:ignore
				imagedestroy( $image );
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
				// phpcs:ignore
				imagedestroy( $thumb );
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

			// phpcs:ignore
			imagedestroy( $thumb );

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
		 * @since NEXT
		 *
		 * @param string $rel_path       The relative image path (ABSPATH-stripped).
		 * @param string $dominant_color Hex color string.
		 * @param string $lqip           LQIP data URI (empty string if not generated).
		 * @return void
		 */
		private function store_placeholder_data( string $rel_path, string $dominant_color, string $lqip ): void {
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
		 * from wppo_img_info.
		 *
		 * @since NEXT
		 *
		 * @param int $post_id The attachment ID.
		 * @return void
		 */
		public static function clean_placeholder_on_delete( int $post_id ): void {
			$file_path = get_attached_file( $post_id );
			if ( ! $file_path ) {
				return;
			}
			$rel_paths   = array();
			$main_rel    = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file_path ) );
			$rel_paths[] = $main_rel;

			// Also clean up resized versions from attachment metadata.
			$metadata = wp_get_attachment_metadata( $post_id );
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

			// Read the latest state via get_img_info() which may include
			// deferred-but-not-yet-committed entries from the current request.
			$img_info = self::get_img_info();
			$changed  = false;
			foreach ( array( 'dominant_color', 'lqip' ) as $key ) {
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
			strpos( $normalized_source, wp_normalize_path( ABSPATH ) ) === 0 ||
			strpos( $normalized_source, wp_normalize_path( WP_CONTENT_DIR ) ) === 0
			);

			if ( $is_already_local ) {
				if ( strpos( $normalized_source, '..' ) !== false ) {
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

					if ( strpos( $source_image, $content_url_base ) === 0 ) {
						$relative_path = substr( $source_image, strlen( $content_url_base ) );
						$local_base    = wp_normalize_path( WP_CONTENT_DIR );
					} elseif ( strpos( $source_image, $home_url ) === 0 ) {
						$relative_path = substr( $source_image, strlen( $home_url ) );
					} else {
						$relative_path = $source_image;
					}

					// Security: Block directory traversal.
					if ( strpos( rawurldecode( $relative_path ), '..' ) !== false ) {
						return $source_image;
					}

					$local_path = wp_normalize_path( untrailingslashit( $local_base ) . '/' . ltrim( $relative_path, '/' ) );

					// Ensure it's still within the WP directory or WP_CONTENT_DIR for safety.
					$norm_abspath = wp_normalize_path( ABSPATH );
					$norm_content = wp_normalize_path( WP_CONTENT_DIR );
					if ( strpos( $local_path, $norm_abspath ) !== 0 && strpos( $local_path, $norm_content ) !== 0 ) {
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
			if ( strpos( $local_path, $wp_content_path ) === 0 ) {
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
		 * @since 1.9.0
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
		 * @since 1.9.0
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

			if ( ! $file || ! file_exists( $file ) || ! is_readable( $file ) || ! function_exists( 'imagecreatefromstring' ) ) {
				return;
			}

			// Security Fix: Prevent File Size & Memory Bomb DoS (same limit as convert_image()).
			$max_bytes = apply_filters( 'wppo_filesize_limit_bytes', 20 * 1024 * 1024 );
			if ( filesize( $file ) > $max_bytes ) {
				return;
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

			// Guard against a TOCTOU race where the file disappears between the
			// checks above and the read; a missing file yields a false contents
			// value that imagecreatefromstring() rejects with a TypeError.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			$contents = @file_get_contents( $file );
			if ( false === $contents ) {
				return;
			}

			try {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$image = @imagecreatefromstring( $contents );
			} catch ( \Throwable $e ) {
				return;
			}

			if ( ! $image instanceof \GdImage ) {
				return;
			}

			$rel_path       = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $file ) );
			$dominant_color = $this->extract_dominant_color( $image );
			$lqip           = $this->generate_lqip( $image );

			$this->store_placeholder_data( $rel_path, $dominant_color, $lqip );

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
						$size_rel = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $dir . '/' . $size_data['file'] ) );
						$this->store_placeholder_data( $size_rel, $dominant_color, $lqip );
					}
				}
			}

			// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- imagedestroy() is still the correct way to free GD resources in PHP 8.x
			imagedestroy( $image );
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
			$supports_avif = strpos( $http_accept, 'image/avif' ) !== false;
			$supports_webp = strpos( $http_accept, 'image/webp' ) !== false;

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
		 * @return array { original_bytes: int, converted_bytes: int, saved_bytes: int, images_counted: int }
		 * @since NEXT
		 */
		public static function get_savings_summary(): array {
			$img_info  = self::get_img_info();
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
		 * lazy frontend discovery. Bounded per run; newest attachments first.
		 *
		 * @param string[] $formats Conversion formats to ensure ('webp', 'avif').
		 * @param int      $limit   Maximum attachments inspected per run.
		 * @return int Number of files newly queued.
		 * @since NEXT
		 */
		public static function queue_unconverted_library_images( array $formats, int $limit = 50 ): int {
			global $wpdb;

			if ( empty( $formats ) || 1 > $limit || ! is_object( $wpdb ) || ! is_callable( array( $wpdb, 'get_col' ) ) || ! is_callable( array( $wpdb, 'prepare' ) ) ) {
				return 0;
			}

			$mime_types   = array( 'image/jpeg', 'image/png', 'image/webp' );
			$placeholders = implode( ',', array_fill( 0, count( $mime_types ), '%s' ) );

			// phpcs:ignore WordPress.WP.PreparedSQL.NotPrepared -- Placeholder list built from a fixed internal constant set; only LIMIT is bound.
			$sql = 'SELECT ID FROM ' . $wpdb->posts . " WHERE post_type = 'attachment' AND post_mime_type IN ( " . $placeholders . ' ) ORDER BY ID DESC LIMIT %d';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Bounded indexed lookup; placeholder list built from fixed internal constants, only LIMIT is bound.
			$attachment_ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $mime_types, array( $limit ) ) ) );

			if ( empty( $attachment_ids ) || ! is_array( $attachment_ids ) ) {
				return 0;
			}

			// Same eligibility list as the queue gate itself.
			$convertible = (array) apply_filters(
				'wppo_convertible_image_extensions',
				array( 'jpg', 'jpeg', 'png', 'webp' )
			);

			$queued = 0;

			foreach ( $attachment_ids as $attachment_id ) {
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
			if ( strpos( $normalized, $upload_dir[ $blog_id ] ) !== 0 ) {
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

			$has_salted = function_exists( 'wp_cache_get_salted' );

			if ( $has_salted ) {
				$cached = wp_cache_get_salted( 'wppo_img_info', 'wppo', self::SALT_KEY );
				if ( false !== $cached ) {
					self::$deferred_img_info = $cached;
					return self::$deferred_img_info;
				}
			}

			self::$deferred_img_info = get_option( 'wppo_img_info', array() );

			if ( $has_salted ) {
				wp_cache_set_salted( 'wppo_img_info', self::$deferred_img_info, 'wppo', self::SALT_KEY );
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
			if ( function_exists( 'wp_cache_get_salted' ) ) {
				update_option( self::SALT_KEY, time(), false );
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
