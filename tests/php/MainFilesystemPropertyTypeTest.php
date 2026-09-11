<?php
/**
 * Regression test for the namespaced global type on Main::$filesystem.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Main;

/**
 * Asserts that Main::$filesystem is typed against the GLOBAL WP_Filesystem_Base.
 *
 * In production the unqualified `WP_Filesystem_Base` type resolved inside the
 * `PerformanceOptimise\Inc` namespace to the non-existent
 * `PerformanceOptimise\Inc\WP_Filesystem_Base`, so assigning the real
 * `\WP_Filesystem_Direct` returned by Util::init_filesystem() threw a TypeError
 * and hard-fataled wp-login.php / wp-admin.
 *
 * @package PerformanceOptimise\Tests
 */
class MainFilesystemPropertyTypeTest extends \PHPUnit\Framework\TestCase {

	/**
	 * The property type must resolve to the global class, never the namespaced
	 * one.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	public function test_filesystem_property_type_resolves_to_global_class(): void {
		// Load the class directly from this checkout (the symlinked vendor
		// classmap can point at the primary checkout when tests run from a git
		// worktree). Required here rather than at file scope so test discovery
		// does not force-load Main for every other suite.
		require_once __DIR__ . '/../../includes/class-main.php';

		$property = new \ReflectionProperty( Main::class, 'filesystem' );
		$property->setAccessible( true );

		$type = $property->getType();

		$this->assertInstanceOf(
			\ReflectionUnionType::class,
			$type,
			'Main::$filesystem is expected to be a union type of WP_Filesystem_Base|false|null.'
		);

		$names = array();
		foreach ( $type->getTypes() as $named ) {
			if ( $named instanceof \ReflectionNamedType ) {
				$names[] = $named->getName();
			}
		}

		$this->assertContains(
			'WP_Filesystem_Base',
			$names,
			'Main::$filesystem must be fully-qualified to the global \WP_Filesystem_Base.'
		);

		$this->assertNotContains(
			'PerformanceOptimise\Inc\WP_Filesystem_Base',
			$names,
			'An unqualified WP_Filesystem_Base resolves inside the namespace and breaks assignment.'
		);
	}
}
