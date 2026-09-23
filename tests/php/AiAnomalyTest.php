<?php
/**
 * Tests for AI anomaly detection ownership (issue #1546, ARCH-010).
 *
 * Ai_Anomaly owns the anomaly-detection cluster extracted verbatim from
 * AI_Adaptive (threshold/band math, breach/alarm option state, RUM digest
 * + blog-aware memos, trend sampling, detect_anomalies(), CSS-refresh
 * reactions, deploy notes); AI_Adaptive keeps thin same-signature static
 * proxies. These tests pin owner behavior plus proxy parity so the split
 * cannot drift.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\AI_Adaptive;
use PerformanceOptimise\Inc\Ai_Anomaly;
use PerformanceOptimise\Inc\RUM;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Anomaly-ownership parity tests for issue #1546.
 *
 * @package PerformanceOptimise\Tests
 */
class AiAnomalyTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * In-memory transients store.
	 *
	 * @var array
	 */
	private $transients = array();

	/**
	 * Install Brain Monkey stubs.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		RUM::clear_field_lcp_cache();
		Ai_Anomaly::reset_rum_anomaly_digest_memo();
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'esc_url_raw',
				'sanitize_text_field',
				'apply_filters',
				'is_multisite',
				'get_current_blog_id',
				'home_url',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $exp = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$this->transients[ $key ] = $value;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return 'http://example.com' . $path;
			}
		);
	}

	/**
	 * Build a single-key trends map with baseline samples + N degraded trailing windows.
	 *
	 * @param string $metric Metric key ('lcp'|'cls').
	 * @param int    $baseline_count Number of baseline samples.
	 * @param float  $baseline_value Baseline sample value.
	 * @param float  $degraded_value Degraded trailing-window value.
	 * @param int    $degraded_count Number of degraded trailing windows.
	 * @return array Trends map.
	 */
	private function make_trends( string $metric, int $baseline_count, float $baseline_value, float $degraded_value, int $degraded_count = 3 ): array {
		$snapshots = array();
		for ( $i = 0; $i < $baseline_count; $i++ ) {
			$snapshots[] = array( $metric => $baseline_value );
		}
		for ( $i = 0; $i < $degraded_count; $i++ ) {
			$snapshots[] = array( $metric => $degraded_value );
		}
		return array( 'key1' => $snapshots );
	}

	/**
	 * Build a RUM aggregate with a single path carrying n samples at an average.
	 *
	 * @param string $metric Metric key ('lcp'|'cls').
	 * @param int    $n Sample count.
	 * @param float  $avg Sample average.
	 * @return array RUM aggregate.
	 */
	private function make_rum( string $metric, int $n, float $avg ): array {
		return array(
			'2026-09-01' => array(
				'/' => array(
					$metric => array(
						'n'   => $n,
						'sum' => (float) $n * $avg,
					),
				),
			),
		);
	}

	/**
	 * Build a multi-date RUM aggregate for digest tests.
	 *
	 * Each window is array( date, path, n, avg ).
	 *
	 * @param string $metric Metric key ('lcp'|'cls').
	 * @param array  $windows Window rows.
	 * @return array RUM aggregate.
	 */
	private function make_digest_rum( string $metric, array $windows ): array {
		$rum = array();
		foreach ( $windows as $w ) {
			list( $date, $path, $n, $avg ) = $w;
			if ( ! isset( $rum[ $date ] ) ) {
				$rum[ $date ] = array();
			}
			if ( ! isset( $rum[ $date ][ $path ] ) ) {
				$rum[ $date ][ $path ] = array();
			}
			$rum[ $date ][ $path ][ $metric ] = array(
				'n'   => $n,
				'sum' => (float) $n * $avg,
			);
		}
		return $rum;
	}

	/**
	 * Seed wppo_settings with RUM collection enabled.
	 *
	 * @return void
	 */
	private function seed_rum_enabled(): void {
		$this->options['wppo_settings'] = array(
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
	}

	/**
	 * Owner and proxy agree on detection verdicts.
	 *
	 * Given persisted regressions with corroborating field data When
	 * evaluated via Ai_Anomaly and via the AI_Adaptive proxy Then both
	 * return the identical payload; undersampled input stays silent on
	 * both paths.
	 *
	 * @return void
	 */
	public function test_proxy_parity_detect_anomalies(): void {
		$this->install_stubs();
		$trends = $this->make_trends( 'lcp', 10, 2000.0, 3000.0 );
		$rum    = $this->make_rum( 'lcp', 12, 2600.0 );

		$owner = Ai_Anomaly::detect_anomalies( $trends, $rum, 1700000000 );
		$this->assertCount( 1, $owner );
		$this->assertSame( 'lcp', $owner[0]['metric'] );

		unset( $this->options['wppo_ai_anomaly_last_alarm'] );
		$proxy = AI_Adaptive::detect_anomalies( $trends, $rum, 1700000000 );
		$this->assertSame( $owner, $proxy );

		unset( $this->options['wppo_ai_anomaly_last_alarm'] );
		$thin = $this->make_trends( 'lcp', 4, 2000.0, 3000.0 );
		$this->assertSame( array(), Ai_Anomaly::detect_anomalies( $thin, $rum, 1700000000 ) );
		$this->assertSame( array(), AI_Adaptive::detect_anomalies( $thin, $rum, 1700000000 ) );
	}

	/**
	 * Moving-band math matches the documented arm shapes.
	 *
	 * Given a flat 2000ms LCP window When banded Then upper is the
	 * +30% relative arm; given a flat CLS window Then upper is the
	 * +0.05 absolute arm; given empty input Then the band is zeroed.
	 *
	 * @return void
	 */
	public function test_moving_band_math(): void {
		$this->install_stubs();
		$band_method = new \ReflectionMethod( Ai_Anomaly::class, 'moving_band' );

		$lcp = $band_method->invoke( null, array_fill( 0, 10, 2000.0 ), 'lcp' );
		$this->assertEqualsWithDelta( 2000.0, $lcp['mean'], 0.001 );
		$this->assertEqualsWithDelta( 0.0, $lcp['std'], 0.001 );
		$this->assertEqualsWithDelta( 2600.0, $lcp['upper'], 0.001 );

		$cls = $band_method->invoke( null, array_fill( 0, 10, 0.05 ), 'cls' );
		$this->assertEqualsWithDelta( 0.05, $cls['mean'], 0.0001 );
		$this->assertEqualsWithDelta( 0.10, $cls['upper'], 0.0001 );

		$zero = $band_method->invoke( null, array(), 'lcp' );
		$this->assertSame( 0.0, $zero['mean'] );
		$this->assertSame( 0.0, $zero['upper'] );
	}

	/**
	 * Breach and alarm state round-trip through the owned options.
	 *
	 * Given breach rows and an alarm timestamp When read back Then the
	 * stored shapes match and the proxy sees the same rows; breach
	 * storage stays bounded.
	 *
	 * @return void
	 */
	public function test_breach_alarm_lifecycle(): void {
		$this->install_stubs();

		$this->assertSame( array(), Ai_Anomaly::get_breach_state() );
		$this->assertSame( 0, Ai_Anomaly::get_last_anomaly_alarm() );

		Ai_Anomaly::set_breach_state(
			array(
				'key1_mobile' => array(
					'metric'      => 'lcp',
					'baseline'    => 2000.0,
					'current'     => 3000.0,
					'breached_at' => 1700000000,
				),
			)
		);
		$state = Ai_Anomaly::get_breach_state();
		$this->assertArrayHasKey( 'key1_mobile', $state );
		$this->assertSame( $state, AI_Adaptive::get_breach_state() );
		$this->assertArrayHasKey( 'wppo_ai_anomaly_breach_state', $this->options );

		Ai_Anomaly::set_last_anomaly_alarm( 1700000000 );
		$this->assertSame( 1700000000, Ai_Anomaly::get_last_anomaly_alarm() );
		$this->assertSame( 1700000000, AI_Adaptive::get_last_anomaly_alarm() );

		// Bounded storage: oversized breach maps are trimmed on write.
		$big = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$big[ 'key' . $i ] = array(
				'metric'      => 'lcp',
				'baseline'    => 2000.0,
				'current'     => 3000.0,
				'breached_at' => 1700000000,
			);
		}
		Ai_Anomaly::set_breach_state( $big );
		$this->assertLessThanOrEqual( 20, count( Ai_Anomaly::get_breach_state() ) );
	}

	/**
	 * Cooldown gating suppresses repeat banners until the window elapses.
	 *
	 * Given a firing regression When evaluated twice in-window Then the
	 * second verdict is empty; after 7 days + 1s it fires again; with
	 * no prior alarm the gate is open.
	 *
	 * @return void
	 */
	public function test_cooldown_gates_detection(): void {
		$this->install_stubs();
		$trends = $this->make_trends( 'lcp', 10, 2000.0, 3000.0 );
		$rum    = $this->make_rum( 'lcp', 12, 2600.0 );
		$now    = 1700000000;

		$this->assertTrue( Ai_Anomaly::is_anomaly_cooled_down( $now ) );

		$first = Ai_Anomaly::detect_anomalies( $trends, $rum, $now );
		$this->assertCount( 1, $first );

		$this->assertFalse( Ai_Anomaly::is_anomaly_cooled_down( $now ) );
		$this->assertSame( array(), Ai_Anomaly::detect_anomalies( $trends, $rum, $now ) );
		$this->assertSame( array(), Ai_Anomaly::detect_anomalies( $trends, $rum, $now + ( 6 * DAY_IN_SECONDS ) ) );

		$this->assertTrue( Ai_Anomaly::is_anomaly_cooled_down( $now + ( 7 * DAY_IN_SECONDS ) + 1 ) );
		$after = Ai_Anomaly::detect_anomalies( $trends, $rum, $now + ( 7 * DAY_IN_SECONDS ) + 1 );
		$this->assertCount( 1, $after );
	}

	/**
	 * Injected-arg digests are deterministic and honor the tolerance band.
	 *
	 * Given the same RUM fixture When digested twice Then the bytes are
	 * identical; a +32.5% shift stays silent inside the band while a
	 * +40% shift alerts with an enriched payload.
	 *
	 * @return void
	 */
	public function test_digest_determinism_and_tolerance(): void {
		$this->install_stubs();
		$now = 1700000000;

		$inside = $this->make_digest_rum(
			'lcp',
			array(
				array( '2026-09-01', '/pricing/', 12, 2000.0 ),
				array( '2026-09-10', '/pricing/', 12, 2650.0 ),
			)
		);
		$this->assertSame( array(), Ai_Anomaly::get_rum_anomaly_digest( $inside, $now ) );

		$outside = $this->make_digest_rum(
			'lcp',
			array(
				array( '2026-09-01', '/pricing/', 12, 2000.0 ),
				array( '2026-09-10', '/pricing/', 12, 2800.0 ),
			)
		);
		$first   = Ai_Anomaly::get_rum_anomaly_digest( $outside, $now );
		$this->assertCount( 1, $first );
		$this->assertSame( 'lcp', $first[0]['metric'] );
		$this->assertEqualsWithDelta( 40.0, $first[0]['change_pct'], 0.001 );

		unset( $this->options['wppo_ai_anomaly_last_alarm'] );
		$second = Ai_Anomaly::get_rum_anomaly_digest( $outside, $now );
		$this->assertSame( $first, $second );

		unset( $this->options['wppo_ai_anomaly_last_alarm'] );
		$this->assertSame( $first, AI_Adaptive::get_rum_anomaly_digest( $outside, $now ) );
	}

	/**
	 * Live-path digest memo is blog-aware.
	 *
	 * Given a firing RUM fixture on blog 1 When the fixture changes Then
	 * the live digest still returns the memoized verdict; on blog 2 it
	 * recomputes; after reset it recomputes on blog 1 too.
	 *
	 * @return void
	 */
	public function test_digest_memo_multisite_isolation(): void {
		$this->install_stubs();
		$this->seed_rum_enabled();
		$blog = 1;
		Functions\when( 'get_current_blog_id' )->alias(
			function () use ( &$blog ) {
				return $blog;
			}
		);

		$firing                               = $this->make_digest_rum(
			'lcp',
			array(
				array( '2026-09-01', '/pricing/', 12, 2000.0 ),
				array( '2026-09-10', '/pricing/', 12, 2800.0 ),
			)
		);
		$this->options['wppo_web_vitals_rum'] = $firing;
		RUM::clear_field_lcp_cache();

		$first = Ai_Anomaly::get_rum_anomaly_digest();
		$this->assertCount( 1, $first );

		// Fixture changes mid-request: the blog-1 memo still wins.
		$this->options['wppo_web_vitals_rum'] = array();
		RUM::clear_field_lcp_cache();
		$this->assertSame( $first, Ai_Anomaly::get_rum_anomaly_digest() );

		// Blog 2 recomputes against the current (empty) fixture.
		$blog = 2;
		$this->assertSame( array(), Ai_Anomaly::get_rum_anomaly_digest() );

		// Reset drops the memo; blog 1 recomputes empty too.
		$blog = 1;
		Ai_Anomaly::reset_rum_anomaly_digest_memo();
		$this->assertSame( array(), Ai_Anomaly::get_rum_anomaly_digest() );
	}

	/**
	 * Deploy notes round-trip and annotate nearby breaches.
	 *
	 * Given a stored note When looked up near its timestamp Then the
	 * note text is returned; far lookups return an empty string.
	 *
	 * @return void
	 */
	public function test_deploy_notes_roundtrip(): void {
		$this->install_stubs();

		$this->assertSame( array(), Ai_Anomaly::get_deploy_notes() );
		$this->assertTrue( Ai_Anomaly::add_deploy_note( 'shipped hero image', 1700000000 ) );

		$notes = Ai_Anomaly::get_deploy_notes();
		$this->assertCount( 1, $notes );
		$this->assertSame( $notes, AI_Adaptive::get_deploy_notes() );

		$this->assertSame( 'shipped hero image', Ai_Anomaly::find_deploy_note_near( 1700000000 + DAY_IN_SECONDS ) );
		$this->assertSame( '', Ai_Anomaly::find_deploy_note_near( 1700000000 + ( 30 * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Provisional state agrees between owner and proxy.
	 *
	 * Given thin trend history When read Then both paths report
	 * trends_thin; given satisfied floors Then both report ready.
	 *
	 * @return void
	 */
	public function test_provisional_state_parity(): void {
		$this->install_stubs();
		Util::clear_settings_cache();

		$thin  = $this->make_trends( 'lcp', 4, 2000.0, 3000.0, 1 );
		$state = Ai_Anomaly::get_anomaly_provisional_state( $thin, $this->make_rum( 'lcp', 50, 2600.0 ), 1700000000 );
		$this->assertTrue( $state['provisional'] );
		$this->assertSame( 'trends_thin', $state['reason'] );
		$this->assertSame( $state, AI_Adaptive::get_anomaly_provisional_state( $thin, $this->make_rum( 'lcp', 50, 2600.0 ), 1700000000 ) );

		$ready = Ai_Anomaly::get_anomaly_provisional_state( $this->make_trends( 'lcp', 10, 2000.0, 3000.0 ), $this->make_rum( 'lcp', 12, 2600.0 ), 1700000000 );
		$this->assertFalse( $ready['provisional'] );
		$this->assertSame( 'ready', $ready['reason'] );
	}

	/**
	 * Build a resolvable LCP anomaly fixture.
	 *
	 * @return array LCP anomaly array.
	 */
	private function make_resolvable_lcp_anomaly(): array {
		return array(
			'key'        => md5( 'http://example.com/' ) . '_mobile',
			'metric'     => 'lcp',
			'baseline'   => 2000.0,
			'current'    => 3000.0,
			'change_pct' => 50.0,
		);
	}

	/**
	 * Stub the scheduler + post-resolution helpers for CSS-refresh tests.
	 *
	 * @param int   $post_id Post ID returned by url_to_postid().
	 * @param array $enqueued Captured enqueue calls (by reference).
	 * @return void
	 */
	private function stub_css_refresh_scheduler( int $post_id, array &$enqueued ): void {
		Functions\when( 'url_to_postid' )->justReturn( $post_id );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_enqueue_async_action' )->alias(
			static function ( $hook, $args = array(), $group = '' ) use ( &$enqueued ) {
				$enqueued[] = array( $hook, $args, $group );
				return 99;
			}
		);
	}

	/**
	 * Seed wppo_settings with the CSS-refresh opt-in state.
	 *
	 * @param bool $enabled Whether the opt-in toggle is on.
	 * @return void
	 */
	private function seed_css_refresh_settings( bool $enabled ): void {
		$this->options['wppo_settings'] = array(
			'ai_adaptive'       => array(
				'enabled'                       => true,
				'css_refresh_on_lcp_regression' => $enabled,
				'css_refresh_cooldown_days'     => 7,
			),
			'performance_audit' => array( 'rum_enabled' => true ),
		);
		Util::clear_settings_cache();
		Util::reset_cached_home_urls();
	}

	/**
	 * An opted-in LCP regression queues one job with a snapshot.
	 *
	 * Runs in a separate process so the Action Scheduler stubs cannot leak
	 * into later test classes (a Brain Monkey stub shell throws
	 * MissingFunctionExpectations once its test ends, which would flip
	 * function_exists-guarded branches elsewhere).
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_css_refresh_queue_and_snapshot(): void {
		$this->install_stubs();
		$this->seed_css_refresh_settings( true );
		$enqueued = array();
		$this->stub_css_refresh_scheduler( 123, $enqueued );

		$result = Ai_Anomaly::maybe_queue_css_refresh( $this->make_resolvable_lcp_anomaly(), 1700000000 );
		$this->assertTrue( $result['queued'] );
		$this->assertSame( 'queued', $result['reason'] );
		$this->assertSame( 'http://example.com/', $result['url'] );
		$this->assertSame( 123, $result['post_id'] );
		$this->assertCount( 1, $enqueued );
		$this->assertSame( 'wppo_used_css_generate', $enqueued[0][0] );

		$snapshot = Ai_Anomaly::get_css_refresh_snapshot( 'http://example.com/' );
		$this->assertTrue( $snapshot['queued'] );
		$this->assertSame( 2000.0, $snapshot['before_lcp'] );
		$this->assertSame( 3000.0, $snapshot['current_lcp'] );

		// The proxy sees the same snapshot row.
		$this->assertSame( $snapshot, AI_Adaptive::get_css_refresh_snapshot( 'http://example.com/' ) );
	}

	/**
	 * A second trigger inside the cooldown queues nothing.
	 *
	 * Isolated in a separate process so the scheduler stubs cannot leak
	 * (see test_css_refresh_queue_and_snapshot).
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_css_refresh_cooldown_suppresses_second_job(): void {
		$this->install_stubs();
		$this->seed_css_refresh_settings( true );
		$enqueued = array();
		$this->stub_css_refresh_scheduler( 123, $enqueued );

		$first = Ai_Anomaly::maybe_queue_css_refresh( $this->make_resolvable_lcp_anomaly(), 1700000000 );
		$this->assertTrue( $first['queued'] );

		$second = Ai_Anomaly::maybe_queue_css_refresh( $this->make_resolvable_lcp_anomaly(), 1700000000 + 3600 );
		$this->assertFalse( $second['queued'] );
		$this->assertSame( 'cooldown', $second['reason'] );
		$this->assertCount( 1, $enqueued );
	}

	/**
	 * Toggle-off never queues and stays suggestion-only.
	 *
	 * Isolated in a separate process so the scheduler stubs cannot leak
	 * (see test_css_refresh_queue_and_snapshot).
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_css_refresh_toggle_off_queues_nothing(): void {
		$this->install_stubs();
		$this->seed_css_refresh_settings( false );
		$enqueued = array();
		$this->stub_css_refresh_scheduler( 123, $enqueued );

		$result = Ai_Anomaly::maybe_queue_css_refresh( $this->make_resolvable_lcp_anomaly(), 1700000000 );
		$this->assertFalse( $result['queued'] );
		$this->assertSame( 'opt-out', $result['reason'] );
		$this->assertCount( 0, $enqueued );
	}
}
