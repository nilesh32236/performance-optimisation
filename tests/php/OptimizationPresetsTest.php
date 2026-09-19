<?php
/**
 * Tests for the Safe / Balanced / Aggressive one-click presets (issue #1368).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Covers preset schema-confinement (existing wppo_settings keys only),
 * diff preview, fail-safe guards forced ON, snapshot-on-apply, and the
 * fail-open paths (unknown preset, write failure).
 *
 * @package PerformanceOptimise\Tests
 */
class OptimizationPresetsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory option store shared by get_option/update_option stubs.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Install get_option/update_option stubs backed by $this->options.
	 *
	 * @param bool $fail_writes When true, update_option() reports failure without writing.
	 */
	private function install_option_stubs( bool $fail_writes = false ): void {
		$this->options = array();
		Util::clear_settings_cache();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $fallback;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) use ( $fail_writes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match update_option().
				if ( $fail_writes ) {
					return false;
				}
				$this->options[ $name ] = $value;
				return true;
			}
		);
	}

	/**
	 * Presets must map to existing wppo_settings keys only (additive, no schema break).
	 */
	public function test_presets_use_existing_settings_keys_only(): void {
		$presets = Util::get_optimization_presets();
		$this->assertSame( array( 'safe', 'balanced', 'aggressive' ), array_keys( $presets ), 'Exactly Safe/Balanced/Aggressive presets must exist' );

		$schema = Util::get_settings_schema();
		foreach ( $presets as $name => $tabs ) {
			foreach ( $tabs as $tab => $keys ) {
				$this->assertContains( $tab, Util::ALLOWED_SETTINGS_TABS, "Preset {$name} tab {$tab} must be an allowed settings tab" );
				$this->assertArrayHasKey( $tab, $schema, "Preset {$name} tab {$tab} must exist in the settings schema" );
				foreach ( $keys as $key => $value ) {
					unset( $value );
					$this->assertArrayHasKey( $key, $schema[ $tab ], "Preset {$name} key {$tab}.{$key} must exist in the settings schema" );
				}
			}
		}
	}

	/**
	 * The Safe preset must mirror the safe-by-default baseline (page cache
	 * on, every aggressive pipeline off).
	 */
	public function test_safe_preset_matches_safe_baseline(): void {
		$presets = Util::get_optimization_presets();

		$this->assertTrue( $presets['safe']['cache_settings']['enableCache'], 'Safe must keep the page cache on' );

		$aggressive_off = array( 'combineCSS', 'delayJS', 'deferJS', 'minifyJS', 'minifyCSS', 'removeUnusedCSS', 'criticalCSS' );
		foreach ( $aggressive_off as $key ) {
			$this->assertFalse( $presets['safe']['file_optimisation'][ $key ], "Safe must keep aggressive key {$key} OFF" );
		}
	}

	/**
	 * Diff preview must list only keys that would change, and be empty when
	 * the preset already matches the current settings.
	 */
	public function test_preset_diff_previews_changes_only(): void {
		$this->install_option_stubs();

		$current = array(
			'file_optimisation' => array(
				'minifyHTML' => false,
				'minifyCSS'  => true,
			),
		);

		$diff = Util::get_preset_diff( 'balanced', $current );

		$changed_keys = array();
		foreach ( $diff as $entry ) {
			$this->assertArrayHasKey( 'tab', $entry );
			$this->assertArrayHasKey( 'key', $entry );
			$this->assertArrayHasKey( 'from', $entry );
			$this->assertArrayHasKey( 'to', $entry );
			$changed_keys[] = $entry['tab'] . '.' . $entry['key'];
		}
		$this->assertContains( 'file_optimisation.minifyHTML', $changed_keys, 'minifyHTML differs and must appear in the diff' );
		$this->assertNotContains( 'file_optimisation.minifyCSS', $changed_keys, 'minifyCSS already matches and must not appear in the diff' );
	}

	/**
	 * Diff preview must be empty for unknown presets (fail-open, never fatal).
	 */
	public function test_preset_diff_empty_for_unknown_preset(): void {
		$this->install_option_stubs();

		$this->assertSame( array(), Util::get_preset_diff( 'turbo', array() ) );
		$this->assertNull( Util::apply_optimization_preset( 'turbo' ) );
	}

	/**
	 * Safety-guard preview must surface guards that are missing or null
	 * (fresh installs, older settings), not just strict false — apply
	 * forces them ON, so the preview must match apply-time behavior.
	 */
	public function test_preset_diff_includes_missing_safety_guards(): void {
		$this->install_option_stubs();

		$diff = Util::get_preset_diff( 'safe', array() );

		$changed_keys = array();
		foreach ( $diff as $entry ) {
			$changed_keys[] = $entry['tab'] . '.' . $entry['key'];
		}
		$this->assertContains( 'cache_settings.wooSafeMode', $changed_keys, 'Missing wooSafeMode guard must appear in the preview diff' );
		$this->assertContains( 'file_optimisation.delayJSSafeMode', $changed_keys, 'Missing delayJSSafeMode guard must appear in the preview diff' );
	}

	/**
	 * Safety-guard preview must stay silent for guards already ON.
	 */
	public function test_preset_diff_omits_satisfied_safety_guards(): void {
		$this->install_option_stubs();

		$current = array(
			'cache_settings'    => array(
				'wooSafeMode' => true,
			),
			'file_optimisation' => array(
				'delayJSSafeMode'       => true,
				'delayJSBuilderPreset'  => true,
				'delayJSCommercePreset' => true,
				'elementorSafeMode'     => true,
			),
		);

		$diff         = Util::get_preset_diff( 'safe', $current );
		$changed_keys = array();
		foreach ( $diff as $entry ) {
			$changed_keys[] = $entry['tab'] . '.' . $entry['key'];
		}
		$this->assertNotContains( 'cache_settings.wooSafeMode', $changed_keys, 'Satisfied wooSafeMode guard must not appear in the preview diff' );
		$this->assertNotContains( 'file_optimisation.delayJSSafeMode', $changed_keys, 'Satisfied delayJSSafeMode guard must not appear in the preview diff' );
	}

	/**
	 * Apply must merge the preset, snapshot the prior settings for one-click
	 * undo, and force the fail-safe guards ON even when they were off.
	 */
	public function test_apply_merges_snapshot_and_forces_guards(): void {
		$this->install_option_stubs();

		$prior                          = array(
			'cache_settings'    => array(
				'enableCache' => false,
				'wooSafeMode' => false,
			),
			'file_optimisation' => array(
				'minifyHTML'            => false,
				'delayJSSafeMode'       => false,
				'delayJSBuilderPreset'  => false,
				'delayJSCommercePreset' => false,
				'elementorSafeMode'     => false,
			),
		);
		$this->options['wppo_settings'] = $prior;
		Util::clear_settings_cache();

		$applied = Util::apply_optimization_preset( 'aggressive' );

		$this->assertIsArray( $applied );
		$this->assertSame( 'aggressive', $applied['preset'] );
		$this->assertTrue( $applied['settings']['cache_settings']['enableCache'], 'Aggressive must turn the page cache on' );
		$this->assertTrue( $applied['settings']['file_optimisation']['delayJS'], 'Aggressive must enable delayJS' );

		// Fail-safe guards stay forced ON.
		$this->assertTrue( $applied['settings']['cache_settings']['wooSafeMode'], 'wooSafeMode guard must be forced ON' );
		$this->assertTrue( $applied['settings']['file_optimisation']['delayJSSafeMode'], 'delayJSSafeMode guard must be forced ON' );
		$this->assertTrue( $applied['settings']['file_optimisation']['delayJSBuilderPreset'], 'Builder preset guard must be forced ON' );
		$this->assertTrue( $applied['settings']['file_optimisation']['delayJSCommercePreset'], 'Commerce preset guard must be forced ON' );
		$this->assertTrue( $applied['settings']['file_optimisation']['elementorSafeMode'], 'Elementor safe-mode guard must be forced ON' );

		// Prior settings snapshotted for one-click undo; stored settings updated.
		$snapshot = Util::get_settings_snapshot();
		$this->assertIsArray( $snapshot );
		$this->assertSame( $prior, $snapshot['settings'], 'Apply must snapshot the prior settings' );
		$this->assertSame( $applied['settings'], $this->options['wppo_settings'], 'Stored settings must reflect the applied preset' );
		$this->assertNotEmpty( $applied['diff'], 'Apply must return the previewed diff' );
	}

	/**
	 * Apply must fail open when the option write fails: null return and the
	 * prior settings left intact.
	 */
	public function test_apply_returns_null_when_write_fails(): void {
		$this->install_option_stubs( true );

		$prior                          = array( 'file_optimisation' => array( 'minifyHTML' => false ) );
		$this->options['wppo_settings'] = $prior;
		Util::clear_settings_cache();

		$this->assertNull( Util::apply_optimization_preset( 'balanced' ) );
		$this->assertSame( $prior, $this->options['wppo_settings'], 'Prior settings must stay intact when the apply write fails' );
	}
}
