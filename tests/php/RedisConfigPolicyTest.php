<?php
/**
 * Security and adapter-parity tests for the Redis configuration value policy.
 *
 * @package PerformanceOptimise\Tests
 *
 * @since NEXT
 */

use PerformanceOptimise\Inc\Object_Cache;
use PerformanceOptimise\Inc\Redis_Config_Policy;
use PerformanceOptimise\Inc\Rest;
use PerformanceOptimise\Inc\WPPO_CLI_Command;

require_once __DIR__ . '/stubs/wp-cli.php';

/**
 * P3-006 Redis configuration policy boundary tests.
 *
 * @since NEXT
 */
final class RedisConfigPolicyTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Load the dependency-light policy in classmap-stale test checkouts.
	 *
	 * @return void
	 */
	private function load_policy(): void {
		if ( ! class_exists( Redis_Config_Policy::class, false ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/Cache/class-redis-config-policy.php';
		}
	}

	/**
	 * The complete Object_Cache key manifest has one policy owner.
	 *
	 * @return void
	 */
	public function test_policy_owns_complete_object_cache_key_manifest(): void {
		$this->load_policy();

		$this->assertSame( Object_Cache::ALLOWED_KEYS, Redis_Config_Policy::ALLOWED_KEYS );
		$this->assertSame(
			array( 'mode', 'host', 'port', 'password', 'database', 'timeout', 'prefix', 'nodes', 'master_name', 'use_tls', 'persistent', 'compression' ),
			Redis_Config_Policy::ALLOWED_KEYS
		);
	}

	/**
	 * Request values win over stored defaults and all values normalize through one policy.
	 *
	 * @return void
	 */
	public function test_build_merges_defaults_and_normalizes_all_supported_values(): void {
		$this->load_policy();

		$config = Redis_Config_Policy::build(
			array(
				'mode'        => 'sentinel',
				'host'        => ' REDIS.EXAMPLE.COM ',
				'port'        => 70000,
				'password'    => 12345678,
				'database'    => 99,
				'timeout'     => '4.5',
				'prefix'      => ' wppo:site-7! ',
				'nodes'       => array( 'NODE-A:26379', '', 'NODE-B:26380' ),
				'master_name' => 'Primary-Master',
				'use_tls'     => 1,
				'persistent'  => 0,
				'compression' => 'zstd',
				'evil'        => 'drop me',
			),
			array(
				'mode'     => 'cluster',
				'host'     => 'stored.example.com',
				'port'     => '6380',
				'timeout'  => 99,
				'password' => 'stored-secret',
			)
		);

		$this->assertSame(
			array(
				'mode'        => 'sentinel',
				'host'        => 'redis.example.com',
				'port'        => 65535,
				'password'    => '12345678',
				'database'    => 15,
				'timeout'     => 4.5,
				'prefix'      => 'wppo:site-7',
				'nodes'       => array( 'node-a:26379', 'node-b:26380' ),
				'master_name' => 'primary-master',
				'use_tls'     => true,
				'persistent'  => false,
				'compression' => 'zstd',
			),
			$config
		);
		$this->assertArrayNotHasKey( 'evil', $config );
	}

	/**
	 * Missing config receives the historic Redis defaults.
	 *
	 * @return void
	 */
	public function test_build_applies_standalone_loopback_defaults(): void {
		$this->load_policy();

		$this->assertSame(
			array(
				'mode' => 'standalone',
				'host' => '127.0.0.1',
				'port' => 6379,
			),
			Redis_Config_Policy::build( array() )
		);
	}

	/**
	 * Schemes, userinfo, malformed hosts, and unsafe node ports fail closed.
	 *
	 * @return void
	 */
	public function test_security_rejects_unsafe_hosts_and_nodes(): void {
		$this->load_policy();

		$this->assertSame( '', Redis_Config_Policy::sanitize_value( 'host', 'redis://127.0.0.1' ) );
		$this->assertSame( '', Redis_Config_Policy::sanitize_value( 'host', 'user:password@redis.example.com' ) );
		$this->assertSame( '', Redis_Config_Policy::sanitize_value( 'host', 'redis.example.com:6379' ) );
		$this->assertSame( '', Redis_Config_Policy::sanitize_value( 'master_name', 'http://redis.example.com' ) );
		$this->assertSame(
			array( 'node-a:6379' ),
			Redis_Config_Policy::sanitize_nodes(
				array(
					'http://169.254.169.254:6379',
					'user:password@node-a:6379',
					'node-b:0',
					'node-c:65536',
					'node-a:6379',
				)
			)
		);
	}

	/**
	 * Node arrays are filtered and reindexed while a scalar becomes one entry.
	 *
	 * @return void
	 */
	public function test_nodes_are_normalized_filtered_and_reindexed(): void {
		$this->load_policy();

		$this->assertSame( array( 'node-a:6379' ), Redis_Config_Policy::sanitize_nodes( ' NODE-A:6379 ' ) );
		$this->assertSame(
			array( 'node-a:6379', '/tmp/redis-b.sock:6380' ),
			Redis_Config_Policy::sanitize_nodes(
				array(
					5  => 'NODE-A:6379',
					9  => array( 'unsafe' ),
					12 => '',
					15 => '/tmp/redis-b.sock:6380',
				)
			)
		);
	}

	/**
	 * REST's compatibility builder is a thin same-behavior proxy to the policy.
	 *
	 * @return void
	 */
	public function test_rest_builder_delegates_to_the_policy(): void {
		$this->load_policy();
		$rest   = new Rest();
		$method = new \ReflectionMethod( Rest::class, 'build_redis_config' );
		$input  = array(
			'host'        => ' REDIS.EXAMPLE.COM ',
			'port'        => '6380',
			'database'    => '2',
			'timeout'     => '1.25',
			'prefix'      => 'wppo_site!',
			'compression' => 'lz4',
			'use_tls'     => true,
			'nodes'       => array( 'NODE-A:6379' ),
		);

		$this->assertSame(
			Redis_Config_Policy::build( $input ),
			$method->invoke( $rest, $input )
		);
	}

	/**
	 * CLI argument and stored-settings builders use the same value policy.
	 *
	 * @return void
	 */
	public function test_cli_builders_use_the_same_value_policy(): void {
		$this->load_policy();
		$method   = new \ReflectionMethod( WPPO_CLI_Command::class, 'get_redis_config_from_assoc' );
		$args     = array(
			'host'        => ' REDIS.EXAMPLE.COM ',
			'port'        => '70000',
			'database'    => '-2',
			'timeout'     => '45',
			'prefix'      => 'wppo:cli!',
			'nodes'       => array( 'NODE-A:6379', 'http://169.254.169.254' ),
			'master_name' => 'Primary',
			'use_tls'     => true,
			'persistent'  => false,
			'compression' => 'none',
			'evil'        => 'drop me',
		);
		$expected = Redis_Config_Policy::build( $args );

		$this->assertSame( $expected, $method->invoke( null, $args ) );
		$this->assertSame(
			$expected,
			WPPO_CLI_Command::build_redis_config_from_settings(
				array( 'object_cache' => $args )
			)
		);
	}

	/**
	 * Numeric lower bounds and non-scalar normalization remain type-safe.
	 *
	 * @return void
	 */
	public function test_numeric_and_prefix_contracts_preserve_types_and_bounds(): void {
		$this->load_policy();

		$this->assertSame( 1, Redis_Config_Policy::sanitize_value( 'port', -10 ) );
		$this->assertSame( 0, Redis_Config_Policy::sanitize_value( 'database', -1 ) );
		$this->assertSame( 0.1, Redis_Config_Policy::sanitize_value( 'timeout', -2 ) );
		$this->assertSame( 1.0, Redis_Config_Policy::sanitize_value( 'timeout', array( 2 ) ) );
		$this->assertSame( '', Redis_Config_Policy::sanitize_value( 'prefix', array( 'unsafe' ) ) );
		$this->assertSame( '', Redis_Config_Policy::sanitize_value( 'compression', 'lzf' ) );
		$this->assertSame( 'standalone', Redis_Config_Policy::sanitize_value( 'mode', 'invalid' ) );
	}
}
