<?php
/**
 * Regression: the plural `urls` input to the LiteSpeed crawler ability must not
 * fatal on a non-string element.
 *
 * `Abilities::execute_crawler()` maps `esc_url_raw` over `$input['urls']`, and
 * on PHP 8 that is a `TypeError` for anything that is not a string — including
 * a nested array, which structured input produces easily.
 *
 * The single `url` case directly above was already guarded, with a comment
 * naming this exact reason: *"Audit #1434: array input fatals esc_url_raw on
 * PHP 8 — skip non-strings like resolve_input_url() does."* The plural `urls`
 * case was simply missed, so one bad element took down the whole run instead of
 * skipping that element.
 *
 * How this observes the fix: `execute_crawler()` dispatches to
 * `LiteSpeed_Crawler::crawl_batch()`, which exists in the classmap, so the test
 * must not reach it. `wp_http_validate_url` is applied to every gathered URL,
 * so the stub records there and returns false — the list then empties and the
 * method returns "No valid URLs to crawl." *before* dispatch. The recorded list
 * is therefore the gathered set, which is exactly what the guard governs.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Abilities;
use Brain\Monkey\Functions;

/**
 * Tests for the crawler ability's plural input handling.
 *
 * @package PerformanceOptimise\Tests
 */
class AbilitiesCrawlerInputTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * URLs seen by wp_http_validate_url, in order.
	 *
	 * @var array
	 */
	private $seen = array();

	/**
	 * Stub the WordPress surface `execute_crawler()` touches.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		$this->seen = array();
		Functions\stubs(
			array(
				'esc_url_raw',
				'wp_parse_url',
				'sanitize_text_field',
				'wp_http_validate_url',
				'get_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'__',
			)
		);
		Functions\when( 'esc_url_raw' )->alias(
			static function ( $url ) {
				// Mirror the real signature: esc_url_raw( string $url ). Passing
				// a non-string here is exactly the TypeError under test, so the
				// stub must NOT silently coerce it — otherwise the regression
				// would be masked by the test double.
				if ( ! is_string( $url ) ) {
					throw new \TypeError( 'esc_url_raw(): Argument #1 ($url) must be of type string' );
				}
				return $url;
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );

		// Record every gathered URL, and reject them all so the method returns
		// "No valid URLs to crawl." before dispatching to the real crawler.
		$seen = &$this->seen;
		Functions\when( 'wp_http_validate_url' )->alias(
			static function ( $url ) use ( &$seen ) {
				$seen[] = $url;
				return false;
			}
		);
	}

	/**
	 * Run `execute_crawler()` and return the URLs it gathered.
	 *
	 * Named away from `run`, which is final on PHPUnit's TestCase.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private function invoke_crawler( array $input ): array {
		$reflection = new ReflectionMethod( Abilities::class, 'execute_crawler' );
		// The early return proves the crawler was never dispatched.
		$result = (array) $reflection->invoke( null, $input );
		$this->assertSame(
			'No valid URLs to crawl.',
			$result['error'] ?? '',
			'the test must short-circuit before LiteSpeed_Crawler::crawl_batch() runs'
		);
		return $this->seen;
	}

	/**
	 * A plain string list still works — the guard must not drop real URLs.
	 *
	 * @return void
	 */
	public function test_string_urls_are_passed_through(): void {
		$this->install_stubs();

		$seen = $this->invoke_crawler( array( 'urls' => array( 'http://example.com/a', 'http://example.com/b' ) ) );

		$this->assertContains( 'http://example.com/a', $seen );
		$this->assertContains( 'http://example.com/b', $seen );
	}

	/**
	 * A nested array element must be skipped, not fatal the run.
	 *
	 * @return void
	 */
	public function test_non_string_url_elements_are_skipped_not_fatal(): void {
		$this->install_stubs();

		$seen = $this->invoke_crawler(
			array(
				'urls' => array(
					'http://example.com/ok',
					array( 'nested' => 'http://example.com/nope' ),
					'http://example.com/also-ok',
				),
			)
		);

		$this->assertContains( 'http://example.com/ok', $seen, 'valid URLs around the bad element must survive' );
		$this->assertContains( 'http://example.com/also-ok', $seen );
		$this->assertNotContains(
			array( 'nested' => 'http://example.com/nope' ),
			$seen,
			'the non-string element must be dropped, not passed through'
		);
	}

	/**
	 * Every element bad is still a clean empty result, not a TypeError.
	 *
	 * @return void
	 */
	public function test_all_non_string_elements_yield_no_urls(): void {
		$this->install_stubs();

		$seen = $this->invoke_crawler( array( 'urls' => array( array( 'a' ), array( 'b' ), 42 ) ) );

		$this->assertSame( array(), $seen );
	}
}
