<?php
/**
 * Tests for the salted-cache coverage from issue #882.
 *
 * The WP 6.9+ salted cache family compares the salt VALUE passed at read/write
 * time against the value stored with each entry. These tests pin the
 * value-not-key contract (Util::cache_salt), the new salted coverage
 * (critical-CSS status cache, unified cache stats, System Info drop-in check)
 * and the salt bumps on the mutators (clear_all, flush_dropin_cache,
 * bump_stats_cache).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\System_Info;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for the salted-cache coverage.
 *
 * @package PerformanceOptimise\Tests
 */
class SaltedCacheCoverageTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Option store shared by the get_option/update_option aliases.
	 *
	 * @var array<string,mixed>
	 */
	private array $options_store = array();

	/**
	 * Transients written via the set_transient alias.
	 *
	 * @var array<string,mixed>
	 */
	private array $transients_store = array();

	/**
	 * Transient keys captured by the delete_transient alias.
	 *
	 * @var string[]
	 */
	private array $deleted_transients = array();

	/**
	 * Set up Brain Monkey, common stubs and an in-memory object cache that
	 * actually stores values so the real salted helpers can be exercised.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		$this->options_store = array();

		Functions\when( 'get_option' )->alias(
			function ( $option, $fallback = false ) {
				return array_key_exists( (string) $option, $this->options_store ) ? $this->options_store[ $option ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $option, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->options_store[ (string) $option ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->transients_store[ (string) $key ] = $value;
				return true;
			}
		);
		$this->deleted_transients = array();
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				$this->deleted_transients[] = (string) $key;
				return true;
			}
		);
		Functions\when( 'size_format' )->justReturn( '1 KB' );

		// Replicate the trait's per-test cache resets for isolation (issue
		// #882 review).
		Util::reset_cached_home_urls();
		Util::clear_settings_cache();
		Util::reset_html_processor_memo();
		Critical_CSS::reset_ccss_memo();

		// In-memory object cache: stores the salted wrapper arrays the drop-in
		// writes, so wp_cache_get_salted() can validate salts for real.
		$GLOBALS['wp_object_cache'] = new WPPO_Salted_Cache_Harness();
	}

	/**
	 * Tear down Brain Monkey and restore the bootstrap cache stub.
	 */
	protected function tearDown(): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		// Restore the bootstrap miss-on-read semantics (the bootstrap default
		// cannot be reconstructed); a storing harness would change later
		// suites to read-your-writes semantics (issue #882 review).
		$GLOBALS['wp_object_cache'] = new WPPO_Miss_On_Read_Cache_Harness();
		unset( $GLOBALS['wp_filesystem'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test the value-not-key salt contract: bumping the salt option must
	 * invalidate entries written under the old salt (issue #882).
	 */
	public function test_salted_entries_invalidate_when_salt_value_changes(): void {
		// Write under the current salt value.
		$this->options_store['wppo_contract_salt'] = '1';
		wp_cache_set_salted( 'contract_key', array( 'v' => 1 ), 'wppo', Util::cache_salt( 'wppo_contract_salt' ) );

		$this->assertSame( array( 'v' => 1 ), wp_cache_get_salted( 'contract_key', 'wppo', Util::cache_salt( 'wppo_contract_salt' ) ) );

		// Bump: entries written under the old value must miss...
		$this->options_store['wppo_contract_salt'] = '2';
		$this->assertFalse( wp_cache_get_salted( 'contract_key', 'wppo', Util::cache_salt( 'wppo_contract_salt' ) ) );

		// ...while entries written under the new value hit.
		wp_cache_set_salted( 'contract_key', array( 'v' => 2 ), 'wppo', Util::cache_salt( 'wppo_contract_salt' ) );
		$this->assertSame( array( 'v' => 2 ), wp_cache_get_salted( 'contract_key', 'wppo', Util::cache_salt( 'wppo_contract_salt' ) ) );
	}

	/**
	 * Test that Critical_CSS status reads/writes go through the salted cache
	 * and that a salt bump invalidates them (issue #882).
	 */
	public function test_critical_css_status_cache_is_salted_and_bumpable(): void {
		$ref = new \ReflectionMethod( Critical_CSS::class, 'set_status_cache' );
		$ref->setAccessible( true );
		$ref->invoke( null, 'abc123', 'ready', WEEK_IN_SECONDS );

		$get = new \ReflectionMethod( Critical_CSS::class, 'get_status_cache' );
		$get->setAccessible( true );
		$this->assertSame( 'ready', $get->invoke( null, 'abc123' ) );

		// Transient fallback is not populated on the salted path.
		$this->assertSame( 'ready', wp_cache_get_salted( 'wppo_ccss_status_abc123', 'wppo', Util::cache_salt( 'wppo_ccss_salt' ) ) );

		// A salt bump invalidates every entry at once.
		$this->options_store['wppo_ccss_salt'] = time();
		$this->assertFalse( $get->invoke( null, 'abc123' ) );
	}

	/**
	 * Test that clear_all() bumps the CCSS status salt (issue #882).
	 */
	public function test_critical_css_clear_all_bumps_salt(): void {
		$GLOBALS['wp_filesystem'] = new WPPO_Salted_Filesystem_Harness();
		Functions\when( 'get_page_templates' )->justReturn( array() );
		Functions\when( 'get_stylesheet' )->justReturn( 'testtheme' );

		$ref = new \ReflectionMethod( Critical_CSS::class, 'set_status_cache' );
		$ref->setAccessible( true );
		$ref->invoke( null, 'abc123', 'pending', HOUR_IN_SECONDS );

		Critical_CSS::clear_all();

		$this->assertArrayHasKey( 'wppo_ccss_salt', $this->options_store );
		$get = new \ReflectionMethod( Critical_CSS::class, 'get_status_cache' );
		$get->setAccessible( true );
		$this->assertFalse( $get->invoke( null, 'abc123' ) );
	}

	/**
	 * Test that System_Info::flush_dropin_cache() bumps the drop-in salt and
	 * clears the transient (issue #882).
	 */
	public function test_flush_dropin_cache_bumps_salt(): void {
		System_Info::flush_dropin_cache();

		$this->assertArrayHasKey( 'wppo_sysinfo_salt', $this->options_store );
		// The drop-in-check transient must be cleared alongside the salt.
		$this->assertContains( Util::transient_key( 'wppo_sysinfo_dropin_check' ), $this->deleted_transients );
	}

	/**
	 * Test that Cache::store_cache_stats() writes the unified payload to both
	 * the salted cache and the transient (issue #882).
	 */
	public function test_store_cache_stats_writes_salted_and_transient(): void {
		$ref = new \ReflectionMethod( Cache::class, 'store_cache_stats' );
		$ref->setAccessible( true );

		$this->options_store['wppo_cache_last_cleared'] = '7';
		$stats_key                                      = Util::transient_key( 'wppo_cache_stats' );
		$ref->invoke(
			null,
			array(
				'size'  => '1 KB',
				'count' => 3,
			),
			$stats_key
		);

		// Salted entry validates against the current salt VALUE.
		$this->assertSame(
			array(
				'size'  => '1 KB',
				'count' => 3,
			),
			wp_cache_get_salted( 'wppo_cache_stats', 'wppo', Util::cache_salt( 'wppo_cache_last_cleared' ) )
		);
		// Transient fallback write is verified too.
		$this->assertSame(
			array(
				'size'  => '1 KB',
				'count' => 3,
			),
			$this->transients_store[ $stats_key ]
		);
	}

	/**
	 * Test that Cache::bump_stats_cache() bumps the wppo_cache_last_cleared
	 * salt when the salted family is available (issue #882 — the salt bump
	 * only invalidates salted entries when reads pass the current VALUE).
	 */
	public function test_bump_stats_cache_bumps_salt_option(): void {
		$this->options_store['wppo_cache_last_cleared'] = 5;

		Cache::bump_stats_cache();

		$this->assertSame( 6, $this->options_store['wppo_cache_last_cleared'] );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile, PSR1.Classes.ClassDeclaration.MultipleClasses

/**
 * In-memory object-cache harness exercising the real salted helpers.
 *
 * Stores whatever wp_cache_set_salted() writes (the wrapper array), so the
 * drop-in's salt comparison runs for real.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Salted_Cache_Harness {

	/**
	 * Stored entries keyed by "group:key".
	 *
	 * @var array<string,mixed>
	 */
	private array $store = array();

	/**
	 * Get a stored entry.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Whether to force.
	 * @param bool|null  $found Whether the value was found.
	 * @return mixed|false
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$found = isset( $this->store[ $group . ':' . $key ] );
		return $found ? $this->store[ $group . ':' . $key ] : false;
	}

	/**
	 * Store an entry.
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Cache data.
	 * @param string     $group  Cache group.
	 * @param int        $expire Expiration in seconds.
	 * @return bool
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->store[ $group . ':' . $key ] = $data;
		return true;
	}

	/**
	 * Delete an entry.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @return bool
	 */
	public function delete( $key, $group = 'default' ) {
		unset( $this->store[ $group . ':' . $key ] );
		return true;
	}

	/**
	 * Flush everything.
	 *
	 * @return bool
	 */
	public function flush() {
		$this->store = array();
		return true;
	}

	/**
	 * No-op salt registration.
	 *
	 * @param string $salt Salt value.
	 * @return bool
	 */
	public function add_salt( $salt ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}

/**
 * Minimal filesystem harness for clear_all().
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Salted_Filesystem_Harness {

	/**
	 * Whether a directory exists.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	public function is_dir( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return false;
	}

	/**
	 * No-op recursive delete.
	 *
	 * @param string $path      Path.
	 * @param bool   $recursive Recursive.
	 * @return bool
	 */
	public function delete( $path, $recursive = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}
}

/**
 * Miss-on-read object-cache harness mirroring the bootstrap default.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Miss_On_Read_Cache_Harness {

	/**
	 * Always a cache miss.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Whether to force.
	 * @param bool|null  $found Whether the value was found.
	 * @return false
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$found = false;
		return false;
	}

	/**
	 * Accept any write (no storage).
	 *
	 * @param int|string $key    Cache key.
	 * @param mixed      $data   Cache data.
	 * @param string     $group  Cache group.
	 * @param int        $expire Expiration in seconds.
	 * @return true
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return true;
	}

	/**
	 * Accept any delete.
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @return true
	 */
	public function delete( $key, $group = 'default' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return true;
	}

	/**
	 * No-op flush.
	 *
	 * @return true
	 */
	public function flush() {
		return true;
	}

	/**
	 * No-op salt registration.
	 *
	 * @param string $salt Salt value.
	 * @return true
	 */
	public function add_salt( $salt ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}

// phpcs:enable Generic.Files.OneObjectStructurePerFile, PSR1.Classes.ClassDeclaration.MultipleClasses
