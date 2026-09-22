<?php
/**
 * Tests for Metabox class.
 *
 * Verifies the post editor metaboxes (Preload Image URL + Asset Manager) are
 * registered and render purely server-side HTML. Since WordPress 7.1 the post
 * editor is always rendered in an iframe with its own `document`/`window`, so
 * any metabox JS reaching for the global `document`/`window` would target the
 * wrong document. These tests assert the rendered output contains no inline
 * script tags, no `addEventListener` bindings, and no `onclick` handlers, i.e.
 * no global document/window access is involved.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */

use PerformanceOptimise\Inc\Metabox;
use Brain\Monkey\Functions;

/**
 * Tests for the Metabox class.
 *
 * @package PerformanceOptimise\Tests
 */
class MetaboxTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Post-like object passed to the render callbacks.
	 *
	 * @var object
	 */
	private $post;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->post = (object) array(
			'ID'          => 123,
			'post_status' => 'publish',
		);

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'disabled' )->justReturn( '' );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		// get_raw_post_string() unslashes before its is_string() guard.
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
	}

	/**
	 * Instantiate the metabox without running its constructor.
	 *
	 * The constructor only registers WordPress hooks, which are not wired up
	 * in the unit-test environment, so the class is created reflection-based
	 * (same pattern as BlockAssetsFiltersTest).
	 *
	 * @return Metabox
	 */
	private function make_metabox(): Metabox {
		return ( new ReflectionClass( Metabox::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Capture the output of a metabox render callback.
	 *
	 * @param callable $callback The render callback to invoke.
	 * @return string The captured output.
	 */
	private function capture_render( callable $callback ): string {
		ob_start();
		$callback( $this->post );
		return (string) ob_get_clean();
	}

	/**
	 * Test that both metaboxes are registered via add_meta_box.
	 *
	 * The preload metabox is added on the current (post editor) screen, the
	 * Asset Manager metabox on every public post type except attachments.
	 */
	public function test_add_metabox_registers_preload_and_asset_manager_metaboxes(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post', 'page', 'attachment', 'product' ) );

		$boxes = array();
		Functions\when( 'add_meta_box' )->alias(
			function () use ( &$boxes ) {
				$boxes[] = func_get_args();
			}
		);

		$metabox = $this->make_metabox();
		$metabox->add_metabox();

		$preload_box = null;
		$asset_boxes = array();
		foreach ( $boxes as $args ) {
			if ( 'preload_image_metabox' === $args[0] ) {
				$preload_box = $args;
			}
			if ( 'wppo_asset_manager' === $args[0] ) {
				$asset_boxes[] = $args;
			}
		}

		// Preload metabox is registered for the current screen, side context.
		$this->assertNotNull( $preload_box );
		$this->assertSame( 'side', $preload_box[4] );

		// Asset Manager metabox is registered per public post type (no attachment).
		$screens = array_map(
			static function ( $args ) {
				return $args[3];
			},
			$asset_boxes
		);
		$this->assertSame( array( 'post', 'page', 'product' ), $screens );
	}

	/**
	 * Test that the Preload Image URL metabox renders server-side markup.
	 *
	 * The output must include the textarea, its saved value, and the nonce
	 * field — and no inline script tags that could rely on the global
	 * document/window inside the always-iframed post editor.
	 */
	public function test_render_metabox_outputs_server_side_markup_without_scripts(): void {
		$preload_url = "https://example.com/hero.jpg\nhttps://example.com/banner.jpg";
		Functions\when( 'get_post_meta' )->justReturn( $preload_url );
		Functions\when( 'wp_nonce_field' )->echoArg( 2 );

		$output = $this->capture_render( array( $this->make_metabox(), 'render_metabox' ) );

		$this->assertStringContainsString( 'wppo_preload_image_url', $output );
		$this->assertStringContainsString( 'https://example.com/hero.jpg', $output );
		$this->assertStringContainsString( 'wppo_preload_image_nonce', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'addEventListener', $output );
		$this->assertStringNotContainsString( 'onclick=', $output );
	}

	/**
	 * Test that the preload metabox renders the manual per-post LCP URL picker.
	 *
	 * The single-URL `_wppo_lcp_preload_url` input is the fallback path that
	 * works before auto-detect (RUM / OD / PageSpeed) has data. Output stays
	 * server-side with no inline scripts.
	 */
	public function test_render_metabox_outputs_manual_lcp_url_picker(): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key, $single ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return '_wppo_lcp_preload_url' === $key ? 'https://example.com/pinned-hero.jpg' : '';
			}
		);
		Functions\when( 'wp_nonce_field' )->echoArg( 2 );

		$output = $this->capture_render( array( $this->make_metabox(), 'render_metabox' ) );

		$this->assertStringContainsString( 'wppo_lcp_preload_url', $output );
		$this->assertStringContainsString( 'https://example.com/pinned-hero.jpg', $output );
		$this->assertStringNotContainsString( '<script', $output );
	}

	/**
	 * Test the Asset Manager metabox empty state when no assets are captured.
	 *
	 * The empty-state message and the "Visit Page to Capture Assets" link are
	 * rendered server-side with no inline scripts.
	 */
	public function test_render_asset_manager_shows_empty_state_when_no_assets_captured(): void {
		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/?p=123' );
		Functions\when( 'wp_nonce_field' )->echoArg( 2 );

		$output = $this->capture_render( array( $this->make_metabox(), 'render_asset_manager_metabox' ) );

		$this->assertStringContainsString( 'No assets have been captured yet', $output );
		$this->assertStringContainsString( 'Visit Page to Capture Assets', $output );
		$this->assertStringContainsString( 'wppo_asset_manager_nonce', $output );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'addEventListener', $output );
		$this->assertStringNotContainsString( 'onclick=', $output );
	}

	/**
	 * Test the Asset Manager metabox renders captured scripts and styles.
	 *
	 * Handles, sources, delay strategies, priorities, and protected markers are
	 * all emitted as plain HTML tables with no inline scripts.
	 */
	public function test_render_asset_manager_renders_captured_assets_without_scripts(): void {
		$assets = array(
			'timestamp' => time(),
			'scripts'   => array(
				array(
					'handle' => 'my-plugin',
					'src'    => 'https://example.com/my-plugin.js',
				),
				array(
					'handle' => 'jquery',
					'src'    => 'https://example.com/jquery.js',
				),
			),
			'styles'    => array(
				array(
					'handle' => 'my-theme',
					'src'    => 'https://example.com/theme.css',
				),
			),
		);

		$meta = array(
			'_wppo_disabled_scripts' => array( 'my-plugin' ),
			'_wppo_disabled_styles'  => array(),
			'_wppo_delay_strategies' => array( 'my-plugin' => 'interaction' ),
			'_wppo_delay_priorities' => array( 'my-plugin' => 'high' ),
		);

		Functions\when( 'get_post_meta' )->alias(
			static function ( $post_id, $key ) use ( $meta ) {
				return $meta[ $key ] ?? array();
			}
		);
		Functions\when( 'get_transient' )->justReturn( $assets );
		Functions\when( 'human_time_diff' )->justReturn( '5 minutes' );
		Functions\when( 'wp_nonce_field' )->echoArg( 2 );

		$output = $this->capture_render( array( $this->make_metabox(), 'render_asset_manager_metabox' ) );

		$this->assertStringContainsString( 'my-plugin', $output );
		$this->assertStringContainsString( 'my-theme', $output );
		$this->assertStringContainsString( 'jquery', $output );
		$this->assertStringContainsString( 'interaction', $output );
		$this->assertStringContainsString( 'high', $output );
		$this->assertStringContainsString( 'protected', $output );
		// Every header cell in the scripts (6, incl. Size) and styles (4,
		// incl. Size) tables must declare its column scope so AT can
		// associate data cells (audit #1077).
		$this->assertSame( 10, substr_count( $output, 'scope="col"' ), 'All Asset Manager <th> cells must carry scope="col"' );
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'addEventListener', $output );
		$this->assertStringNotContainsString( 'onclick=', $output );
	}

	/**
	 * Test that process_delay_setting correctly filters data using guard clauses.
	 *
	 * @since 2.0.0
	 */
	public function test_process_delay_setting_filters_correctly(): void {
		\Brain\Monkey\Functions\when( 'sanitize_text_field' )->returnArg();

		$metabox = $this->make_metabox();

		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'process_delay_setting' );

		$raw_data       = array(
			'valid-handle'   => 'interaction',
			'empty-value'    => '',
			'invalid-handle' => 'viewport',
			'invalid-value'  => 'unknown',
		);
		$valid_handles  = array( 'valid-handle', 'empty-value', 'invalid-value' );
		$allowed_values = array( '', 'interaction', 'idle', 'viewport' );

		$result = $method->invokeArgs( $metabox, array( $raw_data, $valid_handles, $allowed_values ) );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'valid-handle', $result );
		$this->assertSame( 'interaction', $result['valid-handle'] );

		$this->assertArrayNotHasKey( 'empty-value', $result, 'Empty values should be skipped' );
		$this->assertArrayNotHasKey( 'invalid-handle', $result, 'Handles not in valid_handles should be skipped' );
		$this->assertArrayNotHasKey( 'invalid-value', $result, 'Values not in allowed_values should be skipped' );
	}

	/**
	 * An array-shaped POST value must never reach sanitize_text_field().
	 *
	 * Regression: the nonce and preload-URL fields were read straight out of
	 * $_POST behind only an isset() guard, so a crafted array value reached
	 * sanitize_text_field()/sanitize_textarea_field() and threw a TypeError on
	 * PHP 8 — fataling save_post instead of failing nonce verification.
	 */
	public function test_get_raw_post_string_rejects_non_string_values(): void {
		$_POST['wppo_array_probe']  = array( 'nested' );
		$_POST['wppo_scalar_probe'] = 'ok';

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'get_raw_post_string' );

		try {
			$this->assertSame( '', $method->invokeArgs( $metabox, array( 'wppo_array_probe' ) ), 'Array input must coerce to an empty string' );
			$this->assertSame( 'ok', $method->invokeArgs( $metabox, array( 'wppo_scalar_probe' ) ) );
			$this->assertSame( '', $method->invokeArgs( $metabox, array( 'wppo_missing_probe' ) ) );
		} finally {
			unset( $_POST['wppo_array_probe'], $_POST['wppo_scalar_probe'] );
		}
	}

	/**
	 * Saving with array-shaped POST values must fail closed, not fatal.
	 */
	public function test_save_preload_image_urls_fails_closed_on_array_post_values(): void {
		$_POST['wppo_preload_image_nonce'] = array( 'evil' );
		$_POST['wppo_preload_image_url']   = array( 'https://example.com/a.jpg' );

		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'save_preload_image_urls' );

		try {
			// Reaching the assertion at all proves no TypeError was thrown.
			$method->invokeArgs( $metabox, array( 123 ) );
			$this->assertTrue( true, 'Array nonce must fail verification without a TypeError' );
		} finally {
			unset( $_POST['wppo_preload_image_nonce'], $_POST['wppo_preload_image_url'] );
		}
	}

	/**
	 * Saving the Asset Manager metabox with an array nonce must fail closed.
	 */
	public function test_save_asset_manager_settings_fails_closed_on_array_nonce(): void {
		$_POST['wppo_asset_manager_nonce'] = array( 'evil' );

		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'save_asset_manager_settings' );

		try {
			$method->invokeArgs( $metabox, array( 123 ) );
			$this->assertTrue( true, 'Array nonce must fail verification without a TypeError' );
		} finally {
			unset( $_POST['wppo_asset_manager_nonce'] );
		}
	}

	/**
	 * Saving the preload metabox persists the manual per-post LCP URL.
	 */
	public function test_save_preload_image_urls_persists_manual_lcp_url(): void {
		$_POST['wppo_preload_image_nonce'] = 'valid-nonce';
		$_POST['wppo_preload_image_url']   = 'https://example.com/a.jpg';
		$_POST['wppo_lcp_preload_url']     = 'https://example.com/pinned-hero.jpg';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		$saved = array();
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$saved ) {
				$saved[ $key ] = $value;
				return true;
			}
		);

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'save_preload_image_urls' );

		try {
			$method->invokeArgs( $metabox, array( 123 ) );
			$this->assertSame( 'https://example.com/pinned-hero.jpg', $saved['_wppo_lcp_preload_url'] );
		} finally {
			unset( $_POST['wppo_preload_image_nonce'], $_POST['wppo_preload_image_url'], $_POST['wppo_lcp_preload_url'] );
		}
	}

	/**
	 * An array-shaped manual LCP URL must fail closed, not fatal.
	 */
	public function test_save_preload_image_urls_fails_closed_on_array_lcp_url(): void {
		$_POST['wppo_preload_image_nonce'] = 'valid-nonce';
		$_POST['wppo_preload_image_url']   = 'https://example.com/a.jpg';
		$_POST['wppo_lcp_preload_url']     = array( 'https://example.com/pinned-hero.jpg' );

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'update_post_meta' )->justReturn( true );
		$deleted = array();
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'save_preload_image_urls' );

		try {
			// Reaching the assertion at all proves no TypeError was thrown.
			$method->invokeArgs( $metabox, array( 123 ) );
			$this->assertContains( '_wppo_lcp_preload_url', $deleted );
		} finally {
			unset( $_POST['wppo_preload_image_nonce'], $_POST['wppo_preload_image_url'], $_POST['wppo_lcp_preload_url'] );
		}
	}

	/**
	 * An empty manual LCP URL deletes the meta instead of storing an empty string.
	 */
	public function test_save_preload_image_urls_deletes_manual_lcp_url_when_empty(): void {
		$_POST['wppo_preload_image_nonce'] = 'valid-nonce';
		$_POST['wppo_preload_image_url']   = 'https://example.com/a.jpg';
		$_POST['wppo_lcp_preload_url']     = '   ';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'update_post_meta' )->justReturn( true );
		$deleted = array();
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'save_preload_image_urls' );

		try {
			$method->invokeArgs( $metabox, array( 123 ) );
			$this->assertContains( '_wppo_lcp_preload_url', $deleted );
		} finally {
			unset( $_POST['wppo_preload_image_nonce'], $_POST['wppo_preload_image_url'], $_POST['wppo_lcp_preload_url'] );
		}
	}

	/**
	 * Saving with the disable checkbox persists the auto-LCP kill switch.
	 */
	public function test_save_preload_image_urls_persists_disable_auto_lcp(): void {
		$_POST['wppo_preload_image_nonce'] = 'valid-nonce';
		$_POST['wppo_disable_auto_lcp']    = '1';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		$saved = array();
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$saved ) {
				$saved[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->justReturn( true );

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'save_preload_image_urls' );

		try {
			$method->invokeArgs( $metabox, array( 123 ) );
			$this->assertSame( '1', $saved['_wppo_disable_auto_lcp'] );
		} finally {
			unset( $_POST['wppo_preload_image_nonce'], $_POST['wppo_disable_auto_lcp'] );
		}
	}

	/**
	 * The Size column renders for scripts and styles, with an em dash
	 * when the captured entry has no measured size.
	 *
	 * @since 2.3.0
	 */
	public function test_render_asset_manager_shows_size_column(): void {
		$assets = array(
			'timestamp' => time(),
			'scripts'   => array(
				array(
					'handle' => 'heavy-slider',
					'src'    => 'http://example.com/slider.js',
					'size'   => 204800,
				),
				array(
					'handle' => 'unknown-size',
					'src'    => 'http://example.com/unknown.js',
				),
			),
			'styles'    => array(),
		);

		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( $assets );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'human_time_diff' )->justReturn( '5 minutes' );
		Functions\when( 'wp_nonce_field' )->echoArg( 2 );

		$output = $this->capture_render( array( $this->make_metabox(), 'render_asset_manager_metabox' ) );

		$this->assertStringContainsString( 'Size', $output );
		$this->assertStringContainsString( '200.0 KB', $output );
		$this->assertStringNotContainsString( '<script', $output );
	}

	/**
	 * The suggest-only assistant lists heavy candidates without checking
	 * any disable box for them.
	 *
	 * @since 2.3.0
	 */
	public function test_render_asset_manager_suggests_without_auto_disabling(): void {
		$assets = array(
			'timestamp' => time(),
			'scripts'   => array(
				array(
					'handle' => 'heavy-slider',
					'src'    => 'http://example.com/slider.js',
					'size'   => 204800,
				),
			),
			'styles'    => array(),
		);

		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( $assets );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'human_time_diff' )->justReturn( '5 minutes' );
		Functions\when( 'wp_nonce_field' )->echoArg( 2 );

		$output = $this->capture_render( array( $this->make_metabox(), 'render_asset_manager_metabox' ) );

		$this->assertStringContainsString( 'Assistant suggestions', $output );
		$this->assertStringContainsString( 'heavy-slider', $output );
		// No disable checkbox may be pre-checked for the suggested handle.
		$this->assertStringNotContainsString( 'checked', $output );
	}

	/**
	 * A pending blocked-handle notice renders once, server-side (the React
	 * NoticeBanner cannot run inside the iframed post editor).
	 *
	 * @since 2.3.0
	 */
	public function test_render_asset_manager_shows_blocked_notice_once(): void {
		$assets = array(
			'timestamp' => time(),
			'scripts'   => array(
				array(
					'handle' => 'jquery',
					'src'    => 'http://example.com/jquery.js',
				),
			),
			'styles'    => array(),
		);

		Functions\when( 'get_post_meta' )->justReturn( array() );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( $assets ) {
				return false !== strpos( (string) $key, 'blocked' ) ? array( 'jquery' ) : $assets;
			}
		);
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'human_time_diff' )->justReturn( '5 minutes' );
		Functions\when( 'wp_nonce_field' )->echoArg( 2 );

		$output = $this->capture_render( array( $this->make_metabox(), 'render_asset_manager_metabox' ) );

		$this->assertStringContainsString( 'Protected handles were not disabled', $output );
		$this->assertStringContainsString( 'jquery', $output );
		$this->assertStringContainsString( 'role="alert"', $output );
	}

	/**
	 * Invoke the private save_asset_manager_settings() with a stubbed WP API.
	 *
	 * @param array $post_data $_POST fixture.
	 * @param mixed $transient get_transient() return value.
	 * @param array $saved     Collected update_post_meta writes (by key).
	 * @param array $deleted   Collected delete_post_meta keys.
	 * @return void
	 */
	private function invoke_save_asset_manager( array $post_data, $transient, array &$saved, array &$deleted ): void {
		foreach ( $post_data as $key => $value ) {
			$_POST[ $key ] = $value;
		}

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( $transient );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) use ( &$saved ) {
				$saved[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		try {
			$metabox    = $this->make_metabox();
			$reflection = new \ReflectionClass( $metabox );
			$method     = $reflection->getMethod( 'save_asset_manager_settings' );
			$method->invokeArgs( $metabox, array( 123 ) );
		} finally {
			foreach ( array_keys( $post_data ) as $key ) {
				unset( $_POST[ $key ] );
			}
		}
	}

	/**
	 * MB10 guard: with no capture yet, existing disables are preserved
	 * instead of being overwritten to [].
	 *
	 * @since 2.3.0
	 */
	public function test_save_preserves_disables_when_no_assets_captured(): void {
		$saved   = array();
		$deleted = array();
		$this->invoke_save_asset_manager(
			array( 'wppo_asset_manager_nonce' => 'valid-nonce' ),
			false,
			$saved,
			$deleted
		);

		$this->assertArrayNotHasKey( '_wppo_disabled_scripts', $saved );
		$this->assertArrayNotHasKey( '_wppo_disabled_styles', $saved );
		$this->assertNotContains( '_wppo_disabled_scripts', $deleted );
	}

	/**
	 * Protected handles submitted for disabling are stripped and reported
	 * via the flash notice; only per-post meta is written, never site-wide.
	 *
	 * @since 2.3.0
	 */
	public function test_save_blocks_protected_handles_with_notice(): void {
		$assets = array(
			'scripts' => array(
				array( 'handle' => 'my-plugin' ),
				array( 'handle' => 'jquery' ),
			),
			'styles'  => array(),
		);

		$saved   = array();
		$deleted = array();
		$this->invoke_save_asset_manager(
			array(
				'wppo_asset_manager_nonce' => 'valid-nonce',
				'wppo_disabled_scripts'    => array( 'jquery', 'my-plugin' ),
			),
			$assets,
			$saved,
			$deleted
		);

		$this->assertSame( array( 'my-plugin' ), array_values( $saved['_wppo_disabled_scripts'] ) );
		$this->assertSame( array(), $saved['_wppo_delay_strategies'], 'No delay input was posted' );
	}

	/**
	 * One-click revert deletes every per-page disable on the page only.
	 *
	 * @since 2.3.0
	 */
	public function test_save_revert_clears_all_disables(): void {
		$assets = array(
			'scripts' => array(
				array( 'handle' => 'my-plugin' ),
			),
			'styles'  => array(),
		);

		$saved   = array();
		$deleted = array();
		$this->invoke_save_asset_manager(
			array(
				'wppo_asset_manager_nonce'  => 'valid-nonce',
				'wppo_asset_manager_revert' => '1',
				'wppo_disabled_scripts'     => array( 'my-plugin' ),
			),
			$assets,
			$saved,
			$deleted
		);

		$this->assertContains( '_wppo_disabled_scripts', $deleted );
		$this->assertContains( '_wppo_disabled_styles', $deleted );
		$this->assertArrayNotHasKey( '_wppo_disabled_scripts', $saved );
	}

	/**
	 * Saving without the disable checkbox deletes the kill-switch meta.
	 */
	public function test_save_preload_image_urls_deletes_disable_auto_lcp_when_unchecked(): void {
		$_POST['wppo_preload_image_nonce'] = 'valid-nonce';

		Functions\when( 'wp_verify_nonce' )->justReturn( true );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'update_post_meta' )->justReturn( true );
		$deleted = array();
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) use ( &$deleted ) {
				$deleted[] = $key;
				return true;
			}
		);

		$metabox    = $this->make_metabox();
		$reflection = new \ReflectionClass( $metabox );
		$method     = $reflection->getMethod( 'save_preload_image_urls' );

		try {
			$method->invokeArgs( $metabox, array( 123 ) );
			$this->assertContains( '_wppo_disable_auto_lcp', $deleted );
		} finally {
			unset( $_POST['wppo_preload_image_nonce'] );
		}
	}
}
