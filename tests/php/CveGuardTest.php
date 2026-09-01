<?php
/**
 * Tests for LS-903 N9 CVE guard filter (filter-only, S scope).
 *
 * Verifies that apply_filters('wppo_cve_guard_handles', []) (alias wppo_cve_excluded_handles)
 * merges handles into minify/defer/delay exclude lists with array_unique, default empty
 * (no auto-exclude), and is respected by optimisation paths.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;

/**
 * CVE guard filter tests.
 *
 * @since NEXT
 */
class CveGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stub the WP environment needed to construct Main with file_optimisation overrides
	 * and an optional CVE filter return value.
	 *
	 * @param array      $file_overrides File optimisation overrides.
	 * @param mixed|null $cve_handles    Handles to return from wppo_cve_guard_handles (null = no filter).
	 * @param mixed|null $cve_alias      Handles to return from alias wppo_cve_excluded_handles (null = passthrough).
	 */
	private function stub_main_construction( array $file_overrides, $cve_handles = null, $cve_alias = null ): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
				'absint'              => 3000,
			)
		);

		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( $file_overrides ) {
				if ( 'wppo_settings' === $option && is_array( $default_value ) ) {
					$default_value['file_optimisation'] = array_merge( $default_value['file_optimisation'] ?? array(), $file_overrides );
				}
				return $default_value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'has_filter' )->justReturn( false );

		Functions\when( 'function_exists' )->alias(
			static function ( $function_name ) {
				if ( 'WP_Filesystem' === $function_name || 'wp_is_block_theme' === $function_name ) {
					return true;
				}
				return \function_exists( $function_name );
			}
		);

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$unused_args ) use ( $cve_handles, $cve_alias ) {
				$_ = $unused_args; // phpcs:ignore Squiz.PHP.DiscouragedFunctions -- silence unused variadic.
				if ( 'wppo_cve_guard_handles' === $hook ) {
					return null !== $cve_handles ? $cve_handles : $value;
				}
				if ( 'wppo_cve_excluded_handles' === $hook ) {
					return null !== $cve_alias ? $cve_alias : $value;
				}
				return $value;
			}
		);
	}

	/**
	 * Read a private property off a Main instance.
	 *
	 * @param Main   $main Main instance.
	 * @param string $prop Property name.
	 * @return mixed
	 */
	private function read_private_prop( Main $main, string $prop ) {
		$reflection = new \ReflectionProperty( Main::class, $prop );
		$reflection->setAccessible( true );
		return $reflection->getValue( $main );
	}

	/**
	 * Invoke a private method on a Main instance.
	 *
	 * @param Main   $main Main instance.
	 * @param string $name Method name.
	 * @param mixed  ...$args Args.
	 * @return mixed
	 */
	private function invoke_private_method( Main $main, string $name, ...$args ) {
		$reflection = new \ReflectionMethod( Main::class, $name );
		$reflection->setAccessible( true );
		return $reflection->invoke( $main, ...$args );
	}

	/**
	 * Test default empty filter produces no regression (no CVE handles added).
	 */
	public function test_default_empty_filter_no_regression(): void {
		$this->stub_main_construction(
			array(
				'minifyJS'  => true,
				'minifyCSS' => true,
				'deferJS'   => true,
				'delayJS'   => true,
			),
			array()
		);

		$main = new Main();

		$exclude_js    = $this->read_private_prop( $main, 'exclude_js' );
		$exclude_css   = $this->read_private_prop( $main, 'exclude_css' );
		$exclude_defer = $this->read_private_prop( $main, 'exclude_defer_js' );
		$exclude_delay = $this->read_private_prop( $main, 'exclude_delay_js' );

		$this->assertContains( 'jquery', $exclude_js );
		$this->assertNotContains( 'vulnerable-slider', $exclude_js );
		$this->assertContains( 'wppo-combine-css', $exclude_css );
		$this->assertNotContains( 'vulnerable-slider', $exclude_css );
		$this->assertContains( 'wppo-lazyload', $exclude_defer );
		$this->assertNotContains( 'vulnerable-slider', $exclude_defer );
		$this->assertContains( 'wppo-lazyload', $exclude_delay );
		$this->assertNotContains( 'vulnerable-slider', $exclude_delay );
	}

	/**
	 * Test CVE guard handle merges into all exclude lists with array_unique.
	 */
	public function test_cve_guard_handle_merges_into_all_excludes(): void {
		$this->stub_main_construction(
			array(
				'minifyJS'  => true,
				'minifyCSS' => true,
				'deferJS'   => true,
				'delayJS'   => true,
			),
			array( 'vulnerable-slider', 'compromised-gallery' )
		);

		$main = new Main();

		$exclude_js    = $this->read_private_prop( $main, 'exclude_js' );
		$exclude_css   = $this->read_private_prop( $main, 'exclude_css' );
		$exclude_defer = $this->read_private_prop( $main, 'exclude_defer_js' );
		$exclude_delay = $this->read_private_prop( $main, 'exclude_delay_js' );

		$this->assertContains( 'vulnerable-slider', $exclude_js );
		$this->assertContains( 'compromised-gallery', $exclude_js );
		$this->assertContains( 'vulnerable-slider', $exclude_css );
		$this->assertContains( 'vulnerable-slider', $exclude_defer );
		$this->assertContains( 'vulnerable-slider', $exclude_delay );
		$this->assertSame( count( $exclude_js ), count( array_unique( $exclude_js ) ) );
	}

	/**
	 * Test BC alias wppo_cve_excluded_handles also merges.
	 */
	public function test_cve_guard_alias_bc(): void {
		Functions\stubs(
			array(
				'WP_Filesystem'       => false,
				'sanitize_text_field' => '',
				'wp_unslash'          => '',
				'is_user_logged_in'   => false,
				'absint'              => 3000,
			)
		);
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) {
				if ( 'wppo_settings' === $option && is_array( $default_value ) ) {
					$default_value['file_optimisation'] = array_merge( $default_value['file_optimisation'] ?? array(), array( 'minifyJS' => true ) );
				}
				return $default_value;
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'content_url' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_is_block_theme' )->justReturn( false );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'function_exists' )->alias(
			static function ( $func_name ) {
				if ( 'WP_Filesystem' === $func_name || 'wp_is_block_theme' === $func_name ) {
					return true;
				}
				return \function_exists( $func_name );
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_cve_guard_handles' === $hook ) {
					return $value;
				}
				if ( 'wppo_cve_excluded_handles' === $hook ) {
					return array( 'alias-vulnerable' );
				}
				return $value;
			}
		);

		$main       = new Main();
		$exclude_js = $this->read_private_prop( $main, 'exclude_js' );
		$this->assertContains( 'alias-vulnerable', $exclude_js );
	}

	/**
	 * Test that filtered handle is respected by should_optimise / minify path (no minify).
	 */
	public function test_cve_guard_respected_by_minify_js(): void {
		$this->stub_main_construction(
			array(
				'minifyJS' => true,
			),
			array( 'vulnerable-slider' )
		);

		$main = new Main();

		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_cve_guard_handles' === $hook ) {
					return array( 'vulnerable-slider' );
				}
				if ( 'wppo_cve_excluded_handles' === $hook ) {
					return $value;
				}
				return $value;
			}
		);

		$tag    = '<script src="https://example.com/vulnerable-slider.js"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$result = $main->minify_js( $tag, 'vulnerable-slider', 'https://example.com/vulnerable-slider.js' );
		$this->assertSame( $tag, $result );
	}

	/**
	 * Test that CVE guard handle is excluded from defer via add_defer_attribute.
	 */
	public function test_cve_guard_respected_by_defer(): void {
		$this->stub_main_construction(
			array(
				'deferJS' => true,
				'delayJS' => true,
			),
			array( 'vulnerable-slider' )
		);

		$main = new Main();

		$tag    = '<script src="https://example.com/vulnerable-slider.js" type="text/javascript"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$result = $main->add_defer_attribute( $tag, 'vulnerable-slider' );
		$this->assertSame( $tag, $result );

		$exclude_defer = $this->read_private_prop( $main, 'exclude_defer_js' );
		$this->assertContains( 'vulnerable-slider', $exclude_defer );
	}

	/**
	 * Test deduplication and sanitization (empty / whitespace / non-string filtered).
	 */
	public function test_cve_guard_sanitization_and_dedup(): void {
		$this->stub_main_construction(
			array(
				'minifyJS' => true,
			),
			array( ' vulnerable-slider ', 'vulnerable-slider', '', '  ', 'compromised-gallery', 123, null )
		);

		$main       = new Main();
		$exclude_js = $this->read_private_prop( $main, 'exclude_js' );

		$this->assertContains( 'vulnerable-slider', $exclude_js );
		$this->assertContains( 'compromised-gallery', $exclude_js );
		$this->assertNotContains( '', $exclude_js );
		$this->assertNotContains( '  ', $exclude_js );
		$this->assertSame( 1, array_count_values( $exclude_js )['vulnerable-slider'] ?? 0 );
	}

	/**
	 * Test non-array filter return is treated as empty.
	 */
	public function test_cve_guard_non_array_returns_empty(): void {
		$this->stub_main_construction(
			array(
				'minifyJS' => true,
			),
			'not-an-array'
		);

		$main       = new Main();
		$exclude_js = $this->read_private_prop( $main, 'exclude_js' );

		$this->assertNotContains( 'not-an-array', $exclude_js );
		$this->assertContains( 'jquery', $exclude_js );
	}

	/**
	 * Test get_cve_guard_handles private method directly.
	 */
	public function test_get_cve_guard_handles_method(): void {
		$this->stub_main_construction( array(), array( 'a', 'b' ) );
		$main    = new Main();
		$handles = $this->invoke_private_method( $main, 'get_cve_guard_handles' );
		$this->assertSame( array( 'a', 'b' ), $handles );
	}
}
