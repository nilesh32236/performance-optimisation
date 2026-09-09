<?php
/**
 * Tests for wp wppo verify live-state self-verification (issue #909).
 *
 * Covers the pure verify helpers (settings-schema validator, orphan-cron
 * detector, LiteSpeed coherence evaluator, uninstall spot-check classifier,
 * payload builder, domain resolver, Redis config builder) plus a JSON
 * end-to-end run of WPPO_CLI_Command::verify() with Brain Monkey stubs.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Cron;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\WPPO_CLI_Command;
use Brain\Monkey\Functions;

require_once __DIR__ . '/stubs/wp-cli.php';

/**
 * Tests for the verify subcommand helpers and JSON output.
 *
 * @package PerformanceOptimise\Tests
 */
class WppoCliVerifyTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset LiteSpeed statics + WP_CLI recording after each test.
	 */
	protected function tearDown(): void {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		if ( class_exists( 'WP_CLI' ) && method_exists( 'WP_CLI', 'reset_output' ) ) {
			WP_CLI::reset_output();
		}
		unset( $GLOBALS['wp_filesystem'] );
		unset( $_SERVER['SERVER_SOFTWARE'] );
		parent::tearDown();
	}

	/**
	 * Install the option/filter stubs shared by the verify() runs.
	 *
	 * @param array $settings Settings returned for wppo_settings.
	 * @return void
	 */
	private function install_verify_stubs( array $settings ): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) use ( $settings ) {
				if ( 'wppo_settings' === $name ) {
					return $settings;
				}
				if ( 'active_plugins' === $name ) {
					return array();
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
	}

	/**
	 * Install a WP_Filesystem stub claiming our advanced-cache drop-in.
	 *
	 * @return void
	 */
	private function install_dropin_filesystem_stub(): void {
		$stub                     = new class() {
			/**
			 * Claim every path exists.
			 *
			 * @param string $path Path.
			 * @return bool
			 */
			public function exists( $path ): bool {
				unset( $path );
				return true;
			}

			/**
			 * Return WPPO marker content for the advanced-cache drop-in.
			 *
			 * @param string $path Path.
			 * @return string
			 */
			public function get_contents( $path ): string {
				unset( $path );
				return '<?php // WPPO_ADVANCED_CACHE_DROPIN';
			}
		};
		$GLOBALS['wp_filesystem'] = $stub;
	}

	/**
	 * Check names must match the seven planned verify checks.
	 */
	public function test_check_names_match_plan(): void {
		$this->assertSame(
			array( 'cache_dirs', 'dropins', 'redis', 'litespeed', 'settings_schema', 'cron', 'uninstall' ),
			WPPO_CLI_Command::get_verify_check_names()
		);
	}

	/**
	 * Unknown top-level keys must fail the schema check.
	 */
	public function test_schema_flags_unknown_keys(): void {
		$stored              = Util::get_default_settings();
		$stored['rogue_tab'] = array();
		$row                 = WPPO_CLI_Command::validate_settings_schema( $stored );
		$this->assertSame( 'settings_schema', $row['check'] );
		$this->assertSame( 'fail', $row['status'] );
		$this->assertStringContainsString( 'rogue_tab', $row['detail'] );
	}

	/**
	 * Array-vs-scalar mismatches vs defaults must fail the schema check.
	 */
	public function test_schema_flags_type_mismatch(): void {
		$stored                                   = Util::get_default_settings();
		$stored['cache_settings']['ttlOverrides'] = 'not-an-array';
		$row                                      = WPPO_CLI_Command::validate_settings_schema( $stored );
		$this->assertSame( 'fail', $row['status'] );
		$this->assertStringContainsString( 'cache_settings.ttlOverrides', $row['detail'] );
	}

	/**
	 * Missing tabs (fresh/partial install) must warn, not fail.
	 */
	public function test_schema_warns_on_missing_tabs(): void {
		$row = WPPO_CLI_Command::validate_settings_schema( array() );
		$this->assertSame( 'settings_schema', $row['check'] );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'cache_settings', $row['detail'] );
	}

	/**
	 * Canonical defaults must pass the schema check.
	 */
	public function test_schema_passes_on_defaults(): void {
		$row = WPPO_CLI_Command::validate_settings_schema( Util::get_default_settings() );
		$this->assertSame( 'pass', $row['status'] );
	}

	/**
	 * The legacy misspelled hook must be allowlisted (warn, not fail).
	 */
	public function test_orphan_detector_allowlists_legacy_hook(): void {
		$split = WPPO_CLI_Command::find_orphan_cron_hooks( array( 'wppo_img_conversation', 'wppo_page_cron_hook' ) );
		$this->assertSame( array(), $split['orphans'] );
		$this->assertSame( array( 'wppo_img_conversation' ), $split['legacy'] );
	}

	/**
	 * Unknown wppo_* hooks must be reported as orphans.
	 */
	public function test_orphan_detector_flags_unknown_hooks(): void {
		$split = WPPO_CLI_Command::find_orphan_cron_hooks( array( Cron::SCHEDULED_HOOKS[0], 'wppo_evil_hook' ) );
		$this->assertSame( array( 'wppo_evil_hook' ), $split['orphans'] );
		$this->assertSame( array(), $split['legacy'] );
	}

	/**
	 * Non-wppo hooks must be ignored by the orphan detector.
	 */
	public function test_orphan_detector_ignores_core_hooks(): void {
		$split = WPPO_CLI_Command::find_orphan_cron_hooks( array( 'wp_version_check', 'delete_expired_transients' ) );
		$this->assertSame( array(), $split['orphans'] );
		$this->assertSame( array(), $split['legacy'] );
	}

	/**
	 * Unknown LiteSpeed mode strings must fail.
	 */
	public function test_litespeed_unknown_mode_fails(): void {
		$row = WPPO_CLI_Command::evaluate_litespeed_state( 'turbo', 'standalone', false, false, false, false );
		$this->assertSame( 'fail', $row['status'] );
		$this->assertStringContainsString( 'turbo', $row['detail'] );
	}

	/**
	 * Effective litespeed with no LS server and no LSCache plugin must fail.
	 */
	public function test_litespeed_effective_without_server_fails(): void {
		$row = WPPO_CLI_Command::evaluate_litespeed_state( 'auto', 'litespeed', false, false, false, false );
		$this->assertSame( 'fail', $row['status'] );
	}

	/**
	 * Mode wppo on an LS server with LSCache active must warn (double-cache risk).
	 */
	public function test_litespeed_wppo_on_ls_with_lscache_warns(): void {
		$row = WPPO_CLI_Command::evaluate_litespeed_state( 'wppo', 'wppo', true, true, false, false );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'double-cache', $row['detail'] );
	}

	/**
	 * ESI enabled without native ESI must warn.
	 */
	public function test_litespeed_esi_without_enterprise_warns(): void {
		$row = WPPO_CLI_Command::evaluate_litespeed_state( 'auto', 'standalone', false, false, true, false );
		$this->assertSame( 'warn', $row['status'] );
		$this->assertStringContainsString( 'ESI', $row['detail'] );
	}

	/**
	 * Coherent standalone state must pass.
	 */
	public function test_litespeed_coherent_passes(): void {
		$row = WPPO_CLI_Command::evaluate_litespeed_state( 'auto', 'standalone', false, false, false, false );
		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'mode=auto', $row['detail'] );
	}

	/**
	 * Known uninstall options (incl. blog-prefixed) must not be flagged.
	 */
	public function test_unknown_options_ignores_known_keys(): void {
		$unknown = WPPO_CLI_Command::find_unknown_wppo_options(
			array( 'wppo_settings', 'wppo_img_info', '2_wppo_settings', 'wppo_front_page_lcp_mobile' )
		);
		$this->assertSame( array(), $unknown );
	}

	/**
	 * Unknown wppo_* options must be reported.
	 */
	public function test_unknown_options_flags_strays(): void {
		$unknown = WPPO_CLI_Command::find_unknown_wppo_options( array( 'wppo_settings', 'wppo_bogus_key_xyz' ) );
		$this->assertSame( array( 'wppo_bogus_key_xyz' ), $unknown );
	}

	/**
	 * Payload builder must mark overall fail when any row fails.
	 */
	public function test_payload_overall_reflects_failures(): void {
		$rows    = array(
			array(
				'check'  => 'a',
				'status' => 'pass',
				'detail' => 'ok',
			),
			array(
				'check'  => 'b',
				'status' => 'fail',
				'detail' => 'bad',
			),
		);
		$payload = WPPO_CLI_Command::build_verify_payload( $rows );
		$this->assertSame( 'fail', $payload['overall'] );
		$this->assertCount( 2, $payload['checks'] );

		$clean = WPPO_CLI_Command::build_verify_payload(
			array(
				array(
					'check'  => 'a',
					'status' => 'pass',
					'detail' => 'ok',
				),
			)
		);
		$this->assertSame( 'pass', $clean['overall'] );
	}

	/**
	 * Redis config builder must keep only ALLOWED_KEYS.
	 */
	public function test_redis_config_builder_uses_allowlist(): void {
		$config = WPPO_CLI_Command::build_redis_config_from_settings(
			array(
				'object_cache' => array(
					'host' => '127.0.0.1',
					'evil' => 'x',
				),
			)
		);
		$this->assertSame( '127.0.0.1', $config['host'] );
		$this->assertArrayNotHasKey( 'evil', $config );
		foreach ( array_keys( $config ) as $key ) {
			$this->assertContains( $key, Object_Cache::ALLOWED_KEYS );
		}
	}

	/**
	 * Domain resolver must strip ports (Cache convention) and reject bad hosts.
	 */
	public function test_domain_resolver_strips_port_and_rejects_bad_hosts(): void {
		$this->assertSame( 'example.com', WPPO_CLI_Command::resolve_verify_domain( 'http://example.com:8080/' ) );
		$this->assertSame( 'example.com', WPPO_CLI_Command::resolve_verify_domain( 'https://example.com/some/path' ) );
		$this->assertSame( '', WPPO_CLI_Command::resolve_verify_domain( 'http://bad host!/' ) );
		$this->assertSame( '', WPPO_CLI_Command::resolve_verify_domain( 'not-a-url' ) );
	}

	/**
	 * --format=json must emit a single valid JSON payload with all checks.
	 */
	public function test_verify_json_outputs_valid_payload(): void {
		$this->install_verify_stubs( Util::get_default_settings() );
		$this->install_dropin_filesystem_stub();

		$command = new WPPO_CLI_Command();
		$command->verify( array(), array( 'format' => 'json' ) );

		$this->assertNotEmpty( WP_CLI::$logs, 'Expected a JSON payload to be logged.' );
		$payload = json_decode( WP_CLI::$logs[0], true );
		$this->assertIsArray( $payload, 'Verify --format=json must emit a single valid JSON payload.' );
		$this->assertArrayHasKey( 'overall', $payload );
		$this->assertArrayHasKey( 'checks', $payload );
		$this->assertCount( 7, $payload['checks'] );

		$names = array();
		foreach ( $payload['checks'] as $row ) {
			$this->assertArrayHasKey( 'check', $row );
			$this->assertArrayHasKey( 'status', $row );
			$this->assertArrayHasKey( 'detail', $row );
			$this->assertContains( $row['status'], array( 'pass', 'warn', 'fail' ) );
			$names[] = $row['check'];
		}
		$this->assertSame( WPPO_CLI_Command::get_verify_check_names(), $names );
	}

	/**
	 * --check must filter to a single row (redis disabled skips to pass).
	 */
	public function test_verify_single_check_filter(): void {
		$this->install_verify_stubs( Util::get_default_settings() );
		$this->install_dropin_filesystem_stub();

		$command = new WPPO_CLI_Command();
		$command->verify(
			array(),
			array(
				'format' => 'json',
				'check'  => 'redis',
			)
		);

		$this->assertNotEmpty( WP_CLI::$logs );
		$payload = json_decode( WP_CLI::$logs[0], true );
		$this->assertIsArray( $payload );
		$this->assertCount( 1, $payload['checks'] );
		$this->assertSame( 'redis', $payload['checks'][0]['check'] );
		$this->assertSame( 'pass', $payload['checks'][0]['status'] );
	}
}
