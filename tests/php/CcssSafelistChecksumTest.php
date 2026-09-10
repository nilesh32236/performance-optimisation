<?php
/**
 * Tests for the Used/CSS user safelist + checksum auto-regen (issue #1038).
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
	 * Note: this setUp shadows the trait's setUp, so it replicates the
	 * Brain Monkey bootstrap explicitly (see UsedCssHostTest).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::clear_settings_cache();

		$this->option_map    = array();
		$this->transient_map = array();
		$this->http_calls    = 0;

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				$path = str_replace( '\\', '/', (string) $path );
				return preg_replace( '|(?<=.)/+|', '/', $path );
			}
		);
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
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
	 * @return void
	 */
	public function test_refresh_from_local_css_drops_stale_without_remote_fetch(): void {
		$hash = 'safelistrefreshlocal00001';
		$dir  = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$file = $dir . '/' . $hash . '.css';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $file, 'body{margin:0}' );

		try {
			// Baseline the checksum for the original source.
			Critical_CSS::store_source_checksum( $hash, $this->invoke_private( 'extract_above_fold_css', 'body{margin:0}' ) );

			// Unchanged source: fresh, file kept.
			$this->assertFalse( Critical_CSS::maybe_refresh_from_local_css( $hash, 'body{margin:0}' ) );
			$this->assertFileExists( $file );

			// Changed source: stale, file dropped for regen.
			$this->assertTrue( Critical_CSS::maybe_refresh_from_local_css( $hash, "body{margin:0}\nh1{font-size:2em}" ) );
			$this->assertFileDoesNotExist( $file );

			// No remote fetch happened anywhere in the helper path.
			$this->assertSame( 0, $this->http_calls );
		} finally {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $file );
			}
		}
	}

	/**
	 * Safelisted output still honours the 20 KB inline cap.
	 *
	 * @return void
	 */
	public function test_safelisted_output_honours_inline_cap(): void {
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
}
