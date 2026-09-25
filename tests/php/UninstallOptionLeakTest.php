<?php
/**
 * Static guard: every persistent wppo_ option the plugin writes must be
 * removed on uninstall.
 *
 * `UninstallOptionsTest` pins that Util::UNINSTALL_OPTIONS and the inline list
 * in uninstall.php stay in sync with each other, and `wp wppo verify` checks
 * whatever options happen to exist in the database right now. Neither can see
 * an option that is only written on some hosts — which is how
 * `wppo_esi_fallback_secret`, a real 64-character secret created only when
 * wp_salt() is unavailable on a LiteSpeed Enterprise site, survived a full
 * "delete all plugin data" uninstall.
 *
 * This test reads the source instead of the database, so it sees every option
 * the plugin can ever write, including ones no local install has created.
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Uninstall option leak guard.
 */
class UninstallOptionLeakTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Persistent option prefixes that uninstall.php removes with a LIKE match
	 * because the suffix is dynamic.
	 *
	 * @var string[]
	 */
	private const DYNAMIC_PREFIXES = array(
		'wppo_front_page_lcp_',
	);

	/**
	 * Option APIs, i.e. the ones that persist a row in wp_options.
	 *
	 * @var string[]
	 */
	private const OPTION_CALLS = array( 'add_option', 'update_option', 'get_option', 'delete_option' );

	/**
	 * Transient APIs. Keys used only here live in the options table but are
	 * removed by the bulk transient sweep, not by the explicit option list.
	 *
	 * @var string[]
	 */
	private const TRANSIENT_CALLS = array( 'get_transient', 'set_transient', 'delete_transient' );

	/**
	 * Collect every persistent option name the plugin writes.
	 *
	 * @return array<string, string[]> Option name => source locations.
	 */
	private function collect_persistent_options(): array {
		$found = array();

		$files = array_merge(
			(array) glob( WPPO_PLUGIN_PATH . 'includes/*/class-*.php' ),
			(array) glob( WPPO_PLUGIN_PATH . 'includes/class-*.php' )
		);
		$this->assertNotEmpty( $files, 'the source scan must find plugin class files' );

		foreach ( $files as $file ) {
			$lines = explode( "\n", (string) file_get_contents( (string) $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
			$rel   = ltrim( str_replace( '\\', '/', substr( (string) $file, strlen( WPPO_PLUGIN_PATH ) ) ), '/' );

			// Class constants in this file that hold a wppo_ option name.
			$consts = array();
			foreach ( $lines as $line ) {
				if ( preg_match( '#const\s+([A-Z_0-9]+)\s*=\s*([\'"])wppo_[A-Za-z0-9_]+\2#', $line, $match ) ) {
					$consts[ $match[1] ] = $match[1];
				}
			}

			foreach ( $lines as $index => $line ) {
				$location = $rel . ':' . ( $index + 1 );

				// Literal option names.
				foreach ( self::OPTION_CALLS as $fn ) {
					if ( preg_match_all( '#' . $fn . '\s*\(\s*([\'"])(wppo_[A-Za-z0-9_]+)\1#', $line, $matches ) ) {
						foreach ( $matches[2] as $name ) {
							$found[ $name ][] = $location;
						}
					}
				}
				// A constant counts as persistent only when this line hands it
				// to the option API, and is dropped when it reaches only the
				// transient API.
				foreach ( $consts as $const_name => $_ ) {
					$is_option    = (bool) preg_match( '#' . self::option_call_pattern() . $const_name . '\b#', $line );
					$is_transient = (bool) preg_match( '#' . self::transient_call_pattern() . $const_name . '\b#', $line );
					if ( ! $is_option && ! $is_transient ) {
						continue;
					}
					$declared = $this->const_value( $lines, $const_name );
					if ( '' === $declared ) {
						continue;
					}
					if ( $is_option ) {
						$found[ $declared ][] = $location;
					} else {
						unset( $found[ $declared ] );
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Regular-expression fragment matching any option-API call.
	 *
	 * @return string
	 */
	private static function option_call_pattern(): string {
		return '(?:' . implode( '|', self::OPTION_CALLS ) . ')\s*\(\s*';
	}

	/**
	 * Regular-expression fragment matching any transient-API call.
	 *
	 * @return string
	 */
	private static function transient_call_pattern(): string {
		return '(?:' . implode( '|', self::TRANSIENT_CALLS ) . ')\s*\(\s*';
	}

	/**
	 * Resolve a class constant to its literal wppo_ value.
	 *
	 * @param string[] $lines     Source lines.
	 * @param string   $const_name Constant identifier.
	 * @return string Option name, or '' when the value is not a wppo_ literal.
	 */
	private function const_value( array $lines, string $const_name ): string {
		foreach ( $lines as $line ) {
			if ( preg_match( '#const\s+' . preg_quote( $const_name, '#' ) . '\s*=\s*([\'"])(wppo_[A-Za-z0-9_]+)\1#', $line, $match ) ) {
				return $match[2];
			}
		}
		return '';
	}

	/**
	 * Every persistent option must be in the canonical uninstall list.
	 *
	 * @return void
	 */
	public function test_persistent_options_are_removed_on_uninstall(): void {
		$written = $this->collect_persistent_options();
		$this->assertNotEmpty( $written, 'the source scan must actually find plugin options' );

		$covered = array();
		foreach ( \PerformanceOptimise\Inc\Util::UNINSTALL_OPTIONS as $option ) {
			$covered[ $option ] = true;
		}

		$leaks = array();
		foreach ( $written as $name => $locations ) {
			$dynamic = false;
			foreach ( self::DYNAMIC_PREFIXES as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$dynamic = true;
					break;
				}
			}
			if ( $dynamic || isset( $covered[ $name ] ) ) {
				continue;
			}
			$leaks[ $name ] = array_slice( $locations, 0, 3 );
		}

		$report = array();
		foreach ( $leaks as $name => $locations ) {
			$report[] = $name . ' (' . implode( ', ', $locations ) . ')';
		}
		$this->assertSame(
			array(),
			$leaks,
			"Persistent wppo_ options written by the plugin but absent from Util::UNINSTALL_OPTIONS:\n"
			. implode( "\n", $report )
			. "\nAdd each to Util::UNINSTALL_OPTIONS and to the inline list in uninstall.php."
		);
	}

	/**
	 * A dynamic prefix is only safe while uninstall.php still sweeps it.
	 *
	 * @return void
	 */
	public function test_dynamic_option_prefixes_are_swept_on_uninstall(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$uninstall = (string) file_get_contents( WPPO_PLUGIN_PATH . 'uninstall.php' );
		foreach ( self::DYNAMIC_PREFIXES as $prefix ) {
			$this->assertStringContainsString(
				$prefix,
				$uninstall,
				"Dynamic option prefix {$prefix} must still be swept by uninstall.php"
			);
		}
	}

	/**
	 * The known leak is named explicitly so the guard cannot pass by accident.
	 *
	 * @return void
	 */
	public function test_esi_fallback_secret_is_removed_on_uninstall(): void {
		$this->assertContains(
			'wppo_esi_fallback_secret',
			(array) \PerformanceOptimise\Inc\Util::UNINSTALL_OPTIONS,
			'the LiteSpeed ESI fallback secret is a persistent option and must be removed on uninstall'
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$uninstall = (string) file_get_contents( WPPO_PLUGIN_PATH . 'uninstall.php' );
		$this->assertStringContainsString(
			'wppo_esi_fallback_secret',
			$uninstall,
			'the inline standalone uninstall list must carry the same entry'
		);
	}
}
