<?php
/**
 * Minimal functional stand-ins for WP functions used by the min-cache tests.
 *
 * WordPress core is not loaded in the bare PHPUnit environment, so the
 * functions `wp_maybe_inline_styles()` and `WP_Filesystem()` do not exist.
 * These stand-ins let `function_exists()` report them as available (mirroring
 * a real WordPress install) so the tested code paths can proceed. Loading the
 * file after Brain Monkey's setUp() keeps the declarations processable by
 * Patchwork, so they remain mockable via Brain Monkey when needed.
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound, WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

if ( ! function_exists( 'wp_maybe_inline_styles' ) ) {
	/**
	 * Minimal stand-in for core's wp_maybe_inline_styles().
	 *
	 * @return string Empty string.
	 */
	function wp_maybe_inline_styles() {
		return '';
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	/**
	 * Minimal stand-in for core's WP_Filesystem().
	 *
	 * @param bool|array|string $args Optional path or arguments (ignored).
	 * @param string            $context Optional context (ignored).
	 * @param bool              $allow_relaxed_file_ownership Optional (ignored).
	 * @return bool True so Util::init_filesystem() returns the global filesystem.
	 */
	function WP_Filesystem( $args = false, $context = '', $allow_relaxed_file_ownership = false ) {
		return true;
	}
}
