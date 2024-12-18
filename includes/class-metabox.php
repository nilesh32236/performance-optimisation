<?php
namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Metabox' ) ) {

	/**
	 * Handles the functionality for adding and saving the preload image metabox.
	 *
	 * @since 1.0.0
	 */
	class Metabox {

		/**
		 * Constructor to hook into WordPress actions for adding and saving the metabox.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// Hook into WordPress to add the metabox
			add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
			// Hook to save the metabox data
			add_action( 'save_post', array( $this, 'save_metabox' ) );
		}

		/**
		 * Adds the preload image metabox to the post editor.
		 *
		 * @since 1.0.0
		 */
		public function add_metabox() {
			add_meta_box(
				'preload_image_metabox',
				__( 'Preload Image URL', 'performance-optimisation' ),
				array( $this, 'render_metabox' ),
				'',
				'side',
				'default'
			);
		}

		/**
		 * Renders the content of the preload image URL metabox.
		 *
		 * @param \WP_Post $post The current post object.
		 * @since 1.0.0
		 */
		public function render_metabox( $post ) {
			// Retrieve current meta value
			$preload_urls = get_post_meta( $post->ID, '_wppo_preload_image_url', true );

			// Add a nonce for security
			wp_nonce_field( 'save_preload_image_url', 'wppo_preload_image_nonce' );
			?>
			<p>
				<label for="wppo_preload_image_url"><?php esc_html_e( 'Preload Image URL:', 'performance-optimisation' ); ?></label>
				<textarea id="wppo_preload_image_url" name="wppo_preload_image_url" rows="5" style="width: 100%;" placeholder="https://example.com/image1.jpg&#10;https://example.com/image2.jpg"><?php echo esc_textarea( $preload_urls ); ?></textarea>
			</p>
			<?php
		}

		/**
		 * Saves the preload image URLs when the post is saved.
		 *
		 * @param int $post_id The ID of the post being saved.
		 * @since 1.0.0
		 */
		public function save_metabox( $post_id ) {
			// Verify the nonce
			if ( ! isset( $_POST['wppo_preload_image_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppo_preload_image_nonce'] ) ), 'save_preload_image_url' ) ) {
				return;
			}

			// Prevent autosave from overwriting
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			// Check the user's permissions
			if ( isset( $_POST['post_type'] ) && 'post' === $_POST['post_type'] ) {
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return;
				}
			}

			// Sanitize and save the data
			if ( isset( $_POST['wppo_preload_image_url'] ) ) {
				$preload_urls = sanitize_textarea_field( wp_unslash( $_POST['wppo_preload_image_url'] ) );
				update_post_meta( $post_id, '_wppo_preload_image_url', $preload_urls );
			}
		}
	}
}
