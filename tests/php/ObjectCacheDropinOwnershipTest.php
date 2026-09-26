<?php
/**
 * Tests for Object_Cache drop-in ownership classification (issue #924).
 *
 * Covers Object_Cache::is_own_dropin_content(): current marker, legacy
 * marker with/without the WPPO companion signal, foreign content, and
 * non-string input.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Object_Cache;

/**
 * Tests for the object-cache drop-in ownership helper.
 *
 * @package PerformanceOptimise\Tests
 */
class ObjectCacheDropinOwnershipTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that content with the current marker is ours.
	 */
	public function test_current_marker_is_own(): void {
		$this->assertTrue(
			Object_Cache::is_own_dropin_content( '<?php // ' . Object_Cache::DROPIN_MARKER . ' ...' )
		);
	}

	/**
	 * Test that legacy-only content with the WPPO companion signal is ours.
	 */
	public function test_legacy_marker_with_wppo_signal_is_own(): void {
		$content = '<?php // Redis Object Cache Drop-in ... wppo-redis-config.php ...';
		$this->assertStringNotContainsString( Object_Cache::DROPIN_MARKER, $content );
		$this->assertTrue( Object_Cache::is_own_dropin_content( $content ) );
	}

	/**
	 * Test that a generic legacy phrase without the WPPO signal is foreign.
	 *
	 * Guards the 'never delete foreign drop-ins' intent: the legacy marker
	 * is a strict substring of the current marker and can appear in
	 * third-party drop-ins.
	 */
	public function test_legacy_marker_without_wppo_signal_is_foreign(): void {
		$this->assertFalse(
			Object_Cache::is_own_dropin_content( '<?php // Redis Object Cache Drop-in ... some other plugin ...' )
		);
	}

	/**
	 * Test that unrelated foreign content is not ours.
	 */
	public function test_foreign_content_is_not_own(): void {
		$this->assertFalse(
			Object_Cache::is_own_dropin_content( '<?php // Redis Cache by Till Krüss ...' )
		);
	}

	/**
	 * Test that non-string input is never ours (fail-closed).
	 */
	public function test_non_string_input_is_not_own(): void {
		$this->assertFalse( Object_Cache::is_own_dropin_content( false ) );
		$this->assertFalse( Object_Cache::is_own_dropin_content( null ) );
		$this->assertFalse( Object_Cache::is_own_dropin_content( array() ) );
	}

	/**
	 * Test that is_own_dropin() stays private (static helper covers reuse).
	 */
	public function test_is_own_dropin_remains_private(): void {
		$method = new \ReflectionMethod( Object_Cache::class, 'is_own_dropin' );
		$this->assertTrue( $method->isPrivate() );
	}
}
