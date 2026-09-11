<?php
/**
 * Tests for RUM-driven speculation rules (issue #987).
 *
 * Covers Main::get_rum_top_urls() merging, query-string exclusion,
 * the singular eager home-link rule, the archive first-post document
 * rule, logged-in suppression, and single-block no-duplicate output.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */

use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests RUM-driven eager list + document speculation rules.
 *
 * @package PerformanceOptimise\Tests
 * @since 2.0.0
 */
class MainSpeculationDocumentRulesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options store.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Register WP stubs backed by the in-memory options store.
	 *
	 * @param array $settings wppo_settings value.
	 * @param array $rum      wppo_web_vitals_rum value.
	 * @return void
	 */
	private function install_stubs( array $settings = array(), array $rum = array() ): void {
		$this->options = array(
			'wppo_settings'       => $settings,
			'wppo_web_vitals_rum' => $rum,
			'permalink_structure' => '/%postname%/',
		);

		Functions\when( 'untrailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' );
			}
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_singular' )->justReturn( false );
		Functions\when( 'is_archive' )->justReturn( false );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $data ) {
				return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
	}

	/**
	 * Build a Main instance with the given preload_settings.
	 *
	 * @param array $preload_settings preload_settings option value.
	 * @return Main
	 */
	private function make_main( array $preload_settings ): Main {
		$reflection = new \ReflectionClass( Main::class );
		$main       = $reflection->newInstanceWithoutConstructor();

		$options = $reflection->getProperty( 'options' );
		$options->setAccessible( true );
		$options->setValue(
			$main,
			array(
				'preload_settings' => $preload_settings,
				'cache_settings'   => array( 'enableCache' => true ),
			)
		);

		return $main;
	}

	/**
	 * Collect normalized target paths from list rules and document rules.
	 *
	 * The previous no-duplicate collector only looked at list-source `urls`
	 * arrays, so a URL that also appeared as the archive document rule's
	 * `href_matches` pattern was invisible. This walks both sources and reduces
	 * each entry to a path (absolute list URLs via `wp_parse_url()`, document
	 * patterns with the trailing `*` stripped) so the two can be compared on the
	 * same basis.
	 *
	 * @since 2.0.0
	 *
	 * @param array $rules Speculation rules.
	 * @return string[] Normalized target paths.
	 */
	private function collect_rule_target_paths( array $rules ): array {
		$targets = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$source = $rule['source'] ?? '';

			if ( 'list' === $source && ! empty( $rule['urls'] ) && is_array( $rule['urls'] ) ) {
				foreach ( $rule['urls'] as $url ) {
					if ( ! is_string( $url ) || '' === $url ) {
						continue;
					}
					$path      = wp_parse_url( $url, PHP_URL_PATH );
					$targets[] = untrailingslashit( is_string( $path ) && '' !== $path ? $path : $url );
				}
			}

			if ( 'document' === $source && isset( $rule['where'] ) && is_array( $rule['where'] ) ) {
				$and = $rule['where']['and'] ?? array();
				if ( ! is_array( $and ) ) {
					continue;
				}
				foreach ( $and as $condition ) {
					if ( ! is_array( $condition ) || empty( $condition['href_matches'] ) || ! is_string( $condition['href_matches'] ) ) {
						continue;
					}
					$targets[] = untrailingslashit( rtrim( $condition['href_matches'], '*' ) );
				}
			}
		}

		return array_values( $targets );
	}

	/**
	 * Default settings: speculation on.
	 *
	 * @return array
	 */
	private function default_settings(): array {
		return array(
			'preload_settings'  => array(
				'enableSpeculationRules' => true,
				'speculationMode'        => 'prefetch',
				'speculationEagerness'   => 'moderate',
			),
			'performance_audit' => array(
				'high_value_urls' => array(),
			),
		);
	}

	/**
	 * Singular with home link emits an eager prerender list rule for home.
	 *
	 * @return void
	 */
	public function test_singular_emits_eager_prerender_for_home_link(): void {
		$this->install_stubs( $this->default_settings() );
		Functions\when( 'is_singular' )->justReturn( true );
		$main = $this->make_main( $this->default_settings()['preload_settings'] );

		$rules = $main->filter_speculation_list_rules( array() );

		$eager_rules = array_values(
			array_filter(
				$rules,
				static function ( $rule ) {
					return is_array( $rule ) && ( $rule['source'] ?? '' ) === 'list' && ( $rule['eagerness'] ?? '' ) === 'eager';
				}
			)
		);

		$this->assertNotEmpty( $eager_rules );
		$this->assertSame( array( 'http://example.com/' ), $eager_rules[0]['urls'] );
	}

	/**
	 * Singular without a home link emits no eager rule.
	 *
	 * @return void
	 */
	public function test_singular_absent_without_home_link(): void {
		$this->install_stubs( $this->default_settings() );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'home_url' )->justReturn( '' );
		Util::reset_cached_home_urls();
		$main = $this->make_main( $this->default_settings()['preload_settings'] );

		$rules = $main->filter_speculation_list_rules( array() );

		$this->assertSame( array(), $rules );
	}

	/**
	 * Archive emits a first-post selector document rule from query posts.
	 *
	 * @return void
	 */
	public function test_archive_emits_first_post_document_rule(): void {
		$this->install_stubs( $this->default_settings() );
		Functions\when( 'is_archive' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias(
			static function ( $post ) {
				unset( $post );
				return 'http://example.com/first-post/';
			}
		);
		$GLOBALS['wp_query'] = (object) array( 'posts' => array( (object) array( 'ID' => 1 ) ) );
		try {
			$main = $this->make_main( $this->default_settings()['preload_settings'] );

			$rules = $main->filter_speculation_list_rules( array() );

			$document_rules = array_values(
				array_filter(
					$rules,
					static function ( $rule ) {
						return is_array( $rule ) && ( $rule['source'] ?? '' ) === 'document';
					}
				)
			);

			$this->assertCount( 1, $document_rules );
			$where = wp_json_encode( $document_rules[0]['where'] );
			$this->assertStringContainsString( 'first-post', $where );
			$this->assertStringContainsString( 'selector_matches', $where );
		} finally {
			unset( $GLOBALS['wp_query'] );
		}
	}

	/**
	 * Query-string and fragment URLs stay excluded from the list.
	 *
	 * @return void
	 */
	public function test_query_string_urls_excluded(): void {
		$settings = $this->default_settings();
		$settings['performance_audit']['high_value_urls'] = array(
			'http://example.com/good/',
			'http://example.com/sale/?utm_source=x',
			'http://example.com/page/?foo=bar',
			'http://example.com/anchored/#section',
		);
		$this->install_stubs( $settings );
		$main = $this->make_main( $settings['preload_settings'] );

		$urls = $main->get_speculation_list_urls();

		$this->assertSame(
			array(
				'http://example.com/',
				'http://example.com/good/',
			),
			$urls
		);
	}

	/**
	 * RUM top URLs are merged into the list; commerce paths stay excluded.
	 *
	 * @return void
	 */
	public function test_rum_top_urls_merged_and_commerce_excluded(): void {
		$settings = $this->default_settings();
		$rum      = array(
			'2026-09-10' => array(
				'/popular/' => array(
					'lcp'  => array(
						'n'   => 50,
						'sum' => 100000.0,
					),
					'ttfb' => array(
						'n'   => 50,
						'sum' => 25000.0,
					),
				),
				'/cart/'    => array(
					'lcp' => array(
						'n'   => 99,
						'sum' => 999999.0,
					),
				),
				'/'         => array(
					'lcp' => array(
						'n'   => 10,
						'sum' => 10000.0,
					),
				),
			),
		);
		$this->install_stubs( $settings, $rum );
		$main = $this->make_main( $settings['preload_settings'] );

		$urls = $main->get_speculation_list_urls();

		$this->assertContains( 'http://example.com/popular/', $urls );
		$this->assertNotContains( 'http://example.com/cart/', $urls );
	}

	/**
	 * Logged-in visitors get no speculation rules.
	 *
	 * @return void
	 */
	public function test_logged_in_suppresses_rules(): void {
		$this->install_stubs( $this->default_settings() );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$main = $this->make_main( $this->default_settings()['preload_settings'] );

		$this->assertSame( array(), $main->filter_speculation_list_rules( array() ) );
	}

	/**
	 * No URL appears twice across the single-block contribution.
	 *
	 * @return void
	 */
	public function test_no_duplicate_urls_across_rules(): void {
		$this->install_stubs( $this->default_settings() );
		Functions\when( 'is_singular' )->justReturn( true );
		$main = $this->make_main( $this->default_settings()['preload_settings'] );

		$pre_existing = array(
			array(
				'source'    => 'list',
				'urls'      => array( 'http://example.com/' ),
				'eagerness' => 'conservative',
			),
		);
		$rules        = $main->filter_speculation_list_rules( $pre_existing );

		$all_urls = array();
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || ( $rule['source'] ?? '' ) !== 'list' || empty( $rule['urls'] ) || ! is_array( $rule['urls'] ) ) {
				continue;
			}
			foreach ( $rule['urls'] as $url ) {
				$all_urls[] = $url;
			}
		}

		$this->assertSame( $all_urls, array_unique( $all_urls ) );
		$this->assertContains( 'http://example.com/', $all_urls );

		// Also collect document-source targets (none on a singular view) so
		// the assertion covers both contribution kinds.
		$target_paths = $this->collect_rule_target_paths( $rules );
		$this->assertSame( $target_paths, array_unique( $target_paths ) );
	}

	/**
	 * No target duplicates across the generic list and the archive document rule.
	 *
	 * Strengthens the list-only collector above: the archive document rule
	 * carries an `href_matches` pattern rather than a `urls` array, so this
	 * extracts that path too and asserts the plugin-generated generic list
	 * (home + RUM) does not target the same path as the archive first-post
	 * document rule. The two rule kinds are independent speculation semantics
	 * (a list prefetches the URL itself; a document rule matches in-page links),
	 * so a user-configured high-value URL that equals the first post is out of
	 * scope for this invariant.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function test_no_duplicate_across_list_and_document_sources(): void {
		$settings = $this->default_settings();
		$settings['performance_audit']['high_value_urls'] = array( 'http://example.com/first-post/' );
		$this->install_stubs( $settings );
		Functions\when( 'is_archive' )->justReturn( true );
		Functions\when( 'get_permalink' )->alias(
			static function ( $post ) {
				unset( $post );
				return 'http://example.com/first-post/';
			}
		);
		$GLOBALS['wp_query'] = (object) array( 'posts' => array( (object) array( 'ID' => 1 ) ) );

		try {
			$main  = $this->make_main( $settings['preload_settings'] );
			$rules = $main->filter_speculation_list_rules( array() );
		} finally {
			unset( $GLOBALS['wp_query'] );
		}

		// The document rule carries the archive first-post href_matches.
		$document_rules = array_values(
			array_filter(
				$rules,
				static function ( $rule ) {
					return is_array( $rule ) && ( $rule['source'] ?? '' ) === 'document';
				}
			)
		);
		$this->assertCount( 1, $document_rules );
		$this->assertSame( '/first-post/*', $document_rules[0]['where']['and'][0]['href_matches'] );

		// The generic list rule is still emitted alongside it.
		$list_urls = array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && ( $rule['source'] ?? '' ) === 'list' && ! empty( $rule['urls'] ) && is_array( $rule['urls'] ) ) {
				$list_urls = array_merge( $list_urls, $rule['urls'] );
			}
		}
		$this->assertContains( 'http://example.com/', $list_urls );

		// No path is targeted by both a list rule and the document rule.
		$target_paths = $this->collect_rule_target_paths( $rules );
		$this->assertSame( $target_paths, array_unique( $target_paths ) );
		$this->assertContains( '/first-post', $target_paths );
	}

	/**
	 * The document-rules toggle suppresses singular/archive contextual rules.
	 *
	 * @return void
	 */
	public function test_document_rules_toggle_off_suppresses_contextual_rules(): void {
		$this->install_stubs( $this->default_settings() );
		Functions\when( 'is_singular' )->justReturn( true );
		$preload                             = $this->default_settings()['preload_settings'];
		$preload['speculationDocumentRules'] = false;
		$main                                = $this->make_main( $preload );

		$rules = $main->filter_speculation_list_rules( array() );

		foreach ( $rules as $rule ) {
			$this->assertNotSame( 'eager', $rule['eagerness'] ?? null );
			$this->assertNotSame( 'document', $rule['source'] ?? null );
		}

		// Suppressing contextual rules must NOT suppress the generic list:
		// the home URL is still emitted as a list-source rule.
		$list_rules = array_values(
			array_filter(
				$rules,
				static function ( $rule ) {
					return is_array( $rule ) && ( $rule['source'] ?? '' ) === 'list' && ! empty( $rule['urls'] ) && is_array( $rule['urls'] );
				}
			)
		);
		$this->assertNotEmpty( $list_rules );

		$list_urls = array();
		foreach ( $list_rules as $rule ) {
			$list_urls = array_merge( $list_urls, $rule['urls'] );
		}
		$this->assertContains( 'http://example.com/', $list_urls );
	}
}
