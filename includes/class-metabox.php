<?php
/**
 * Handles the functionality for adding and saving metaboxes.
 *
 * This file includes the `Metabox` class, which integrates with the WordPress post editor
 * to allow users to add and save a list of image URLs to preload, and to manage
 * per-page script/style assets.
 *
 * @package PerformanceOptimise
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Metabox' ) ) {

	/**
	 * Metabox Class for Preload Image URL and Asset Manager.
	 *
	 * This class handles the functionality for adding and saving the preload image
	 * metabox and the asset manager metabox to the WordPress post editor.
	 *
	 * @since 1.0.0
	 * @package PerformanceOptimise
	 */
	class Metabox {

		/**
		 * Constructor to hook into WordPress actions for adding and saving the metabox.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// Hook into WordPress to add the metaboxes.
			add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
			// Hook to save the metabox data.
			add_action( 'save_post', array( $this, 'save_metabox' ) );
		}

		/**
		 * Adds metaboxes to the post editor.
		 *
		 * @since 1.0.0
		 */
		public function add_metabox() {
			$post_types = get_post_types( array( 'public' => true ), 'names' );
			$excluded   = array( 'attachment' );
			$post_types = array_diff( $post_types, $excluded );

			add_meta_box(
				'preload_image_metabox',
				__( 'Preload Image URL', 'performance-optimisation' ),
				array( $this, 'render_metabox' ),
				'',
				'side',
				'default'
			);

			// Asset Manager meta box — appears on all public post types.
			foreach ( $post_types as $post_type ) {
				add_meta_box(
					'wppo_asset_manager',
					__( 'Asset Manager — Disable Scripts/Styles', 'performance-optimisation' ),
					array( $this, 'render_asset_manager_metabox' ),
					$post_type,
					'normal',
					'low'
				);
			}
		}

		/**
		 * Renders the content of the preload image URL metabox.
		 *
		 * @param \WP_Post $post The current post object.
		 * @since 1.0.0
		 */
		public function render_metabox( $post ) {
			// Retrieve current meta value.
			$preload_urls = get_post_meta( $post->ID, '_wppo_preload_image_url', true );

			// Add a nonce for security.
			wp_nonce_field( 'save_preload_image_url', 'wppo_preload_image_nonce' );
			?>
			<p>
				<label for="wppo_preload_image_url"><?php esc_html_e( 'Preload Image URL:', 'performance-optimisation' ); ?></label>
				<textarea id="wppo_preload_image_url" name="wppo_preload_image_url" rows="5" style="width: 100%;" placeholder="https://example.com/image1.jpg&#10;https://example.com/image2.jpg"><?php echo esc_textarea( $preload_urls ); ?></textarea>
			</p>
			<?php
		}

		/**
		 * Renders the Asset Manager metabox content.
		 *
		 * Displays a list of all scripts and styles captured on the frontend
		 * for the current post, with checkboxes to disable them.
		 *
		 * @param \WP_Post $post The current post object.
		 * @since 1.1.0
		 */
		public function render_asset_manager_metabox( $post ) {
			wp_nonce_field( 'wppo_save_asset_manager', 'wppo_asset_manager_nonce' );

			$disabled_scripts = get_post_meta( $post->ID, '_wppo_disabled_scripts', true );
			$disabled_styles  = get_post_meta( $post->ID, '_wppo_disabled_styles', true );

			if ( ! is_array( $disabled_scripts ) ) {
				$disabled_scripts = array();
			}
			if ( ! is_array( $disabled_styles ) ) {
				$disabled_styles = array();
			}

			$assets        = Asset_Manager::get_page_assets( $post->ID );
			$protected_js  = Asset_Manager::get_protected_scripts();
			$protected_css = Asset_Manager::get_protected_styles();
			?>
			<div class="wppo-asset-manager">
				<?php if ( false === $assets || ( empty( $assets['scripts'] ) && empty( $assets['styles'] ) ) ) : ?>
					<p class="description">
						<?php
						esc_html_e(
							'No assets have been captured yet. Visit this page/post on the frontend while logged out, then come back here to manage its assets.',
							'performance-optimisation'
						);
						?>
					</p>
					<?php if ( 'publish' === $post->post_status ) : ?>
						<p>
							<a href="<?php echo esc_url( get_permalink( $post->ID ) ); ?>" target="_blank" class="button">
								<?php esc_html_e( 'Visit Page to Capture Assets', 'performance-optimisation' ); ?>
							</a>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<?php if ( ! empty( $assets['timestamp'] ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: Human-readable time difference */
								esc_html__( 'Assets captured %s ago. Visit the page again to refresh.', 'performance-optimisation' ),
								esc_html( human_time_diff( $assets['timestamp'], time() ) )
							);
							?>
						</p>
					<?php endif; ?>

					<?php if ( ! empty( $assets['scripts'] ) ) : ?>
						<h4><?php esc_html_e( 'Scripts', 'performance-optimisation' ); ?></h4>
						<table class="widefat fixed striped" style="margin-bottom: 15px;">
							<thead>
								<tr>
									<th style="width: 30px;"><?php esc_html_e( 'Disable', 'performance-optimisation' ); ?></th>
									<th><?php esc_html_e( 'Handle', 'performance-optimisation' ); ?></th>
									<th><?php esc_html_e( 'Source', 'performance-optimisation' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $assets['scripts'] as $script ) : ?>
									<?php
									$is_protected = in_array( $script['handle'], $protected_js, true );
									$is_disabled  = in_array( $script['handle'], $disabled_scripts, true );
									?>
									<tr<?php echo $is_protected ? ' style="opacity: 0.5;"' : ''; ?>>
										<td>
											<input
												type="checkbox"
												name="wppo_disabled_scripts[]"
												value="<?php echo esc_attr( $script['handle'] ); ?>"
												<?php checked( $is_disabled ); ?>
												<?php disabled( $is_protected ); ?>
											/>
										</td>
										<td>
											<code><?php echo esc_html( $script['handle'] ); ?></code>
											<?php if ( $is_protected ) : ?>
												<em>(<?php esc_html_e( 'protected', 'performance-optimisation' ); ?>)</em>
											<?php endif; ?>
										</td>
										<td>
											<small><?php echo esc_html( $script['src'] ); ?></small>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<?php if ( ! empty( $assets['styles'] ) ) : ?>
						<h4><?php esc_html_e( 'Styles', 'performance-optimisation' ); ?></h4>
						<table class="widefat fixed striped">
							<thead>
								<tr>
									<th style="width: 30px;"><?php esc_html_e( 'Disable', 'performance-optimisation' ); ?></th>
									<th><?php esc_html_e( 'Handle', 'performance-optimisation' ); ?></th>
									<th><?php esc_html_e( 'Source', 'performance-optimisation' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $assets['styles'] as $style ) : ?>
									<?php
									$is_protected = in_array( $style['handle'], $protected_css, true );
									$is_disabled  = in_array( $style['handle'], $disabled_styles, true );
									?>
									<tr<?php echo $is_protected ? ' style="opacity: 0.5;"' : ''; ?>>
										<td>
											<input
												type="checkbox"
												name="wppo_disabled_styles[]"
												value="<?php echo esc_attr( $style['handle'] ); ?>"
												<?php checked( $is_disabled ); ?>
												<?php disabled( $is_protected ); ?>
											/>
										</td>
										<td>
											<code><?php echo esc_html( $style['handle'] ); ?></code>
											<?php if ( $is_protected ) : ?>
												<em>(<?php esc_html_e( 'protected', 'performance-optimisation' ); ?>)</em>
											<?php endif; ?>
										</td>
										<td>
											<small><?php echo esc_html( $style['src'] ); ?></small>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Saves metabox data when the post is saved.
		 *
		 * @param int $post_id The ID of the post being saved.
		 * @since 1.0.0
		 */
		public function save_metabox( $post_id ) {
			// Prevent autosave from overwriting.
			if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
				return;
			}

			// Check the user's permissions.
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			// Save preload image URLs.
			if ( isset( $_POST['wppo_preload_image_nonce'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppo_preload_image_nonce'] ) ), 'save_preload_image_url' ) ) {
				if ( isset( $_POST['wppo_preload_image_url'] ) ) {
					$preload_urls = sanitize_textarea_field( wp_unslash( $_POST['wppo_preload_image_url'] ) );
					update_post_meta( $post_id, '_wppo_preload_image_url', $preload_urls );
				}
			}

			// Save Asset Manager disabled scripts/styles.
			if ( isset( $_POST['wppo_asset_manager_nonce'] ) &&
				wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppo_asset_manager_nonce'] ) ), 'wppo_save_asset_manager' ) ) {

				// Save disabled scripts.
				$disabled_scripts = array();
				if ( isset( $_POST['wppo_disabled_scripts'] ) && is_array( $_POST['wppo_disabled_scripts'] ) ) {
					$disabled_scripts = array_map( 'sanitize_text_field', wp_unslash( $_POST['wppo_disabled_scripts'] ) );
				}
				update_post_meta( $post_id, '_wppo_disabled_scripts', $disabled_scripts );

				// Save disabled styles.
				$disabled_styles = array();
				if ( isset( $_POST['wppo_disabled_styles'] ) && is_array( $_POST['wppo_disabled_styles'] ) ) {
					$disabled_styles = array_map( 'sanitize_text_field', wp_unslash( $_POST['wppo_disabled_styles'] ) );
				}
				update_post_meta( $post_id, '_wppo_disabled_styles', $disabled_styles );
			}
		}
	}
}
