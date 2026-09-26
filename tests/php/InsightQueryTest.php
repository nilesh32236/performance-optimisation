<?php
/**
 * Behavior and boundary tests for the P3-009 Insight_Query read seam (issue #1582).
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source seam.

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Insight_Query;

/**
 * Insight_Query read-model tests.
 */
class InsightQueryTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Load the new owner directly for a dirty development classmap.
	 *
	 * @return void
	 */
	private function load_query(): void {
		if ( ! class_exists( Insight_Query::class, false ) ) {
			require_once WPPO_PLUGIN_PATH . 'includes/Insight/class-insight-query.php';
		}
	}

	/**
	 * Install the WordPress primitives shared by telemetry read tests.
	 *
	 * @return void
	 */
	private function install_read_stubs(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( false );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	/**
	 * A single-site telemetry read returns the transient payload and its key shape.
	 *
	 * @return void
	 */
	public function test_telemetry_read_uses_single_site_transient_key(): void {
		$this->load_query();
		$this->install_read_stubs();
		$expected = array(
			'page_url' => 'https://example.com/sample/',
			'ttfb'     => 123.0,
		);
		$seen_key = '';
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( $expected, &$seen_key ) {
				$seen_key = $key;
				return $expected;
			}
		);

		$this->assertSame( $expected, Insight_Query::get_telemetry( 'https://example.com/sample/' ) );
		$this->assertSame( 'wppo_audit_' . md5( 'https://example.com/sample/' ), $seen_key );
	}

	/**
	 * A multisite telemetry read retains the canonical blog-prefixed key.
	 *
	 * @return void
	 */
	public function test_telemetry_read_retains_multisite_key_isolation(): void {
		$this->load_query();
		$this->install_read_stubs();
		$seen_key = '';
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 7 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_transient' )->alias(
			static function ( $key ) use ( &$seen_key ) {
				$seen_key = $key;
				return array( 'page_url' => 'https://example.com/multisite/' );
			}
		);

		Insight_Query::get_telemetry( 'https://example.com/multisite/' );

		$this->assertSame( '7_wppo_audit_' . md5( 'https://example.com/multisite/' ), $seen_key );
	}

	/**
	 * A valid salted object-cache value is authoritative over the transient copy.
	 *
	 * @return void
	 */
	public function test_telemetry_read_prefers_matching_salted_cache_value(): void {
		$this->load_query();
		$this->install_read_stubs();
		$url                        = 'https://example.com/salted/';
		$cache_key                  = 'wppo_audit_' . md5( $url );
		$salted                     = array(
			'page_url' => $url,
			'ttfb'     => 88.0,
		);
		$transient                  = array(
			'page_url' => $url,
			'ttfb'     => 999.0,
		);
		$old_cache                  = $GLOBALS['wp_object_cache'];
		$GLOBALS['wp_object_cache'] = new WPPO_Insight_Query_Cache();

		try {
			Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
			Functions\when( 'get_option' )->justReturn( 41 );
			Functions\when( 'get_transient' )->justReturn( $transient );
			wp_cache_set_salted( $cache_key, $salted, 'wppo', '41' );

			$this->assertSame( $salted, Insight_Query::get_telemetry( $url ) );
		} finally {
			$GLOBALS['wp_object_cache'] = $old_cache;
		}
	}

	/**
	 * A stale salted value falls back to the transient without surfacing stale data.
	 *
	 * @return void
	 */
	public function test_telemetry_read_falls_back_to_transient_when_salt_is_stale(): void {
		$this->load_query();
		$this->install_read_stubs();
		$url                        = 'https://example.com/stale-salt/';
		$cache_key                  = 'wppo_audit_' . md5( $url );
		$fallback                   = array(
			'page_url' => $url,
			'ttfb'     => 145.0,
		);
		$old_cache                  = $GLOBALS['wp_object_cache'];
		$GLOBALS['wp_object_cache'] = new WPPO_Insight_Query_Cache();

		try {
			Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
			Functions\when( 'get_option' )->justReturn( 42 );
			Functions\when( 'get_transient' )->justReturn( $fallback );
			wp_cache_set_salted(
				$cache_key,
				array(
					'page_url' => $url,
					'ttfb'     => 1.0,
				),
				'wppo',
				'41'
			);

			$this->assertSame( $fallback, Insight_Query::get_telemetry( $url ) );
		} finally {
			$GLOBALS['wp_object_cache'] = $old_cache;
		}
	}

	/**
	 * The REST PageSpeed read model adds deterministic suggestions to a result.
	 *
	 * @return void
	 */
	public function test_pagespeed_report_preserves_result_and_adds_suggestions(): void {
		$this->load_query();
		$this->install_read_stubs();
		$url    = 'https://example.com/lcp/';
		$result = array(
			'scores' => array( 'performance' => 72 ),
			'vitals' => array( 'lcp' => array( 'value' => 3200 ) ),
		);
		$key    = 'wppo_pagespeed_' . md5( $url ) . '_mobile';
		Functions\when( 'get_transient' )->alias(
			static function ( $requested ) use ( $key, $result ) {
				return $key === $requested ? $result : false;
			}
		);

		$report = Insight_Query::get_pagespeed_report( $url, 'invalid-strategy' );

		$this->assertSame( 72, $report['scores']['performance'] );
		$this->assertCount( 1, $report['suggestions'] );
		$this->assertSame( 'lcp', $report['suggestions'][0]['metric'] );
	}

	/**
	 * PageSpeed failure sentinels remain untouched for adapter-level messaging.
	 *
	 * @return void
	 */
	public function test_pagespeed_report_preserves_failure_sentinel_without_augmentation(): void {
		$this->load_query();
		$this->install_read_stubs();
		$failure = array(
			'error'   => true,
			'message' => 'PageSpeed API key is not configured.',
		);
		Functions\when( 'get_transient' )->justReturn( $failure );

		$this->assertSame( $failure, Insight_Query::get_pagespeed_report( 'https://example.com/failure/', 'mobile' ) );
	}

	/**
	 * Adapters use the shared read seam and keep domain side effects in owners.
	 *
	 * @return void
	 */
	public function test_query_is_read_only_and_adapters_route_through_it(): void {
		$this->load_query();
		$query_source = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Insight/class-insight-query.php' );
		$rest_source  = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Admin/class-rest.php' );
		$abilities    = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Admin/class-abilities.php' );
		$cli          = (string) file_get_contents( WPPO_PLUGIN_PATH . 'includes/Admin/class-wppo-cli-command.php' );

		$query_tokens = array();
		foreach ( token_get_all( $query_source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NAME_QUALIFIED ), true ) ) {
				$query_tokens[] = $token[1];
			}
		}
		$query_executable = implode( ' ', $query_tokens );
		foreach ( array( 'AI_Adaptive', 'RUM', 'Telemetry::scan', 'Pagespeed::queue_scan', 'store_lcp_image_url' ) as $forbidden ) {
			$this->assertStringNotContainsString( $forbidden, $query_executable, 'Insight_Query must not own side-effecting domain work.' );
		}
		$this->assertStringContainsString( 'Insight_Query::get_telemetry', $rest_source );
		$this->assertStringContainsString( 'Insight_Query::get_pagespeed_report', $rest_source );
		$this->assertStringContainsString( 'Insight_Query::get_telemetry', $abilities );
		$this->assertStringContainsString( 'Insight_Query::get_pagespeed', $abilities );
		$this->assertStringContainsString( 'Insight_Query::get_pagespeed', $cli );
		$this->assertStringNotContainsString( 'get_transient( $transient_key )', $rest_source );
		$this->assertStringNotContainsString( 'wppo_audit_', $abilities );
		foreach ( array( $rest_source, $abilities, $cli ) as $adapter_source ) {
			$this->assertStringNotContainsString( 'Pagespeed::get_results(', $adapter_source );
		}
		$this->assertStringContainsString( 'AI_Adaptive::is_enabled()', $rest_source );
		$this->assertStringContainsString( 'Suggestion_Engine::from_ai_adaptive()', $abilities );
		$this->assertStringContainsString( 'RUM::get_aggregate_readonly()', $rest_source );
	}

	/**
	 * Administrative insight routes stay protected while RUM collection stays public.
	 *
	 * @return void
	 */
	public function test_rest_security_contract_is_unchanged(): void {
		Functions\when( 'register_rest_route' )->justReturn( true );
		$rest   = new \PerformanceOptimise\Inc\Rest();
		$routes = ( new ReflectionMethod( $rest, 'get_routes' ) )->invoke( $rest );

		foreach ( array( 'pagespeed_results', 'suggestions' ) as $route ) {
			$this->assertSame( array( $rest, 'permission_callback' ), $routes[ $route ]['permission_callback'] );
		}
		$this->assertSame( '__return_true', $routes['rum_collect']['permission_callback'] );
	}

	/**
	 * The stale-classmap fallback resolves the new query owner.
	 *
	 * @return void
	 */
	public function test_loader_map_contains_insight_query(): void {
		$map = \PerformanceOptimise\Inc\Loader_Map::fallback_map();
		$this->assertSame( 'Insight/class-insight-query.php', $map['Insight_Query'] );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile -- Small local cache fixture for salted reads.

if ( ! class_exists( 'WPPO_Insight_Query_Cache' ) ) {
	/**
	 * Minimal salted-cache backing store for read tests.
	 */
	class WPPO_Insight_Query_Cache {
		/**
		 * Stored values.
		 *
		 * @var array<string,array<string,mixed>>
		 */
		private array $values = array();

		/**
		 * Read a cache value.
		 *
		 * @param string    $key Cache key.
		 * @param string    $group Cache group.
		 * @param bool      $force Unused.
		 * @param bool|null $found Found flag.
		 * @return mixed
		 */
		public function get( $key, $group = 'default', $force = false, &$found = null ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			unset( $group, $force );
			$id = (string) $key;
			if ( ! array_key_exists( $id, $this->values ) ) {
				$found = false;
				return false;
			}
			$found = true;
			return $this->values[ $id ];
		}

		/**
		 * Store a cache value.
		 *
		 * @param string $key Cache key.
		 * @param mixed  $data Value.
		 * @param string $group Cache group.
		 * @param int    $expire Expiration.
		 * @return bool
		 */
		public function set( $key, $data, $group = 'default', $expire = 0 ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
			unset( $group, $expire );
			$this->values[ (string) $key ] = $data;
			return true;
		}
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
