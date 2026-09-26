<?php
/**
 * Characterization test: pin exactly which features the safe-mode kill switch
 * gates today, and — more importantly — which it does not.
 *
 * Safe mode is the plugin's advertised "one-click recovery". An audit found the
 * recovery surface is much narrower than a user in a broken site would assume:
 * the flag is `file_optimisation.safeMode`, and only five subsystems consult it
 * (Delay, Defer, Combine CSS, Used CSS, Critical CSS). Minification, image
 * optimisation, CDN rewriting, preload, Google Fonts and speculation rules do
 * NOT consult it, and the enable handler then purges the page cache — so a
 * still-broken page is re-cached with all of those still applied.
 *
 * The UI now says so explicitly. This test exists so that the *scope* is a
 * deliberate decision rather than an accident: if a future change adds or drops
 * a consumer, this test fails and the copy has to be updated with it.
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Safe-mode scope characterization.
 */
class SafeModeScopeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Files that legitimately consult the safe-mode kill switch.
	 *
	 * @var string[]
	 */
	private const GATED = array(
		'includes/Core/class-hook-registry.php',
		'includes/Assets/class-css-combine.php',
		'includes/Assets/class-script-strategy.php',
		'includes/CSS/class-used-css.php',
		'includes/CSS/class-critical-css.php',
	);

	/**
	 * Subsystems that transform frontend output but never consult safe mode.
	 *
	 * @var string[]
	 */
	private const NOT_GATED = array(
		'includes/Cache/class-cache.php',
		'includes/minify/class-minify-policy.php',
		'includes/Edge/class-cdn.php',
		'includes/Images/class-image-optimisation.php',
		'includes/Assets/class-google-fonts.php',
		'includes/Cache/class-cache-invalidator.php',
	);

	/**
	 * Read a plugin file as text.
	 *
	 * @param string $relative Path relative to the plugin root.
	 * @return string
	 */
	private function read( string $relative ): string {
		$path = WPPO_PLUGIN_PATH . $relative;
		$this->assertFileExists( $path, "expected plugin file {$relative} to exist" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		return (string) file_get_contents( $path );
	}

	/**
	 * Every consumer of the kill switch is on the known-gated list.
	 *
	 * Scans every plugin PHP file rather than trusting the list, so a new
	 * consumer cannot be added without this test noticing.
	 *
	 * @return void
	 */
	public function test_every_safe_mode_consumer_is_known(): void {
		$consumers = array();
		$files     = array_merge(
			(array) glob( WPPO_PLUGIN_PATH . 'includes/*/class-*.php' ),
			(array) glob( WPPO_PLUGIN_PATH . 'includes/class-*.php' )
		);
		foreach ( $files as $file ) {
			$text = $this->read( ltrim( str_replace( '\\', '/', substr( (string) $file, strlen( WPPO_PLUGIN_PATH ) ) ), '/' ) );
			if ( false !== strpos( $text, 'is_safe_mode_active' ) || false !== strpos( $text, 'is_safe_mode_enabled' ) ) {
				// The definition site itself is not a consumer.
				if ( false !== strpos( $text, 'public static function is_safe_mode_active' ) ) {
					continue;
				}
				$consumers[] = ltrim( str_replace( '\\', '/', substr( (string) $file, strlen( WPPO_PLUGIN_PATH ) ) ), '/' );
			}
		}
		sort( $consumers );

		$expected = self::GATED;
		sort( $expected );

		$this->assertSame(
			$expected,
			array_values( array_unique( $consumers ) ),
			'the set of subsystems consulting the safe-mode kill switch changed. If a feature was added or removed, update the safe-mode description and warning banner in src/components/FileOptimization.js to match.'
		);
	}

	/**
	 * The ungated subsystems really do not consult the kill switch.
	 *
	 * This is the half that matters to a user whose site is still broken after
	 * enabling safe mode, and it is asserted explicitly so the test documents
	 * the limitation instead of only implying it.
	 *
	 * @return void
	 */
	public function test_ungated_transform_subsystems_do_not_consult_safe_mode(): void {
		foreach ( self::NOT_GATED as $relative ) {
			$this->assertStringNotContainsString(
				'is_safe_mode_active',
				$this->read( $relative ),
				"{$relative} now consults the safe-mode kill switch. If that is intentional, update self::NOT_GATED, the user-facing description and the warning banner — safe mode is documented as not covering these."
			);
		}
	}

	/**
	 * The UI tells the user which features safe mode does NOT stop.
	 *
	 * Without this the plugin silently fails to recover a site broken by
	 * minification, images or the CDN, and the user has no way to tell why.
	 *
	 * @return void
	 */
	public function test_ui_states_that_safe_mode_does_not_cover_everything(): void {
		$ui = $this->read( 'src/components/FileOptimization.js' );

		$this->assertMatchesRegularExpression(
			'/Minification, image optimisation, CDN rewriting and preload are NOT affected by safe mode/',
			$ui,
			'the safe-mode description must state which features it does not stop'
		);
		$this->assertMatchesRegularExpression(
			'/Minification, image optimisation, CDN rewriting and preload keep running/',
			$ui,
			'the safe-mode warning banner must state which features keep running'
		);
	}

	/**
	 * The advertised coverage still matches the real gated set.
	 *
	 * The description and banner promise Delay, Defer, Combine and Used CSS.
	 * Those four are genuinely covered, so the copy is not overstating in the
	 * other direction either.
	 *
	 * @return void
	 */
	public function test_ui_still_advertises_the_features_that_are_actually_gated(): void {
		$ui = $this->read( 'src/components/FileOptimization.js' );
		$this->assertStringContainsString(
			'Instantly disable Delay JS, Defer JS, Combine CSS and Remove Unused CSS',
			$ui,
			'the safe-mode description must keep naming the features it really does disable'
		);
		$this->assertStringContainsString(
			'Delay, Defer, Combine and Used CSS are paused',
			$ui,
			'the safe-mode banner must keep naming the features it really does pause'
		);
	}
}
