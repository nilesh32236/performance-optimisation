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
		\Brain\Monkey\setUp();
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

		$object = new stdClass();
		$result = $core_tweaks->disable_emojis_remove_dns_prefetch( $object, 'dns-prefetch' );
		$this->assertSame( $object, $result );
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
}
