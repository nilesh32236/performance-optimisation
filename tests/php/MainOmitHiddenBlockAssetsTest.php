<?php
/**
 * Tests for Main::omit_hidden_block_assets() (issue #1147 follow-up).
 *
 * Covers the hardened omission pass: singular-only gating, on-demand
 * opt-out gating, core-src handle verification, monolith skip, fail-open
 * filter-throw path, and deferral to core 6.9 hoisting.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * Tests the hidden-block-asset omission behavior.
 *
 * @package PerformanceOptimise\Tests
 */
class MainOmitHiddenBlockAssetsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap {
		WPPO_Test_Bootstrap::setUp as protected bootstrapSetUp;
		WPPO_Test_Bootstrap::tearDown as protected bootstrapTearDown;
	}

	/**
	 * Previously-global $wp_styles value.
	 *
	 * @var mixed
	 */
	private $saved_wp_styles;

	/**
	 * Previously-global $wp_version value.
	 *
	 * @var mixed
	 */
	private $saved_wp_version;

	/**
	 * Registered filter callbacks, keyed by hook, for the in-test registry.
	 *
	 * Brain Monkey's native apply_filters() only serves expectations, so
	 * filter-behavior tests need a real minimal registry (same pattern as
	 * DelayExclusionLateFilterTest).
	 *
	 * @var array
	 */
	private $registered_filters = array();

	/**
	 * Set up Brain Monkey plus global-state preservation.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->bootstrapSetUp();
		$this->saved_wp_styles  = $GLOBALS['wp_styles'] ?? null;
		$this->saved_wp_version = $GLOBALS['wp_version'] ?? null;
	}

	/**
	 * Restore global state and tear down Brain Monkey.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( null === $this->saved_wp_styles ) {
			unset( $GLOBALS['wp_styles'] );
		} else {
			$GLOBALS['wp_styles'] = $this->saved_wp_styles;
		}
		if ( null === $this->saved_wp_version ) {
			unset( $GLOBALS['wp_version'] );
		} else {
			$GLOBALS['wp_version'] = $this->saved_wp_version;
		}
		$this->bootstrapTearDown();
	}

	/**
	 * Install a real, minimal filter registry over Brain Monkey's stubs.
	 *
	 * @return void
	 */
	private function install_filter_registry(): void {
		$this->registered_filters = array();

		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) {
				$this->registered_filters[ $hook ][] = $callback;
				return true;
			}
		);

		Functions\when( 'has_filter' )->alias(
			function ( $hook ) {
				return ! empty( $this->registered_filters[ $hook ] );
			}
		);

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				foreach ( $this->registered_filters[ $hook ] ?? array() as $callback ) {
					$value = $callback( $value, ...$args );
				}
				return $value;
			}
		);
	}

	/**
	 * Build a Main instance with the given file_optimisation options.
	 *
	 * @param array $file_optimisation file_optimisation option value.
	 * @return Main
	 */
	private function make_main( array $file_optimisation ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$options = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue(
			$main,
			array(
				'file_optimisation' => $file_optimisation,
				'cache_settings'    => array(),
			)
		);

		return $main;
	}

	/**
	 * Install the WP stubs for an omission-pass run.
	 *
	 * @param array $args Stub arguments (singular, post_id, content, queue, registered, wp_version, separate, buffer).
	 * @param array $dequeued Out param recording wp_dequeue_style() calls.
	 * @return void
	 */
	private function install_stubs( array $args, array &$dequeued ): void {
		$singular   = $args['singular'] ?? true;
		$post_id    = $args['post_id'] ?? 7;
		$content    = $args['content'] ?? '';
		$queue      = $args['queue'] ?? array();
		$registered = $args['registered'] ?? array();

		$GLOBALS['wp_version'] = $args['wp_version'] ?? '6.8.0';

		Functions\when( 'is_singular' )->justReturn( $singular );
		Functions\when( 'get_the_ID' )->justReturn( $post_id );
		Functions\when( 'get_post_field' )->alias(
			static function ( $field, $id ) use ( $content ) {
				unset( $field, $id );
				return $content;
			}
		);
		Functions\when( 'wp_dequeue_style' )->alias(
			static function ( $handle ) use ( &$dequeued ): void {
				$dequeued[] = $handle;
			}
		);
		Functions\when( 'wp_should_load_separate_core_block_assets' )->justReturn( $args['separate'] ?? false );
		Functions\when( 'wp_should_output_buffer_template_for_enhancement' )->justReturn( $args['buffer'] ?? false );

		global $wp_styles;
		$wp_styles             = new \stdClass();
		$wp_styles->queue      = $queue;
		$wp_styles->registered = $registered;
	}

	/**
	 * Build a registered core block-style entry.
	 *
	 * @param string $src Registered stylesheet src.
	 * @return object
	 */
	private function registered_style( string $src ): object {
		return (object) array(
			'src'  => $src,
			'args' => 'all',
		);
	}

	/**
	 * Default enabled file_optimisation options for the omission pass.
	 *
	 * @return array
	 */
	private function enabled_options(): array {
		return array(
			'blockAssetsOnDemand'    => true,
			'loadAllCoreBlockAssets' => false,
		);
	}

	/**
	 * Present block is kept, absent block is omitted on singular views.
	 *
	 * @return void
	 */
	public function test_singular_omits_absent_block_and_keeps_present_block(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-cover', 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-cover'   => $this->registered_style( 'http://example.com/wp-includes/blocks/cover/style.css' ),
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
			),
			$dequeued
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array( 'wp-block-gallery' ), $dequeued );
	}

	/**
	 * Non-singular views never omit: archive content is not authoritative.
	 *
	 * @return void
	 */
	public function test_non_singular_bails_out(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'singular'   => false,
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
			),
			$dequeued
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * Empty/unresolvable content keeps every asset (fail-open).
	 *
	 * @return void
	 */
	public function test_empty_content_keeps_all_assets(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'post_id'    => 0,
				'content'    => '',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
			),
			$dequeued
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * The re-enable filter keeps an otherwise-hidden asset.
	 *
	 * @return void
	 */
	public function test_filter_reenable_keeps_asset(): void {
		$dequeued = array();
		$this->install_filter_registry();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
			),
			$dequeued
		);
		add_filter(
			'wppo_allow_hidden_block_asset',
			static function ( $allowed, $block_name, $handle ) {
				unset( $allowed, $handle );
				return 'core/gallery' === $block_name ? true : false;
			},
			10,
			3
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * A throwing filter listener fails open (asset kept, never unstyled).
	 *
	 * @return void
	 */
	public function test_filter_throw_keeps_asset(): void {
		$dequeued = array();
		$this->install_filter_registry();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
			),
			$dequeued
		);
		add_filter(
			'wppo_allow_hidden_block_asset',
			static function () {
				throw new \Exception( 'customizer broke' );
			},
			10,
			3
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * Core 6.9 hoisting with the enhancement buffer defers entirely to core.
	 *
	 * @return void
	 */
	public function test_defers_to_core_hoisting_buffer(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
				'wp_version' => '6.9.0',
				'separate'   => true,
				'buffer'     => true,
			),
			$dequeued
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * The combined monolith family is never an omission candidate.
	 *
	 * @return void
	 */
	public function test_monolith_handle_skipped(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-library', 'wp-block-library-theme' ),
				'registered' => array(
					'wp-block-library'       => $this->registered_style( 'http://example.com/wp-includes/css/dist/block-library/style.css' ),
					'wp-block-library-theme' => $this->registered_style( 'http://example.com/wp-includes/css/dist/block-library/theme.css' ),
				),
			),
			$dequeued
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * Third-party handles sharing the wp-block- prefix are never omitted.
	 *
	 * @return void
	 */
	public function test_non_core_prefixed_handle_skipped(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-custom-slider' ),
				'registered' => array(
					'wp-block-custom-slider' => $this->registered_style( 'http://example.com/wp-content/plugins/custom-slider/style.css' ),
				),
			),
			$dequeued
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * Queued handles with no registered src are kept (unverifiable).
	 *
	 * @return void
	 */
	public function test_unregistered_handle_skipped(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(),
			),
			$dequeued
		);

		$this->make_main( $this->enabled_options() )->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * The on-demand opt-out disables the omission pass entirely.
	 *
	 * @return void
	 */
	public function test_optout_disables_omission(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
			),
			$dequeued
		);

		$this->make_main(
			array(
				'blockAssetsOnDemand'    => true,
				'loadAllCoreBlockAssets' => true,
			)
		)->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}

	/**
	 * Disabled on-demand loading disables the omission pass entirely.
	 *
	 * @return void
	 */
	public function test_disabled_on_demand_disables_omission(): void {
		$dequeued = array();
		$this->install_stubs(
			array(
				'content'    => '<!-- wp:core/cover --><!-- /wp:core/cover -->',
				'queue'      => array( 'wp-block-gallery' ),
				'registered' => array(
					'wp-block-gallery' => $this->registered_style( 'http://example.com/wp-includes/blocks/gallery/style.css' ),
				),
			),
			$dequeued
		);

		$this->make_main(
			array(
				'blockAssetsOnDemand'    => false,
				'loadAllCoreBlockAssets' => false,
			)
		)->omit_hidden_block_assets();

		$this->assertSame( array(), $dequeued );
	}
}
