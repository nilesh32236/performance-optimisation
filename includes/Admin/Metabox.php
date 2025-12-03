<?php
/**
 * Handles the functionality for adding and saving the preload image metabox.
 *
 * This file includes the `Metabox` class, which integrates with the WordPress post editor
 * to allow users to add and save a list of image URLs to preload for a specific post/page.
 * The metabox is rendered in the post editor and the values are saved as post metadata.
 *
 * @package PerformanceOptimise
 * @since 1.0.0
 */

namespace PerformanceOptimisation\Admin;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Services\SettingsService;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\ValidationUtil;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimisation\Admin\Metabox' ) ) {

	/**
	 * Metabox Class for Preload Image URL.
	 *
	 * This class handles the functionality for adding and saving the preload image
	 * metabox to the WordPress post editor. It allows the user to add URLs for images
	 * to be preloaded on the page.
	 *
	 * @since 1.0.0
	 */
	class Metabox {

		/**
		 * Meta key for storing preload image URLs.
		 *
		 * @var string
		 */
		const META_KEY = '_wppo_preload_image_urls';

		/**
		 * Nonce action name for saving metabox data.
		 *
		 * @var string
		 */
		const NONCE_ACTION = 'wppo_save_preload_images_metabox';

		/**
		 * Nonce field name.
		 *
		 * @var string
		 */
		const NONCE_FIELD = 'wppo_preload_images_nonce';

		private ServiceContainerInterface $container;
		private SettingsService $settingsService;
		private LoggingUtil $logger;
		private ValidationUtil $validator;

		/**
		 * Constructor to hook into WordPress actions for adding and saving the metabox.
		 *
		 * @since 1.0.0
		 */
		public function __construct( ServiceContainerInterface $container ) {
			$this->container = $container;

			// Validate critical services
			$required_services = array( 'settings_service', 'logger', 'validator' );
			foreach ( $required_services as $service ) {
				if ( ! $container->has( $service ) ) {
					throw new \Exception( "Required service not available: {$service}" );
				}
			}

			$this->settingsService = $container->get( 'settings_service' );
			$this->logger          = $container->get( 'logger' );
			$this->validator       = $container->get( 'validator' );

			add_action( 'add_meta_boxes', array( $this, 'add_preload_images_metabox' ) );
			add_action( 'save_post', array( $this, 'save_preload_images_metabox_data' ) );

			$this->logger->debug( 'Metabox hooks setup completed' );
		}

		/**
		 * Adds the preload image metabox to applicable post types.
		 *
		 * @since 1.0.0
		 * @param string $post_type The current post type.
		 */
		public function add_preload_images_metabox( string $post_type ): void {
			$applicable_post_types = $this->settingsService->get_setting( 'preload_settings', 'applicable_post_types' ) ?? array( 'post', 'page' );

			if ( in_array( $post_type, $applicable_post_types, true ) ) {
				add_meta_box(
					'wppo_preload_images_metabox',
					__( 'Preload Critical Images', 'performance-optimisation' ),
					array( $this, 'render_preload_images_metabox' ),
					$post_type,
					'side',
					'default'
				);
			}
		}

		/**
		 * Renders the content of the preload image URLs metabox.
		 *
		 * @since 1.0.0
		 * @param \WP_Post $post The current post object.
		 */
		public function render_preload_images_metabox( \WP_Post $post ): void {
			$preload_urls_string = get_post_meta( $post->ID, self::META_KEY, true );
			$preload_urls_string = is_string( $preload_urls_string ) ? $preload_urls_string : '';

			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			?>
			<p>
				<label for="wppo_preload_image_urls_textarea">
					<?php esc_html_e( 'Enter image URLs to preload (one per line):', 'performance-optimisation' ); ?>
				</label>
			</p>
			<textarea
				id="wppo_preload_image_urls_textarea"
				name="<?php echo esc_attr( self::META_KEY ); ?>"
				rows="5"
				style="width:100%;"
				placeholder="<?php esc_attr_e( "e.g., /wp-content/uploads/image.jpg\nmobile:/path/to/mobile-image.jpg\ndesktop:/path/to/desktop-image.jpg", 'performance-optimisation' ); ?>"
			><?php echo esc_textarea( $preload_urls_string ); ?></textarea>
			<p class="description">
				<?php
				echo wp_kses_post(
					__( 'Add full or relative URLs. Use <code>mobile:</code> or <code>desktop:</code> prefix for device-specific preloading (e.g., <code>mobile:/uploads/image-sm.jpg</code>).', 'performance-optimisation' )
				);
				?>
			</p>
			<?php
		}

		/**
		 * Saves the preload image URLs when the post is saved.
		 *
		 * @since 1.0.0
		 * @param int $post_id The ID of the post being saved.
		 */
		public function save_preload_images_metabox_data( int $post_id ): void {
			// Verify nonce
			if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
				$this->logger->warning( 'Metabox save failed: Invalid nonce', array( 'post_id' => $post_id ) );
				return;
			}

			// Skip autosave
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			// Check permissions
			$post_type        = get_post_type( $post_id );
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
				$this->logger->warning(
					'Metabox save failed: Insufficient permissions',
					array(
						'post_id'   => $post_id,
						'user_id'   => get_current_user_id(),
						'post_type' => $post_type,
					)
				);
				return;
			}

			if ( isset( $_POST[ self::META_KEY ] ) ) {
				$preload_urls_string = sanitize_textarea_field( wp_unslash( $_POST[ self::META_KEY ] ) );

				// Process and validate URLs
				$processed_urls = $this->processPreloadUrls( $preload_urls_string );

				if ( ! empty( $processed_urls['valid'] ) ) {
					$cleaned_urls_string = implode( "\n", $processed_urls['valid'] );
					update_post_meta( $post_id, self::META_KEY, $cleaned_urls_string );

					$this->logger->info(
						'Preload URLs saved for post',
						array(
							'post_id'       => $post_id,
							'url_count'     => count( $processed_urls['valid'] ),
							'invalid_count' => count( $processed_urls['invalid'] ),
						)
					);
				} else {
					delete_post_meta( $post_id, self::META_KEY );
					$this->logger->debug( 'Preload URLs cleared for post', array( 'post_id' => $post_id ) );
				}

				// Log invalid URLs for debugging
				if ( ! empty( $processed_urls['invalid'] ) ) {
					$this->logger->warning(
						'Invalid preload URLs detected',
						array(
							'post_id'      => $post_id,
							'invalid_urls' => $processed_urls['invalid'],
						)
					);
				}
			} else {
				delete_post_meta( $post_id, self::META_KEY );
				$this->logger->debug( 'Preload URLs removed for post', array( 'post_id' => $post_id ) );
			}
		}

		/**
		 * Process and validate preload URLs.
		 *
		 * @param string $urls_string Raw URLs string from textarea.
		 * @return array Processed URLs with valid and invalid arrays.
		 */
		private function processPreloadUrls( string $urls_string ): array {
			$lines = explode( "\n", $urls_string );
			$lines = array_map( 'trim', $lines );
			$lines = array_filter( $lines ); // Remove empty lines

			$valid_urls   = array();
			$invalid_urls = array();

			foreach ( $lines as $line ) {
				// Check for device-specific prefixes
				$device_prefix = '';
				if ( preg_match( '/^(mobile|desktop|tablet):\s*(.+)$/i', $line, $matches ) ) {
					$device = strtolower( $matches[1] );

					// Validate device prefix against allowed values
					$allowed_devices = array( 'mobile', 'desktop', 'tablet' );
					if ( ! in_array( $device, $allowed_devices, true ) ) {
						$invalid_urls[] = $line;
						continue;
					}

					$device_prefix = $device . ':';
					$url           = trim( $matches[2] );
				} else {
					$url = $line;
				}

				// Validate URL
				if ( $this->isValidPreloadUrl( $url ) ) {
					$valid_urls[] = $device_prefix . $url;
				} else {
					$invalid_urls[] = $line;
				}
			}

			return array(
				'valid'   => $valid_urls,
				'invalid' => $invalid_urls,
			);
		}

		/**
		 * Validate if URL is suitable for preloading.
		 *
		 * @param string $url URL to validate.
		 * @return bool True if valid, false otherwise.
		 */
		private function isValidPreloadUrl( string $url ): bool {
			// Basic URL validation
			if ( empty( $url ) ) {
				return false;
			}

			// Validate relative URLs more strictly
			if ( strpos( $url, '/' ) === 0 ) {
				// Prevent directory traversal
				if ( strpos( $url, '..' ) !== false ) {
					return false;
				}

				// Ensure it's within wp-content or uploads
				$allowed_paths = array( '/wp-content/', '/wp-includes/' );
				$is_allowed    = false;
				foreach ( $allowed_paths as $path ) {
					if ( strpos( $url, $path ) === 0 ) {
						$is_allowed = true;
						break;
					}
				}

				if ( ! $is_allowed ) {
					return false;
				}

				// Check if it's an image URL
				$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' );
				$extension        = strtolower( pathinfo( $url, PATHINFO_EXTENSION ) );

				return in_array( $extension, $image_extensions, true );
			}

			// Validate absolute URLs
			if ( filter_var( $url, FILTER_VALIDATE_URL ) ) {
				// Check if it's an image URL
				$image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg' );
				$extension        = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

				return in_array( $extension, $image_extensions, true );
			}

			return false;
		}

		/**
		 * Get preload URLs for a specific post.
		 *
		 * @param int $post_id Post ID.
		 * @return array Array of preload URLs.
		 */
		public function getPreloadUrls( int $post_id ): array {
			$urls_string = get_post_meta( $post_id, self::META_KEY, true );

			if ( empty( $urls_string ) ) {
				return array();
			}

			$lines = explode( "\n", $urls_string );
			$lines = array_map( 'trim', $lines );
			$lines = array_filter( $lines );

			return $lines;
		}

		/**
		 * Get device-specific preload URLs.
		 *
		 * @param int    $post_id Post ID.
		 * @param string $device  Device type (mobile, desktop, tablet).
		 * @return array Array of URLs for the specified device.
		 */
		public function getDeviceSpecificUrls( int $post_id, string $device = '' ): array {
			$all_urls    = $this->getPreloadUrls( $post_id );
			$device_urls = array();

			foreach ( $all_urls as $url ) {
				if ( preg_match( '/^(mobile|desktop|tablet):\s*(.+)$/i', $url, $matches ) ) {
					$url_device = strtolower( $matches[1] );
					$clean_url  = trim( $matches[2] );

					if ( empty( $device ) || $url_device === strtolower( $device ) ) {
						$device_urls[] = $clean_url;
					}
				} elseif ( empty( $device ) ) {
					// URLs without device prefix are universal
					$device_urls[] = $url;
				}
			}

			return $device_urls;
		}
	}
}