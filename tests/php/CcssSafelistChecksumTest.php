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

use PerformanceOptimise\Inc\Critical_CSS;
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
			// Baseline the checksum for the original source in the canonical
			// (minified) domain that maybe_refresh_from_local_css() hashes.
			$baseline = $this->invoke_private( 'extract_above_fold_css', 'body{margin:0}' );
			$minifier = new \MatthiasMullie\Minify\CSS( $baseline );
			Critical_CSS::store_source_checksum( $hash, $minifier->minify() );

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
}
