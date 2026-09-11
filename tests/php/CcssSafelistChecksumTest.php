<?php
/**
 * Tests for the Used CSS and Critical CSS user safelist + checksum auto-regen (issue #1038).
 *
 * Covers the Critical CSS user safelist (`ccssSafelistExtra`), the
 * content-checksum helpers on both engines, the checksum-triggered refresh
 * (local reads only — no remote fetch), and the 20 KB inline-cap honouring
 * on safelisted output.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Google_Fonts;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Used_CSS;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Class CcssSafelistChecksumTest.
 *
 * @package PerformanceOptimise\Tests
 */
class CcssSafelistChecksumTest extends \PHPUnit\Framework\TestCase {

	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option map backing the get_option stub.
	 *
	 * @var array
	 */
	private array $option_map = array();

	/**
	 * In-memory transient map backing the transient stubs.
	 *
	 * @var array
	 */
	private array $transient_map = array();

	/**
	 * Count of HTTP API calls made during the test.
	 *
	 * @var int
	 */
	private int $http_calls = 0;

	/**
	 * Stub the WP functions used by the new helpers.
	 *
	 * Note: this setUp shadows the trait's setUp, so it mirrors the trait
	 * bootstrap explicitly (see UsedCssHostTest): common stubs plus the
	 * per-test cache resets the trait performs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_cached_home_urls();
		Util::clear_settings_cache();
		Util::clear_permalink_cache();
		if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
			\PerformanceOptimise\Inc\Critical_CSS::reset_ccss_memo();
		}
		if ( class_exists( 'PerformanceOptimise\Inc\CDN' ) ) {
			\PerformanceOptimise\Inc\CDN::reset_cache();
		}
		if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
			\PerformanceOptimise\Inc\LiteSpeed_Crawler::reset_cache();
		}
		if ( class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) && method_exists( 'PerformanceOptimise\Inc\AI_Adaptive', 'reset_disabled_assets_cache' ) ) {
			\PerformanceOptimise\Inc\AI_Adaptive::reset_disabled_assets_cache();
		}

		$this->option_map    = array();
		$this->transient_map = array();
		$this->http_calls    = 0;

		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'untrailingslashit' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'is_archive' )->justReturn( false );
		Functions\when( 'is_search' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'get_stylesheet' )->justReturn( 'test-theme' );

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->option_map ) ? $this->option_map[ $name ] : $fallback;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transient_map ) ? $this->transient_map[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value ) {
				$this->transient_map[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transient_map[ $key ] );
				return true;
			}
		);

		$self = $this;
		Functions\when( 'wp_remote_get' )->alias(
			function () use ( $self ) {
				++$self->http_calls;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'a{color:red}',
				);
			}
		);
		Functions\when( 'wp_safe_remote_get' )->alias(
			function () use ( $self ) {
				++$self->http_calls;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'a{color:red}',
				);
			}
		);

		\PerformanceOptimise\Inc\Util::clear_settings_cache();
	}

	/**
	 * Restore globals touched by these tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_styles'] );
		if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
			\PerformanceOptimise\Inc\Critical_CSS::reset_ccss_memo();
		}
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Invoke a private static method via reflection.
	 *
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, ...$args ) {
		$reflection = new ReflectionMethod( Critical_CSS::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( null, ...$args );
	}

	/**
	 * Empty safelist keeps current behaviour: nothing matches.
	 *
	 * @return void
	 */
	public function test_empty_safelist_matches_nothing(): void {
		$this->assertSame( array(), Critical_CSS::get_ccss_safelist() );
		$this->assertFalse( Critical_CSS::matches_ccss_safelist( '.modal-open' ) );
		$this->assertFalse( Critical_CSS::matches_ccss_safelist( '' ) );
	}

	/**
	 * Safelisted hidden selectors match; unlisted dynamic content stays out.
	 *
	 * @return void
	 */
	public function test_safelist_preserves_hidden_selector(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => ".modal-open\n.sub-menu",
			),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$this->assertSame( array( '.modal-open', '.sub-menu' ), Critical_CSS::get_ccss_safelist() );
		$this->assertTrue( Critical_CSS::matches_ccss_safelist( '.modal-open' ) );
		$this->assertTrue( Critical_CSS::matches_ccss_safelist( 'nav .sub-menu li' ) );
		$this->assertFalse( Critical_CSS::matches_ccss_safelist( '.random-widget-xyz' ) );
	}

	/**
	 * Extraction keeps safelisted hidden rules and drops unlisted ones.
	 *
	 * @return void
	 */
	public function test_extraction_honours_safelist(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => '.modal-open',
			),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$css       = ".modal-open{display:block}\n.random-widget-xyz{color:blue}\nbody{margin:0}";
		$extracted = $this->invoke_private( 'extract_above_fold_css', $css );

		$this->assertStringContainsString( '.modal-open', $extracted );
		$this->assertStringContainsString( 'body', $extracted );
		$this->assertStringNotContainsString( '.random-widget-xyz', $extracted );
	}

	/**
	 * Checksums are stable per input and change with content.
	 *
	 * @return void
	 */
	public function test_checksum_stable_and_content_sensitive(): void {
		$css = 'body{color:red}';

		$this->assertSame( Critical_CSS::compute_css_checksum( $css ), Critical_CSS::compute_css_checksum( $css ) );
		$this->assertNotSame( Critical_CSS::compute_css_checksum( $css ), Critical_CSS::compute_css_checksum( $css . 'h1{margin:0}' ) );
		$this->assertSame( '', Critical_CSS::compute_css_checksum( '' ) );
	}

	/**
	 * Staleness lifecycle: no baseline is fresh, stored match is fresh,
	 * changed source is stale.
	 *
	 * @return void
	 */
	public function test_checksum_staleness_lifecycle(): void {
		$hash   = 'safelistchecksumlifecycle1';
		$source = 'body{color:red}';

		$this->assertFalse( Critical_CSS::is_source_checksum_stale( $hash, $source ) );

		Critical_CSS::store_source_checksum( $hash, $source );
		$this->assertFalse( Critical_CSS::is_source_checksum_stale( $hash, $source ) );
		$this->assertTrue( Critical_CSS::is_source_checksum_stale( $hash, $source . 'h1{margin:0}' ) );
		$this->assertFalse( Critical_CSS::is_source_checksum_stale( $hash, '' ) );
		$this->assertFalse( Critical_CSS::is_source_checksum_stale( '', $source ) );
	}

	/**
	 * The refresh helper uses local reads only and drops stale variants.
	 *
	 * Uses a unique hash per run so parallel runs or leftover files from a
	 * prior aborted run cannot collide; CCSS memo is reset around the
	 * file-drop assertion.
	 *
	 * @return void
	 */
	public function test_refresh_from_local_css_drops_stale_without_remote_fetch(): void {
		$hash = 'safelistrefresh' . substr( md5( uniqid( 'wppo', true ) ), 0, 8 );
		Critical_CSS::reset_ccss_memo();
		$dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$file = $dir . '/' . $hash . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file, 'body{margin:0}' );

		try {
			// Baseline the raw local-source domain that
			// maybe_refresh_from_local_css() hashes (issue #1038): the
			// generation-time baseline and the probe must share one domain.
			Critical_CSS::store_source_checksum( $hash, 'body{margin:0}' );

			// Unchanged source: fresh, file kept.
			$this->assertFalse( Critical_CSS::maybe_refresh_from_local_css( $hash, 'body{margin:0}' ) );
			$this->assertFileExists( $file );

			// Changed source: stale, file dropped for regen.
			$this->assertTrue( Critical_CSS::maybe_refresh_from_local_css( $hash, "body{margin:0}\nh1{font-size:2em}" ) );
			$this->assertFileDoesNotExist( $file );

			// No remote fetch happened anywhere in the helper path.
			$this->assertSame( 0, $this->http_calls );
		} finally {
			Critical_CSS::reset_ccss_memo();
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $file );
			}
		}
	}

	/**
	 * Integration: an unchanged source keeps the generated CCSS variant.
	 *
	 * Drives the real production pair — generate_and_store() baselines the
	 * source checksum from the fetched page, then the frontend probe
	 * maybe_check_stale_and_requeue() recomputes it from $wp_styles. Both
	 * sides must hash the SAME source domain, otherwise the freshly generated
	 * .css is deleted on the very next request and regenerated forever
	 * (issue #1038 blocking defect).
	 *
	 * The page emits two stylesheets in document order b,a (alphabetical order
	 * would be a,b) so the old remote-output-vs-sorted-local mismatch is
	 * exercised: pre-fix, the probe hashed sorted local extraction against the
	 * remote-generated output and dropped the file.
	 *
	 * @return void
	 */
	public function test_generate_and_store_retains_variant_when_source_unchanged(): void {
		$hash = 'ccssintegrity' . substr( md5( uniqid( 'wppo', true ) ), 0, 8 );

		// A non-empty safelist is what activates the checksum auto-regen.
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => '.modal-open',
			),
		);
		Util::clear_settings_cache();

		// Two local stylesheets on disk; document order below is b then a.
		$theme_dir = wp_normalize_path( WP_CONTENT_DIR . '/themes/wppo-ccss-integrity' );
		if ( ! is_dir( $theme_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $theme_dir, 0775, true );
		}
		$file_a = $theme_dir . '/a.css';
		$file_b = $theme_dir . '/b.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_a, 'h1{font-size:2em}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_b, '.container{width:100%}' );

		$url_a = 'http://example.com/wp-content/themes/wppo-ccss-integrity/a.css';
		$url_b = 'http://example.com/wp-content/themes/wppo-ccss-integrity/b.css';

		// The fetched page carries an inline <style> (so generate() has a
		// non-empty source) plus the two external stylesheets in b,a order.
		$html = '<html><head><style>body{margin:0}</style>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test fixture HTML.
			. '<link rel="stylesheet" href="' . $url_b . '" />'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test fixture HTML.
			. '<link rel="stylesheet" href="' . $url_a . '" />'
			. '</head><body></body></html>';

		$self = $this;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( $self, $html ) {
				++$self->http_calls;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => ( false !== strpos( (string) $url, '.css' ) ) ? '' : $html,
				);
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? 200;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );

		// Frontend queue order matches document order: b then a.
		$styles               = new \stdClass();
		$entry_a              = new \stdClass();
		$entry_a->src         = $url_a;
		$entry_b              = new \stdClass();
		$entry_b->src         = $url_b;
		$styles->queue        = array( 'wppo-fixture-b', 'wppo-fixture-a' );
		$styles->registered   = array(
			'wppo-fixture-b' => $entry_b,
			'wppo-fixture-a' => $entry_a,
		);
		$GLOBALS['wp_styles'] = $styles;

		$dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$ccss_file = $dir . '/' . $hash . '.css';

		Critical_CSS::reset_ccss_memo();
		try {
			$generated = $this->invoke_private( 'generate_and_store', $hash, 'index' );
			$this->assertTrue( $generated, 'generate_and_store() should succeed' );
			$this->assertFileExists( $ccss_file );

			// Frontend probe on an unchanged source: same domain → fresh, so
			// the generated variant must be RETAINED (not dropped/requeued).
			Critical_CSS::reset_ccss_memo();
			$dropped = Critical_CSS::maybe_check_stale_and_requeue( $hash );

			$this->assertFalse( $dropped, 'Unchanged source must not be treated as stale' );
			$this->assertFileExists( $ccss_file, 'Fresh generated CCSS must not be deleted' );
		} finally {
			unset( $GLOBALS['wp_styles'] );
			Critical_CSS::reset_ccss_memo();
			foreach ( array( $ccss_file, $file_a, $file_b ) as $cleanup ) {
				if ( file_exists( $cleanup ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
					unlink( $cleanup );
				}
			}
			if ( is_dir( $theme_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
				rmdir( $theme_dir );
			}
		}
	}

	/**
	 * Integration: a deferred <link> + its <noscript> copy must not churn.
	 *
	 * The defer_stylesheets() shape emits the deferred tag AND
	 * `<noscript>$tag</noscript>` where $tag is the original
	 * `<link rel="stylesheet">`. generate()'s XPath
	 * matches both, doubling the stylesheet in the baseline while the probe
	 * reads the handle once. build_local_source_css() must de-duplicate by
	 * resolved local path so an unchanged page keeps its variant.
	 *
	 * @return void
	 */
	public function test_generate_and_store_retains_variant_when_link_noscript_duplicated(): void {
		$hash = 'ccssnoscript' . substr( md5( uniqid( 'wppo', true ) ), 0, 8 );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => '.modal-open',
			),
		);
		Util::clear_settings_cache();

		$theme_dir = wp_normalize_path( WP_CONTENT_DIR . '/themes/wppo-ccss-noscript' );
		if ( ! is_dir( $theme_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $theme_dir, 0775, true );
		}
		$file_a = $theme_dir . '/a.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_a, 'h1{font-size:2em}' );
		$url_a = 'http://example.com/wp-content/themes/wppo-ccss-noscript/a.css';

		// Shape emitted by Critical_CSS::defer_stylesheets(): a deferred
		// <link> plus the original inside <noscript> (both rel=stylesheet).
		$html = '<html><head><style>body{margin:0}</style>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test fixture HTML.
			. '<link rel="stylesheet" href="' . $url_a . '" media="print" onload="this.media=\'all\'" data-wppo-ccss="1" />'
			. '<noscript>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test fixture HTML.
			. '<link rel="stylesheet" href="' . $url_a . '" media="all" />'
			. '</noscript></head><body></body></html>';

		$this->stub_generation_fetch( $html );

		$entry_a              = new \stdClass();
		$entry_a->src         = $url_a;
		$styles               = new \stdClass();
		$styles->queue        = array( 'wppo-fixture-a' );
		$styles->registered   = array( 'wppo-fixture-a' => $entry_a );
		$GLOBALS['wp_styles'] = $styles;

		$ccss_file = $this->prepare_ccss_dir( $hash );

		Critical_CSS::reset_ccss_memo();
		try {
			$this->assertTrue( $this->invoke_private( 'generate_and_store', $hash, 'index' ) );
			$this->assertFileExists( $ccss_file );

			Critical_CSS::reset_ccss_memo();
			$dropped = Critical_CSS::maybe_check_stale_and_requeue( $hash );

			$this->assertFalse( $dropped, 'Deferred <noscript> duplicate must not be stale' );
			$this->assertFileExists( $ccss_file, 'Fresh generated CCSS must not be deleted' );
		} finally {
			$this->cleanup_ccss_fixture( $ccss_file, array( $file_a ), $theme_dir );
		}
	}

	/**
	 * Integration: a core path-inlined handle must not churn.
	 *
	 * The Main::minify_queued_styles() path opts styles into core's inline
	 * pass via wp_style_add_data($handle, 'path', ...). Core's
	 * wp_maybe_inline_styles() (wp_head priority 1) then emits an inline
	 * <style> and no <link>, so the
	 * generation fetch never sees the handle while the probe (priority 0) still
	 * has it queued with a src. The probe must skip it, or baseline and probe
	 * never converge.
	 *
	 * @return void
	 */
	public function test_generate_and_store_retains_variant_when_handle_is_path_inlined(): void {
		$hash = 'ccssinline' . substr( md5( uniqid( 'wppo', true ) ), 0, 8 );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => '.modal-open',
			),
		);
		Util::clear_settings_cache();

		$theme_dir = wp_normalize_path( WP_CONTENT_DIR . '/themes/wppo-ccss-inline' );
		if ( ! is_dir( $theme_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $theme_dir, 0775, true );
		}
		$file_a = $theme_dir . '/a.css';
		$file_b = $theme_dir . '/inline-b.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_a, 'h1{font-size:2em}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_b, '.container{width:100%}' );
		$url_a = 'http://example.com/wp-content/themes/wppo-ccss-inline/a.css';
		$url_b = 'http://example.com/wp-content/themes/wppo-ccss-inline/inline-b.css';

		// Fetched page: a.css is a normal <link>; the b handle was path-inlined
		// by core, so it appears only as an inline <style>.
		$html = '<html><head><style>body{margin:0}</style>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test fixture HTML.
			. '<link rel="stylesheet" href="' . $url_a . '" />'
			. '<style id="wppo-fixture-b-inline-css">.container{width:100%}</style>'
			. '</head><body></body></html>';

		$this->stub_generation_fetch( $html );

		$entry_a              = new \stdClass();
		$entry_a->src         = $url_a;
		$entry_b              = new \stdClass();
		$entry_b->src         = $url_b;
		$entry_b->extra       = array( 'path' => $file_b );
		$styles               = new \stdClass();
		$styles->queue        = array( 'wppo-fixture-a', 'wppo-fixture-b' );
		$styles->registered   = array(
			'wppo-fixture-a' => $entry_a,
			'wppo-fixture-b' => $entry_b,
		);
		$GLOBALS['wp_styles'] = $styles;

		$ccss_file = $this->prepare_ccss_dir( $hash );

		Critical_CSS::reset_ccss_memo();
		try {
			$this->assertTrue( $this->invoke_private( 'generate_and_store', $hash, 'index' ) );
			$this->assertFileExists( $ccss_file );

			Critical_CSS::reset_ccss_memo();
			$dropped = Critical_CSS::maybe_check_stale_and_requeue( $hash );

			$this->assertFalse( $dropped, 'Path-inlined handle must be excluded from the probe' );
			$this->assertFileExists( $ccss_file, 'Fresh generated CCSS must not be deleted' );
		} finally {
			$this->cleanup_ccss_fixture( $ccss_file, array( $file_a, $file_b ), $theme_dir );
		}
	}

	/**
	 * The persisted document-ordered URL list is re-hashed on probe, so a
	 * $wp_styles queue with a different order cannot churn (audit #9).
	 */
	public function test_generate_and_store_persists_source_urls_for_probe(): void {
		$hash = 'ccsssrcdom' . substr( md5( uniqid( 'wppo', true ) ), 0, 8 );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => '.modal-open',
			),
		);
		Util::clear_settings_cache();

		$theme_dir = wp_normalize_path( WP_CONTENT_DIR . '/themes/wppo-ccss-srcdom' );
		if ( ! is_dir( $theme_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $theme_dir, 0775, true );
		}
		$file_a = $theme_dir . '/a.css';
		$file_b = $theme_dir . '/b.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_a, 'h1{font-size:2em}' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_b, '.container{width:100%}' );

		$url_a = 'http://example.com/wp-content/themes/wppo-ccss-srcdom/a.css';
		$url_b = 'http://example.com/wp-content/themes/wppo-ccss-srcdom/b.css';

		// The fetched page emits document order b, a.
		$html = '<html><head><style>body{margin:0}</style>'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test fixture HTML.
			. '<link rel="stylesheet" href="' . $url_b . '" />'
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Test fixture HTML.
			. '<link rel="stylesheet" href="' . $url_a . '" />'
			. '</head><body></body></html>';
		$this->stub_generation_fetch( $html );

		// Probe queue is reversed: pre-fix this hashes a, b and churns.
		$entry_a              = new \stdClass();
		$entry_a->src         = $url_a;
		$entry_b              = new \stdClass();
		$entry_b->src         = $url_b;
		$styles               = new \stdClass();
		$styles->queue        = array( 'wppo-fixture-a', 'wppo-fixture-b' );
		$styles->registered   = array(
			'wppo-fixture-a' => $entry_a,
			'wppo-fixture-b' => $entry_b,
		);
		$GLOBALS['wp_styles'] = $styles;

		$ccss_file = $this->prepare_ccss_dir( $hash );

		Critical_CSS::reset_ccss_memo();
		try {
			$this->assertTrue( $this->invoke_private( 'generate_and_store', $hash, 'index' ) );
			$this->assertFileExists( $ccss_file );

			// The canonical document-ordered URL list must be persisted.
			$persisted_key = Util::transient_key( 'wppo_ccss_sources_' . $hash );
			$this->assertArrayHasKey( $persisted_key, $this->transient_map );
			$this->assertSame( array( $url_b, $url_a ), $this->transient_map[ $persisted_key ] );

			// Probe re-hashes the persisted list, not the reversed queue.
			Critical_CSS::reset_ccss_memo();
			$dropped = Critical_CSS::maybe_check_stale_and_requeue( $hash );

			$this->assertFalse( $dropped, 'Probe must re-hash the persisted document-ordered URL list' );
			$this->assertFileExists( $ccss_file, 'Persisted source domain must keep the variant fresh' );
		} finally {
			$this->cleanup_ccss_fixture( $ccss_file, array( $file_a, $file_b ), $theme_dir );
		}
	}

	/**
	 * Already-cached entries without a persisted URL list fall back to the
	 * previous $wp_styles-derived domain instead of being invalidated (#9).
	 */
	public function test_probe_falls_back_when_persisted_url_list_absent(): void {
		$hash = 'ccsslegacy' . substr( md5( uniqid( 'wppo', true ) ), 0, 8 );

		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => '.modal-open',
			),
		);
		Util::clear_settings_cache();

		$theme_dir = wp_normalize_path( WP_CONTENT_DIR . '/themes/wppo-ccss-legacy' );
		if ( ! is_dir( $theme_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $theme_dir, 0775, true );
		}
		$file_a = $theme_dir . '/a.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_a, 'h1{font-size:2em}' );
		$url_a = 'http://example.com/wp-content/themes/wppo-ccss-legacy/a.css';

		$entry_a              = new \stdClass();
		$entry_a->src         = $url_a;
		$styles               = new \stdClass();
		$styles->queue        = array( 'wppo-fixture-a' );
		$styles->registered   = array( 'wppo-fixture-a' => $entry_a );
		$GLOBALS['wp_styles'] = $styles;

		$ccss_file = $this->prepare_ccss_dir( $hash );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $ccss_file, 'body{margin:0}' );

		// Legacy baseline: checksum present, URL list absent.
		Critical_CSS::store_source_checksum( $hash, 'stale-source-domain' );

		Critical_CSS::reset_ccss_memo();
		try {
			$dropped = Critical_CSS::maybe_check_stale_and_requeue( $hash );

			$this->assertTrue( $dropped, 'Legacy entries must fall back to the $wp_styles-derived domain' );
			$this->assertFileDoesNotExist( $ccss_file );
		} finally {
			$this->cleanup_ccss_fixture( $ccss_file, array( $file_a ), $theme_dir );
		}
	}

	/**
	 * Stub the HTTP + mkdir calls used by the generate_and_store() fixtures.
	 *
	 * @param string $html Fetched page HTML returned for non-.css requests.
	 * @return void
	 */
	private function stub_generation_fetch( string $html ): void {
		$self = $this;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url ) use ( $self, $html ) {
				++$self->http_calls;
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => ( false !== strpos( (string) $url, '.css' ) ) ? '' : $html,
				);
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'] ?? 200;
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'] ?? '';
			}
		);
		Functions\when( 'wp_mkdir_p' )->justReturn( true );
	}

	/**
	 * Ensure the CCSS cache directory exists and return the variant path.
	 *
	 * @param string $hash Template hash.
	 * @return string Variant path.
	 */
	private function prepare_ccss_dir( string $hash ): string {
		$dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		return $dir . '/' . $hash . '.css';
	}

	/**
	 * Tear down a generate_and_store() fixture.
	 *
	 * @param string   $ccss_file Variant path.
	 * @param string[] $files     Stylesheet fixture paths.
	 * @param string   $theme_dir Theme fixture directory.
	 * @return void
	 */
	private function cleanup_ccss_fixture( string $ccss_file, array $files, string $theme_dir ): void {
		unset( $GLOBALS['wp_styles'] );
		Critical_CSS::reset_ccss_memo();
		foreach ( array_merge( array( $ccss_file ), $files ) as $cleanup ) {
			if ( file_exists( $cleanup ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $cleanup );
			}
		}
		if ( is_dir( $theme_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
			rmdir( $theme_dir );
		}
	}

	/**
	 * Extracted regular rules keep their selector (issue #1038 correctness).
	 *
	 * Regression guard for parse_regular_rules(): storing only the
	 * `{declarations}` fragment yields selector-less CSS that browsers
	 * discard, so the above-fold rules had no effect.
	 *
	 * @return void
	 */
	public function test_regular_rules_keep_selector_with_declarations(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => '.modal-open',
			),
		);
		Util::clear_settings_cache();

		$extracted = $this->invoke_private( 'extract_above_fold_css', '.modal-open{display:block}' );

		$this->assertStringContainsString( '.modal-open{display:block}', $extracted );
	}

	/**
	 * The truncate_to_cap() helper cuts at a rule boundary under the cap.
	 *
	 * Helper-scope coverage for the cap boundary: safelisted output flowing
	 * through inline_ccss() file-first + 20 KB cap delivery is exercised by
	 * the production path, not asserted here.
	 *
	 * @return void
	 */
	public function test_truncate_to_cap_boundary(): void {
		$rule = '.modal-open{display:block}';
		$big  = str_repeat( $rule, 2000 );

		$this->assertGreaterThan( Critical_CSS::get_ccss_max_size(), strlen( $big ) );
		$truncated = Critical_CSS::truncate_to_cap( $big, Critical_CSS::get_ccss_max_size() );
		$this->assertLessThanOrEqual( Critical_CSS::get_ccss_max_size(), strlen( $truncated ) );
		$this->assertStringEndsWith( '}', $truncated );
	}

	/**
	 * Used-CSS: user-safelisted hidden selectors are treated as used.
	 *
	 * @return void
	 */
	public function test_used_css_safelist_preserves_hidden_selector(): void {
		$used_css = new Used_CSS(
			array(
				'file_optimisation' => array(
					'excludeUnusedCSS' => ".my-hidden-thing\n.sub-menu",
				),
			)
		);

		$empty_used = array(
			'tags'    => array(),
			'classes' => array(),
			'ids'     => array(),
			'attrs'   => array(),
		);

		$this->assertTrue( $used_css->is_selector_used( '.my-hidden-thing', $empty_used ) );
		$this->assertFalse( $used_css->is_selector_used( '.random-widget-xyz', $empty_used ) );
		// The bare universal selector is kept exactly; it must not act as a
		// match-everything wildcard (issue #1038).
		$this->assertTrue( $used_css->is_selector_used( '*', $empty_used ) );
	}

	/**
	 * Used-CSS checksum helpers: stable hashes, no signal without sources.
	 *
	 * @return void
	 */
	public function test_used_css_checksum_helpers(): void {
		$used_css = new Used_CSS( array() );

		$this->assertSame( $used_css->compute_css_checksum( 'a{color:red}' ), $used_css->compute_css_checksum( 'a{color:red}' ) );
		$this->assertNotSame( $used_css->compute_css_checksum( 'a{color:red}' ), $used_css->compute_css_checksum( 'a{color:blue}' ) );
		$this->assertSame( '', $used_css->compute_css_checksum( '' ) );

		// No queued stylesheets: no checksum signal (fail-open, mtime stands).
		$GLOBALS['wp_styles'] = null;
		$this->assertSame( '', $used_css->compute_local_source_checksum() );
		$this->assertSame( 0, $this->http_calls );
	}

	/**
	 * A single-character safelist entry keeps only its exact selector.
	 *
	 * Guards the substring-fallback narrowing: 'p' must not keep every
	 * rule via stripos, while the exact 'p' selector still matches.
	 *
	 * @return void
	 */
	public function test_short_safelist_entry_does_not_keep_everything(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array(
				'ccssSafelistExtra' => 'p',
			),
		);
		\PerformanceOptimise\Inc\Util::clear_settings_cache();

		$this->assertTrue( Critical_CSS::matches_ccss_safelist( 'p' ) );
		$this->assertFalse( Critical_CSS::matches_ccss_safelist( '.random-widget-xyz' ) );
		$this->assertFalse( Critical_CSS::matches_ccss_safelist( '.page-title' ) );
	}

	/**
	 * Rogue filter output degrades to ignored entries instead of a fatal.
	 *
	 * @return void
	 */
	public function test_ccss_safelist_filter_ignores_non_string_entries(): void {
		Functions\when( 'has_filter' )->alias(
			static function ( $hook ) {
				return 'wppo_ccss_safelist' === $hook;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_ccss_safelist' === $hook ) {
					return array( ' .keep-me ', array( 'nested' ), 123, null, new \stdClass() );
				}
				return $value;
			}
		);

		$this->assertSame( array( '.keep-me' ), Critical_CSS::get_ccss_safelist() );
		$this->assertTrue( Critical_CSS::matches_ccss_safelist( '.keep-me' ) );
		$this->assertFalse( Critical_CSS::matches_ccss_safelist( '.random-widget-xyz' ) );
	}

	/**
	 * Used-CSS checksum sidecar lifecycle: persist, fresh, then stale.
	 *
	 * Stages a real local stylesheet under ABSPATH plus a wp_styles queue
	 * entry, persists the sidecar via reflection, and asserts the
	 * fresh-vs-stale verdicts (no remote fetch anywhere).
	 *
	 * @return void
	 */
	public function test_used_css_checksum_sidecar_lifecycle(): void {
		$css_dir  = WP_CONTENT_DIR . '/themes/wppo-safelist-fixture';
		$css_file = $css_dir . '/style.css';
		if ( ! is_dir( $css_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $css_dir, 0775, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $css_file, 'a{color:red}' );

		$used_css             = new Used_CSS( array() );
		$styles               = new \stdClass();
		$entry                = new \stdClass();
		$entry->src           = 'http://example.com/wp-content/themes/wppo-safelist-fixture/style.css';
		$styles->queue        = array( 'wppo-fixture-style' );
		$styles->registered   = array( 'wppo-fixture-style' => $entry );
		$GLOBALS['wp_styles'] = $styles;

		$used_css_path = $used_css->get_used_css_path( 'http://example.com/safelist-sidecar-page/' );

		$get_checksum = new \ReflectionMethod( Used_CSS::class, 'get_checksum_path' );
		$get_checksum->setAccessible( true );
		$is_stale = new \ReflectionMethod( Used_CSS::class, 'is_checksum_stale' );
		$is_stale->setAccessible( true );
		$persist = new \ReflectionMethod( Used_CSS::class, 'persist_source_checksum' );
		$persist->setAccessible( true );

		try {
			$this->assertNotSame( '', $used_css_path );

			// The page cache directory does not exist yet: create it so the
			// sidecar fallback write (WP_Filesystem unavailable in tests)
			// has a directory to land in.
			$page_dir = dirname( $used_css_path );
			if ( ! is_dir( $page_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
				mkdir( $page_dir, 0775, true );
			}

			// No sidecar yet: fail-open fresh (mtime verdict stands).
			$this->assertFalse( $is_stale->invoke( $used_css, $used_css_path ) );

			$persist->invoke( $used_css, $used_css_path );
			$sidecar = $get_checksum->invoke( $used_css, $used_css_path );
			$this->assertNotSame( '', $sidecar );
			$this->assertFileExists( $sidecar );

			// Baseline matches: fresh.
			$used_css->reset_source_checksum_memo();
			$this->assertFalse( $is_stale->invoke( $used_css, $used_css_path ) );

			// Content edit (mtime may be preserved on deploy sync): stale.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
			file_put_contents( $css_file, 'a{color:blue}' );
			$used_css->reset_source_checksum_memo();
			$this->assertTrue( $is_stale->invoke( $used_css, $used_css_path ) );

			$this->assertSame( 0, $this->http_calls );
		} finally {
			unset( $GLOBALS['wp_styles'] );
			if ( isset( $sidecar ) && is_string( $sidecar ) && file_exists( $sidecar ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $sidecar );
			}
			if ( file_exists( $css_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $css_file );
			}
			if ( is_dir( $css_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
				rmdir( $css_dir );
			}
			if ( isset( $page_dir ) && is_dir( $page_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
				rmdir( $page_dir );
			}
		}
	}

	/**
	 * Capture Main::setup_hooks() registrations for ordering assertions.
	 *
	 * The test-class setUp stubs add_action()/add_filter() to no-ops, so they
	 * are re-stubbed here with recording aliases. This mirrors
	 * BufferCharacterizationTest::capture_setup_hooks().
	 *
	 * @param array $options Plugin options.
	 * @return array{actions:array<int,array{0:string,1:mixed,2:int}>,filters:array<int,array{0:string,1:mixed,2:int}>}
	 */
	private function capture_setup_hooks( array $options ): array {
		$actions = array();
		$filters = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback = null, $priority = 10, $args = 1 ) use ( &$actions ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_action().
				$actions[] = array( $hook, $callback, $priority );
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback = null, $priority = 10, $args = 1 ) use ( &$filters ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Test mock signature must match add_filter().
				$filters[] = array( $hook, $callback, $priority );
				return true;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'do_action' )->justReturn( null );

		$main = ( new \ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty( Main::class, 'options' );
		$prop->setAccessible( true );
		$prop->setValue( $main, $options );
		$prop = new \ReflectionProperty( Main::class, 'image_optimisation' );
		$prop->setAccessible( true );
		$prop->setValue( $main, new Image_Optimisation( $options ) );
		$prop = new \ReflectionProperty( Main::class, 'google_fonts' );
		$prop->setAccessible( true );
		$prop->setValue( $main, new Google_Fonts( $options ) );
		$prop = new \ReflectionProperty( Main::class, 'used_css_buffer_enhanced' );
		$prop->setAccessible( true );
		$prop->setValue( $main, false );

		$method = new \ReflectionMethod( Main::class, 'setup_hooks' );
		$method->setAccessible( true );
		$method->invoke( $main );

		return array(
			'actions' => $actions,
			'filters' => $filters,
		);
	}

	/**
	 * Find a captured hook registration by hook + method name.
	 *
	 * @param array  $hooks  Captured hooks.
	 * @param string $hook   Hook name.
	 * @param string $method Callback method name fragment.
	 * @return array|null Matching [hook, callback, priority] entry, or null.
	 */
	private function find_hook( array $hooks, string $hook, string $method ): ?array {
		foreach ( $hooks as $entry ) {
			if ( $entry[0] !== $hook ) {
				continue;
			}
			$callback = $entry[1];
			if ( is_array( $callback ) && isset( $callback[1] ) && false !== strpos( (string) $callback[1], $method ) ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Defect 1 (blocking): the freshness probe must run after the queue is final.
	 *
	 * `inline_ccss()` is hooked at `wp_head` priority 0, but on the standard
	 * enqueue path `$wp_styles->queue` is not populated until core runs
	 * `wp_enqueue_scripts` inside its `wp_head` priority-1 callback — AFTER
	 * priority 0. This test pins the corrected ordering: the probe is
	 * registered on the `wp_enqueue_scripts` ACTION at PHP_INT_MAX (queue
	 * final, before core's `wp_maybe_inline_styles()`), and `inline_ccss()`
	 * stays at `wp_head` priority 0 but no longer owns the probe.
	 *
	 * Fails pre-fix: no callback named `maybe_check_stale_on_enqueue` exists on
	 * `wp_enqueue_scripts`.
	 *
	 * @return void
	 */
	public function test_ccss_probe_runs_on_final_layout_queue_not_pre_enqueue_wp_head(): void {
		$captured = $this->capture_setup_hooks(
			array(
				'file_optimisation' => array( 'criticalCSS' => true ),
			)
		);

		$probe = $this->find_hook( $captured['actions'], 'wp_enqueue_scripts', 'maybe_check_stale_on_enqueue' );
		$this->assertNotNull( $probe, 'Freshness probe must be registered on wp_enqueue_scripts' );
		$this->assertSame( PHP_INT_MAX, $probe[2], 'Probe must run at PHP_INT_MAX (queue already final)' );
		$this->assertSame( array( Critical_CSS::class, 'maybe_check_stale_on_enqueue' ), $probe[1] );

		// inline_ccss() must remain at wp_head priority 0 to render critical CSS
		// as early as possible; it must not be the probe entrypoint anymore.
		$inline = $this->find_hook( $captured['actions'], 'wp_head', 'inline_ccss' );
		$this->assertNotNull( $inline, 'inline_ccss() must stay hooked to wp_head' );
		$this->assertSame( 0, $inline[2], 'inline_ccss() must stay at wp_head priority 0' );
	}

	/**
	 * Defect 1 functional proof: a stale checksum detected on the final queue.
	 *
	 * Simulates the production `wp_enqueue_scripts` point (queue populated) and
	 * invokes the new probe entrypoint. With a baselined checksum that no longer
	 * matches the queued local source, the probe must drop the stale variant so
	 * the next request's `inline_ccss()` re-queues generation.
	 *
	 * @return void
	 */
	public function test_ccss_probe_drops_stale_variant_when_queue_is_final(): void {
		$this->option_map['wppo_settings'] = array(
			'file_optimisation' => array( 'ccssSafelistExtra' => '.modal-open' ),
		);
		Util::clear_settings_cache();

		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$theme_dir = wp_normalize_path( WP_CONTENT_DIR . '/themes/wppo-ccss-probe' );
		if ( ! is_dir( $theme_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $theme_dir, 0775, true );
		}
		$file_a = $theme_dir . '/probe.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file_a, 'h1{font-size:2em}' );
		$url_a = 'http://example.com/wp-content/themes/wppo-ccss-probe/probe.css';

		$entry                = new \stdClass();
		$entry->src           = $url_a;
		$styles               = new \stdClass();
		$styles->queue        = array( 'wppo-probe-a' );
		$styles->registered   = array( 'wppo-probe-a' => $entry );
		$GLOBALS['wp_styles'] = $styles;

		// get_template_hash() resolves deterministically from the stubbed
		// conditionals (front page = home, stylesheet = test-theme, blog 1).
		$hash      = Critical_CSS::get_template_hash();
		$ccss_file = $this->prepare_ccss_dir( $hash );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $ccss_file, 'body{margin:0}' );

		try {
			// Baseline the OLD source so the current queued source is stale.
			Critical_CSS::store_source_checksum( $hash, 'old-source{different}' );
			Critical_CSS::reset_ccss_memo();

			Critical_CSS::maybe_check_stale_on_enqueue();

			$this->assertFileDoesNotExist( $ccss_file, 'Stale variant must be dropped from the final queue' );
			$this->assertSame( 0, $this->http_calls, 'Probe must use local reads only' );
		} finally {
			unset( $GLOBALS['wp_styles'] );
			Critical_CSS::reset_ccss_memo();
			if ( file_exists( $ccss_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $ccss_file );
			}
			if ( file_exists( $file_a ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $file_a );
			}
			if ( is_dir( $theme_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test fixture cleanup.
				rmdir( $theme_dir );
			}
		}
	}

	/**
	 * Defect 2: Cache and Critical_CSS share ONE version-aware budget helper.
	 *
	 * The two private delegates must return the same value as
	 * Util::get_styles_inline_limit() for every boundary — especially
	 * `'6.9-alpha'`, where the old `'6.9'` vs `'6.9-alpha'` comparators
	 * disagreed.
	 *
	 * @return void
	 */
	public function test_styles_inline_limit_is_shared_and_pre_release_aware(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$cache = ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();

		$cache_method = new ReflectionMethod( Cache::class, 'get_styles_inline_limit' );
		$cache_method->setAccessible( true );
		$ccss_method = new ReflectionMethod( Critical_CSS::class, 'get_styles_inline_limit' );
		$ccss_method->setAccessible( true );

		$cases = array(
			'6.8'       => 20000,
			'6.9-alpha' => 40000,
			'6.9-beta1' => 40000,
			'6.9'       => 40000,
		);

		foreach ( $cases as $version => $expected ) {
			$GLOBALS['wp_version'] = $version;
			$this->assertSame( $expected, Util::get_styles_inline_limit(), 'Util budget for ' . $version );
			$this->assertSame( $expected, $ccss_method->invoke( null ), 'Critical_CSS budget for ' . $version );
			$this->assertSame( $expected, $cache_method->invoke( $cache ), 'Cache budget for ' . $version );
		}

		unset( $GLOBALS['wp_version'] );
		$this->assertSame( 40000, Util::get_styles_inline_limit(), 'Absent version assumes newest default' );
	}

	/**
	 * Defect 3: the no-op `is_core_block_hoisting_active()` duplicate is gone.
	 *
	 * It returned true in both branches and was equivalent to
	 * `block_assets_are_separate()` on WP >= 6.9-alpha; combine_css() now uses
	 * the single source of truth directly.
	 *
	 * @return void
	 */
	public function test_core_block_hoisting_noop_duplicate_is_removed(): void {
		$this->assertFalse(
			method_exists( Cache::class, 'is_core_block_hoisting_active' ),
			'No-op duplicate of block_assets_are_separate() must be removed'
		);
	}
}
