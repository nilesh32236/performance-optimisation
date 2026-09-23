<?php
/**
 * Parity tests for the central Wp_Version gate (REF-010).
 *
 * The scattered `$GLOBALS['wp_version']` reads plus `version_compare()`
 * gates in Main, Cache, and Util were routed through
 * `PerformanceOptimise\Inc\Wp_Version` with zero behavior change. These
 * tests pin that contract: for every historic spelling they re-evaluate the
 * OLD inline expression and assert the new helper returns the identical
 * outcome across a version matrix (fake `$wp_version` 6.2 through 7.x,
 * plus unset/empty) combined with stubbed `get_bloginfo()` versions.
 *
 * Two read semantics are covered because the historic sites disagree on the
 * unknown-version default:
 *
 * - Canonical (fail-false): non-empty global, then `get_bloginfo()`, then
 *   false. Used by the native-API predicates.
 * - Global-only (assume-newest): `$GLOBALS` alone, no `get_bloginfo()`
 *   fallback. Used by the inline-budget and block-asset gates.
 *
 * @package PerformanceOptimise\Tests
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\Wp_Version;
use PHPUnit\Framework\Attributes\DataProvider;

if ( ! class_exists( 'PerformanceOptimise\Inc\Wp_Version' ) ) {
	require_once __DIR__ . '/../../includes/class-wp-version.php';
}

/**
 * Wp_Version gate parity tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WpVersionGateTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Previously-global $wp_version value.
	 *
	 * @var mixed
	 */
	private $saved_wp_version;

	/**
	 * Whether $GLOBALS['wp_version'] existed before the test.
	 *
	 * @var bool
	 */
	private $had_wp_version = false;

	/**
	 * Preserve the $wp_version global across tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->had_wp_version   = array_key_exists( 'wp_version', $GLOBALS );
		$this->saved_wp_version = $GLOBALS['wp_version'] ?? null;
		Wp_Version::reset_memo();
	}

	/**
	 * Restore the $wp_version global.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $this->had_wp_version ) {
			$GLOBALS['wp_version'] = $this->saved_wp_version;
		} else {
			unset( $GLOBALS['wp_version'] );
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		Wp_Version::reset_memo();
		parent::tearDown();
	}

	/**
	 * Install a version fixture: $GLOBALS state plus get_bloginfo() return.
	 *
	 * @param mixed  $global_value   Global value, or null to unset the global.
	 * @param string $bloginfo get_bloginfo('version') return.
	 * @return void
	 */
	private function set_version_state( $global_value, string $bloginfo ): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( null === $global_value ) {
			unset( $GLOBALS['wp_version'] );
		} else {
			$GLOBALS['wp_version'] = $global_value;
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
		Functions\when( 'get_bloginfo' )->justReturn( $bloginfo );
		Wp_Version::reset_memo();
	}

	/**
	 * Historic canonical read: non-empty global wins, then get_bloginfo().
	 *
	 * Mirrors the pre-REF-010 spelling in Main::supports_native_*() and
	 * Util::supports_script_strategy(). Returns null when no source exists.
	 *
	 * @param mixed $global_value   Installed global value (null = unset).
	 * @return string|null Effective version, or null when unreadable.
	 */
	private function old_canonical_read( $global_value ): ?string {
		if ( null !== $global_value && is_string( $global_value ) && '' !== $global_value ) {
			return $global_value;
		}
		if ( function_exists( 'get_bloginfo' ) ) {
			return (string) get_bloginfo( 'version' );
		}
		return null;
	}

	/**
	 * Human-readable label for a version-fixture global value.
	 *
	 * @param mixed $global_value Installed global value (null = unset).
	 * @return string Fixture label for assertion messages.
	 */
	private function version_label( $global_value ): string {
		if ( null === $global_value ) {
			return 'unset';
		}
		return "'" . (string) $global_value . "'";
	}

	/**
	 * Version matrix: unset/empty plus real-world cores 6.2 through 7.x.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function version_provider(): array {
		return array(
			'unset global' => array( null ),
			'empty global' => array( '' ),
			'wp 6.2'       => array( '6.2' ),
			'wp 6.2.6'     => array( '6.2.6' ),
			'wp 6.3-alpha' => array( '6.3-alpha' ),
			'wp 6.3'       => array( '6.3' ),
			'wp 6.6.2'     => array( '6.6.2' ),
			'wp 6.7.2'     => array( '6.7.2' ),
			'wp 6.8'       => array( '6.8' ),
			'wp 6.8.3'     => array( '6.8.3' ),
			'wp 6.9-alpha' => array( '6.9-alpha' ),
			'wp 6.9-beta1' => array( '6.9-beta1' ),
			'wp 6.9'       => array( '6.9' ),
			'wp 6.9.1'     => array( '6.9.1' ),
			'wp 7.0-alpha' => array( '7.0-alpha' ),
			'wp 7.0'       => array( '7.0' ),
			'wp 7.2'       => array( '7.2' ),
		);
	}

	/**
	 * Floors exercised by the migrated gates.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function floor_provider(): array {
		return array(
			'script strategy floor' => array( '6.3-alpha' ),
			'inline src floor'      => array( '6.3' ),
			'speculation floor'     => array( '6.8' ),
			'block assets floor'    => array( '6.9-alpha' ),
			'readable floor'        => array( '7.0' ),
		);
	}

	/**
	 * The current() reader prefers the global over get_bloginfo().
	 *
	 * @return void
	 */
	public function test_current_prefers_global_over_bloginfo(): void {
		$this->set_version_state( '6.1.3', '6.8.0' );

		$this->assertSame( '6.1.3', Wp_Version::current() );
	}

	/**
	 * The current() reader falls back to get_bloginfo() without the global.
	 *
	 * @return void
	 */
	public function test_current_falls_back_to_bloginfo(): void {
		$this->set_version_state( null, '6.4.2' );

		$this->assertSame( '6.4.2', Wp_Version::current() );
	}

	/**
	 * The current() reader reports unknown when no source has a version.
	 *
	 * @return void
	 */
	public function test_current_empty_when_unknown(): void {
		$this->set_version_state( null, '' );

		$this->assertSame( '', Wp_Version::current() );
	}

	/**
	 * An explicitly empty global is skipped in favour of get_bloginfo().
	 *
	 * Canonicalization note: the two pre-REF-010 speculation reads used a
	 * bare isset() cast, so an explicit '' global failed their 6.8 gate
	 * while every other site fell through to get_bloginfo(). Core never
	 * produces an empty $wp_version (wp-includes/version.php always sets a
	 * non-empty string), so the central gate adopts the majority spelling;
	 * this test pins the documented outcome.
	 *
	 * @return void
	 */
	public function test_current_skips_empty_global(): void {
		$this->set_version_state( '', '6.8' );

		$this->assertSame( '6.8', Wp_Version::current() );
	}

	/**
	 * The bloginfo fallback is memoized until reset_memo().
	 *
	 * The `$GLOBALS` read itself stays fresh every call; only the
	 * `get_bloginfo()` fallback (the sole WP call on the no-global path) is
	 * cached so requests hitting several gates resolve it once.
	 *
	 * @return void
	 */
	public function test_current_memoizes_bloginfo_fallback(): void {
		$this->set_version_state( null, '6.8' );
		$this->assertSame( '6.8', Wp_Version::current() );

		// Re-stub bloginfo without resetting: the memoized fallback wins.
		Functions\when( 'get_bloginfo' )->justReturn( '6.2' );
		$this->assertSame( '6.8', Wp_Version::current() );

		Wp_Version::reset_memo();
		$this->assertSame( '6.2', Wp_Version::current() );
	}

	/**
	 * A non-string global is ignored by the canonical read but cast by the global-only read.
	 *
	 * Pins the historic split: the canonical family guarded with
	 * `is_string()` (so an int/float/bool global falls through to
	 * `get_bloginfo()`), while the global-only family compared the
	 * `(string)` cast as-is via a bare `isset()` guard. Core always sets a
	 * non-empty string, so these inputs only arise from faulty test
	 * fixtures — the helpers preserve each family's verbatim outcome.
	 *
	 * @return void
	 */
	public function test_non_string_global_parity(): void {
		foreach ( array( 0, 68, 6.8, true, false ) as $global_value ) {
			$this->set_version_state( $global_value, '6.8' );
			if ( is_bool( $global_value ) ) {
				$label = $global_value ? 'bool(true)' : 'bool(false)';
			} elseif ( is_int( $global_value ) || is_float( $global_value ) ) {
				$label = gettype( $global_value ) . '(' . (string) $global_value . ')';
			} else {
				$label = gettype( $global_value );
			}

			// Canonical read: non-string global ignored, bloginfo wins.
			$this->assertSame( '6.8', Wp_Version::current(), 'current() with global ' . $label );
			$this->assertTrue( Wp_Version::is_at_least( '6.3-alpha' ), 'is_at_least() with global ' . $label );

			// Global-only read: present global compared as-is via (string) cast.
			foreach ( array( '6.3-alpha', '6.3', '6.8', '6.9-alpha', '7.0' ) as $floor ) {
				$expected = (bool) version_compare( (string) $global_value, $floor, '>=' );
				$this->assertSame( $expected, Wp_Version::is_global_at_least( $floor ), "Floor {$floor} with global " . $label );
			}
		}
	}

	/**
	 * The is_at_least() helper matches the historic fail-false spelling on every version.
	 *
	 * @param mixed $global_value Installed global value (null = unset).
	 * @return void
	 */
	#[DataProvider( 'version_provider' )]
	public function test_is_at_least_matches_canonical_spelling( $global_value ): void {
		$this->set_version_state( $global_value, '6.8' );

		foreach ( array( '6.3-alpha', '6.3', '6.8', '6.9-alpha', '7.0' ) as $floor ) {
			$read = $this->old_canonical_read( $global_value );
			$old  = null !== $read && '' !== $read && version_compare( $read, $floor, '>=' );

			$this->assertSame( $old, Wp_Version::is_at_least( $floor ), "Floor {$floor} with global " . $this->version_label( $global_value ) );
		}
	}

	/**
	 * The is_at_least() helper with an unknown version returns the caller default.
	 *
	 * @return void
	 */
	public function test_is_at_least_unknown_version_uses_default(): void {
		$this->set_version_state( null, '' );

		$this->assertFalse( Wp_Version::is_at_least( '6.3-alpha' ) );
		$this->assertFalse( Wp_Version::is_at_least( '6.3-alpha', false ) );
		$this->assertTrue( Wp_Version::is_at_least( '6.8', true ) );
	}

	/**
	 * The is_global_at_least() helper matches the historic isset() spellings exactly.
	 *
	 * Covers both the `!isset() || >=` (assume-newest) and the
	 * `isset() && <` (early-return) spellings, and proves no get_bloginfo()
	 * fallback leaks in: the bloginfo stub returns 6.2 here, so any
	 * fallback would flip the newest-default assertions.
	 *
	 * @param mixed $global_value Installed global value (null = unset).
	 * @return void
	 */
	#[DataProvider( 'version_provider' )]
	public function test_is_global_at_least_matches_isset_spelling( $global_value ): void {
		$this->set_version_state( $global_value, '6.2' );

		foreach ( array( '6.3-alpha', '6.3', '6.8', '6.9-alpha', '7.0' ) as $floor ) {
			// Historic assume-newest spelling (Cache inline gates, Util
			// inline limits): absent global passes.
			if ( null === $global_value ) {
				$old_newest = true;
			} else {
				$old_newest = (bool) version_compare( (string) $global_value, $floor, '>=' );
			}
			$this->assertSame( $old_newest, Wp_Version::is_global_at_least( $floor ), "Assume-newest floor {$floor} with global " . $this->version_label( $global_value ) );

			// Historic early-return spelling (block-asset gates): absent
			// global proceeds (returns false from the guard).
			if ( null === $global_value ) {
				$old_guard_returns_false = false;
			} else {
				$old_guard_returns_false = (bool) version_compare( (string) $global_value, $floor, '<' );
			}
			$this->assertSame( $old_guard_returns_false, ! Wp_Version::is_global_at_least( $floor ), "Guard floor {$floor} with global " . $this->version_label( $global_value ) );

			// Explicit fail-false default still fails closed when unknown.
			if ( null === $global_value ) {
				$this->assertFalse( Wp_Version::is_global_at_least( $floor, false ), "Fail-false floor {$floor} with unset global" );
			}
		}
	}

	/**
	 * Util::supports_script_strategy() still gates on the 6.3-alpha floor.
	 *
	 * @return void
	 */
	public function test_util_supports_script_strategy_delegates(): void {
		$this->set_version_state( '6.2', '6.8' );
		$this->assertFalse( Util::supports_script_strategy() );

		$this->set_version_state( '6.3', '6.2' );
		$this->assertTrue( Util::supports_script_strategy() );

		// Unknown global falls back to get_bloginfo() (fail-false family).
		$this->set_version_state( null, '6.8' );
		$this->assertTrue( Util::supports_script_strategy() );

		$this->set_version_state( null, '6.2' );
		$this->assertFalse( Util::supports_script_strategy() );
	}

	/**
	 * Util inline limits keep the newest default without the global.
	 *
	 * The get_bloginfo() stub returns 6.2 here to prove the global-only
	 * read: any bloginfo fallback would report the 20KB legacy default.
	 *
	 * @return void
	 */
	public function test_util_inline_limits_assume_newest(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->set_version_state( '6.8', '6.2' );
		$this->assertSame( 20000, Util::get_styles_inline_limit() );
		$this->assertSame( 20000, Util::get_styles_inline_default() );

		$this->set_version_state( '6.9', '6.2' );
		$this->assertSame( 40000, Util::get_styles_inline_limit() );
		$this->assertSame( 40000, Util::get_styles_inline_default() );

		$this->set_version_state( null, '6.2' );
		$this->assertSame( 40000, Util::get_styles_inline_limit() );
		$this->assertSame( 40000, Util::get_styles_inline_default() );
	}

	/**
	 * Build a Cache instance without running its constructor.
	 *
	 * The tested gates are pure version reads, so the constructor (domain
	 * validation) and collaborators are skipped — same pattern as
	 * InlineCssTest::make_cache().
	 *
	 * @return Cache Cache instance.
	 */
	private function make_cache(): Cache {
		return ( new \ReflectionClass( Cache::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Cache inline gates keep their floors and newest defaults.
	 *
	 * @return void
	 */
	public function test_cache_inline_gates_delegate(): void {
		$cache = $this->make_cache();

		$require_src      = new \ReflectionMethod( Cache::class, 'inline_candidates_require_src' );
		$require_readable = new \ReflectionMethod( Cache::class, 'inline_candidates_require_readable' );

		$this->set_version_state( '6.2', '7.0' );
		$this->assertFalse( $require_src->invoke( $cache ) );
		$this->assertFalse( $require_readable->invoke( $cache ) );

		$this->set_version_state( '6.3', '6.2' );
		$this->assertTrue( $require_src->invoke( $cache ) );
		$this->assertFalse( $require_readable->invoke( $cache ) );

		$this->set_version_state( '7.0', '6.2' );
		$this->assertTrue( $require_src->invoke( $cache ) );
		$this->assertTrue( $require_readable->invoke( $cache ) );

		// Unknown global assumes newest even when bloginfo reads old.
		$this->set_version_state( null, '6.2' );
		$this->assertTrue( $require_src->invoke( $cache ) );
		$this->assertTrue( $require_readable->invoke( $cache ) );
	}

	/**
	 * Main native-defer predicate keeps its version floor plus API probes.
	 *
	 * @return void
	 */
	public function test_main_supports_native_defer_strategy_delegates(): void {
		Functions\when( 'wp_script_add_data' )->justReturn( true );

		$this->set_version_state( '6.2', '6.8' );
		$this->assertFalse( Main::supports_native_defer_strategy() );

		$this->set_version_state( '6.8', '6.2' );
		$this->assertTrue( Main::supports_native_defer_strategy() );

		// Unknown global falls back to get_bloginfo() (fail-false family).
		$this->set_version_state( null, '6.8' );
		$this->assertTrue( Main::supports_native_defer_strategy() );

		$this->set_version_state( null, '' );
		$this->assertFalse( Main::supports_native_defer_strategy() );
	}

	/**
	 * Floor provider is wired (kept for the matrix documentation).
	 *
	 * @param string $floor Version floor.
	 * @return void
	 */
	#[DataProvider( 'floor_provider' )]
	public function test_floors_compare_against_matrix( string $floor ): void {
		$this->set_version_state( '6.9', '6.2' );

		$this->assertSame( version_compare( '6.9', $floor, '>=' ), Wp_Version::is_global_at_least( $floor ) );
		$this->assertSame( version_compare( '6.9', $floor, '>=' ), Wp_Version::is_at_least( $floor ) );
	}
}
