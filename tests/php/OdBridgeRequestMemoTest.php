<?php
/**
 * Characterization tests for the OD_Bridge per-request memo (P3-021).
 *
 * Covers the request-local classification: memo-hit dedup, the 30-entry
 * bound reset, explicit clearing, the Runtime_State registry contract,
 * and switch_blog parity via Image_Optimisation::clear_runtime_caches().
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\OD_Bridge;
use PerformanceOptimise\Inc\Runtime_State;

/**
 * OD_Bridge request memo characterization tests.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */
class OdBridgeRequestMemoTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options for Util::get_settings().
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Read the private OD_Bridge request memo.
	 *
	 * @return array<string,mixed>
	 */
	private function read_memo(): array {
		$prop = new \ReflectionProperty( OD_Bridge::class, 'request_memo' );
		$memo = $prop->getValue();
		return is_array( $memo ) ? $memo : array();
	}

	/**
	 * Overwrite the private OD_Bridge request memo.
	 *
	 * @param array<string,mixed> $memo Memo contents.
	 * @return void
	 */
	private function write_memo( array $memo ): void {
		$prop = new \ReflectionProperty( OD_Bridge::class, 'request_memo' );
		$prop->setValue( null, $memo );
	}

	/**
	 * Install stubs for WP functions used by OD_Bridge and the reset paths.
	 *
	 * @return void
	 */
	private function install_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'apply_filters',
				'get_current_blog_id',
				'is_multisite',
				'home_url',
				'untrailingslashit',
				'esc_url_raw',
				'add_query_arg',
				'wp_parse_url',
				'get_transient',
				'wp_using_ext_object_cache',
				'delete_transient',
			)
		);

		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return $this->options;
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
				$args = func_get_args();
				return $args[1];
			}
		);
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . (string) $path;
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $v ) {
				return rtrim( (string) $v, '/' );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'add_query_arg' )->alias(
			static function () {
				return 'http://example.com/current-page/';
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'delete_transient' )->justReturn( true );

		global $wp;
		$wp          = new \stdClass();
		$wp->request = 'current-page';

		$GLOBALS['od_url_metrics'] = array();
		if ( ! isset( $GLOBALS['od_metrics_stub'] ) ) {
			$GLOBALS['od_metrics_stub'] = array();
		}
	}

	/**
	 * Define the OD stub class/function when not already defined.
	 *
	 * @return void
	 */
	private function ensure_od_class(): void {
		if ( ! class_exists( 'OD_URL_Metric' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
				'
                class OD_URL_Metric {
                    private $data;
                    public function __construct( $data = array() ) { $this->data = $data; }
                    public function get_lcp_element() { return $this->data["lcp"] ?? null; }
                    public function get_elements() { return $this->data["elements"] ?? array(); }
                    public function get_viewport_width() { return $this->data["viewportWidth"] ?? 0; }
                    public function get_url() { return $this->data["url"] ?? ""; }
                    public function is_lcp() { return !empty($this->data["isLCP"]); }
                    public function get_src() { return $this->data["src"] ?? ""; }
                }
                '
			);
		}

		if ( ! function_exists( 'od_get_url_metrics' ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
				'
                function od_get_url_metrics( $url = "" ) {
                    return $GLOBALS["od_metrics_stub"] ?? array();
                }
                '
			);
		}
	}

	/**
	 * Repeated lookups on the same URL hit the memo instead of re-scanning.
	 *
	 * @return void
	 */
	public function test_memo_hit_dedups_repeated_lookups(): void {
		$this->install_stubs();
		$this->ensure_od_class();
		$this->options = array(
			'od_integration' => array( 'enabled' => true ),
		);

		$hero_a = 'https://example.com/wp-content/uploads/hero-a.jpg';
		$hero_b = 'https://example.com/wp-content/uploads/hero-b.jpg';

		$GLOBALS['od_metrics_stub'] = array(
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 400,
					'lcp'           => array(
						'src'   => $hero_a,
						'isLCP' => true,
					),
				)
			),
		);

		$this->assertSame( $hero_a, OD_Bridge::get_lcp_url() );

		// Swap the underlying metrics: a memo hit must still return hero A.
		$GLOBALS['od_metrics_stub'] = array(
			new \OD_URL_Metric(
				array(
					'viewportWidth' => 400,
					'lcp'           => array(
						'src'   => $hero_b,
						'isLCP' => true,
					),
				)
			),
		);

		$this->assertSame( $hero_a, OD_Bridge::get_lcp_url(), 'Second lookup on the same URL must hit the request memo.' );

		// Explicit reset drops the memo so the next lookup re-scans.
		OD_Bridge::clear_request_memo();
		$this->assertSame( $hero_b, OD_Bridge::get_lcp_url(), 'After clear_request_memo() the lookup must re-scan.' );
	}

	/**
	 * The memo resets itself once it grows past 30 entries.
	 *
	 * @return void
	 */
	public function test_memo_bound_resets_past_thirty_entries(): void {
		$this->install_stubs();
		OD_Bridge::clear_request_memo();

		$set = new \ReflectionMethod( OD_Bridge::class, 'request_memo_set' );
		for ( $i = 0; $i < 30; $i++ ) {
			$set->invoke( null, 'k' . $i, 'v' . $i );
		}
		$this->assertCount( 30, $this->read_memo() );

		$set->invoke( null, 'k30', 'v30' );
		$this->assertSame( array(), $this->read_memo(), '31st entry must trigger the bounded reset.' );
	}

	/**
	 * Explicit clearing empties a populated memo.
	 *
	 * @return void
	 */
	public function test_clear_request_memo_empties_memo(): void {
		$this->install_stubs();
		$this->write_memo( array( 'lcp:http://example.com/current-page' => 'https://example.com/hero.jpg' ) );
		$this->assertNotEmpty( $this->read_memo() );

		OD_Bridge::clear_request_memo();
		$this->assertSame( array(), $this->read_memo() );
	}

	/**
	 * The Runtime_State registry contract resets the OD memo directly.
	 *
	 * @return void
	 */
	public function test_runtime_state_reset_clears_memo(): void {
		$this->install_stubs();
		$this->assertArrayHasKey( 'OD_Bridge', Runtime_State::owners(), 'OD_Bridge must be a registered reset owner.' );

		$this->write_memo( array( 'lcp:http://example.com/current-page' => 'https://example.com/hero.jpg' ) );
		Runtime_State::reset_all();
		$this->assertSame( array(), $this->read_memo(), 'Runtime_State::reset_all() must clear the OD memo.' );
	}

	/**
	 * Blog-switch parity: the indirect clear_runtime_caches() path also clears the memo.
	 *
	 * @return void
	 */
	public function test_switch_blog_parity_via_clear_runtime_caches(): void {
		$this->install_stubs();
		$this->write_memo( array( 'lcp:http://example.com/current-page' => 'https://example.com/hero.jpg' ) );

		Image_Optimisation::clear_runtime_caches();
		$this->assertSame( array(), $this->read_memo(), 'clear_runtime_caches() must preserve the indirect OD reset.' );
	}
}
