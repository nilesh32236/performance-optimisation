<?php
/**
 * Pins the behaviour of a zero cacheLife in the generated advanced-cache.php
 * drop-in. See issue #1769.
 *
 * The drop-in's wppo_serve_cache_file() guards its staleness check with
 * `if ( $cache_life > 0 )` and then falls through to readfile() with no else.
 * A cacheLife of 0 therefore serves the cached file with no age test at all.
 *
 * includes/Settings/class-settings-store.php:786-787 ships
 * 'enableCache' => true and 'cacheLife' => 0 as the DEFAULTS, so this is the
 * path a fresh install takes before anyone opens the settings screen.
 *
 * This test asserts the INTENDED behaviour: a cacheLife of 0 must not silently
 * disable expiry. It is expected to FAIL against the current drop-in template,
 * which is the point - it turns a prose finding into a red test.
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;

final class CacheLifeZeroTtlTest extends TestCase {

	private string $source;

	protected function setUp(): void {
		$this->source = (string) file_get_contents(
			__DIR__ . '/../../includes/Cache/class-advanced-cache-handler.php'
		);
	}

	/**
	 * The staleness check must not be skipped just because the TTL is zero.
	 * Either zero is rejected explicitly, or zero is normalised before the
	 * guard. Silently falling through to readfile() is not acceptable.
	 */
	public function test_zero_cache_life_cannot_skip_the_staleness_check(): void {
		self::assertMatchesRegularExpression(
			'/if\s*\(\s*\\\$cache_life\s*>\s*0\s*\)\s*\{/',
			$this->source,
			'Expected the drop-in template to still contain the TTL guard, so this test is measuring the real template.'
		);

		// The defect: the guard opens a block that ends and then falls through
		// to the serving code. There must be an explicit non-positive branch.
		self::assertDoesNotMatchRegularExpression(
			'/\}\s*\.\s*PHP_EOL\s*\.\s*PHP_EOL\s*\.\s*\'\s*\.\s*PHP_EOL\s*\.\s*\'\s*\.\s*PHP_EOL\s*\.\s*\'if \(\s*false !==/',
			$this->source,
			'Sanity: expected the serving block to follow the TTL guard.'
		);

		self::assertStringContainsString(
			'cache_life <= 0',
			str_replace( ' ', ' ', $this->source ),
			'A zero or negative cacheLife must be handled explicitly before the TTL guard. '
			. 'Without it, wppo_serve_cache_file() reaches readfile() with no age check.'
		);
	}

	/**
	 * The shipped default must not be a TTL of zero.
	 */
	public function test_shipped_default_cache_life_is_not_zero(): void {
		$store = (string) file_get_contents(
			__DIR__ . '/../../includes/Settings/class-settings-store.php'
		);

		self::assertDoesNotMatchRegularExpression(
			'/\'cacheLife\'\s*=>\s*0\s*,/',
			$store,
			'The shipped default cacheLife is 0, which the drop-in treats as "never expires". '
			. 'Either the default must be a real TTL, or the drop-in must reject 0 explicitly.'
		);
	}
}
