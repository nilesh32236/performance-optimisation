<?php
/**
 * Architecture guard: direct reads of the wppo_settings option (#902).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Fails CI when a direct get_option('wppo_settings') appears outside the
 * documented allowlist. All runtime reads must go through the per-request
 * memo in Util::get_settings().
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */
class SettingsReadGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Files allowed to call get_option('wppo_settings') directly, with the
	 * expected number of code (non-comment) occurrences and the reason.
	 *
	 * @var array<string, array{count: int, reason: string}>
	 */
	private const ALLOWLIST = array(
		'includes/class-util.php'     => array(
			'count'  => 1,
			'reason' => 'Canonical read inside Util::get_settings().',
		),
		'includes/class-activate.php' => array(
			'count'  => 1,
			'reason' => 'Null-distinguishing fresh-install check in maybe_seed_settings().',
		),
		'includes/class-main.php'     => array(
			'count'  => 3,
			'reason' => 'Bare reads distinguishing "no row" from "stored array" in migrate_block_assets_setting(), maybe_migrate_ccss_max_size(), and maybe_migrate_image_alt_edge_defaults().',
		),
	);

	/**
	 * Regex matching a direct wppo_settings option read.
	 *
	 * @var string
	 */
	private const READ_PATTERN = "/get_option\s*\(\s*['\"]wppo_settings['\"]/";

	/**
	 * Collect direct wppo_settings reads in code (comment lines excluded).
	 *
	 * @param string $dir Directory to scan (plugin root relative).
	 * @return array<string, int[]> Map of relative path to 1-based line numbers.
	 */
	private function collect_direct_reads( string $dir ): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		foreach ( glob( $root . '/' . $dir . '/*.php' ) as $file ) {
			$lines = file( $file );
			if ( ! is_array( $lines ) ) {
				continue;
			}
			foreach ( $lines as $number => $line ) {
				if ( ! preg_match( self::READ_PATTERN, $line ) ) {
					continue;
				}
				// Skip docblock/line comments (e.g. `get_option( 'wppo_settings' )`
				// mentions in @since notes); only real code reads count.
				$trimmed = ltrim( $line );
				if ( str_starts_with( $trimmed, '*' ) || str_starts_with( $trimmed, '//' ) || str_starts_with( $trimmed, '#' ) ) {
					continue;
				}
				$relative             = substr( (string) $file, strlen( $root ) + 1 );
				$found[ $relative ][] = $number + 1;
			}
		}

		return $found;
	}

	/**
	 * Every direct read must be allowlisted and carry an allowlist marker.
	 */
	public function test_no_unlisted_direct_settings_reads(): void {
		$found = $this->collect_direct_reads( 'includes' );

		foreach ( $found as $file => $lines ) {
			$this->assertArrayHasKey(
				$file,
				self::ALLOWLIST,
				"Direct get_option('wppo_settings') in {$file}:" . implode( ',', $lines ) . ' is not allowlisted — use Util::get_settings() instead'
			);
			$this->assertCount(
				self::ALLOWLIST[ $file ]['count'],
				$lines,
				"Direct-read count drifted in {$file} — update the guard allowlist"
			);

			// Non-canonical allowlist entries must carry an inline marker so the
			// exception stays documented at the call site.
			if ( 'includes/class-util.php' === $file ) {
				continue;
			}
			$source = file( dirname( __DIR__, 2 ) . '/' . $file );
			$this->assertIsArray( $source );
			foreach ( $lines as $line_number ) {
				$window = implode( '', array_slice( $source, max( 0, $line_number - 6 ), 6 ) );
				$this->assertStringContainsString(
					'allowlist(settings-read-guard)',
					$window,
					"Allowlisted read in {$file}:{$line_number} must carry an allowlist(settings-read-guard) comment"
				);
			}
		}
	}

	/**
	 * Early-boot templates must never read wppo_settings (Util unavailable).
	 */
	public function test_templates_have_no_direct_settings_reads(): void {
		$found = $this->collect_direct_reads( 'templates' );
		$this->assertSame(
			array(),
			$found,
			'templates/ must stay free of get_option(\'wppo_settings\') — drop-ins run before plugin load'
		);
	}

	/**
	 * Util::option_key() must exist and share transient_key() semantics.
	 */
	public function test_option_key_semantics(): void {
		$this->assertTrue( method_exists( Util::class, 'option_key' ) );

		Functions\when( 'is_multisite' )->justReturn( false );
		$this->assertSame( 'wppo_foo', Util::option_key( 'wppo_foo' ) );
		$this->assertSame( Util::transient_key( 'wppo_foo' ), Util::option_key( 'wppo_foo' ) );

		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 42 );
		$this->assertSame( '42_wppo_foo', Util::option_key( 'wppo_foo' ) );
		$this->assertSame( Util::transient_key( 'wppo_foo' ), Util::option_key( 'wppo_foo' ) );
	}

	/**
	 * The LiteSpeed purge-queue option key must route through option_key().
	 */
	public function test_db_queue_key_uses_option_key(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->assertSame(
			Util::option_key( LiteSpeed_Integration::DB_QUEUE ),
			LiteSpeed_Integration::get_db_queue_key()
		);

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-litespeed-integration.php' );
		$this->assertDoesNotMatchRegularExpression(
			'/transient_key\s*\(\s*self::DB_QUEUE\s*\)/',
			$source,
			'DB_QUEUE is an option name and must use Util::option_key(), not transient_key()'
		);
	}
}
