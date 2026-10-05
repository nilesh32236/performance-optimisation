<?php
/**
 * Verify the published Playground demo points at a blueprint that exists.
 *
 * The published docs/site/playground.html ships a code-executing link whose
 * blueprint-url pins a commit SHA on raw.githubusercontent.com. Nothing in CI
 * checks that pin, so a bad or stale SHA is only discovered by a visitor whose
 * browser runs whatever that URL serves.
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

const EXPECTED_OWNER = 'nilesh32236';
const EXPECTED_REPO  = 'performance-optimisation';


const PLUGIN_RELEASE_URL_PATTERN = '#^https://github\.com/'
	. '(?P<owner>' . EXPECTED_OWNER . ')/(?P<repo>' . EXPECTED_REPO . ')'
	. '/releases/download/v(?P<version>[0-9]+\.[0-9]+\.[0-9]+)'
	. '/performance-optimisation-(?P=version)\.zip$#';
// NOT a class: this is a plain CLI script, so `self::` would fatal with
// "Cannot access self when no class scope is active" - a fatal on the failure
// path, which is the worst place for one.
// The plugin asset must live in THIS project's GitHub releases at a v-tagged
// version, and the filename version must equal the tag. Anchored, so a substring
// elsewhere in a URL cannot satisfy it and a scheme prefix is not mistaken for
// a check.


// phpcs:disable WordPress.WP.AlternativeFunctions -- CI development script: it runs
// outside WordPress and must use native filesystem, shell and output APIs, which is
// the same scope generate-class-inventory.php disables.
// phpcs:disable WordPress.Security.EscapeOutput -- CLI stdout, not HTML. Every value
// printed is either a hex SHA matched from a 40-character regex or a basename().
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- the
// check is "does this commit exist in git"; there is no WordPress API for that.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- the optional --network
// probe turns a fetch warning into false on purpose, to report it as a failure.

$root      = dirname( __DIR__ );
$page_path = $root . '/docs/site/playground.html';
$network   = in_array( '--network', $argv, true );
$failures  = array();

if ( ! is_readable( $page_path ) ) {
	fwrite( STDERR, "cannot read docs/site/playground.html\n" );
	exit( 1 );
}

$html = (string) file_get_contents( $page_path );

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
$blueprint_path = '.wordpress-org/playground/blueprint.json';

// CI checks out at depth 1 (actions/checkout's default), and the pinned SHA is
// usually an ancestor, so `git show` would fail there even when the pin is
// perfectly good. Fetch that one object before asking for it. A shallow local
// clone here hits the same path, which is how this was found.
// shell_exec() returns NULL on success and a non-empty string on failure, so
// this test must be a truthiness test. `0 !== shell_exec(...)` was true for
// EVERY successful exit - null !== 0 - and so fetched on every single run.
$have_object = '' === shell_exec( 'git cat-file -e ' . escapeshellarg( $sha ) . ' 2>/dev/null' );
if ( ! $have_object ) {
	shell_exec(
		'git fetch --quiet --depth=1 origin ' . escapeshellarg( $sha ) . ' 2>/dev/null'
	);
}

$contents = shell_exec( 'git show ' . escapeshellarg( $sha . ':' . $blueprint_path ) . ' 2>/dev/null' );

if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
	// is_string() is checked FIRST and short-circuits, so trim() is never
	// reached with null. Under strict_types=1, trim( null ) is an uncaught
	// TypeError that replaces the designed FAIL line with a stack trace.
	$failures[] = "blueprint not found at {$sha}:{$blueprint_path}";
} else {
	$blueprint = json_decode( $contents, true );
	if ( ! is_array( $blueprint ) ) {
		$failures[] = "blueprint at {$sha} is not valid JSON: " . json_last_error_msg();
	} else {
		echo "blueprint parses at the pinned SHA\n";

		// 4. the plugin source must be THIS project's release asset, not merely
		// a string beginning with https://. A scheme prefix is not a check:
		// any https URL, including one on an unrelated host serving something
		// else, passed the old test.
		$url      = $blueprint['plugins'][0]['url'] ?? '';
		$expected = PLUGIN_RELEASE_URL_PATTERN;
		if ( ! is_string( $url ) ) {
			$failures[] = 'blueprint plugins[0].url is not a string';
		} elseif ( ! preg_match( $expected, $url, $m ) ) {
			$failures[] = sprintf(
				'blueprint plugins[0].url is not this project\'s release asset. Expected '
				. 'https://github.com/%s/%s/releases/download/v<version>/performance-optimisation-<version>.zip, got: %s',
				EXPECTED_OWNER,
				EXPECTED_REPO,
				$url
			);
		} else {
			echo 'plugin source: ' . basename( $url ) . "\n";
		}
	}
}

// Stale-pin detection. The docblock promises this script catches a STALE sha,
// but existence alone does not: the pinned commit can exist and still hold an
// older blueprint than this tree, which is precisely the case a visitor hits
// after the blueprint changes and the pin is not moved with it.
$head_blueprint = @file_get_contents( __DIR__ . '/../' . $blueprint_path );

if ( is_string( $contents ) && ! is_string( $head_blueprint ) ) {
	$failures[] = "cannot read {$blueprint_path} from the working tree";
} elseif ( ! is_string( $contents ) ) {
	// $contents is null whenever `git show` produced nothing and the failure is
	// ALREADY recorded above. Falling through to trim() on it raises an uncaught
	// TypeError under strict_types=1, replacing this script's own FAIL line with
	// a stack trace - the one diagnostic it exists to print is the one thing a
	// fatal destroys.
	echo "skipping stale-pin comparison: no pinned blueprint to compare\n";
} elseif ( ! is_string( $head_blueprint ) ) {
	$failures[] = "cannot read {$blueprint_path} from the working tree";
} elseif ( trim( $head_blueprint ) === trim( $contents ) ) {
	echo "pinned blueprint matches the working tree\n";
} else {
	$pinned_url  = json_decode( $contents, true )['plugins'][0]['url'] ?? '(unreadable)';
	$working_url = json_decode( $head_blueprint, true )['plugins'][0]['url'] ?? '(unreadable)';

	$failures[] = sprintf(
		'STALE PIN: %s at %s serves %s, but the working tree serves %s. '
		. 'Move the pin in docs/site/playground.html to a commit carrying the current blueprint.',
		$blueprint_path,
		substr( $sha, 0, 8 ),
		basename( (string) $pinned_url ),
		basename( (string) $working_url )
	);
}


// The release ZIP's byte size is quoted in prose in more than one place. Two
// hand-copied numbers that drift apart are worse than one number, because a
// reader has no way to tell which is current. Assert every in-repo copy agrees.
$sized = array();
foreach ( array( 'docs/growth/claims.md', '.wordpress-org/playground/README.md' ) as $doc ) {
	$doc_path = __DIR__ . '/../' . $doc;
	if ( ! is_readable( $doc_path ) ) {
		continue;
	}
	$text = (string) file_get_contents( $doc_path );
	// Only lines that actually mention the release asset. Matching ANY large
	// number in the file produced a false positive on an unrelated byte count.
	foreach ( preg_split( '/\R/', $text ) as $line ) {
		// Match on either the literal asset name or the release-ZIP wording, so
		// BOTH documents are actually scanned. Keying on the literal filename
		// alone found only one of them and the cross-document comparison below
		// silently compared a set of one - reporting 'consistent' about nothing.
		$is_asset_line = preg_match( '/performance-optimisation-[0-9]+\.[0-9]+\.[0-9]+\.zip/', $line )
			|| preg_match( '/release ZIP/i', $line );
		if ( ! $is_asset_line ) {
			continue;
		}
		if ( preg_match_all( '/\b\d{1,3}(?:,\d{3}){2,}\b/', $line, $m ) ) {
			foreach ( $m[0] as $n ) {
				$sized[ $doc ][] = str_replace( ',', '', $n );
			}
		}
	}
}
$flat = array_unique( array_merge( array(), ...array_values( $sized ) ) );
if ( count( $flat ) > 1 ) {
	$failures[] = sprintf(
		'The release ZIP size is quoted inconsistently across documentation: %s. '
		. 'Re-measure and correct every copy, or quote none of them.',
		implode( ' vs ', array_map( static fn( $n ) => number_format( (int) $n ), $flat ) )
	);
} elseif ( 1 === count( $flat ) ) {
	echo 'release ZIP size quoted consistently: ' . number_format( (int) $flat[0] ) . " bytes\n";
}

// Optional: prove the published URL actually serves it.
if ( $network ) {
	$ctx  = stream_context_create( array( 'http' => array( 'timeout' => 20 ) ) );
	$body = @file_get_contents( $raw, false, $ctx );
	if ( false === $body ) {
		$failures[] = "pinned URL is not reachable: {$raw}";
	} else {
		echo 'pinned URL reachable, ' . strlen( $body ) . " bytes\n";
	}
}

foreach ( $failures as $failure ) {
	fwrite( STDERR, "FAIL: {$failure}\n" );
}

exit( $failures ? 1 : 0 );
