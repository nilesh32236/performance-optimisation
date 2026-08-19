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
 * @since NEXT
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
		$this->assertStringNotContainsString( '<script', $output );
		$this->assertStringNotContainsString( 'addEventListener', $output );
		$this->assertStringNotContainsString( 'onclick=', $output );
	}

	/**
	 * Test that process_delay_setting correctly filters data using guard clauses.
	 *
	 * @since NEXT
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
}
