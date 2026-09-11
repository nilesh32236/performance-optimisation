<?php
/**
 * WordPress compliance regression tests (independent audit).
 *
 * Covers: the RUM direct-access ABSPATH guard, admin table `scope="col"`
 * headers, WP_DEBUG-gated error_log diagnostics, invented `@since` versions,
 * and the plugin-screen scoping of the review notice.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

/**
 * Compliance regression tests.
 *
 * @package PerformanceOptimise\Tests
 */
class ComplianceAuditTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Read a repository file relative to the plugin root.
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private function read_plugin_file( string $relative ): string {
		$source = file_get_contents( WPPO_PLUGIN_PATH . $relative );
		$this->assertNotFalse( $source, "Could not read {$relative}" );
		return (string) $source;
	}

	/**
	 * The RUM class must carry a direct-access ABSPATH guard.
	 */
	public function test_rum_class_has_abspath_guard(): void {
		$source = $this->read_plugin_file( 'includes/class-rum.php' );

		$this->assertStringContainsString( "defined( 'ABSPATH' )", $source );
		$this->assertMatchesRegularExpression(
			"/defined\\( 'ABSPATH' \\)\\s*\\)\\s*\\{\\s*exit;/s",
			$source,
			'class-rum.php must guard direct access like the other includes/ classes'
		);
	}

	/**
	 * Every admin table header in the audited components must declare
	 * scope="col" so assistive tech can associate data cells with headers.
	 *
	 * @dataProvider admin_table_scope_provider
	 *
	 * @param string $relative Relative path.
	 * @param int    $expected Expected header-cell count.
	 */
	public function test_admin_table_headers_have_col_scope( string $relative, int $expected ): void {
		$source = $this->read_plugin_file( $relative );

		$this->assertSame(
			$expected,
			substr_count( $source, '<th scope="col"' ),
			"{$relative}: header cells must carry scope=\"col\""
		);
		$this->assertSame(
			0,
			substr_count( $source, '<th>' ),
			"{$relative}: found a header cell without scope=\"col\""
		);
	}

	/**
	 * Data provider for the admin table scope checks.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function admin_table_scope_provider(): array {
		return array(
			'WebVitalsRum'     => array( 'src/components/WebVitalsRum.js', 6 ),
			'PageSpeedPanel'   => array( 'src/components/PageSpeedPanel.js', 3 ),
			'PerformanceAudit' => array( 'src/components/PerformanceAudit.js', 3 ),
		);
	}

	/**
	 * Every error_log() diagnostic in the audited files must be gated behind
	 * WP_DEBUG while keeping the diagnostic messages.
	 *
	 * @dataProvider debug_log_provider
	 *
	 * @param string $relative Relative path.
	 * @param int    $expected Expected error_log() call count.
	 */
	public function test_error_log_calls_are_debug_gated( string $relative, int $expected ): void {
		$source = $this->read_plugin_file( $relative );

		preg_match_all( '/error_log\(/', $source, $matches, PREG_OFFSET_CAPTURE );
		$calls = $matches[0];

		$this->assertCount( $expected, $calls, "{$relative}: error_log() call count changed unexpectedly" );

		foreach ( $calls as $call ) {
			$pos    = $call[1];
			$before = substr( $source, max( 0, $pos - 400 ), 400 );

			$this->assertStringContainsString(
				"defined( 'WP_DEBUG' ) && WP_DEBUG",
				$before,
				"{$relative}: error_log() at offset {$pos} is not WP_DEBUG-gated"
			);
		}
	}

	/**
	 * Data provider for the WP_DEBUG gating checks.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function debug_log_provider(): array {
		return array(
			'redis-connect-helper' => array( 'includes/redis-connect-helper.php', 3 ),
			'od-bridge'            => array( 'includes/class-od-bridge.php', 1 ),
			'img-converter'        => array( 'includes/class-img-converter.php', 8 ),
		);
	}

	/**
	 * Invented @since versions must not reappear in the audited files.
	 *
	 * @dataProvider invented_since_provider
	 *
	 * @param string $relative Relative path.
	 * @param string $invented Invented version string.
	 */
	public function test_no_invented_since_versions( string $relative, string $invented ): void {
		$source = $this->read_plugin_file( $relative );

		$this->assertStringNotContainsString(
			'@since ' . $invented,
			$source,
			"{$relative}: invented @since {$invented} must use @since 2.0.0 instead"
		);
	}

	/**
	 * Data provider for invented @since checks.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function invented_since_provider(): array {
		return array(
			'cache-2.22.0'             => array( 'includes/class-cache.php', '2.22.0' ),
			'image-optimisation-1.2.4' => array( 'includes/class-image-optimisation.php', '1.2.4' ),
			'minify-css-1.6.1'         => array( 'includes/minify/class-css.php', '1.6.1' ),
		);
	}

	/**
	 * The review notice must only render on the plugin's own admin screen.
	 */
	public function test_review_notice_is_scoped_to_plugin_screen(): void {
		$source = $this->read_plugin_file( 'includes/class-admin-notices.php' );

		$this->assertStringContainsString( 'get_current_screen()', $source );
		$this->assertStringContainsString( "'toplevel_page_performance-optimisation' !== \$screen->base", $source );
		$this->assertStringNotContainsString(
			'is_admin()',
			$source,
			'The review notice must scope by screen instead of nagging on all admin pages'
		);
	}
}
