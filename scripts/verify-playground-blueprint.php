<?php
/**
 * Verify the published Playground demo points at a blueprint that exists.
 *
 * docs/site/playground.html ships a code-executing link whose blueprint-url
 * pins a commit SHA on raw.githubusercontent.com. Nothing in CI checks that
 * pin, so a bad or stale SHA is only discovered by a visitor whose browser
 * runs whatever that URL serves.
 *
 * Checks, in order:
 *   1. docs/site/playground.html contains a blueprint-url
 *   2. the pinned SHA is a full 40-character hex commit
 *   3. the blueprint exists at that SHA and parses as JSON
 *   4. the blueprint's plugin URL is an absolute https URL
 *
 * Offline by default. Pass --network to also fetch the pinned URL.
 *
 * Exit 0 when every enabled check passes, 1 otherwise.
 *
 * @package PerformanceOptimisation
 */

declare( strict_types = 1 );

$root      = dirname( __DIR__ );
$page      = $root . '/docs/site/playground.html';
$network   = in_array( '--network', $argv, true );
$failures  = array();

if ( ! is_readable( $page ) ) {
	fwrite( STDERR, "cannot read docs/site/playground.html\n" );
	exit( 1 );
}

$html = (string) file_get_contents( $page );

if ( ! preg_match( '#blueprint-url=([^"\'<>\s]+)#', $html, $m ) ) {
	fwrite( STDERR, "no blueprint-url found in docs/site/playground.html\n" );
	exit( 1 );
}

$raw = rawurldecode( $m[1] );

// 2. the pin must be a full SHA, not a branch name.
if ( ! preg_match( '#raw\.githubusercontent\.com/[^/]+/[^/]+/([0-9a-f]{40})/#', $raw, $s ) ) {
	fwrite( STDERR, "blueprint-url is not pinned to a 40-character commit SHA: {$raw}\n" );
	exit( 1 );
}

$sha = $s[1];
echo "pinned SHA: {$sha}\n";

// 3. the blueprint must exist at that SHA and parse.
$path     = '.wordpress-org/playground/blueprint.json';
$contents = shell_exec( 'git show ' . escapeshellarg( $sha . ':' . $path ) . ' 2>/dev/null' );

if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
	$failures[] = "blueprint not found at {$sha}:{$path}";
} else {
	$blueprint = json_decode( $contents, true );
	if ( ! is_array( $blueprint ) ) {
		$failures[] = "blueprint at {$sha} is not valid JSON: " . json_last_error_msg();
	} else {
		echo "blueprint parses at the pinned SHA\n";

		// 4. the plugin source must be an absolute https URL.
		$url = $blueprint['plugins'][0]['url'] ?? '';
		if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://' ) ) {
			$failures[] = 'blueprint plugins[0].url is not an absolute https URL';
		} else {
			echo 'plugin source: ' . basename( $url ) . "\n";
		}
	}
}

// Optional: prove the published URL actually serves it.
if ( $network ) {
	$ctx  = stream_context_create( array( 'http' => array( 'timeout' => 20 ) ) );
	$body = @file_get_contents( $raw, false, $ctx );
	if ( false === $body ) {
		$failures[] = "pinned URL is not reachable: {$raw}";
	} else {
		echo "pinned URL reachable, " . strlen( $body ) . " bytes\n";
	}
}

foreach ( $failures as $failure ) {
	fwrite( STDERR, "FAIL: {$failure}\n" );
}

exit( $failures ? 1 : 0 );
