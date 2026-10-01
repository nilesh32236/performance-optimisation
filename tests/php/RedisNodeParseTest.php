<?php
/**
 * Redis Node Parse Tests
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

// phpcs:disable WordPress.Files.FileName -- PHPUnit discovers via the *Test.php suffix; the class name cannot satisfy both PHPCS and PHPUnit.

use PHPUnit\Framework\TestCase;

/**
 * Class RedisNodeParseTest
 *
 * @since NEXT
 */
final class RedisNodeParseTest extends TestCase {
	use WPPO_Test_Bootstrap;

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
	 * @param mixed  $input    The input node string.
	 * @param string $exp_host The expected host.
	 * @param int    $exp_port The expected port.
	 * @since NEXT
	 * @return void
	 */
	public function test_valid_nodes( mixed $input, string $exp_host, int $exp_port ): void {
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
	 * @since NEXT
	 * @return array<string, array{mixed, string, int}>
	 */
	public static function provider_valid_nodes(): array {
		return array(
			// Bracketed IPv6 with port.
			'bracketed IPv6 with port'               => array( '[fe80::202:b3ff:fe1e:8329]:1234', 'fe80::202:b3ff:fe1e:8329', 1234 ),

			// Bracketed IPv6 without port (defaults to sentinel port).
			'bracketed IPv6 without port'            => array( '[fe80::1]', 'fe80::1', 26379 ),

			// Raw unbracketed IPv6 (defaults to sentinel port).
			'raw IPv6'                               => array( 'fe80::1', 'fe80::1', 26379 ),
			'raw IPv6 long'                          => array( '2001:0db8:85a3:0000:0000:8a2e:0370:7334', '2001:0db8:85a3:0000:0000:8a2e:0370:7334', 26379 ),
			'raw IPv6 with port'                     => array( 'fe80::1:26379', 'fe80::1:26379', 26379 ),

			// Standard host:port.
			'standard host:port'                     => array( 'localhost:26380', 'localhost', 26380 ),
			'standard IP:port'                       => array( '127.0.0.1:6379', '127.0.0.1', 6379 ),
			'FQDN:port'                              => array( 'redis.example.internal:12345', 'redis.example.internal', 12345 ),

			// Standard host without port (defaults to sentinel port).
			'standard host without port'             => array( 'localhost', 'localhost', 26379 ),
			'standard IP without port'               => array( '127.0.0.1', '127.0.0.1', 26379 ),
			'FQDN without port'                      => array( 'redis.example.internal', 'redis.example.internal', 26379 ),

			// Edge cases and coercions.
			'null input'                             => array( null, '', 26379 ),
			'int input'                              => array( 12345, '12345', 26379 ),
			'array input (non-scalar fails closed)'  => array( array(), '', 26379 ),
			'object input (non-scalar fails closed)' => array( (object) array(), '', 26379 ),
			'empty string input'                     => array( '', '', 26379 ),
			'empty port'                             => array( 'host:', 'host', 0 ),
			'bracketed empty port'                   => array( '[::1]:', '::1', 0 ),
			'negative port'                          => array( 'host:-1', 'host', -1 ),
			'unterminated bracket'                   => array( '[::1', '::1', 26379 ),
			'only colon'                             => array( ':', '', 0 ),
			'non-numeric port'                       => array( 'host:abc', 'host', 0 ),
		);
	}
}
