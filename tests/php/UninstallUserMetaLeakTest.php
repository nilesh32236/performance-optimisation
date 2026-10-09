<?php
/**
 * Static guard: every persistent wppo_ user-meta key the plugin writes must be
 * removed on uninstall.
 *
 * `UninstallOptionLeakTest` does the same job for options and caught a real
 * leak (`wppo_esi_fallback_secret`). The user-meta side had the same hole:
 * uninstall.php deleted only `wppo_welcome_dismissed`, so three plugin-owned
 * user-meta rows survived every uninstall — the LiteSpeed notice, the
 * AVIF/WebP-only notice and the nginx/Redis config notice, all written with
 * `update_user_meta` in includes/Admin/class-admin-notices.php.
 *
 * The test reads the source rather than the database, so it sees keys that are
 * only written on some hosts or by an interaction no local install has
 * performed.
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Uninstall user-meta leak guard.
 */
class UninstallUserMetaLeakTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * User-meta APIs, i.e. the ones that persist a row in wp_usermeta.
	 *
	 * @var string[]
	 */
	private const USER_META_WRITES = array( 'update_user_meta', 'add_user_meta' );

	/**
	 * User-meta APIs that remove a row, used to spot deliberate exceptions.
	 *
	 * @var string[]
	 */
	private const USER_META_DELETES = array( 'delete_user_meta', 'delete_metadata' );

	/**
	 * Collect every wppo_ user-meta key the plugin writes.
	 *
	 * @return array<string, string[]> Meta key => source locations.
	 */
	private function collect_written_user_meta(): array {
		$found = array();
		$files = array_merge(
			(array) glob( WPPO_PLUGIN_PATH . 'includes/*/class-*.php' ),
			(array) glob( WPPO_PLUGIN_PATH . 'includes/class-*.php' )
		);
		$this->assertNotEmpty( $files, 'the source scan must find plugin class files' );

		foreach ( $files as $file ) {
			$lines = explode( "\n", (string) file_get_contents( (string) $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
			$rel   = ltrim( str_replace( '\\', '/', substr( (string) $file, strlen( WPPO_PLUGIN_PATH ) ) ), '/' );

			foreach ( $lines as $index => $line ) {
				$location = $rel . ':' . ( $index + 1 );
				// A read is not a write: get_user_meta / a delete call must not
				// look like an owner of the key.
				if ( preg_match( '#get_user_meta#', $line ) ) {
					continue;
				}
				foreach ( self::USER_META_WRITES as $fn ) {
					if ( preg_match_all( '#' . $fn . '\s*\((?:[^,]+,\s*)?([\'"])(wppo_[A-Za-z0-9_]+)\1#', $line, $matches ) ) {
						foreach ( $matches[2] as $name ) {
							$found[ $name ][] = $location;
						}
					}
				}
			}
		}

		return $found;
	}

	/**
	 * Every wppo_ user-meta key uninstall.php removes, by literal or loop.
	 *
	 * @return string[]
	 */
	private function uninstall_removed_user_meta(): array {
		$uninstall = (string) file_get_contents( WPPO_PLUGIN_PATH . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.

		$keys = array();
		if ( preg_match_all( '#delete_(?:user_meta|metadata)\s*\([^)]*?([\'"])(wppo_[A-Za-z0-9_]+)\1#', $uninstall, $matches ) ) {
			$keys = $matches[2];
		}

		// uninstall.php drives its deletes from an explicit array, so a loop
		// over that array is also a real removal. The array is read as text
		// because evaluating it would require booting WordPress.
		if ( preg_match( '#foreach\s*\(\s*array\((.*?)\)\s*as\s*\$wppo_user_meta_key#s', $uninstall, $loop ) ) {
			if ( preg_match_all( '#([\'"])(wppo_[A-Za-z0-9_]+)\1#', $loop[1], $loop_keys ) ) {
				$keys = array_merge( $keys, $loop_keys[2] );
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Every wppo_ user-meta key the plugin writes is removed on uninstall.
	 *
	 * @return void
	 */
	public function test_no_written_user_meta_survives_uninstall(): void {
		$written = $this->collect_written_user_meta();
		$removed = $this->uninstall_removed_user_meta();

		$this->assertNotEmpty( $written, 'the scan must find at least one written user-meta key, otherwise the guard is vacuous' );
		$this->assertNotEmpty( $removed, 'uninstall.php must remove at least one user-meta key, otherwise the guard is vacuous' );

		$leaked = array_diff( array_keys( $written ), $removed );

		$this->assertSame(
			array(),
			array_values( $leaked ),
			"User-meta keys written by the plugin survive uninstall: \n  " .
			implode(
				"\n  ",
				array_map(
					static function ( $key ) use ( $written ) {
						return $key . ' (written at ' . implode( ', ', $written[ $key ] ) . ')';
					},
					array_values( $leaked )
				)
			)
		);
	}

	/**
	 * The removal path is the core metadata API, which deletes the key for
	 * every user and cannot touch another plugin's meta.
	 *
	 * @return void
	 */
	public function test_removal_uses_core_metadata_api_for_all_users(): void {
		$uninstall = (string) file_get_contents( WPPO_PLUGIN_PATH . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.

		$this->assertMatchesRegularExpression(
			'#delete_metadata\(\s*[\'"]user[\'"]\s*,\s*null\s*,#',
			$uninstall,
			'uninstall.php must delete user meta via delete_metadata( \'user\', null, ... ) so every user is covered and no other plugin\'s meta is touched'
		);
	}
}
