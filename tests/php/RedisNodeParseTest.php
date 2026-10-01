<?php
/**
 * Redis Node Parse Tests
 *
 * @package Performance_Optimisation
 */

namespace PerformanceOptimise\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Class RedisNodeParseTest
 */
class RedisNodeParseTest extends TestCase {

	/**
	 * Setup before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wppo_parse_redis_node' ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/Support/redis-connect-helper.php';
		}
	}

	/**
	 * Test parsing valid node strings.
	 *
	 * @dataProvider provider_valid_nodes
	 * @param string $input    The input node string.
	 * @param string $exp_host The expected host.
	 * @param int    $exp_port The expected port.
	 */
	public function test_valid_nodes( string $input, string $exp_host, int $exp_port ): void {
		$result = wppo_parse_redis_node( $input );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result );
		$this->assertArrayHasKey( 'host', $result );
		$this->assertArrayHasKey( 'port', $result );

		$this->assertSame( $exp_host, $result['host'] );
		$this->assertSame( $exp_port, $result['port'] );
	}

	/**
	 * Data provider for test_valid_nodes.
	 *
	 * @return array
	 */
	public static function provider_valid_nodes(): array {
		return array(
			// Bracketed IPv6 with port.
			array( '[::1]:26379', '::1', 26379 ),
			array( '[fe80::202:b3ff:fe1e:8329]:1234', 'fe80::202:b3ff:fe1e:8329', 1234 ),

			// Bracketed IPv6 without port (defaults to sentinel port).
			array( '[::1]', '::1', 26379 ),
			array( '[fe80::1]', 'fe80::1', 26379 ),

			// Raw unbracketed IPv6 (defaults to sentinel port).
			array( 'fe80::1', 'fe80::1', 26379 ),
			array( '2001:0db8:85a3:0000:0000:8a2e:0370:7334', '2001:0db8:85a3:0000:0000:8a2e:0370:7334', 26379 ),

			// Standard host:port.
			array( 'localhost:26380', 'localhost', 26380 ),
			array( '127.0.0.1:6379', '127.0.0.1', 6379 ),
			array( 'redis.example.internal:12345', 'redis.example.internal', 12345 ),

			// Standard host without port (defaults to sentinel port).
			array( 'localhost', 'localhost', 26379 ),
			array( '127.0.0.1', '127.0.0.1', 26379 ),
			array( 'redis.example.internal', 'redis.example.internal', 26379 ),
		);
	}

	/**
	 * Test parsing malformed node strings.
	 */
	public function test_malformed_nodes(): void {
		// Non-numeric port becomes 0. The caller (wppo_redis_connect_sentinel)
		// rejects ports <= 0.
		$result = wppo_parse_redis_node( 'host:abc' );
		$this->assertSame( 'host', $result['host'] );
		$this->assertSame( 0, $result['port'] );

		// Only colon.
		$result = wppo_parse_redis_node( ':' );
		$this->assertSame( '', $result['host'] );
		$this->assertSame( 0, $result['port'] );
	}
}
