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
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$core_tweaks = new Core_Tweaks();
		$pung        = array(
			'http://example.com/2026/08/post',
			'http://other-site.example/x',
		);

		$core_tweaks->disable_self_pingbacks( $pung );

		$this->assertSame( array( 1 => 'http://other-site.example/x' ), $pung );
	}

	/**
	 * Test that disable_self_pingbacks ignores similar-looking foreign hosts and
	 * still catches same-host URLs with a different scheme.
	 */
	public function test_disable_self_pingbacks_is_host_aware(): void {
		Functions\when( 'get_option' )->justReturn( 'http://example.com' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$core_tweaks = new Core_Tweaks();
		$pung        = array(
			'https://example.com/secure/post', // Same host, https — should be removed.
			'http://example.com.evil/x',       // Lookalike host — must be kept.
		);

		$core_tweaks->disable_self_pingbacks( $pung );

		$this->assertSame(
			array( 1 => 'http://example.com.evil/x' ),
			$pung
		);
	}

	/**
	 * Test that disable_emojis dequeues emoji script module when available.
	 */
	public function test_disable_emojis_dequeues_script_module_when_available(): void {
		if ( ! function_exists( 'wp_dequeue_script_module' ) ) {
			eval( 'function wp_dequeue_script_module($id){ $GLOBALS["wppo_test_dequeued_module"] = $id; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		if ( ! function_exists( 'wp_dequeue_script' ) ) {
			eval( 'function wp_dequeue_script($handle){ $GLOBALS["wppo_test_dequeued_script"] = $handle; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		$GLOBALS['wppo_test_dequeued_module'] = null;
		$GLOBALS['wppo_test_dequeued_script'] = null;
		Functions\when( 'remove_action' )->justReturn( true );
		Functions\when( 'remove_filter' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		// Ensure Brain Monkey stub (if already defined by earlier test) is configured to capture.
		Functions\when( 'wp_dequeue_script_module' )->alias(
			static function ( $id ) {
				$GLOBALS['wppo_test_dequeued_module'] = $id;
			}
		);
		Functions\when( 'wp_dequeue_script' )->alias(
			static function ( $handle ) {
				$GLOBALS['wppo_test_dequeued_script'] = $handle;
			}
		);

		$core_tweaks = new Core_Tweaks();
		$core_tweaks->disable_emojis();

		$this->assertSame( 'emoji', $GLOBALS['wppo_test_dequeued_module'] );
		$this->assertSame( 'wp-emoji', $GLOBALS['wppo_test_dequeued_script'] );
	}

	/**
	 * Test that disable_emojis_script_module dequeues emoji module.
	 */
	public function test_disable_emojis_script_module_method_dequeues(): void {
		if ( ! function_exists( 'wp_dequeue_script_module' ) ) {
			eval( 'function wp_dequeue_script_module($id){ $GLOBALS["wppo_test_dequeued_module"] = $id; }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		$GLOBALS['wppo_test_dequeued_module'] = null;

		$core_tweaks = new Core_Tweaks();
		$core_tweaks->disable_emojis_script_module();

		$this->assertSame( 'emoji', $GLOBALS['wppo_test_dequeued_module'] );
	}

	/**
	 * Test that constructor registers emoji module hooks when function exists.
	 */
	public function test_constructor_registers_emoji_module_hooks(): void {
		if ( ! function_exists( 'wp_dequeue_script_module' ) ) {
			eval( 'function wp_dequeue_script_module($id){ }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		Functions\when( 'add_action' )->justReturn( true );
		// Capture add_action calls via mock.
		$actions = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $cb, $priority = 10 ) use ( &$actions ) {
				$actions[] = array( $hook, $cb, $priority );
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );

		$core_tweaks = new Core_Tweaks( array( 'disableEmojis' => true ) );
		$hooks       = array_column( $actions, 0 );
		$this->assertContains( 'wp_enqueue_scripts', $hooks );
		$this->assertContains( 'admin_enqueue_scripts', $hooks );
	}
}
