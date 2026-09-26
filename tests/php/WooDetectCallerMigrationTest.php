<?php
/**
 * P3-017 source and parity tests for Woo_Detect caller migration (issue #1599).
 *
 * @package PerformanceOptimise\Tests
 * @since   NEXT
 */

use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\Woo_Detect;

/**
 * Proves the bounded external caller cluster targets Woo_Detect directly
 * while every public Util compatibility proxy keeps its original contract.
 *
 * @since NEXT
 */
final class WooDetectCallerMigrationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Runtime callers in the cache, preload, and frontend clusters must not
	 * execute a Util Woo facade. Comments and mixed-version fallbacks may name
	 * Util for unrelated helpers.
	 *
	 * @return void
	 */
	public function test_external_woo_callers_target_woo_detect(): void {
		$root    = dirname( __DIR__, 2 );
		$callers = array(
			'includes/Cache/class-advanced-cache-handler.php',
			'includes/Cache/class-cache.php',
			'includes/Cache/class-cache-invalidator.php',
			'includes/Core/class-main.php',
			'includes/Core/class-preload-buffer-coordinator.php',
			'includes/Assets/class-script-strategy.php',
			'includes/Scheduler/class-cron.php',
		);

		foreach ( $callers as $relative ) {
			$source = file_get_contents( $root . '/' . $relative ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
			$this->assertNotFalse( $source, $relative );
			$code = $this->executable_source( (string) $source );
			$this->assertDoesNotMatchRegularExpression(
				'/\\bUtil\s*::\s*(?:is_woo_[a-z_]+|get_woo_excluded_paths|woo_cache_self_test)\s*\(/i',
				$code,
				$relative . ' must call Woo_Detect directly for the migrated Woo cluster.'
			);
			$this->assertMatchesRegularExpression(
				'/\\bWoo_Detect\s*::\s*(?:is_woo_[a-z_]+|get_woo_excluded_paths)\s*\(/i',
				$code,
				$relative . ' must retain an executable Woo_Detect call.'
			);
		}
	}

	/**
	 * Public compatibility signatures and representative behavior remain exact.
	 *
	 * @return void
	 */
	public function test_util_proxies_preserve_signatures_and_woo_behavior(): void {
		$methods = array(
			'is_woo_safe_mode_enabled',
			'is_woo_store_api_path',
			'is_woo_store_api_request',
			'is_woo_dynamic_path',
			'get_woo_excluded_paths',
			'is_woo_active',
			'is_woo_faceted_query',
			'is_woo_ajax_request',
			'is_woo_add_to_cart_request',
			'is_woo_excluded_url',
			'woo_cache_self_test',
		);

		foreach ( $methods as $method ) {
			$facade = new ReflectionMethod( Util::class, $method );
			$owner  = new ReflectionMethod( Woo_Detect::class, $method );
			$this->assertSame( $this->signature( $facade ), $this->signature( $owner ), $method . ' proxy signature changed.' );
		}

		$settings_off = array( 'cache_settings' => array( 'wooSafeMode' => false ) );
		$this->assertSame( Woo_Detect::is_woo_safe_mode_enabled( $settings_off ), Util::is_woo_safe_mode_enabled( $settings_off ) );
		$this->assertSame( Woo_Detect::is_woo_store_api_path( '/wp-json/wc/store/v1/cart' ), Util::is_woo_store_api_path( '/wp-json/wc/store/v1/cart' ) );
		$this->assertSame( Woo_Detect::is_woo_store_api_request( '/', 'rest_route=/wc/store/v1/cart' ), Util::is_woo_store_api_request( '/', 'rest_route=/wc/store/v1/cart' ) );
		$this->assertSame( Woo_Detect::is_woo_ajax_request( '/wc-ajax/fragments/' ), Util::is_woo_ajax_request( '/wc-ajax/fragments/' ) );
		$this->assertSame( Woo_Detect::is_woo_faceted_query( 'filter_color=blue' ), Util::is_woo_faceted_query( 'filter_color=blue' ) );
		$this->assertSame( Woo_Detect::is_woo_dynamic_path( '/subsite/cart/' ), Util::is_woo_dynamic_path( '/subsite/cart/' ) );
		$this->assertSame( Woo_Detect::get_woo_excluded_paths(), Util::get_woo_excluded_paths() );
	}

	/**
	 * Strip comments and whitespace while preserving executable PHP tokens.
	 *
	 * @since NEXT
	 * @param string $source PHP source.
	 * @return string Executable source text.
	 */
	private function executable_source( string $source ): string {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				if ( ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
					$code .= $token[1];
				}
				continue;
			}
			$code .= $token;
		}
		return $code;
	}

	/**
	 * Render a stable public-method signature for proxy comparison.
	 *
	 * @since NEXT
	 * @param ReflectionMethod $method Reflected method.
	 * @return string Stable signature.
	 */
	private function signature( ReflectionMethod $method ): string {
		$parameters = array();
		foreach ( $method->getParameters() as $parameter ) {
			$parameters[] = implode(
				':',
				array(
					$parameter->getName(),
					$parameter->hasType() ? (string) $parameter->getType() : '',
					$parameter->isPassedByReference() ? 'reference' : 'value',
					$parameter->isVariadic() ? 'variadic' : '',
					$parameter->isOptional() && $parameter->isDefaultValueAvailable() ? get_debug_type( $parameter->getDefaultValue() ) . ':' . (string) $parameter->getDefaultValue() : '',
				)
			);
		}
		return $method->getName() . '(' . implode( ',', $parameters ) . '):' . ( $method->hasReturnType() ? (string) $method->getReturnType() : '' );
	}
}
