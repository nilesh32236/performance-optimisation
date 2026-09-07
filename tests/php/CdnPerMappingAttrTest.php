<?php
/**
 * Tests for per-mapping cdn_attr handling (audit #888 finding 21).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\CDN;
use Brain\Monkey\Functions;

// The dev classmap can lag behind new class files (vendor is built on demand);
// require the class directly like LiteSpeedCrawlerTest does.
require_once __DIR__ . '/../../includes/class-cdn.php';

/**
 * Tests that CDN attribute restrictions are resolved per mapping, not by a
 * global union across mappings.
 *
 * @package PerformanceOptimise\Tests
 */
class CdnPerMappingAttrTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stub the URL helpers find_cdn_match() relies on.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
	}

	/**
	 * A normalized single mapping restricted to the `src` attribute.
	 *
	 * @return array
	 */
	private function src_restricted_mapping(): array {
		return array(
			'cdn_url'           => 'https://cdn.example.com',
			'ori'               => '',
			'ori_dir'           => '',
			'include_dirs'      => 'wp-content|wp-includes',
			'include_filetypes' => 'jpg,png',
			'cdn_attr'          => 'src',
			'cdn_urls'          => array( 'https://cdn.example.com' ),
		);
	}

	/**
	 * Test that find_cdn_match returns both the CDN URL and the mapping.
	 */
	public function test_find_cdn_match_returns_cdn_and_mapping(): void {
		$mappings = array( $this->src_restricted_mapping() );

		$match = CDN::find_cdn_match( 'http://example.com/wp-content/uploads/img.jpg', $mappings );

		$this->assertNotNull( $match );
		$this->assertSame( 'https://cdn.example.com', $match['cdn'] );
		$this->assertSame( 'src', $match['mapping']['cdn_attr'] );
	}

	/**
	 * Test that find_cdn_for_url keeps returning the plain CDN URL.
	 */
	public function test_find_cdn_for_url_returns_plain_cdn(): void {
		$mappings = array( $this->src_restricted_mapping() );

		$this->assertSame(
			'https://cdn.example.com',
			CDN::find_cdn_for_url( 'http://example.com/wp-content/uploads/img.jpg', $mappings )
		);
	}

	/**
	 * Test that a mapping restricted to `src` permits `src` only.
	 */
	public function test_mapping_allows_attr_respects_restriction(): void {
		$ref = new \ReflectionMethod( CDN::class, 'mapping_allows_attr' );
		$ref->setAccessible( true );

		$mapping = array( 'cdn_attr' => 'src' );

		$this->assertTrue( $ref->invoke( null, $mapping, 'src' ) );
		$this->assertFalse( $ref->invoke( null, $mapping, 'href' ) );
		$this->assertFalse( $ref->invoke( null, $mapping, 'srcset' ) );
	}

	/**
	 * Test that a mapping with an empty cdn_attr allows the default set.
	 */
	public function test_mapping_allows_attr_allows_all_when_unrestricted(): void {
		$ref = new \ReflectionMethod( CDN::class, 'mapping_allows_attr' );
		$ref->setAccessible( true );

		$this->assertTrue( $ref->invoke( null, array(), 'href' ) );
		$this->assertTrue( $ref->invoke( null, array( 'cdn_attr' => '' ), 'poster' ) );
	}

	/**
	 * Test that multi-attribute restrictions are honoured per attribute.
	 */
	public function test_mapping_allows_attr_multi_values(): void {
		$ref = new \ReflectionMethod( CDN::class, 'mapping_allows_attr' );
		$ref->setAccessible( true );

		$mapping = array( 'cdn_attr' => 'src, srcset' );

		$this->assertTrue( $ref->invoke( null, $mapping, 'src' ) );
		$this->assertTrue( $ref->invoke( null, $mapping, 'srcset' ) );
		$this->assertFalse( $ref->invoke( null, $mapping, 'content' ) );
	}
}
