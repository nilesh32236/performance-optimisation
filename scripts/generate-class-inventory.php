<?php
/**
 * Deterministic tokenizer-based class inventory and dependency graph generator.
 *
 * Scans first-party runtime PHP and writes:
 *   - docs/architecture/class-inventory.json
 *   - docs/architecture/DEPENDENCY-GRAPH.json
 *
 * Runtime dependencies come from PhpToken syntax. Comments and docblocks are
 * recorded separately and never become graph edges. The analyzer also covers
 * loader/file paths, compatibility probes, inheritance, trait use, external
 * dependency signals, method-size metrics, static state, and exact-shape
 * duplicate candidates.
 *
 * Usage: php scripts/generate-class-inventory.php [--check]
 *   --check exits non-zero when committed JSON is stale.
 *
 * @package PerformanceOptimise
 * @since   NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI development script runs outside WordPress; native filesystem/token APIs are required.

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "generate-class-inventory.php must run from the CLI.\n" );
	exit( 1 );
}

require_once __DIR__ . '/architecture/class-source-analyzer.php';
require_once __DIR__ . '/architecture/class-dependency-graph.php';
require_once __DIR__ . '/architecture/class-architecture-guards.php';

$plugin_root = dirname( __DIR__ );
$check_mode  = in_array( '--check', $argv, true );

$inventory_files = array_merge(
	(array) glob( $plugin_root . '/includes/*/class-*.php' ),
	(array) glob( $plugin_root . '/includes/*/trait-*.php' ),
	(array) glob( $plugin_root . '/includes/class-*.php' ),
	(array) glob( $plugin_root . '/includes/Support/redis-connect-helper.php' ),
	(array) glob( $plugin_root . '/templates/object-cache.php' )
);
$inventory_files = array_values( array_unique( $inventory_files ) );
sort( $inventory_files, SORT_STRING );

$runtime_files = $inventory_files;
foreach ( array( 'performance-optimisation.php', 'uninstall.php', 'templates/perf-translations.php' ) as $relative_file ) {
	$absolute_file = $plugin_root . '/' . $relative_file;
	if ( file_exists( $absolute_file ) ) {
		$runtime_files[] = $absolute_file;
	}
}
$runtime_files = array_values( array_unique( $runtime_files ) );
sort( $runtime_files, SORT_STRING );

try {
	$analyzer       = new PerformanceOptimise\Architecture\Source_Analyzer();
	$nodes          = $analyzer->analyze( $runtime_files, $plugin_root );
	$graph          = new PerformanceOptimise\Architecture\Dependency_Graph( $nodes );
	$graph_payload  = $graph->graph();
	$inventory      = $graph->inventory(
		array_map(
			static function ( string $file ) use ( $plugin_root ): string {
				return ltrim( str_replace( '\\', '/', substr( $file, strlen( $plugin_root ) ) ), '/' );
			},
			$inventory_files
		)
	);
	$inventory_json = json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
	$graph_json     = json_encode( $graph_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ) . "\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Architecture inventory failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}

$inv_path   = $plugin_root . '/docs/architecture/class-inventory.json';
$graph_path = $plugin_root . '/docs/architecture/DEPENDENCY-GRAPH.json';

if ( $check_mode ) {
	$ok = true;
	if ( ! file_exists( $inv_path ) || file_get_contents( $inv_path ) !== $inventory_json ) {
		fwrite( STDERR, "class-inventory.json is stale; regenerate without --check.\n" );
		$ok = false;
	}
	if ( ! file_exists( $graph_path ) || file_get_contents( $graph_path ) !== $graph_json ) {
		fwrite( STDERR, "DEPENDENCY-GRAPH.json is stale; regenerate without --check.\n" );
		$ok = false;
	}
	if ( $ok ) {
		$guards = new PerformanceOptimise\Architecture\Architecture_Guards();
		$result = $guards->evaluate( $inventory, $graph_payload );
		if ( 'pass' !== $result['status'] ) {
			foreach ( $result['violations'] as $violation ) {
				fwrite(
					STDERR,
					sprintf(
						"Architecture guard %s/%s: %s (%s)\n",
						$violation['guard'],
						$violation['code'],
						$violation['subject'],
						$violation['reason']
					)
				);
			}
			$ok = false;
		}
	}
	exit( $ok ? 0 : 1 );
}

if ( false === file_put_contents( $inv_path, $inventory_json ) || false === file_put_contents( $graph_path, $graph_json ) ) {
	fwrite( STDERR, "Could not write architecture inventory files.\n" );
	exit( 1 );
}

fwrite(
	STDOUT,
	sprintf(
		"Wrote %s (%d files) and %s (%d nodes, %d edges, %d cyclic components).\n",
		$inv_path,
		count( $inventory ),
		$graph_path,
		$graph_payload['summary']['files'],
		$graph_payload['summary']['edges'],
		$graph_payload['summary']['cyclic_components']
	)
);
