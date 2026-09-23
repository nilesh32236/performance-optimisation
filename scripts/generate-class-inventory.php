<?php
/**
 * Deterministic class-inventory + dependency-graph generator (ARCH-001).
 *
 * Scans includes/class-*.php + includes/trait-*.php and writes:
 *   docs/architecture/class-inventory.json  (existing schema + additive `refs` field)
 *   docs/architecture/DEPENDENCY-GRAPH.json (file => [referenced plugin classes])
 *
 * Method counts use the same definition as the campaign baseline:
 * lines starting with an optional indent followed by a visibility keyword
 * and the `function` keyword. Cross-class refs are static `ClassName::`
 * occurrences for known plugin short names; dynamic/string references
 * (class_exists('...'), method_exists()) are intentionally out of scope and
 * documented as a limitation in INCLUDE-HIERARCHY.md.
 *
 * Usage: php scripts/generate-class-inventory.php [--check]
 *   --check exits non-zero when the committed JSON differs (CI drift gate).
 *
 * @package PerformanceOptimise
 * @since NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- CLI dev script runs outside WordPress (no WP_Filesystem, wp_remote_get, or wp_json_encode available).

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "generate-class-inventory.php must run from the CLI.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__ );
$includes    = $plugin_root . '/includes';
$check_mode  = in_array( '--check', $argv, true );

/**
 * Known plugin class short names (namespace PerformanceOptimise\Inc).
 *
 * @return string[]
 */
function wppo_known_classes(): array {
	return array(
		'Abilities',
		'Activate',
		'Admin_Notices',
		'Advanced_Cache_Handler',
		'AI_Adaptive',
		'Asset_Manager',
		'Bfcache',
		'Builder_Purge_Watcher',
		'Cache',
		'Cache_Key',
		'CDN',
		'CDN_Purger',
		'Cloudflare_Purger',
		'Core_Tweaks',
		'Critical_CSS',
		'Css_Safelist',
		'Cron',
		'Database_Cleanup',
		'Deactivate',
		'Edge_Cache',
		'Edge_Purger',
		'Filesystem',
		'Google_Fonts',
		'Header_Emitter',
		'Hook_Registry',
		'Htaccess_Handler',
		'Http',
		'Image_Optimisation',
		'Img_Converter',
		'LiteSpeed_Crawler',
		'LiteSpeed_ESI',
		'LiteSpeed_Integration',
		'Llms',
		'Log',
		'Main',
		'Metabox',
		'Object_Cache',
		'OD_Bridge',
		'Pagespeed',
		'Perf_Translations',
		'Purge_Logger',
		'Rest',
		'RUM',
		'Sandbox_Preview',
		'Scheduler',
		'Server_Rules',
		'Settings_Store',
		'Suggestion_Engine',
		'System_Info',
		'Telemetry',
		'Used_CSS',
		'Url',
		'Util',
		'Woo_Detect',
		'Wp_Version',
		'WPPO_CLI_Command',
	);
}

$known   = wppo_known_classes();
$pattern = '/(?<![A-Za-z_\\\\])(' . implode( '|', $known ) . ')::/';
$files   = array_merge(
	(array) glob( $includes . '/class-*.php' ),
	(array) glob( $includes . '/trait-*.php' ),
	// Procedural helper loaded outside the class map; tracked for completeness.
	(array) glob( $includes . '/redis-connect-helper.php' )
);
sort( $files );

$inventory = array();
$edges     = array();

foreach ( $files as $file_path ) {
	$src = file_get_contents( $file_path );
	if ( false === $src ) {
		fwrite( STDERR, "Cannot read {$file_path}\n" );
		exit( 1 );
	}
	$rel = 'includes/' . basename( $file_path );

	preg_match_all( '/^\s*(?:public|protected|private)\s+(?:static\s+)?function\s+([a-zA-Z0-9_]+)/m', $src, $m );
	$methods = $m[1];

	preg_match_all( '/add_(?:action|filter)\s*\(\s*[\'"]([^\'"]+)[\'"]/', $src, $h );
	$hooks = array_values( array_unique( $h[1] ) );

	// Mutable static state = static PROPERTY declarations. Static methods
	// alone (Main, Cache, Util facades) do not count.
	$static_state = (bool) preg_match( '/(?:private|protected|public|var)\s+static\s+\$/', $src );

	preg_match_all( $pattern, $src, $r );
	$refs = array_values( array_unique( $r[1] ) );
	sort( $refs );

	// Exclude the self-reference: every class mentions its own name.
	$own_class = preg_match( '/(?:class|trait)\s+([A-Za-z0-9_]+)/', $src, $sm ) ? $sm[1] : null;
	if ( null !== $own_class ) {
		$refs = array_values( array_diff( $refs, array( $own_class ) ) );
	}

	// Line count uses wc -l semantics (newline count; +1 only when the file
	// does not end with a newline) to stay comparable with campaign baselines.
	$line_count = substr_count( $src, "\n" );
	if ( ! str_ends_with( $src, "\n" ) ) {
		++$line_count;
	}

	$inventory[] = array(
		'file'         => $rel,
		'class'        => null,
		'lines'        => $line_count,
		'methods'      => count( $methods ),
		'hooks'        => $hooks,
		'static_state' => $static_state,
		'refs'         => $refs,
	);

	if ( count( $refs ) > 0 ) {
		$edges[ $rel ] = $refs;
	}
}

$inv_json  = json_encode( $inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
$edge_json = json_encode( $edges, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

$inv_path  = $plugin_root . '/docs/architecture/class-inventory.json';
$edge_path = $plugin_root . '/docs/architecture/DEPENDENCY-GRAPH.json';

if ( $check_mode ) {
	$ok = true;
	if ( ! file_exists( $inv_path ) || file_get_contents( $inv_path ) !== $inv_json ) {
		fwrite( STDERR, "class-inventory.json is stale; regenerate without --check.\n" );
		$ok = false;
	}
	if ( ! file_exists( $edge_path ) || file_get_contents( $edge_path ) !== $edge_json ) {
		fwrite( STDERR, "DEPENDENCY-GRAPH.json is stale; regenerate without --check.\n" );
		$ok = false;
	}
	exit( $ok ? 0 : 1 );
}

file_put_contents( $inv_path, $inv_json );
file_put_contents( $edge_path, $edge_json );

fwrite(
	STDOUT,
	sprintf(
		"Wrote %s (%d files) and %s (%d edges).\n",
		$inv_path,
		count( $inventory ),
		$edge_path,
		array_sum( array_map( 'count', $edges ) )
	)
);
