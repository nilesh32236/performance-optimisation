<?php
/**
 * Handles the functionality for adding and saving metaboxes.
 *
 * This file includes the `Metabox` class, which integrates with the WordPress post editor
 * to allow users to add and save a list of image URLs to preload, and to manage
 * per-page script/style assets.
 *
 * @package PerformanceOptimise\Inc
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
	 * @package PerformanceOptimise\Inc
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
				$post_types,
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
		 * Output is purely server-side HTML (form fields + nonce). The WP 7.1+
		 * post editor always renders in an iframe with its own document/window,
		 * so no inline or enqueued JS may touch the global document/window for
		 * the canvas.
		 *
		 * @param \WP_Post $post The current post object.
		 * @since 1.0.0
		 * @since NEXT Output intentionally remains server-side/iframe-safe.
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
		 * Output is purely server-side HTML (tables + nonce). The WP 7.1+ post
		 * editor always renders in an iframe with its own document/window, so
		 * no inline or enqueued JS may touch the global document/window for
		 * the canvas.
		 *
		 * @param \WP_Post $post The current post object.
		 * @since 1.1.0
		 * @since NEXT Output intentionally remains server-side/iframe-safe.
		 */
		public function render_asset_manager_metabox( $post ) {
			wp_nonce_field( 'wppo_save_asset_manager', 'wppo_asset_manager_nonce' );

			$disabled_scripts = get_post_meta( $post->ID, '_wppo_disabled_scripts', true );
			$disabled_styles  = get_post_meta( $post->ID, '_wppo_disabled_styles', true );
			$delay_strategies = get_post_meta( $post->ID, '_wppo_delay_strategies', true );
			$delay_priorities = get_post_meta( $post->ID, '_wppo_delay_priorities', true );

			$disabled_scripts = is_array( $disabled_scripts ) ? $disabled_scripts : array();
			$disabled_styles  = is_array( $disabled_styles ) ? $disabled_styles : array();
			$delay_strategies = is_array( $delay_strategies ) ? $delay_strategies : array();
			$delay_priorities = is_array( $delay_priorities ) ? $delay_priorities : array();

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
									<th style="width: 130px;"><?php esc_html_e( 'Delay Strategy', 'performance-optimisation' ); ?></th>
									<th style="width: 90px;"><?php esc_html_e( 'Priority', 'performance-optimisation' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $assets['scripts'] as $script ) : ?>
									<?php
									$is_protected = in_array( $script['handle'], $protected_js, true );
									$is_disabled  = in_array( $script['handle'], $disabled_scripts, true );
									$strategy     = $delay_strategies[ $script['handle'] ] ?? '';
									$priority     = $delay_priorities[ $script['handle'] ] ?? '';
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
										<td>
											<select
												name="wppo_delay_strategies[<?php echo esc_attr( $script['handle'] ); ?>]"
												style="width: 100%;"
												<?php disabled( $is_protected ); ?>
											>
												<option value="" <?php selected( $strategy, '' ); ?>>
													<?php esc_html_e( 'Inherit', 'performance-optimisation' ); ?>
												</option>
												<option value="interaction" <?php selected( $strategy, 'interaction' ); ?>>
													<?php esc_html_e( 'Interaction', 'performance-optimisation' ); ?>
												</option>
												<option value="idle" <?php selected( $strategy, 'idle' ); ?>>
													<?php esc_html_e( 'Idle', 'performance-optimisation' ); ?>
												</option>
												<option value="viewport" <?php selected( $strategy, 'viewport' ); ?>>
													<?php esc_html_e( 'Viewport', 'performance-optimisation' ); ?>
												</option>
											</select>
										</td>
										<td>
											<select
												name="wppo_delay_priorities[<?php echo esc_attr( $script['handle'] ); ?>]"
												style="width: 100%;"
												<?php disabled( $is_protected ); ?>
											>
												<option value="" <?php selected( $priority, '' ); ?>>
													<?php esc_html_e( 'Inherit', 'performance-optimisation' ); ?>
												</option>
												<option value="high" <?php selected( $priority, 'high' ); ?>>
													<?php esc_html_e( 'High', 'performance-optimisation' ); ?>
												</option>
												<option value="normal" <?php selected( $priority, 'normal' ); ?>>
													<?php esc_html_e( 'Normal', 'performance-optimisation' ); ?>
												</option>
												<option value="low" <?php selected( $priority, 'low' ); ?>>
													<?php esc_html_e( 'Low', 'performance-optimisation' ); ?>
												</option>
											</select>
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

			$this->save_preload_image_urls( $post_id );
			$this->save_asset_manager_settings( $post_id );
		}

		/**
		 * Saves the preload image URLs metabox data.
		 *
		 * @param int $post_id The ID of the post being saved.
		 * @since 1.2.1
		 */
		private function save_preload_image_urls( $post_id ) {
			if ( ! isset( $_POST['wppo_preload_image_nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppo_preload_image_nonce'] ) ), 'save_preload_image_url' ) ) {
				return;
			}

			if ( ! isset( $_POST['wppo_preload_image_url'] ) ) {
				return;
			}

			$preload_urls = sanitize_textarea_field( wp_unslash( $_POST['wppo_preload_image_url'] ) );
			update_post_meta( $post_id, '_wppo_preload_image_url', $preload_urls );
		}

		/**
		 * Saves the Asset Manager metabox data (disabled scripts and styles).
		 *
		 * Sanitizes and then whitelists posted handles against the canonical list
		 * of captured assets for the current context.
		 *
		 * @param int $post_id The ID of the post being saved.
		 * @since 1.2.1
		 */
		private function save_asset_manager_settings( $post_id ) {
			if ( ! isset( $_POST['wppo_asset_manager_nonce'] ) ) {
				return;
			}

			if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wppo_asset_manager_nonce'] ) ), 'wppo_save_asset_manager' ) ) {
				return;
			}

			// Capture assets for whitelisting.
			$assets        = Asset_Manager::get_page_assets( $post_id );
			$valid_scripts = array();
			$valid_styles  = array();

			if ( is_array( $assets ) ) {
				if ( ! empty( $assets['scripts'] ) && is_array( $assets['scripts'] ) ) {
					$valid_scripts = array_column( $assets['scripts'], 'handle' );
				}
				if ( ! empty( $assets['styles'] ) && is_array( $assets['styles'] ) ) {
					$valid_styles = array_column( $assets['styles'], 'handle' );
				}
			}

			// Process and whitelist disabled scripts and styles.
			$raw_scripts = $this->get_raw_post_array( 'wppo_disabled_scripts' );
			$raw_styles  = $this->get_raw_post_array( 'wppo_disabled_styles' );

			update_post_meta( $post_id, '_wppo_disabled_scripts', $this->process_disabled_assets( $raw_scripts, $valid_scripts ) );
			update_post_meta( $post_id, '_wppo_disabled_styles', $this->process_disabled_assets( $raw_styles, $valid_styles ) );

			// Process per-page delay strategies.
			$raw_strategies     = $this->get_raw_post_array( 'wppo_delay_strategies' );
			$allowed_strategies = array( '', 'interaction', 'idle', 'viewport' );
			update_post_meta( $post_id, '_wppo_delay_strategies', $this->process_delay_setting( $raw_strategies, $valid_scripts, $allowed_strategies ) );

			// Process per-page delay priorities.
			$raw_priorities     = $this->get_raw_post_array( 'wppo_delay_priorities' );
			$allowed_priorities = array( '', 'high', 'normal', 'low' );
			update_post_meta( $post_id, '_wppo_delay_priorities', $this->process_delay_setting( $raw_priorities, $valid_scripts, $allowed_priorities ) );
		}

		/**
		 * Safely retrieves an unslashed array from $_POST if it exists and is an array.
		 *
		 * @param string $key The $_POST key to check.
		 * @return array The raw unslashed array, or an empty array if invalid.
		 * @since NEXT
		 */
		private function get_raw_post_array( string $key ): array {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array();
		}

		/**
		 * Helper method to process disabled assets.
		 *
		 * @param array $raw_data      Raw input array from $_POST.
		 * @param array $valid_handles Array of valid handles for the page.
		 * @return array Sanitized and whitelisted array of disabled handles.
		 * @since NEXT
		 */
		private function process_disabled_assets( array $raw_data, array $valid_handles ): array {
			if ( empty( $raw_data ) ) {
				return array();
			}
			$submitted = array_map( 'sanitize_text_field', $raw_data );
			return array_intersect( $submitted, $valid_handles );
		}

		/**
		 * Helper method to process associative delay settings (strategies, priorities).
		 *
		 * @param array $raw_data       Raw input associative array from $_POST.
		 * @param array $valid_handles  Array of valid script handles for the page.
		 * @param array $allowed_values Array of valid values for the setting.
		 * @return array Sanitized and whitelisted associative array.
		 * @since NEXT
		 */
		private function process_delay_setting( array $raw_data, array $valid_handles, array $allowed_values ): array {
			if ( empty( $raw_data ) ) {
				return array();
			}

			$saved_settings = array();
			foreach ( $raw_data as $handle => $value ) {
				$clean_handle = sanitize_text_field( $handle );
				$clean_value  = sanitize_text_field( $value );

				if ( '' === $clean_value ) {
					continue;
				}

				if ( ! in_array( $clean_handle, $valid_handles, true ) ) {
					continue;
				}

				if ( ! in_array( $clean_value, $allowed_values, true ) ) {
					continue;
				}

				$saved_settings[ $clean_handle ] = $clean_value;
			}

			return $saved_settings;
		}
	}
}
