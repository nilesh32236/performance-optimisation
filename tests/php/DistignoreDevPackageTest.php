<?php
/**
 * Guard: no composer dev-only package may reach the release ZIP.
 *
 * The release workflow installs production dependencies with
 * `composer install --no-dev`, so CI never exercises the local
 * `scripts/build-release.sh` path that stages whatever the working tree
 * happens to contain. `.distignore` lists dev vendor directories by hand, and
 * three packages added to `require-dev` after that list was written
 * (`phpstan/phpstan`, `szepeviktor/phpstan-wordpress` and its transitive
 * `php-stubs/wordpress-stubs`) were missing from it: a local build staged 78
 * files of WordPress stubs and static-analysis tooling into the ZIP, along with
 * a third-party LICENSE.
 *
 * This test derives the required list from composer.lock instead of restating
 * it, so adding a dev dependency without a matching `.distignore` entry fails
 * the build.
 *
 * @package PerformanceOptimise\Tests
 */

/**
 * Release packaging guard.
 */
class DistignoreDevPackageTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Read the `.distignore` patterns.
	 *
	 * @return string[]
	 */
	private function distignore_patterns(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$lines = explode( "\n", (string) file_get_contents( WPPO_PLUGIN_PATH . '.distignore' ) );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$out[] = rtrim( $line, '/' );
		}
		return $out;
	}

	/**
	 * Decode composer.lock.
	 *
	 * @return array
	 */
	private function composer_lock(): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test-only local source scan.
		$raw     = (string) file_get_contents( WPPO_PLUGIN_PATH . 'composer.lock' );
		$decoded = json_decode( $raw, true );
		$this->assertIsArray( $decoded, 'composer.lock must be valid JSON' );
		return $decoded;
	}

	/**
	 * Every dev-only package's vendor directory must be excluded.
	 *
	 * @return void
	 */
	public function test_dev_only_packages_are_excluded_from_the_release(): void {
		$lock = $this->composer_lock();
		$prod = array();
		$dev  = array();
		foreach ( (array) ( $lock['packages'] ?? array() ) as $package ) {
			$prod[ (string) $package['name'] ] = true;
		}
		foreach ( (array) ( $lock['packages-dev'] ?? array() ) as $package ) {
			$dev[ (string) $package['name'] ] = true;
		}
		$this->assertNotEmpty( $dev, 'composer.lock must declare dev packages for this guard to mean anything' );

		$patterns = $this->distignore_patterns();

		$missing = array();
		foreach ( array_keys( $dev ) as $name ) {
			// A package in both lists is a real runtime dependency and must ship.
			if ( isset( $prod[ $name ] ) ) {
				continue;
			}
			$vendor = explode( '/', $name )[0];
			$wanted = '/vendor/' . $vendor;
			if ( ! in_array( $wanted, $patterns, true ) ) {
				$missing[] = $name . ' (needs ' . $wanted . ' in .distignore)';
			}
		}

		$this->assertSame(
			array(),
			$missing,
			"Composer dev-only packages that would ship in the release ZIP:\n"
			. implode( "\n", $missing )
			. "\nAdd each vendor directory to .distignore."
		);
	}

	/**
	 * The internal agent artifacts that are tracked in git must not ship.
	 *
	 * @return void
	 */
	public function test_internal_agent_artifacts_are_excluded_from_the_release(): void {
		$patterns = $this->distignore_patterns();
		foreach ( array( '/wppo-agent-rules.md', '/empty_commit.sh' ) as $required ) {
			$this->assertContains(
				$required,
				$patterns,
				"{$required} is tracked in git and must be excluded from the release ZIP"
			);
		}
	}
}
