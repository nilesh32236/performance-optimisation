<?php
/**
 * Tests for the PHP 8.2 / WP 6.2 runtime guard (issue #1017).
 *
 * The guard helpers live in performance-optimisation.php, which is loaded
 * here with WPPO_UNIT_TESTS defined so the function definitions are
 * available without booting Main. The optional $php_version/$wp_version
 * parameters let these tests exercise both sides of each floor on any
 * runtime (same pattern as Util::is_php85_or_greater()).
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */

use Brain\Monkey\Functions;

if ( ! defined( 'WPPO_UNIT_TESTS' ) ) {
	define( 'WPPO_UNIT_TESTS', true );
}

require_once dirname( __DIR__, 2 ) . '/performance-optimisation.php';

/**
 * Runtime-floor guard tests.
 *
 * @package PerformanceOptimise\Tests
 */
class VersionGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Remove the $wp_version global override after each test.
	 *
	 * @param mixed $global_value Value to restore, or null to unset.
	 * @return void
	 */
	private function restore_wp_version_global( $global_value ): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( null === $global_value ) {
			unset( $GLOBALS['wp_version'] );
		} else {
			$GLOBALS['wp_version'] = $global_value;
		}
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * PHP below the 8.2 floor must refuse boot, even with a new WordPress.
	 *
	 * @return void
	 */
	public function test_blocks_php_below_floor(): void {
		$this->assertFalse( wppo_requirements_met( '8.1.99', '6.8.0' ) );
		$this->assertFalse( wppo_requirements_met( '7.4.33', '6.8.0' ) );
	}

	/**
	 * WordPress below the 6.2 floor must refuse boot, even with a new PHP.
	 *
	 * @return void
	 */
	public function test_blocks_wp_below_floor(): void {
		$this->assertFalse( wppo_requirements_met( '8.2.0', '6.1.9' ) );
		$this->assertFalse( wppo_requirements_met( '8.3.0', '5.9.10' ) );
	}

	/**
	 * Exactly the documented floors must allow boot.
	 *
	 * @return void
	 */
	public function test_allows_at_floor(): void {
		$this->assertTrue( wppo_requirements_met( '8.2.0', '6.2.0' ) );
	}

	/**
	 * Above both floors must allow boot unchanged.
	 *
	 * @return void
	 */
	public function test_allows_above_floor(): void {
		$this->assertTrue( wppo_requirements_met( '8.2.1', '6.2.1' ) );
		$this->assertTrue( wppo_requirements_met( '8.3.0', '6.8.0' ) );
		$this->assertTrue( wppo_requirements_met( '9.0.0', '7.0.0' ) );
	}

	/**
	 * An unknown WordPress version fails open so a broken version API
	 * cannot itself take the site down (PHP gate still applies).
	 *
	 * @return void
	 */
	public function test_unknown_wp_version_fails_open(): void {
		$this->assertTrue( wppo_requirements_met( '8.2.0', '' ) );
		$this->assertFalse( wppo_requirements_met( '8.1.0', '' ) );
	}

	/**
	 * An unknown PHP version fails closed to "do not boot".
	 *
	 * @return void
	 */
	public function test_unknown_php_version_fails_closed(): void {
		$this->assertFalse( wppo_requirements_met( '', '6.8.0' ) );
		$this->assertFalse( wppo_requirements_met( '   ', '6.8.0' ) );
	}

	/**
	 * Default (no-arg) check must reflect the actual runtime.
	 *
	 * @return void
	 */
	public function test_defaults_reflect_runtime(): void {
		$had_global = array_key_exists( 'wp_version', $GLOBALS ) ? $GLOBALS['wp_version'] : null;
		try {
			$this->restore_wp_version_global( null );
			Functions\when( 'get_bloginfo' )->justReturn( '6.8.0' );

			$expected = version_compare( PHP_VERSION, '8.2', '>=' );
			$this->assertSame( $expected, wppo_requirements_met() );
		} finally {
			$this->restore_wp_version_global( $had_global );
		}
	}

	/**
	 * The loaded $wp_version global takes precedence over get_bloginfo().
	 *
	 * @return void
	 */
	public function test_get_wp_version_prefers_global(): void {
		$had_global = array_key_exists( 'wp_version', $GLOBALS ) ? $GLOBALS['wp_version'] : null;
		try {
			// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited
			$GLOBALS['wp_version'] = '6.1.3';
			// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
			Functions\when( 'get_bloginfo' )->justReturn( '6.8.0' );

			$this->assertSame( '6.1.3', wppo_get_wp_version() );
		} finally {
			$this->restore_wp_version_global( $had_global );
		}
	}

	/**
	 * Without the global, the version falls back to get_bloginfo().
	 *
	 * @return void
	 */
	public function test_get_wp_version_falls_back_to_bloginfo(): void {
		$had_global = array_key_exists( 'wp_version', $GLOBALS ) ? $GLOBALS['wp_version'] : null;
		try {
			$this->restore_wp_version_global( null );
			Functions\when( 'get_bloginfo' )->justReturn( '6.4.2' );

			$this->assertSame( '6.4.2', wppo_get_wp_version() );
		} finally {
			$this->restore_wp_version_global( $had_global );
		}
	}

	/**
	 * With no version source available, an empty string is returned.
	 *
	 * @return void
	 */
	public function test_get_wp_version_empty_when_unknown(): void {
		$had_global = array_key_exists( 'wp_version', $GLOBALS ) ? $GLOBALS['wp_version'] : null;
		try {
			$this->restore_wp_version_global( null );
			Functions\when( 'get_bloginfo' )->justReturn( '' );

			$this->assertSame( '', wppo_get_wp_version() );
		} finally {
			$this->restore_wp_version_global( $had_global );
		}
	}

	/**
	 * Below the floors the guard refuses boot and registers admin notices.
	 *
	 * @return void
	 */
	public function test_guard_registers_notices_below_floor(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback = null ) use ( &$actions ) {
				$actions[] = array( $hook, $callback );
				return true;
			}
		);

		$this->assertFalse( wppo_version_guard( '8.1.0', '6.8.0' ) );
		$this->assertFalse( wppo_version_guard( '8.2.0', '6.1.0' ) );

		$hooks = array_column( $actions, 0 );
		$this->assertContains( 'admin_notices', $hooks );
		$this->assertContains( 'network_admin_notices', $hooks );

		foreach ( $actions as $action ) {
			$this->assertSame( 'wppo_render_requirements_notice', $action[1] );
		}
	}

	/**
	 * Above the floors the guard allows boot without registering notices.
	 *
	 * @return void
	 */
	public function test_guard_allows_boot_without_notices_above_floor(): void {
		$actions = array();
		Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback = null ) use ( &$actions ) {
				$actions[] = array( $hook, $callback );
				return true;
			}
		);

		$this->assertTrue( wppo_version_guard( '8.2.0', '6.2.0' ) );
		$this->assertTrue( wppo_version_guard( '8.3.0', '6.8.0' ) );

		$this->assertSame( array(), $actions );
	}

	/**
	 * The notice must name the required versions and the detected versions.
	 *
	 * @return void
	 */
	public function test_notice_names_required_and_detected_versions(): void {
		$had_global = array_key_exists( 'wp_version', $GLOBALS ) ? $GLOBALS['wp_version'] : null;
		try {
			$this->restore_wp_version_global( null );
			Functions\when( 'get_bloginfo' )->justReturn( '6.1.0' );
			Functions\when( 'current_user_can' )->justReturn( true );

			$buffer_level = ob_get_level();
			ob_start();
			try {
				wppo_render_requirements_notice();
				$output = (string) ob_get_clean();
			} finally {
				if ( ob_get_level() > $buffer_level ) {
					ob_end_clean();
				}
			}

			$this->assertStringContainsString( '8.2', $output );
			$this->assertStringContainsString( '6.2', $output );
			$this->assertStringContainsString( '6.1.0', $output );
			$this->assertStringContainsString( 'paused', $output );
			$this->assertStringContainsString( 'role="alert"', $output );
		} finally {
			$this->restore_wp_version_global( $had_global );
		}
	}

	/**
	 * The notice is capability-gated to administrators.
	 *
	 * @return void
	 */
	public function test_notice_hidden_without_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		wppo_render_requirements_notice();
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The entry point must gate Main behind the guard (no unconditional boot).
	 *
	 * @return void
	 */
	public function test_entry_point_gates_boot_behind_guard(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/performance-optimisation.php' );

		$this->assertStringContainsString( 'if ( wppo_version_guard() )', $source );
		$this->assertStringContainsString( 'wppo_render_requirements_notice', $source );
		$this->assertSame( 1, substr_count( $source, 'new Main()' ) );
	}
}
