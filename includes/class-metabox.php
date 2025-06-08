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

namespace PerformanceOptimise\Inc;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Metabox' ) ) {

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
		const META_KEY = '_wppo_preload_image_urls'; // Changed from _wppo_preload_image_url to reflect multiple URLs.

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


		/**
		 * Constructor to hook into WordPress actions for adding and saving the metabox.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_action( 'add_meta_boxes', array( $this, 'add_preload_images_metabox' ) );
			add_action( 'save_post', array( $this, 'save_preload_images_metabox_data' ) );
		}

		/**
		 * Adds the preload image metabox to applicable post types.
		 *
		 * @since 1.0.0
		 * @param string $post_type The current post type.
		 */
		public function add_preload_images_metabox( string $post_type ): void {
			$applicable_post_types = apply_filters( 'wppo_preload_metabox_post_types', array( 'post', 'page' ) );

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
			if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
				return;
			}

			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			$post_type        = get_post_type( $post_id );
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object || ! current_user_can( $post_type_object->cap->edit_post, $post_id ) ) {
				return;
			}

			if ( isset( $_POST[ self::META_KEY ] ) ) {
				$preload_urls_string = sanitize_textarea_field( wp_unslash( $_POST[ self::META_KEY ] ) );

				$lines               = explode( "\n", $preload_urls_string );
				$lines               = array_map( 'trim', $lines );
				$lines               = array_filter( $lines ); // Remove empty lines.
				$cleaned_urls_string = implode( "\n", $lines );

				if ( ! empty( $cleaned_urls_string ) ) {
					update_post_meta( $post_id, self::META_KEY, $cleaned_urls_string );
				} else {
					delete_post_meta( $post_id, self::META_KEY );
				}
			} else {
				delete_post_meta( $post_id, self::META_KEY );
			}
		}
	}
}