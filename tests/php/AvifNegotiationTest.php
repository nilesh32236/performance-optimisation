<?php
/**
 * Tests for local AVIF/WebP negotiation hardening (issue #1096).
 *
 * Covers: Imagick AVIF helpers (fail-open), Accept-header negotiation
 * matrix (Firefox 65-92 WebP-only vs old Safari original), htaccess
 * next-gen block on plain Apache (not just LiteSpeed) with AVIF-before-WebP
 * ordering + Vary: Accept, Nginx try_files ordering + Vary, and the
 * server-agnostic Apache gate.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Htaccess_Handler;
use PerformanceOptimise\Inc\Image_Optimisation;
use PerformanceOptimise\Inc\Img_Converter;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Server_Rules;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * AVIF/WebP negotiation tests.
 *
 * @package PerformanceOptimise\Tests
 */
class AvifNegotiationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Base options for converter/optimisation instances.
	 *
	 * @var array
	 */
	private array $options;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();

		Util::clear_settings_cache();
		Image_Optimisation::clear_file_exists_cache();
		$this->reset_nextgen_cache();

		$this->options = array(
			'image_optimisation' => array(
				'convertImg'              => true,
				'conversionFormat'        => 'both',
				'avifFirst'               => true,
				'smartQuality'            => true,
				'skipSmallThresholdBytes' => 5120,
				'placeholderType'         => 'none',
			),
		);

		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( true );
		Functions\when( 'wp_normalize_path' )->alias(
			static function ( $path ) {
				return str_replace( '\\', '/', (string) $path );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		unset( $_SERVER['HTTP_ACCEPT'] );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}

	/**
	 * Tear down test environment.
	 */
	protected function tearDown(): void {
		Util::clear_settings_cache();
		Image_Optimisation::clear_file_exists_cache();
		$this->reset_nextgen_cache();
		unset( $_SERVER['HTTP_ACCEPT'] );
		unset( $_SERVER['SERVER_SOFTWARE'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Reset the LiteSpeed next-gen static cache between tests.
	 */
	private function reset_nextgen_cache(): void {
		$prop = new ReflectionProperty( LiteSpeed_Integration::class, 'cached_nextgen' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	/**
	 * Stub wppo_settings for gate tests.
	 *
	 * @param array $settings Settings to return for wppo_settings.
	 */
	private function stub_settings( array $settings ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) use ( $settings ) {
				if ( 'wppo_settings' === $name ) {
					return $settings;
				}
				return $fallback;
			}
		);
		Util::clear_settings_cache();
		$this->reset_nextgen_cache();
	}

	/**
	 * Imagick AVIF probe must return a boolean and never fatal.
	 *
	 * @since NEXT
	 */
	public function test_is_imagick_avif_available_returns_bool(): void {
		$this->assertIsBool( Img_Converter::is_imagick_avif_available() );
	}

	/**
	 * Imagick AVIF encoder must fail open on bad inputs (never fatal).
	 *
	 * @since NEXT
	 */
	public function test_encode_avif_via_imagick_fail_open(): void {
		$converter = new Img_Converter( $this->options );

		$this->assertFalse( $converter->encode_avif_via_imagick( '', '', 80 ), 'Empty paths fail open' );
		$this->assertFalse( $converter->encode_avif_via_imagick( '/definitely/missing/source.jpg', '/tmp/wppo-test.avif', 80 ), 'Missing source fails open' );
		$this->assertFalse( $converter->encode_avif_via_imagick( '/tmp/wordpress/wp-content/uploads/x.jpg', '/etc/wppo-evil.avif', 80 ), 'Unsafe write path fails open' );
	}

	/**
	 * Apache-aware next-gen gate must be server-agnostic (no LiteSpeed needed).
	 *
	 * @since NEXT
	 */
	public function test_apache_gate_enabled_without_litespeed(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->stub_settings(
			array(
				'litespeed_integration' => array( 'enableNextGenRewrite' => true ),
				'image_optimisation'    => array( 'convertImg' => true ),
			)
		);

		$this->assertTrue( LiteSpeed_Integration::is_nextgen_rewrite_enabled_for_apache() );
	}

	/**
	 * Apache-aware gate must stay off when conversion is disabled.
	 *
	 * @since NEXT
	 */
	public function test_apache_gate_off_without_convert(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->stub_settings(
			array(
				'litespeed_integration' => array( 'enableNextGenRewrite' => true ),
				'image_optimisation'    => array( 'convertImg' => false ),
			)
		);

		$this->assertFalse( LiteSpeed_Integration::is_nextgen_rewrite_enabled_for_apache() );
	}

	/**
	 * Plain Apache hosts must get the Accept-aware AVIF-before-WebP block.
	 *
	 * @since NEXT
	 */
	public function test_htaccess_nextgen_on_plain_apache(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->stub_settings(
			array(
				'litespeed_integration' => array( 'enableNextGenRewrite' => true ),
				'image_optimisation'    => array( 'convertImg' => true ),
			)
		);

		$rules  = Htaccess_Handler::get_rules();
		$joined = implode( "\n", $rules );

		$this->assertStringContainsString( 'HTTP:Accept', $joined, 'Accept-aware rewrite required' );
		$this->assertStringContainsString( 'Vary Accept', $joined, 'Vary: Accept required' );

		// Scope ordering to the next-gen delivery block: the base
		// expires block above also names both MIME types.
		$block_pos = strpos( $joined, '# WPPO Next-gen delivery' );
		$this->assertNotFalse( $block_pos, 'Next-gen delivery block must be present' );
		$block    = substr( $joined, (int) $block_pos );
		$avif_pos = strpos( $block, 'image/avif' );
		$webp_pos = strpos( $block, 'image/webp' );
		$this->assertNotFalse( $avif_pos, 'AVIF branch must be present' );
		$this->assertNotFalse( $webp_pos, 'WebP branch must be present' );
		$this->assertLessThan( $webp_pos, $avif_pos, 'AVIF rewrite must precede WebP rewrite' );
	}

	/**
	 * Htaccess must omit the next-gen block when conversion is disabled.
	 *
	 * @since NEXT
	 */
	public function test_htaccess_omits_nextgen_without_convert(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->stub_settings(
			array(
				'litespeed_integration' => array( 'enableNextGenRewrite' => true ),
				'image_optimisation'    => array( 'convertImg' => false ),
			)
		);

		$rules  = Htaccess_Handler::get_rules();
		$joined = implode( "\n", $rules );

		$this->assertStringNotContainsString( 'HTTP:Accept', $joined );
	}

	/**
	 * Htaccess next-gen output must be stable across calls (flush survival proxy).
	 *
	 * Get_rules() is pure from settings: identical settings must yield
	 * identical rules, so a cache flush (which never touches .htaccess)
	 * cannot silently change the emitted block.
	 *
	 * @since NEXT
	 */
	public function test_htaccess_rules_stable_across_calls(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->stub_settings(
			array(
				'litespeed_integration' => array( 'enableNextGenRewrite' => true ),
				'image_optimisation'    => array( 'convertImg' => true ),
			)
		);

		$first  = implode( "\n", Htaccess_Handler::get_rules() );
		$second = implode( "\n", Htaccess_Handler::get_rules() );

		$this->assertSame( $first, $second );
		$this->assertStringContainsString( 'Vary Accept', $first );
	}

	/**
	 * Nginx rules must try AVIF before WebP with Vary: Accept.
	 *
	 * @since NEXT
	 */
	public function test_nginx_try_files_avif_before_webp_with_vary(): void {
		$this->stub_settings(
			array(
				'litespeed_integration' => array( 'enableNextGenRewrite' => true ),
				'image_optimisation'    => array( 'convertImg' => true ),
			)
		);

		$rules = Server_Rules::get_nginx_rules();

		$this->assertStringContainsString( 'image/avif', $rules );
		$this->assertStringContainsString( 'Vary Accept', $rules );

		$try_pos = strpos( $rules, 'try_files' );
		$this->assertNotFalse( $try_pos, 'try_files directive must be present' );

		$try_line = substr( $rules, $try_pos, 400 );
		$avif_pos = strpos( $try_line, 'avif' );
		$webp_pos = strpos( $try_line, 'webp' );
		$this->assertNotFalse( $avif_pos, 'AVIF must be in try_files' );
		$this->assertNotFalse( $webp_pos, 'WebP must be in try_files' );
		$this->assertLessThan( $webp_pos, $avif_pos, 'try_files must order AVIF before WebP' );
	}

	/**
	 * Negotiation matrix: modern browsers get AVIF, Firefox 65-92 gets WebP, old Safari gets original.
	 *
	 * @since NEXT
	 */
	public function test_client_accepts_avif_negotiation_matrix(): void {
		$optimisation = new Image_Optimisation( $this->options );

		// Modern Chromium: advertises both — AVIF allowed.
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';
		$this->assertTrue( $optimisation->client_accepts_avif() );

		// Firefox 65-92 style: WebP only, no AVIF — must NOT get AVIF.
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8';
		$this->assertFalse( $optimisation->client_accepts_avif() );

		// Old Safari style: no next-gen image types — must get the original.
		$_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
		$this->assertFalse( $optimisation->client_accepts_avif() );

		// Missing header — fail closed to non-AVIF delivery.
		unset( $_SERVER['HTTP_ACCEPT'] );
		$this->assertFalse( $optimisation->client_accepts_avif() );
	}
}
