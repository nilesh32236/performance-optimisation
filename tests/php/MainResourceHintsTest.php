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
	 * Minimal re-implementation of core's wp_resource_hints() render loop for
	 * preconnect/dns-prefetch: host guard via wp_parse_url(), scheme+host
	 * normalization for preconnect, protocol-relative `//host` for
	 * dns-prefetch, and single-quoted attribute output.
	 *
	 * Note: esc_url() currently prepends http:// to scheme-less hostnames, so
	 * core does not drop them today. The plugin still normalizes them to
	 * protocol-relative form so survival never depends on that scheme-inference
	 * behavior, which is what this strict host guard locks in.
	 *
	 * @param array  $urls          Filtered hints (plain URLs or attribute arrays).
	 * @param string $relation_type 'preconnect' or 'dns-prefetch'.
	 * @return string[] Rendered <link> tags.
	 */
	private function simulate_core_render( array $urls, string $relation_type ): array {
		$processed = array();

		foreach ( $urls as $url ) {
			$atts = array();

			if ( is_array( $url ) ) {
				if ( isset( $url['href'] ) ) {
					$atts = $url;
					$url  = $url['href'];
				} else {
					continue;
				}
			}

			if ( in_array( $relation_type, array( 'preconnect', 'dns-prefetch' ), true ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Emulates wp_parse_url() from core's render loop in tests.
				$parsed = parse_url( $url );

				if ( empty( $parsed['host'] ) ) {
					continue;
				}

				if ( 'preconnect' === $relation_type && ! empty( $parsed['scheme'] ) ) {
					$url = $parsed['scheme'] . '://' . $parsed['host'];
				} else {
					$url = '//' . $parsed['host'];
				}
			}

			$atts['rel']  = $relation_type;
			$atts['href'] = $url;

			$processed[ $url ] = $atts;
		}

		$links = array();

		foreach ( $processed as $atts ) {
			$html = '';

			foreach ( $atts as $attr => $value ) {
				$html .= " $attr='" . $value . "'";
			}

			$links[] = '<link ' . trim( $html ) . ' />';
		}

		return $links;
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

	/**
	 * Test that bare hostnames (the settings UI's documented dns-prefetch
	 * format, e.g. "example.com") are normalized to protocol-relative form so
	 * they survive core's host guard instead of being silently dropped.
	 */
	public function test_bare_hostname_dns_prefetch_normalized_to_protocol_relative(): void {
		$main = $this->make_main(
			array(
				'prefetchDNS'        => true,
				'dnsPrefetchOrigins' => "example.com\nhttps://dns.example.com\n//cdn.example.com",
			)
		);

		$result = $main->add_resource_hints( array( 'https://existing.example.com' ), 'dns-prefetch' );

		$this->assertSame(
			array(
				'https://existing.example.com',
				'//example.com',
				'https://dns.example.com',
				'//cdn.example.com',
			),
			$result
		);
	}

	/**
	 * Test the full pipeline for dns-prefetch: feed add_resource_hints() output
	 * through a minimal re-implementation of core's render loop and assert the
	 * rendered <link> tags. Bare hostnames must survive as protocol-relative
	 * hints and scheme-ful URLs must be normalized to `//host`.
	 */
	public function test_core_render_loop_dns_prefetch_bare_hostname_survives(): void {
		$main = $this->make_main(
			array(
				'prefetchDNS'        => true,
				'dnsPrefetchOrigins' => "example.com\nhttps://api.example.com",
			)
		);

		$result = $main->add_resource_hints( array(), 'dns-prefetch' );

		$this->assertSame(
			array(
				"<link rel='dns-prefetch' href='//example.com' />",
				"<link rel='dns-prefetch' href='//api.example.com' />",
			),
			$this->simulate_core_render( $result, 'dns-prefetch' )
		);
	}

	/**
	 * Test the full pipeline for preconnect: core normalizes the href to
	 * scheme+host (stripping path, query, and non-default ports) while keeping
	 * the crossorigin attribute from the array-hint format.
	 */
	public function test_core_render_loop_preconnect_normalizes_to_scheme_host(): void {
		$main = $this->make_main(
			array(
				'preconnect'        => true,
				'preconnectOrigins' => 'https://cdn.example.com:8080/assets/app.js?v=1',
			)
		);

		$result = $main->add_resource_hints( array( 'https://existing.example.com' ), 'preconnect' );

		$this->assertSame(
			array(
				"<link rel='preconnect' href='https://existing.example.com' />",
				"<link href='https://cdn.example.com' crossorigin='anonymous' rel='preconnect' />",
			),
			$this->simulate_core_render( $result, 'preconnect' )
		);
	}
}
