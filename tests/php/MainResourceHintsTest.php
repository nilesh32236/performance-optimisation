<?php
/**
 * Tests for Main::add_resource_hints() migrating preconnect/dns-prefetch
 * emission to core's `wp_resource_hints` filter.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;

/**
 * Tests the wp_resource_hints filter callback that appends the plugin's
 * configured preconnect/dns-prefetch origins to core's resource hints.
 *
 * @package PerformanceOptimise\Tests
 */
class MainResourceHintsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build a Main instance (skipping the full constructor) with the given
	 * preload_settings, so add_resource_hints() can be exercised in isolation.
	 *
	 * @param array $preload_settings preload_settings option value.
	 * @return Main
	 */
	private function make_main( array $preload_settings ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$options = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue( $main, array( 'preload_settings' => $preload_settings ) );

		return $main;
	}

	/**
	 * Test that enabled preconnect origins are appended with a
	 * crossorigin="anonymous" attribute matching the legacy echo output.
	 */
	public function test_preconnect_origins_appended_with_crossorigin(): void {
		$main = $this->make_main(
			array(
				'preconnect'        => true,
				'preconnectOrigins' => "https://cdn.example.com\nhttps://fonts.example.com",
			)
		);

		$result = $main->add_resource_hints( array( 'https://existing.example.com' ), 'preconnect' );

		$this->assertSame(
			array(
				'https://existing.example.com',
				array(
					'href'        => 'https://cdn.example.com',
					'crossorigin' => 'anonymous',
				),
				array(
					'href'        => 'https://fonts.example.com',
					'crossorigin' => 'anonymous',
				),
			),
			$result
		);
	}

	/**
	 * Test that enabled dns-prefetch origins are appended as plain URLs.
	 */
	public function test_dns_prefetch_origins_appended(): void {
		$main = $this->make_main(
			array(
				'prefetchDNS'        => true,
				'dnsPrefetchOrigins' => "https://dns.example.com\nhttps://api.example.com",
			)
		);

		$result = $main->add_resource_hints( array( 'https://existing.example.com' ), 'dns-prefetch' );

		$this->assertSame(
			array(
				'https://existing.example.com',
				'https://dns.example.com',
				'https://api.example.com',
			),
			$result
		);
	}

	/**
	 * Test that disabled preconnect settings leave the array unchanged.
	 */
	public function test_disabled_preconnect_unchanged(): void {
		$main = $this->make_main(
			array(
				'preconnect'        => false,
				'preconnectOrigins' => 'https://cdn.example.com',
			)
		);

		$urls   = array( 'https://existing.example.com' );
		$result = $main->add_resource_hints( $urls, 'preconnect' );
		$this->assertSame( $urls, $result );
	}

	/**
	 * Test that disabled dns-prefetch settings leave the array unchanged.
	 */
	public function test_disabled_dns_prefetch_unchanged(): void {
		$main = $this->make_main(
			array(
				'prefetchDNS'        => false,
				'dnsPrefetchOrigins' => 'https://api.example.com',
			)
		);

		$urls   = array( 'https://existing.example.com' );
		$result = $main->add_resource_hints( $urls, 'dns-prefetch' );
		$this->assertSame( $urls, $result );
	}

	/**
	 * Test that entirely missing preload_settings leave the array unchanged.
	 */
	public function test_missing_preload_settings_unchanged(): void {
		$main = $this->make_main( array() );

		$urls   = array( 'https://existing.example.com' );
		$result = $main->add_resource_hints( $urls, 'preconnect' );
		$this->assertSame( $urls, $result );

		$result = $main->add_resource_hints( $urls, 'dns-prefetch' );
		$this->assertSame( $urls, $result );
	}

	/**
	 * Test that unrelated relation types (prefetch, prerender) are returned
	 * unchanged even when preconnect/dns-prefetch are enabled.
	 */
	public function test_other_relation_types_unchanged(): void {
		$main = $this->make_main(
			array(
				'preconnect'         => true,
				'preconnectOrigins'  => 'https://cdn.example.com',
				'prefetchDNS'        => true,
				'dnsPrefetchOrigins' => 'https://api.example.com',
			)
		);

		$urls = array( 'https://existing.example.com' );

		$this->assertSame( $urls, $main->add_resource_hints( $urls, 'prefetch' ) );
		$this->assertSame( $urls, $main->add_resource_hints( $urls, 'prerender' ) );
	}
}
