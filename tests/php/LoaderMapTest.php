<?php
/**
 * Regression tests for the ARCH-003 Loader_Map boundary (issue #1532).
 *
 * Pins the centralized loader data extracted from `Main::includes()`:
 * eager-load parity with the 18-file set (16 always + 2
 * LiteSpeed-conditional; ARCH-010 added `class-ai-anomaly.php` after
 * `class-ai-adaptive.php`), fallback-map completeness (every
 * `PerformanceOptimise\Inc\*` class in `includes/` resolves to an existing
 * file), the LiteSpeed split contract both branches rely on (stack files
 * eager-skippable yet autoload-resolvable), and the WP-CLI file path.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

use PerformanceOptimise\Inc\Loader_Map;

/**
 * Loader_Map boundary tests.
 */
class LoaderMapTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Load the system under test (no top-level require: bootstrap/Patchwork rule).
	 *
	 * @return void
	 */
	private function load_loader_map(): void {
		if ( ! class_exists( Loader_Map::class, false ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/Core/class-loader-map.php';
		}
	}

	/**
	 * Pre-change eager set plus ARCH-010: the 16 files always required by Main::includes().
	 *
	 * @return string[]
	 */
	private function expected_eager_files(): array {
		return array(
			'Core/class-wp-version.php',
			'Edge/class-server-rules.php',
			'Edge/class-header-emitter.php',
			'Integrations/class-litespeed-integration.php',
			'Compatibility/class-llms.php',
			'Insight/class-od-bridge.php',
			'Cache/class-bfcache.php',
			'Admin/class-perf-translations.php',
			'Insight/class-ai-adaptive.php',
			'Insight/class-ai-anomaly.php',
			'Edge/class-edge-cache.php',
			'Support/trait-purge-logger.php',
			'Edge/class-edge-purger.php',
			'Edge/class-cdn.php',
			'Integrations/class-builder-purge-watcher.php',
			'Core/class-hook-registry.php',
		);
	}

	/**
	 * Eager list matches today's always-loaded set, in load order.
	 *
	 * @return void
	 */
	public function test_eager_files_match_pre_change_set(): void {
		$this->load_loader_map();
		$this->assertSame( $this->expected_eager_files(), Loader_Map::eager_files() );
	}

	/**
	 * LiteSpeed-conditional stack is exactly the two skippable files.
	 *
	 * @return void
	 */
	public function test_litespeed_stack_files_match_pre_change_set(): void {
		$this->load_loader_map();
		$this->assertSame(
			array(
				'Integrations/class-litespeed-crawler.php',
				'Integrations/class-litespeed-esi.php',
			),
			Loader_Map::litespeed_stack_files()
		);
	}

	/**
	 * Eager + stack combined equal the full 18-file eager set.
	 *
	 * @return void
	 */
	public function test_combined_eager_set_is_disjoint_and_complete(): void {
		$this->load_loader_map();
		$eager = Loader_Map::eager_files();
		$stack = Loader_Map::litespeed_stack_files();
		$this->assertSame( array(), array_intersect( $eager, $stack ), 'Stack files must be skippable (not in the always list).' );
		$combined = array_merge( $eager, $stack );
		$this->assertCount( 18, $combined );
		$this->assertSame( $combined, array_unique( $combined ) );
		foreach ( $combined as $file ) {
			$this->assertFileExists( Loader_Map::file_path( $file ), "Eager file missing: {$file}" );
		}
	}

	/**
	 * Both LiteSpeed branches stay resolvable: stack files are in the
	 * fallback map so late callers autoload them when the eager load was
	 * skipped on non-LiteSpeed requests.
	 *
	 * @return void
	 */
	public function test_litespeed_stack_resolves_via_fallback_when_eager_skipped(): void {
		$this->load_loader_map();
		$map = Loader_Map::fallback_map();
		foreach ( Loader_Map::litespeed_stack_files() as $file ) {
			$shorts = array_keys( $map, $file, true );
			$this->assertNotEmpty( $shorts, "Stack file not autoloadable: {$file}" );
			foreach ( $shorts as $short ) {
				$this->assertFileExists( (string) Loader_Map::path_for( (string) $short ) );
			}
		}
	}

	/**
	 * Every mapped short name resolves to an existing file.
	 *
	 * @return void
	 */
	public function test_every_mapped_short_resolves_to_existing_file(): void {
		$this->load_loader_map();
		$map = Loader_Map::fallback_map();
		$this->assertCount( 80, $map, 'Fallback map must cover every Loader_Map-managed plugin class.' );
		foreach ( $map as $short => $file ) {
			$path = Loader_Map::path_for( (string) $short );
			$this->assertNotNull( $path, "Unresolvable short name: {$short}" );
			$this->assertFileExists( (string) $path, "Mapped file missing: {$short} => {$file}" );
		}
	}

	/**
	 * Every inventory class resolves through `Loader_Map::path_for()`.
	 *
	 * ARCH-013 smoke gate: scans the class inventory JSON and asserts each
	 * listed class short name resolves via `path_for()` to an existing file,
	 * so a missed mapping in the directory move fails the suite.
	 *
	 * @return void
	 */
	public function test_every_inventory_class_resolves_via_path_for(): void {
		$this->load_loader_map();
		$inv_path = dirname( __DIR__, 2 ) . '/docs/architecture/class-inventory.json';
		$this->assertFileExists( $inv_path, 'Class inventory JSON must exist for the loader smoke gate.' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local file scan (no remote URL, no WP_Filesystem in unit tests).
		$raw = file_get_contents( $inv_path );
		$this->assertNotFalse( $raw, "Cannot read {$inv_path}" );
		$inventory = json_decode( (string) $raw, true );
		$this->assertIsArray( $inventory, 'Class inventory JSON must decode to an array.' );
		$this->assertNotEmpty( $inventory );
		$loader_class_count = 0;
		foreach ( $inventory as $entry ) {
			$file  = isset( $entry['file'] ) ? (string) $entry['file'] : '';
			$scope = isset( $entry['scope'] ) ? (string) $entry['scope'] : 'runtime';
			if ( in_array( $scope, array( 'protected_vendor_adjacent', 'drop_in' ), true ) ) {
				continue;
			}
			$this->assertNotSame( '', $file, 'Inventory entry must carry a file path.' );
			$abs = dirname( __DIR__, 2 ) . '/' . $file;
			$this->assertFileExists( $abs, "Inventory file missing: {$file}" );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local file scan (no remote URL, no WP_Filesystem in unit tests).
			$src = file_get_contents( $abs );
			$this->assertNotFalse( $src, "Cannot read {$file}" );
			if ( ! preg_match( '/^\s*(?:abstract\s+|final\s+)?(?:class|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', (string) $src, $m ) ) {
				continue;
			}
			++$loader_class_count;
			$short = $m[1];
			$path  = Loader_Map::path_for( $short );
			$this->assertNotNull( $path, "Inventory class {$short} ({$file}) does not resolve via Loader_Map::path_for()." );
			$this->assertFileExists( (string) $path, "Inventory class {$short} resolves to a missing file." );
		}
		$this->assertSame( 79, $loader_class_count, 'All Loader_Map-managed runtime classes must participate in the completeness gate.' );
	}

	/**
	 * The P3-007 registry resolves through the lazy fallback map.
	 *
	 * @return void
	 */
	public function test_dropin_registry_resolves_via_fallback(): void {
		$this->load_loader_map();

		$this->assertSame( 'Cache/class-dropin-registry.php', Loader_Map::fallback_map()['Dropin_Registry'] );
		$path = Loader_Map::path_for( 'Dropin_Registry' );
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'includes/Cache/class-dropin-registry.php', (string) $path );
		$this->assertFileExists( (string) $path );
	}

	/**
	 * The P3-010 cleanup runner resolves through the lazy fallback map.
	 *
	 * @return void
	 */
	public function test_database_cleanup_runner_resolves_via_fallback(): void {
		$this->load_loader_map();

		$this->assertSame( 'Database/class-database-cleanup-runner.php', Loader_Map::fallback_map()['Database_Cleanup_Runner'] );
		$path = Loader_Map::path_for( 'Database_Cleanup_Runner' );
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'includes/Database/class-database-cleanup-runner.php', (string) $path );
		$this->assertFileExists( (string) $path );
	}

	/**
	 * The P3-012 coordinator resolves through the lazy fallback map.
	 *
	 * @return void
	 */
	public function test_edge_purge_coordinator_resolves_via_fallback(): void {
		$this->load_loader_map();

		$this->assertSame( 'Edge/class-edge-purge-coordinator.php', Loader_Map::fallback_map()['Edge_Purge_Coordinator'] );
		$path = Loader_Map::path_for( 'Edge_Purge_Coordinator' );
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'includes/Edge/class-edge-purge-coordinator.php', (string) $path );
		$this->assertFileExists( $path );
	}

	/**
	 * The P3-013 cache coordinator resolves through the lazy fallback map.
	 *
	 * @return void
	 */
	public function test_cache_coordinator_resolves_via_fallback(): void {
		$this->load_loader_map();

		$this->assertSame( 'Cache/class-cache-coordinator.php', Loader_Map::fallback_map()['Cache_Coordinator'] );
		$path = Loader_Map::path_for( 'Cache_Coordinator' );
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'includes/Cache/class-cache-coordinator.php', (string) $path );
		$this->assertFileExists( $path );
	}

	/**
	 * The P3-015 preload/buffer coordinator resolves through the lazy fallback map.
	 *
	 * @return void
	 */
	public function test_preload_buffer_coordinator_resolves_via_fallback(): void {
		$this->load_loader_map();

		$this->assertSame( 'Core/class-preload-buffer-coordinator.php', Loader_Map::fallback_map()['Preload_Buffer_Coordinator'] );
		$path = Loader_Map::path_for( 'Preload_Buffer_Coordinator' );
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'includes/Core/class-preload-buffer-coordinator.php', (string) $path );
		$this->assertFileExists( $path );
		$this->assertSame( $path, Loader_Map::path_for( 'PerformanceOptimise\\Inc\\Preload_Buffer_Coordinator' ) );
	}

	/**
	 * The loader resolves itself and the orchestrator everywhere Main resolves.
	 *
	 * @return void
	 */
	public function test_loader_map_and_main_self_registered(): void {
		$this->load_loader_map();
		$this->assertSame( 'Core/class-loader-map.php', Loader_Map::fallback_map()['Loader_Map'] );
		$this->assertSame( 'Core/class-main.php', Loader_Map::fallback_map()['Main'] );
		$this->assertFileExists( (string) Loader_Map::path_for( 'Loader_Map' ) );
		$this->assertFileExists( (string) Loader_Map::path_for( 'Main' ) );
	}

	/**
	 * Every plugin class declared under includes/ resolves through the map.
	 *
	 * Scans the declared `class`/`trait` name of each class file and asserts
	 * it is covered by the eager list or the fallback map (the completeness
	 * gate future ARCH-013 moves must keep green).
	 *
	 * @return void
	 */
	public function test_every_includes_class_resolves(): void {
		$this->load_loader_map();
		$map         = Loader_Map::fallback_map();
		$load_always = array_merge( Loader_Map::eager_files(), Loader_Map::litespeed_stack_files() );
		$files       = array_merge(
			(array) glob( WPPO_PLUGIN_PATH . 'includes/*/class-*.php' ),
			(array) glob( WPPO_PLUGIN_PATH . 'includes/*/trait-*.php' ),
			(array) glob( WPPO_PLUGIN_PATH . 'includes/class-*.php' )
		);
		// minify/ wrappers are third-party-adjacent and out of the loader scope.
		$files = array_values(
			array_filter(
				(array) $files,
				static function ( $file_path ) {
					return false === strpos( (string) $file_path, '/includes/minify/' );
				}
			)
		);
		$this->assertNotEmpty( $files );
		foreach ( $files as $file_path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local file scan (no remote URL, no WP_Filesystem in unit tests).
			$src = file_get_contents( (string) $file_path );
			$this->assertNotFalse( $src, "Cannot read {$file_path}" );
			if ( ! preg_match( '/^\s*(?:abstract\s+|final\s+)?(?:class|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/m', (string) $src, $m ) ) {
				continue;
			}
			$short     = $m[1];
			$via_eager = false;
			foreach ( $load_always as $eager_file ) {
				if ( $this->file_declares_short( Loader_Map::file_path( $eager_file ), $short ) ) {
					$via_eager = true;
					break;
				}
			}
			$this->assertTrue(
				$via_eager || isset( $map[ $short ] ),
				"Plugin class {$short} (" . basename( (string) $file_path ) . ') resolves through neither eager list nor fallback map.'
			);
			if ( isset( $map[ $short ] ) ) {
				$this->assertFileExists( (string) Loader_Map::path_for( $short ) );
			}
		}
	}

	/**
	 * Whether a file declares a given class/trait short name.
	 *
	 * @param string $file_path Absolute file path.
	 * @param string $short     Class short name.
	 * @return bool
	 */
	private function file_declares_short( string $file_path, string $short ): bool {
		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local file scan (no remote URL, no WP_Filesystem in unit tests).
		$src = file_get_contents( $file_path );
		if ( false === $src ) {
			return false;
		}
		return (bool) preg_match( '/^\s*(?:abstract\s+|final\s+)?(?:class|trait)\s+' . preg_quote( $short, '/' ) . '\b/m', $src );
	}

	/**
	 * Autoloader resolves a lazily-loaded class without eager require.
	 *
	 * `Cache` is never in the eager list; the spl delegate in
	 * `Main::includes()` calls `Loader_Map::path_for()` for exactly this.
	 *
	 * @return void
	 */
	public function test_path_for_resolves_lazy_class(): void {
		$this->load_loader_map();
		$this->assertNotContains( 'Cache/class-cache.php', Loader_Map::eager_files() );
		$this->assertNotContains( 'Cache/class-cache.php', Loader_Map::litespeed_stack_files() );
		$path = Loader_Map::path_for( 'Cache' );
		$this->assertNotNull( $path );
		$this->assertStringEndsWith( 'includes/Cache/class-cache.php', (string) $path );
		$this->assertFileExists( (string) $path );
		// Fully-qualified name resolves identically (autoloader passes FQCNs).
		$this->assertSame( $path, Loader_Map::path_for( 'PerformanceOptimise\\Inc\\Cache' ) );
		$minify_path = Loader_Map::path_for( 'PerformanceOptimise\\Inc\\Minify\\Minify_Policy' );
		$this->assertNotNull( $minify_path );
		$this->assertStringEndsWith( 'includes/minify/class-minify-policy.php', (string) $minify_path );
	}

	/**
	 * Unknown and foreign names resolve to null (autoloader no-op).
	 *
	 * @return void
	 */
	public function test_path_for_returns_null_for_unknown(): void {
		$this->load_loader_map();
		$this->assertNull( Loader_Map::path_for( 'No_Such_Class' ) );
		$this->assertNull( Loader_Map::path_for( 'Some\\Other\\Cache' ) );
		$this->assertNull( Loader_Map::path_for( 'PerformanceOptimise\\Inc\\Some\\Other\\Cache' ) );
	}

	/**
	 * WP-CLI command file path is present and exists.
	 *
	 * @return void
	 */
	public function test_cli_file_present_and_exists(): void {
		$this->load_loader_map();
		$cli = Loader_Map::cli_file();
		$this->assertStringEndsWith( 'includes/Admin/class-wppo-cli-command.php', $cli );
		$this->assertFileExists( $cli );
		$this->assertSame( 'Admin/class-wppo-cli-command.php', Loader_Map::fallback_map()['WPPO_CLI_Command'] );
	}
}
