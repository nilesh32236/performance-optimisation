<?php
/**
 * Tests for checksum-gated CCSS regen on save (issue #1388).
 *
 * Pins the widest-blast-radius new API (`Critical_CSS::maybe_regen_on_save()`):
 * checksum-change regen vs plain-save no-op, revision/autosave skip,
 * disabled-setting no-op, plus the `ccssInlineBudgetKb` sanitizer clamp and
 * the `Main::maybe_migrate_css_queue_defaults()` backfill for the three
 * additive keys.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Critical_CSS;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Class CcssChecksumRegen1388Test.
 *
 * @package PerformanceOptimise\Tests
 */
class CcssChecksumRegen1388Test extends \PHPUnit\Framework\TestCase {

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
	 * Original global $wpdb (restored in tearDown).
	 *
	 * @var mixed
	 */
	private $wpdb_backup = null;

	/**
	 * Whether a $wpdb instance existed before the test.
	 *
	 * @var bool
	 */
	private bool $wpdb_had_instance = false;

	/**
	 * Source CSS fixture file backing Util::get_local_path() mapping.
	 *
	 * @var string
	 */
	private string $src_file = '';

	/**
	 * Source stylesheet URL resolving to $src_file via ABSPATH mapping.
	 *
	 * @var string
	 */
	private string $src_url = 'http://example.com/wp-content/wppo-1388-src.css';

	/**
	 * Stub the WP functions used by the checksum-regen path.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::reset_runtime_caches();
		Critical_CSS::reset_ccss_memo();

		$this->option_map    = array();
		$this->transient_map = array();
		$this->src_file      = wp_normalize_path( WP_CONTENT_DIR . '/wppo-1388-src.css' );

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
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'test-theme' );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/sample/' );
		Functions\when( 'wp_kses_post' )->returnArg();

		Util::clear_settings_cache();
	}

	/**
	 * Restore $wpdb and Brain Monkey state, remove fixtures.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( '' !== $this->src_file && file_exists( $this->src_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $this->src_file );
		}
		if ( $this->wpdb_had_instance ) {
			$GLOBALS['wpdb'] = $this->wpdb_backup;
		}
		$this->wpdb_backup       = null;
		$this->wpdb_had_instance = false;
		Critical_CSS::reset_ccss_memo();
		Util::clear_settings_cache();
		\Brain\Monkey\tearDown();
		if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
			\PerformanceOptimise\Inc\Main::reset_instance();
		}
		parent::tearDown();
	}

	/**
	 * Swap global $wpdb for an insert recorder so Log::add() can run.
	 *
	 * @return void
	 */
	private function swap_wpdb_recorder(): void {
		$this->wpdb_had_instance = isset( $GLOBALS['wpdb'] );
		if ( $this->wpdb_had_instance ) {
			$this->wpdb_backup = $GLOBALS['wpdb'];
		}
		$GLOBALS['wpdb'] = new class() {
			/**
			 * Table prefix.
			 *
			 * @var string
			 */
			public $prefix = 'wp_';

			/**
			 * Record an insert.
			 *
			 * @param string $table  Table name.
			 * @param array  $data   Row data.
			 * @param array  $format Formats.
			 * @return int
			 */
			public function insert( $table, $data, $format = array() ) {
				return 1;
			}
		};
	}

	/**
	 * Write the home-template CCSS fixture plus a baselined source checksum.
	 *
	 * The checksum baseline is built from the exact document-ordered URL list
	 * the probe re-hashes, so an unchanged file reads as fresh.
	 *
	 * @param string $src_content Source CSS file content.
	 * @param string $ccss_content Stored CCSS variant content.
	 * @return string Home template hash.
	 */
	private function seed_home_variant_with_checksum( string $src_content, string $ccss_content = 'body{color:red}' ): string {
		$dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' );
		if ( ! is_dir( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $dir, 0775, true );
		}
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		if ( ! is_dir( $content_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $content_dir, 0775, true );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $this->src_file, $src_content );

		$hash = Critical_CSS::get_template_hash( 'home' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $dir . '/' . $hash . '.css', $ccss_content );
		Critical_CSS::reset_ccss_memo();

		$source = $this->build_source_for_urls( array( $this->src_url ) );
		$this->assertNotSame( '', $source, 'Source fixture must resolve locally.' );
		Critical_CSS::store_source_urls( $hash, array( $this->src_url ) );
		Critical_CSS::store_source_checksum( $hash, $source );

		return $hash;
	}

	/**
	 * Remove the home-template CCSS fixture.
	 *
	 * @param string $hash Template hash.
	 * @return void
	 */
	private function remove_home_fixture( string $hash ): void {
		$file = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css';
		if ( file_exists( $file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
			unlink( $file );
		}
		Critical_CSS::reset_ccss_memo();
	}

	/**
	 * Invoke the private build_local_source_css() helper.
	 *
	 * @param string[] $urls Ordered stylesheet URLs.
	 * @return string
	 */
	private function build_source_for_urls( array $urls ): string {
		$reflection = new ReflectionMethod( Critical_CSS::class, 'build_local_source_css' );

		return $reflection->invoke( null, $urls );
	}

	/**
	 * Revisions never trigger checksum regen work.
	 *
	 * @return void
	 */
	public function test_maybe_regen_on_save_skips_revisions(): void {
		$hash = $this->seed_home_variant_with_checksum( 'body{color:red}' );

		try {
			Functions\when( 'wp_is_post_revision' )->justReturn( true );

			$this->assertFalse( Critical_CSS::maybe_regen_on_save( 123, null ) );
			$this->assertFileExists( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
		} finally {
			$this->remove_home_fixture( $hash );
		}
	}

	/**
	 * Autosaves never trigger checksum regen work.
	 *
	 * @return void
	 */
	public function test_maybe_regen_on_save_skips_autosaves(): void {
		$hash = $this->seed_home_variant_with_checksum( 'body{color:red}' );

		try {
			Functions\when( 'wp_is_post_autosave' )->justReturn( true );

			$this->assertFalse( Critical_CSS::maybe_regen_on_save( 123, null ) );
			$this->assertFileExists( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
		} finally {
			$this->remove_home_fixture( $hash );
		}
	}

	/**
	 * A disabled ccssChecksumRegen setting is a no-op even when CSS changed.
	 *
	 * @return void
	 */
	public function test_maybe_regen_on_save_noop_when_disabled(): void {
		$hash = $this->seed_home_variant_with_checksum( 'body{color:red}' );

		try {
			$this->option_map['wppo_settings'] = array(
				'file_optimisation' => array( 'ccssChecksumRegen' => false ),
			);
			Util::clear_settings_cache();

			// Change the source CSS: with the gate off nothing may happen.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture mutation.
			file_put_contents( $this->src_file, 'body{color:blue}' );
			Critical_CSS::reset_ccss_memo();

			$this->assertFalse( Critical_CSS::maybe_regen_on_save( 123, null ) );
			$this->assertFileExists( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
		} finally {
			$this->remove_home_fixture( $hash );
		}
	}

	/**
	 * A plain post save with unchanged CSS fires nothing and keeps the file.
	 *
	 * @return void
	 */
	public function test_maybe_regen_on_save_noop_when_css_unchanged(): void {
		$hash = $this->seed_home_variant_with_checksum( 'body{color:red}' );

		try {
			$this->assertFalse( Critical_CSS::maybe_regen_on_save( 123, null ) );
			$this->assertFileExists( wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css' );
		} finally {
			$this->remove_home_fixture( $hash );
		}
	}

	/**
	 * A checksum change drops the stale variant and reports a requeue.
	 *
	 * @return void
	 */
	public function test_maybe_regen_on_save_regens_when_css_changed(): void {
		$hash = $this->seed_home_variant_with_checksum( 'body{color:red}' );
		$file = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ) . '/' . $hash . '.css';

		try {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture mutation.
			file_put_contents( $this->src_file, 'body{color:blue}.new{margin:0}' );
			Critical_CSS::reset_ccss_memo();

			$this->assertTrue( Critical_CSS::maybe_regen_on_save( 123, null ) );
			$this->assertFileDoesNotExist( $file );
		} finally {
			$this->remove_home_fixture( $hash );
		}
	}

	/**
	 * The budget sanitizer clamps to 1-100 KB and fails open to 14.
	 *
	 * @return void
	 */
	public function test_budget_sanitizer_clamps_to_1_to_100(): void {
		$clean = Util::sanitize_settings_recursively(
			array(
				'file_optimisation' => array( 'ccssInlineBudgetKb' => 20 ),
			)
		);

		$this->assertSame( 20, $clean['file_optimisation']['ccssInlineBudgetKb'] );

		foreach ( array( 500, 0, -3, 'not-a-number', array( 'nested' ) ) as $bad ) {
			$clean = Util::sanitize_settings_recursively(
				array(
					'file_optimisation' => array( 'ccssInlineBudgetKb' => $bad ),
				)
			);

			$this->assertSame( 14, $clean['file_optimisation']['ccssInlineBudgetKb'], 'Bad budget must heal to 14.' );
		}

		$clean = Util::sanitize_settings_recursively(
			array(
				'file_optimisation' => array(
					'ccssCommerceExclude' => 'yes',
					'ccssChecksumRegen'   => '',
				),
			)
		);

		$this->assertTrue( $clean['file_optimisation']['ccssCommerceExclude'] );
		$this->assertFalse( $clean['file_optimisation']['ccssChecksumRegen'] );
	}

	/**
	 * Run the CSS-queue migration against a stored wppo_settings value.
	 *
	 * @param mixed $stored Stored wppo_settings value (or false for no row).
	 * @return array Tuple of (Main instance, writes list).
	 */
	private function migrate( $stored ): array {
		$writes = array();

		Functions\when( 'get_option' )->alias(
			static function ( $key, $default_value = false ) use ( $stored ) {
				return 'wppo_settings' === $key ? $stored : $default_value;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$writes ) {
				$writes[] = array( $key, $value );
				return true;
			}
		);

		$main = ( new ReflectionClass( Main::class ) )->newInstanceWithoutConstructor();

		$options_prop = new ReflectionProperty( Main::class, 'options' );
		$options_prop->setValue( $main, array() );

		$main->maybe_migrate_css_queue_defaults();

		return array( $main, $writes );
	}

	/**
	 * The migration backfills the three additive keys with safe defaults.
	 *
	 * @return void
	 */
	public function test_migration_backfills_1388_defaults(): void {
		$this->swap_wpdb_recorder();

		list( , $writes ) = $this->migrate(
			array(
				'file_optimisation' => array( 'ccssQueueCap' => 5 ),
			)
		);

		$this->assertNotEmpty( $writes, 'Migration must persist backfilled defaults.' );
		$file = $writes[0][1]['file_optimisation'];

		$this->assertSame( 14, $file['ccssInlineBudgetKb'] );
		$this->assertTrue( $file['ccssCommerceExclude'] );
		$this->assertTrue( $file['ccssChecksumRegen'] );
		// Pre-existing values survive the backfill.
		$this->assertSame( 5, $file['ccssQueueCap'] );
	}

	/**
	 * The migration preserves explicit values and is idempotent when complete.
	 *
	 * @return void
	 */
	public function test_migration_preserves_explicit_values_and_is_idempotent(): void {
		$this->swap_wpdb_recorder();

		list( , $writes ) = $this->migrate(
			array(
				'file_optimisation' => array(
					'ccssInlineBudgetKb'  => 20,
					'ccssCommerceExclude' => false,
					'ccssChecksumRegen'   => false,
				),
			)
		);

		$this->assertNotEmpty( $writes, 'Missing sibling keys still trigger a write.' );
		$file = $writes[0][1]['file_optimisation'];

		$this->assertSame( 20, $file['ccssInlineBudgetKb'] );
		$this->assertFalse( $file['ccssCommerceExclude'] );
		$this->assertFalse( $file['ccssChecksumRegen'] );

		list( , $writes ) = $this->migrate(
			array(
				'file_optimisation' => array(
					'ccssQueueCap'         => 5,
					'usedCssQueueCap'      => 50,
					'ccssViewportVariants' => false,
					'usedCSSDeliveryMode'  => 'file',
					'ccssGenTimeout'       => 25,
					'ccssInlineBudgetKb'   => 20,
					'ccssCommerceExclude'  => false,
					'ccssChecksumRegen'    => false,
				),
			)
		);

		$this->assertSame( array(), $writes, 'A complete row must not trigger a write.' );
	}
}
