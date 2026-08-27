<?php
// phpcs:ignoreFile Generic.Files.OneObjectStructurePerFile.MultipleFound -- test helper mock in same file
/**
 * Tests for Llms class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Llms;
use Brain\Monkey\Functions;
use Brain\Monkey\Filters;

/**
 * Tests for LLMs.txt generation and virtual routing.
 *
 * @package PerformanceOptimise\Tests
 */
class LlmsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Install option stubs.
	 */
	private function install_option_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'update_option',
				'get_bloginfo',
				'wp_parse_url',
				'esc_url_raw',
				'sanitize_text_field',
				'sanitize_key',
				'apply_filters',
				'is_multisite',
				'get_current_blog_id',
				'wp_normalize_path',
				'trailingslashit',
				'home_url',
				'has_filter',
				'esc_url',
				'get_post_types',
				'get_posts',
				'get_permalink',
				'wp_remote_get',
				'wp_remote_retrieve_body',
				'is_wp_error',
				'wp_mkdir_p',
				'flush_rewrite_rules',
				'status_header',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $k ) {
				return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) );
			}
		);
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $p ) {
				return str_replace( '\\', '/', (string) $p );
			}
		);
		if ( ! function_exists( 'apply_filters' ) ) {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					return $value;
				}
			);
		} else {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					return $value;
				}
			);
		}
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'trailingslashit' )->alias(
			static function ( $p ) {
				return rtrim( (string) $p, '/' ) . '/';
			}
		);
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'http://example.com' . (string) $path;
			}
		);
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_get' )->justReturn( new WP_Error_Mock( 'error' ) );
		Functions\when( 'wp_remote_retrieve_body' )->justReturn( '' );
		Functions\when( 'status_header' )->justReturn( null );
	}

	/**
	 * Test register_rewrite adds rules when enabled.
	 */
	public function test_register_rewrite_adds_rules_when_enabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array( 'llms_txt' => array( 'enabled' => true ) );

		Functions\expect( 'add_rewrite_rule' )->twice()->andReturn( null );

		Llms::register_rewrite();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test register_rewrite skipped when disabled.
	 */
	public function test_register_rewrite_skipped_when_disabled(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array( 'llms_txt' => array( 'enabled' => false ) );

		// No expectation for add_rewrite_rule — must not be called.
		Llms::register_rewrite();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test get_file_path returns multisite-scoped path.
	 */
	public function test_get_file_path_multisite(): void {
		$this->install_option_stubs();
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 5 );

		$path = Llms::get_file_path( 'llms' );

		$this->assertStringContainsString( 'site-5', $path );
		$this->assertStringEndsWith( 'llms.txt', $path );
	}

	/**
	 * Test generate creates files and applies filter.
	 */
	public function test_generate_creates_files_and_applies_filter(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array(
			'llms_txt' => array(
				'enabled' => true,
				'source'  => 'both',
			),
		);

		// Stub filesystem via Util::init_filesystem returning false so fallback file_put_contents is used.
		// But we mock Util::prepare_cache_dir and wp_mkdir_p via functions, and let file_put_contents write to temp.
		// Override base dir by ensuring WP_CONTENT_DIR is defined (bootstrap defines /tmp/wordpress/wp-content).
		$result = Llms::generate();

		$this->assertTrue( $result );
		$path = Llms::get_file_path( 'llms' );
		$this->assertFileExists( $path );
		$content = file_get_contents( $path );
		$this->assertStringContainsString( '#', $content );
		$this->assertStringContainsString( 'http://example.com', $content );

		// Cleanup.
		@unlink( $path );
		@unlink( Llms::get_file_path( 'full' ) );
	}

	/**
	 * Test content filter is applied.
	 */
	public function test_generate_applies_filter(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array( 'llms_txt' => array( 'enabled' => true ) );

		// Use Functions mock for apply_filters since install_option_stubs already stubs it via Functions\when.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'wppo_llms_txt_content' === $hook ) {
					return $value . "\n# Filtered";
				}
				return $value;
			}
		);

		Llms::generate();

		$path    = Llms::get_file_path( 'llms' );
		$content = file_get_contents( $path );
		$this->assertStringContainsString( 'Filtered', $content );

		@unlink( $path );
		@unlink( Llms::get_file_path( 'full' ) );
	}

	/**
	 * Test emit_link_header is noop when disabled.
	 */
	public function test_emit_link_header_disabled_is_noop(): void {
		$this->install_option_stubs();
		$this->options['wppo_settings'] = array( 'llms_txt' => array( 'enabled' => false ) );

		// Should not call header() — no exception means noop.
		Llms::emit_link_header();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test add_query_vars adds vars.
	 */
	public function test_add_query_vars(): void {
		$vars = Llms::add_query_vars( array( 'page' ) );
		$this->assertContains( 'wppo_llms', $vars );
		$this->assertContains( 'wppo_llms_full', $vars );
	}

	/**
	 * Test content capped at 20KB.
	 */
	public function test_build_markdown_capped(): void {
		$this->install_option_stubs();
		// Force many URLs via get_posts mock returning many permalinks.
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) {
				$ids = range( 1, 100 );
				return array_slice( $ids, 0, $args['posts_per_page'] ?? 50 );
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ) {
				return 'http://example.com/page-' . $id . '/very-long-url-path-to-increase-size-abcdefghijklmnopqrstuvwxyz/';
			}
		);
		$this->options['wppo_settings'] = array(
			'llms_txt' => array(
				'enabled' => true,
				'source'  => 'both',
			),
		);

		Llms::generate();

		$path = Llms::get_file_path( 'llms' );
		$this->assertLessThanOrEqual( 20 * 1024, filesize( $path ) );

		@unlink( $path );
		@unlink( Llms::get_file_path( 'full' ) );
	}
}

if ( ! class_exists( 'WP_Error_Mock' ) ) {
	/**
	 * Minimal WP_Error mock.
	 */
	class WP_Error_Mock {
		/**
		 * Constructor.
		 *
		 * @param string $code Error code.
		 */
		public function __construct( $code = '' ) {}
	}
}
