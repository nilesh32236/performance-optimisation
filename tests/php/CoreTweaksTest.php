<?php
/**
 * Tests for Core_Tweaks class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Core_Tweaks;
use Brain\Monkey\Functions;

/**
 * Tests for Core_Tweaks class.
 *
 * @package PerformanceOptimise\Tests
 */
class CoreTweaksTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Test that a non-array $urls value is returned unchanged for dns-prefetch.
	 */
	public function test_dns_prefetch_non_array_urls_returned_unchanged(): void {
		$core_tweaks = new Core_Tweaks();

		$result = $core_tweaks->disable_emojis_remove_dns_prefetch( null, 'dns-prefetch' );
		$this->assertNull( $result );

		$result = $core_tweaks->disable_emojis_remove_dns_prefetch( 'string', 'dns-prefetch' );
		$this->assertSame( 'string', $result );
	}

	/**
	 * Test that the emoji SVG URL is removed from dns-prefetch hints.
	 */
	public function test_dns_prefetch_array_removes_emoji_svg_url(): void {
		$core_tweaks = new Core_Tweaks();

		$urls     = array(
			'https://s.w.org/images/core/emoji/15.0.3/svg/',
			'https://example.com/',
		);
		$result   = $core_tweaks->disable_emojis_remove_dns_prefetch( $urls, 'dns-prefetch' );
		$expected = array(
			1 => 'https://example.com/',
		);
		$this->assertSame( $expected, $result );
	}

	/**
	 * Test that non-dns-prefetch relation types leave the array unchanged.
	 */
	public function test_non_dns_prefetch_relation_type_unchanged(): void {
		$core_tweaks = new Core_Tweaks();

		$urls   = array(
			'https://example.com/',
		);
		$result = $core_tweaks->disable_emojis_remove_dns_prefetch( $urls, 'preconnect' );
		$this->assertSame( $urls, $result );
	}

	/**
	 * Test that remove_jquery_migrate drops the migrate dependency.
	 */
	public function test_remove_jquery_migrate_drops_dependency(): void {
		$core_tweaks = new Core_Tweaks();

		$scripts = (object) array(
			'registered' => array(
				'jquery' => (object) array(
					'deps' => array( 'jquery-migrate', 'utils' ),
				),
			),
		);

		$core_tweaks->remove_jquery_migrate( $scripts );

		$this->assertSame( array( 'utils' ), $scripts->registered['jquery']->deps );
	}

	/**
	 * Test that remove_jquery_migrate is a no-op without a jquery handle.
	 */
	public function test_remove_jquery_migrate_noop_without_jquery(): void {
		$core_tweaks = new Core_Tweaks();

		$scripts = (object) array(
			'registered' => array( 'other' => (object) array( 'deps' => array() ) ),
		);

		$core_tweaks->remove_jquery_migrate( $scripts );

		$this->assertSame( array( 'other' ), array_keys( (array) $scripts->registered ) );
	}

	/**
	 * Test that disable_self_pingbacks strips self-ping URLs.
	 */
	public function test_disable_self_pingbacks_removes_home_urls(): void {
		Functions\when( 'get_option' )->justReturn( 'http://example.com' );

		$core_tweaks = new Core_Tweaks();
		$pung        = array(
			'http://example.com/2026/08/post',
			'http://other-site.example/x',
		);

		$core_tweaks->disable_self_pingbacks( $pung );

		$this->assertSame( array( 1 => 'http://other-site.example/x' ), $pung );
	}
}
