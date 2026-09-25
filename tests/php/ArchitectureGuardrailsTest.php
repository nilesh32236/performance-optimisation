<?php
/**
 * Executable architecture guardrails for P3-022 (issue #1615).
 *
 * The graph drift gate in ArchitectureInventoryTest proves the committed
 * inventory/graph match current PHP syntax, but nothing fails when a new
 * forbidden boundary edge, an unowned scheduler hook, or a newly introduced
 * static-state owner appears. These guards close that gap without touching
 * runtime behavior:
 *
 * 1. Forbidden boundary edges: every metrics.boundary_violations[] from/to
 *    pair in the committed DEPENDENCY-GRAPH.json must be listed in
 *    ALLOWED_BOUNDARY_EDGES. A new violation fails until it is either removed
 *    or explicitly ratcheted here with a follow-up item.
 * 2. Schedule ownership: every wppo_* hook literal passed to a WP-Cron,
 *    Scheduler, or Action Scheduler schedule/read/unschedule call under
 *    includes/ must be owned by the Job_Registry cron/AS union, and the
 *    Cron::clear_cron_jobs(), Deactivate, and uninstall.php teardown paths
 *    must keep covering that union.
 * 3. Static-state ownership: every static_state=true inventory node must map
 *    to Runtime_State::owners(), the Bfcache shutdown seam, or the
 *    BOUNDARIES.md cross-request/protected/request-memo classification.
 *
 * Constant-indirected schedules (self::AS_HOOK, ::DRIFT_PURGE_HOOK, ...) are
 * intentionally out of the token-scan scope; JobRegistryTest and
 * CronHookParityTest pin those contracts instead.
 *
 * @package PerformanceOptimise\Tests
 * @since   NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test-only local JSON reads and deterministic token scans.

use PerformanceOptimise\Inc\Job_Registry;
use PerformanceOptimise\Inc\Runtime_State;
use PHPUnit\Framework\TestCase;

/**
 * Fail-shut architecture guardrails over the committed graph evidence.
 *
 * @since NEXT
 */
final class ArchitectureGuardrailsTest extends TestCase {

	/**
	 * Explicitly ratcheted forbidden boundary edges (from=>to node pairs).
	 *
	 * Seeded from the current 20 metrics.boundary_violations[] entries. Each
	 * pair is a documented compatibility or lifecycle bridge; adding a new
	 * edge without ratcheting this list fails
	 * test_no_new_forbidden_boundary_edges().
	 *
	 * @var string[]
	 */
	private const ALLOWED_BOUNDARY_EDGES = array(
		'PerformanceOptimise\\Inc\\Abilities=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Asset_Manager=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Builder_Purge_Watcher=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Cache=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Critical_CSS=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Css_Combine=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\LiteSpeed_Integration=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Metabox=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Minify\\CSS=>PerformanceOptimise\\Inc\\Img_Converter',
		'PerformanceOptimise\\Inc\\Minify\\HTML=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Minify\\HTML=>PerformanceOptimise\\Inc\\Sandbox_Preview',
		'PerformanceOptimise\\Inc\\Minify\\Minify_Policy=>PerformanceOptimise\\Inc\\Cache',
		'PerformanceOptimise\\Inc\\Minify\\Minify_Policy=>PerformanceOptimise\\Inc\\LiteSpeed_Integration',
		'PerformanceOptimise\\Inc\\Minify\\Minify_Policy=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Rest=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Script_Strategy=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Script_Strategy=>PerformanceOptimise\\Inc\\Preload_Buffer_Coordinator',
		'PerformanceOptimise\\Inc\\Used_CSS=>PerformanceOptimise\\Inc\\Main',
		'PerformanceOptimise\\Inc\\Util=>PerformanceOptimise\\Inc\\CDN',
		'PerformanceOptimise\\Inc\\Util=>PerformanceOptimise\\Inc\\Woo_Detect',
	);

	/**
	 * Documented cross-request/persisted static-state owners (BOUNDARIES.md).
	 *
	 * Options, transients, files, queues, or explicitly bounded process
	 * caches remain owned with their existing write/invalidation contracts.
	 *
	 * @var string[]
	 */
	private const DOCUMENTED_CROSS_REQUEST_STATE = array(
		'Settings_Store',
		'Filesystem',
		'Url',
		'Cache',
		'Cache_Capacity',
		'Ccss_Store',
		'Img_Converter',
		'Log',
		'Scheduler',
		'CDN',
		'Used_CSS',
	);

	/**
	 * Documented protected static-state owners (BOUNDARIES.md).
	 *
	 * Protected ESI behavior and safety allowlists remain unchanged; no state
	 * extraction is implied.
	 *
	 * @var string[]
	 */
	private const DOCUMENTED_PROTECTED_STATE = array(
		'LiteSpeed_ESI',
		'Asset_Manager',
	);

	/**
	 * Classified request-memo residues (BOUNDARIES.md follow-up residues).
	 *
	 * Covers the named Admin_Notices, Lcp_Preload, OD_Bridge, and
	 * Sandbox_Preview residues plus the remaining Main/CSS/Image request
	 * memos, alongside the compatibility-layer request memos (delay-pattern
	 * regex cache, version probe, RUM digest, per-request purge dedup, and
	 * settings-snapshot helpers). Each is request-scoped with an existing
	 * reset or blog-keyed memo; none is silently cross-request state.
	 *
	 * @var string[]
	 */
	private const DOCUMENTED_REQUEST_MEMO_RESIDUE = array(
		'Admin_Notices',
		'Lcp_Preload',
		'OD_Bridge',
		'Sandbox_Preview',
		'Main',
		'Critical_CSS',
		'Ccss_Generator',
		'Image_Optimisation',
		'Script_Strategy',
		'Wp_Version',
		'Ai_Anomaly',
		'Builder_Purge_Watcher',
		'Util',
	);

	/**
	 * Schedule/read/unschedule call names whose wppo_* literals must be owned.
	 *
	 * Lowercase T_STRING spellings; matched against Scheduler wrappers, the
	 * wp_* cron API, and the as_* Action Scheduler API.
	 *
	 * @var string[]
	 */
	private const SCHEDULE_FUNCTIONS = array(
		'schedule_recurring_event',
		'schedule_single_event',
		'wp_schedule_event',
		'wp_schedule_single_event',
		'wp_next_scheduled',
		'wp_unschedule_event',
		'wp_unschedule_hook',
		'wp_clear_scheduled_hook',
		'as_enqueue_async_action',
		'as_schedule_single_action',
		'as_schedule_recurring_action',
		'as_schedule_cron_action',
		'as_has_scheduled_action',
		'as_unschedule_action',
		'as_unschedule_all_actions',
	);

	/**
	 * No new forbidden boundary edges beyond the ratcheted allowlist.
	 *
	 * @return void
	 */
	public function test_no_new_forbidden_boundary_edges(): void {
		$graph = $this->read_json( 'DEPENDENCY-GRAPH.json' );
		$this->assertArrayHasKey( 'metrics', $graph );
		$this->assertArrayHasKey( 'boundary_violations', $graph['metrics'] );
		$this->assertIsArray( $graph['metrics']['boundary_violations'] );

		$pairs = array();
		foreach ( $graph['metrics']['boundary_violations'] as $violation ) {
			$this->assertArrayHasKey( 'from', $violation );
			$this->assertArrayHasKey( 'to', $violation );
			$pairs[] = $violation['from'] . '=>' . $violation['to'];
		}

		$this->assertNotEmpty( $pairs, 'The boundary guard is vacuous without committed violations; check the generator output.' );
		$this->assertSame(
			array(),
			$this->unexplained_edges( $pairs, self::ALLOWED_BOUNDARY_EDGES ),
			'New forbidden boundary edges must be removed or explicitly ratcheted in ALLOWED_BOUNDARY_EDGES with a follow-up item.'
		);
	}

	/**
	 * Every scheduled hook literal is owned by the Job_Registry union.
	 *
	 * @return void
	 */
	public function test_every_scheduled_hook_literal_is_owned_by_job_registry(): void {
		$found = $this->scan_scheduled_hook_literals();
		$owned = array_merge(
			Job_Registry::all_cron_hooks(),
			Job_Registry::all_action_scheduler_hooks()
		);

		$this->assertNotEmpty( $found, 'The schedule guard is vacuous without scanned hook literals; check the token scan.' );
		$this->assertSame(
			array(),
			$this->unowned_hooks( $found, $owned ),
			'New wppo_* scheduled hooks must be registered in Job_Registry before they are scheduled.'
		);
	}

	/**
	 * Teardown paths keep covering the owned hook union.
	 *
	 * @return void
	 */
	public function test_teardown_paths_cover_the_owned_hook_union(): void {
		$root = dirname( __DIR__, 2 );

		$cron_source = file_get_contents( $root . '/includes/Scheduler/class-cron.php' );
		$this->assertNotFalse( $cron_source );
		$this->assertStringContainsString(
			'Job_Registry::all_cron_hooks()',
			(string) $cron_source,
			'Cron::clear_cron_jobs() must derive its unschedule list from Job_Registry::all_cron_hooks().'
		);

		$deactivate_source = file_get_contents( $root . '/includes/Core/class-deactivate.php' );
		$this->assertNotFalse( $deactivate_source );
		$this->assertStringContainsString(
			'Cron::clear_cron_jobs()',
			(string) $deactivate_source,
			'Deactivation must delegate WP-Cron teardown to Cron::clear_cron_jobs().'
		);
		$this->assertStringContainsString(
			'Job_Registry::all_action_scheduler_hooks()',
			(string) $deactivate_source,
			'Deactivation must delegate Action Scheduler teardown to the registry union.'
		);

		$uninstall_source = file_get_contents( $root . '/uninstall.php' );
		$this->assertNotFalse( $uninstall_source );
		$this->assertStringContainsString(
			'Job_Registry::all_cron_hooks()',
			(string) $uninstall_source,
			'uninstall.php must clear the registry WP-Cron union.'
		);
		$this->assertStringContainsString(
			'Job_Registry::all_action_scheduler_hooks()',
			(string) $uninstall_source,
			'uninstall.php must clear the registry Action Scheduler union.'
		);
	}

	/**
	 * No new unclassified static-state owners beyond the documented set.
	 *
	 * @return void
	 */
	public function test_no_new_unclassified_static_state_owners(): void {
		$inventory = $this->read_json( 'class-inventory.json' );
		$this->assertNotEmpty( $inventory );

		$static = array();
		foreach ( $inventory as $entry ) {
			if ( empty( $entry['static_state'] ) ) {
				continue;
			}
			$this->assertArrayHasKey( 'class', $entry );
			if ( is_string( $entry['class'] ) && '' !== $entry['class'] ) {
				$static[] = $entry['class'];
			}
		}
		sort( $static, SORT_STRING );

		$allowed = array_merge(
			array_keys( Runtime_State::owners() ),
			array( 'Bfcache' ),
			self::DOCUMENTED_CROSS_REQUEST_STATE,
			self::DOCUMENTED_PROTECTED_STATE,
			self::DOCUMENTED_REQUEST_MEMO_RESIDUE
		);

		$this->assertNotEmpty( $static, 'The static-state guard is vacuous without static_state inventory nodes.' );
		$this->assertSame(
			array(),
			$this->unclassified_static_owners( $static, $allowed ),
			'New static-state owners must be classified in BOUNDARIES.md and ratcheted here before they land.'
		);
	}

	/**
	 * Fixture: the boundary guard fails shut on an unlisted edge.
	 *
	 * @return void
	 */
	public function test_boundary_guard_fails_shut_on_fixture_violation(): void {
		$fixture = array(
			self::ALLOWED_BOUNDARY_EDGES[0],
			'PerformanceOptimise\\Inc\\Fake_Feature=>PerformanceOptimise\\Inc\\Main',
		);

		$this->assertSame(
			array( 'PerformanceOptimise\\Inc\\Fake_Feature=>PerformanceOptimise\\Inc\\Main' ),
			$this->unexplained_edges( $fixture, self::ALLOWED_BOUNDARY_EDGES ),
			'The boundary guard must report edges outside the allowlist.'
		);
		$this->assertSame(
			array(),
			$this->unexplained_edges( array( self::ALLOWED_BOUNDARY_EDGES[0] ), self::ALLOWED_BOUNDARY_EDGES ),
			'The boundary guard must pass allowlisted edges.'
		);
	}

	/**
	 * Fixture: the schedule guard fails shut on an unregistered hook.
	 *
	 * @return void
	 */
	public function test_schedule_guard_fails_shut_on_fixture_hook(): void {
		$owned   = array_merge(
			Job_Registry::all_cron_hooks(),
			Job_Registry::all_action_scheduler_hooks()
		);
		$fixture = array( $owned[0], 'wppo_fake_hook' );

		$this->assertSame(
			array( 'wppo_fake_hook' ),
			$this->unowned_hooks( $fixture, $owned ),
			'The schedule guard must report hooks outside the Job_Registry union.'
		);
		$this->assertSame(
			array(),
			$this->unowned_hooks( array( $owned[0] ), $owned ),
			'The schedule guard must pass owned hooks.'
		);
	}

	/**
	 * Fixture: the static-state guard fails shut on an unclassified owner.
	 *
	 * @return void
	 */
	public function test_static_state_guard_fails_shut_on_fixture_owner(): void {
		$allowed = array_merge(
			array( 'RUM' ),
			array( 'Bfcache' ),
			self::DOCUMENTED_CROSS_REQUEST_STATE
		);
		$fixture = array( 'RUM', 'Fake_Owner' );

		$this->assertSame(
			array( 'Fake_Owner' ),
			$this->unclassified_static_owners( $fixture, $allowed ),
			'The static-state guard must report owners outside the documented set.'
		);
		$this->assertSame(
			array(),
			$this->unclassified_static_owners( array( 'RUM' ), $allowed ),
			'The static-state guard must pass documented owners.'
		);
	}

	/**
	 * Report boundary pairs outside the allowlist (pure data, no I/O).
	 *
	 * @since NEXT
	 * @param string[] $pairs     From=>to pairs under test.
	 * @param string[] $allowlist Ratcheted pairs.
	 * @return string[] Unexplained pairs, in input order.
	 */
	private function unexplained_edges( array $pairs, array $allowlist ): array {
		$allowed     = array_fill_keys( $allowlist, true );
		$unexplained = array();
		foreach ( $pairs as $pair ) {
			if ( ! isset( $allowed[ $pair ] ) ) {
				$unexplained[] = $pair;
			}
		}
		return $unexplained;
	}

	/**
	 * Report scheduled hooks outside the owned union (pure data, no I/O).
	 *
	 * @since NEXT
	 * @param string[] $found Scanned hook names.
	 * @param string[] $owned Registry union.
	 * @return string[] Unowned hook names, sorted.
	 */
	private function unowned_hooks( array $found, array $owned ): array {
		$owned_map = array_fill_keys( $owned, true );
		$unowned   = array();
		foreach ( $found as $hook ) {
			if ( ! isset( $owned_map[ $hook ] ) ) {
				$unowned[] = $hook;
			}
		}
		sort( $unowned, SORT_STRING );
		return $unowned;
	}

	/**
	 * Report static-state owners outside the documented set (pure data).
	 *
	 * @since NEXT
	 * @param string[] $owners  Short class names under test.
	 * @param string[] $allowed Documented short class names.
	 * @return string[] Unclassified owners, sorted.
	 */
	private function unclassified_static_owners( array $owners, array $allowed ): array {
		$allowed_map  = array_fill_keys( $allowed, true );
		$unclassified = array();
		foreach ( $owners as $owner ) {
			if ( ! isset( $allowed_map[ $owner ] ) ) {
				$unclassified[] = $owner;
			}
		}
		sort( $unclassified, SORT_STRING );
		return array_values( array_unique( $unclassified, SORT_STRING ) );
	}

	/**
	 * Token-scan includes/ for wppo_* hook literals in schedule calls.
	 *
	 * Uses token_get_all so hook names are found regardless of line breaks
	 * or argument position (wp_schedule_single_event takes the hook second).
	 * Class-constant indirections (self::AS_HOOK) carry no literal and are
	 * pinned by JobRegistryTest/CronHookParityTest instead.
	 *
	 * @since NEXT
	 * @return string[] Sorted unique hook names.
	 */
	private function scan_scheduled_hook_literals(): array {
		$root  = dirname( __DIR__, 2 );
		$found = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/includes', FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}
			foreach ( $this->scheduled_hook_literals_in_file( $file->getPathname() ) as $hook ) {
				$found[ $hook ] = true;
			}
		}

		$hooks = array_keys( $found );
		sort( $hooks, SORT_STRING );
		return $hooks;
	}

	/**
	 * Extract wppo_* literals from top-level schedule-call arguments.
	 *
	 * @since NEXT
	 * @param string $path File to scan.
	 * @return string[] Unique hook names in file order.
	 */
	private function scheduled_hook_literals_in_file( string $path ): array {
		$raw = file_get_contents( $path );
		$this->assertNotFalse( $raw, "Unable to read {$path} for the schedule scan." );
		$tokens = token_get_all( (string) $raw );
		$hooks  = array();
		$total  = count( $tokens );

		for ( $i = 0; $i < $total; ++$i ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			if ( ! in_array( strtolower( $token[1] ), self::SCHEDULE_FUNCTIONS, true ) ) {
				continue;
			}
			$prev = $this->previous_significant_token( $tokens, $i - 1 );
			if ( is_array( $prev ) && T_FUNCTION === $prev[0] ) {
				continue;
			}
			foreach ( $this->top_level_string_arguments( $tokens, $i ) as $literal ) {
				if ( str_starts_with( $literal, 'wppo_' ) ) {
					$hooks[] = $literal;
				}
			}
		}

		return array_values( array_unique( $hooks, SORT_STRING ) );
	}

	/**
	 * Fetch the previous non-whitespace/comment token.
	 *
	 * @since NEXT
	 * @param array<int,mixed> $tokens Token stream.
	 * @param int              $index  Start index (inclusive).
	 * @return mixed Token or null when exhausted.
	 */
	private function previous_significant_token( array $tokens, int $index ) {
		for ( $i = $index; $i >= 0; --$i ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return $token;
		}
		return null;
	}

	/**
	 * Collect string literals in the top-level argument list of a call.
	 *
	 * Starts at a function-name token, opens at the next significant "(",
	 * and records T_CONSTANT_ENCAPSED_STRING values at depth 1 only, so
	 * nested calls and closures cannot leak unrelated literals.
	 *
	 * @since NEXT
	 * @param array<int,mixed> $tokens Token stream.
	 * @param int              $name_index Index of the function-name token.
	 * @return string[] Unquoted literal values.
	 */
	private function top_level_string_arguments( array $tokens, int $name_index ): array {
		$total = count( $tokens );
		$open  = null;
		for ( $i = $name_index + 1; $i < $total; ++$i ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			if ( '(' === $token ) {
				$open = $i;
			}
			break;
		}
		if ( null === $open ) {
			return array();
		}

		$literals = array();
		$depth    = 0;
		for ( $i = $open; $i < $total; ++$i ) {
			$token = $tokens[ $i ];
			if ( '(' === $token || '[' === $token || '{' === $token ) {
				++$depth;
				continue;
			}
			if ( ')' === $token || ']' === $token || '}' === $token ) {
				--$depth;
				if ( $depth <= 0 ) {
					break;
				}
				continue;
			}
			if ( 1 === $depth && is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$literals[] = stripcslashes( substr( $token[1], 1, -1 ) );
			}
		}
		return $literals;
	}

	/**
	 * Read one committed architecture JSON artifact.
	 *
	 * @since NEXT
	 * @param string $filename Artifact filename.
	 * @return array<string|int,mixed>
	 */
	private function read_json( string $filename ): array {
		$path = dirname( __DIR__, 2 ) . '/docs/architecture/' . $filename;
		$this->assertFileExists( $path );
		$raw = file_get_contents( $path );
		$this->assertNotFalse( $raw );
		$data = json_decode( (string) $raw, true );
		$this->assertIsArray( $data );

		return $data;
	}
}
