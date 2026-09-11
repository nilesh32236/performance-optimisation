<?php
/**
 * Regression tests locking the canonical settings schema to the live shape.
 *
 * `Util::get_default_settings()` is the single source of truth consumed by
 * `WPPO_CLI_Command::validate_settings_schema()`. Keys legitimately saved by
 * the SPA but omitted from the defaults are reported as "unknown sub-keys"
 * and fail `wp wppo verify --check=settings_schema` on a healthy install.
 * These tests pin every live front-end key into the canonical defaults and
 * assert a representative stored shape validates as `pass`.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\WPPO_CLI_Command;

require_once __DIR__ . '/stubs/wp-cli.php';

/**
 * Canonical-defaults completeness + schema-validation regression tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsSchemaDefaultsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Front-end-saved keys that were missing from the canonical defaults.
	 *
	 * Keys are grouped by tab so each is asserted against the matching
	 * `get_default_settings()` tab (a key in the wrong tab must fail).
	 *
	 * @return array<string, string[]> Map of tab => list of sub-keys.
	 */
	private static function expected_keys_by_tab(): array {
		return array(
			'file_optimisation'  => array(
				'removeQueryStrings',
				'removeWooCSSJS',
				'excludeUrlToKeepJSCSS',
				'removeCssJsHandle',
				'disableEmojis',
				'disableEmbeds',
				'disableDashicons',
				'disableXMLRPC',
				'heartbeatControl',
			),
			'preload_settings'   => array(
				'preconnect',
				'preconnectOrigins',
				'prefetchDNS',
				'dnsPrefetchOrigins',
				'preloadFonts',
				'preloadFontsUrls',
				'preloadCSS',
				'preloadCSSUrls',
			),
			'image_optimisation' => array(
				'wrapInPicture',
				'excludeFirstImages',
				'excludeImages',
				'lazyLoadVideos',
				'enableVideoPlaceholder',
				'excludeVideos',
				'convertImg',
				'conversionFormat',
				'excludeConvertImages',
				'preloadFrontPageImages',
				'preloadFrontPageImagesUrls',
				'preloadPostTypeImage',
				'selectedPostType',
				'availablePostTypes',
				'excludePostTypeImgUrl',
				'maxWidthImgSize',
				'excludeSize',
				'forceServerSideConversion',
			),
			'edge_cache'         => array(
				'provider',
				'ttl',
				'staleWhileRevalidate',
				'cloudflareZoneId',
				'bunnyPullZoneId',
			),
		);
	}

	/**
	 * Live `wppo_settings` values for the keys above (captured from a healthy
	 * install). Used to lock the default's TYPE so the schema validator's
	 * array-vs-scalar comparison cannot regress.
	 *
	 * @return array<string, array<string, mixed>> Map of tab => live values.
	 */
	private static function live_values_by_tab(): array {
		return array(
			'file_optimisation'  => array(
				'removeQueryStrings'    => true,
				'removeWooCSSJS'        => false,
				'excludeUrlToKeepJSCSS' => '',
				'removeCssJsHandle'     => '',
				'disableEmojis'         => true,
				'disableEmbeds'         => true,
				'disableDashicons'      => true,
				'disableXMLRPC'         => true,
				'heartbeatControl'      => '60s',
			),
			'preload_settings'   => array(
				'preconnect'         => true,
				'preconnectOrigins'  => '',
				'prefetchDNS'        => true,
				'dnsPrefetchOrigins' => '',
				'preloadFonts'       => true,
				'preloadFontsUrls'   => '',
				'preloadCSS'         => true,
				'preloadCSSUrls'     => '',
			),
			'image_optimisation' => array(
				'wrapInPicture'              => true,
				'excludeFirstImages'         => 3,
				'excludeImages'              => '',
				'lazyLoadVideos'             => true,
				'enableVideoPlaceholder'     => false,
				'excludeVideos'              => '',
				'convertImg'                 => true,
				'conversionFormat'           => 'both',
				'excludeConvertImages'       => '',
				'preloadFrontPageImages'     => true,
				'preloadFrontPageImagesUrls' => '',
				'preloadPostTypeImage'       => true,
				'selectedPostType'           => array(),
				'availablePostTypes'         => array( 'post', 'page', 'project' ),
				'excludePostTypeImgUrl'      => '',
				'maxWidthImgSize'            => 0,
				'excludeSize'                => '',
				'forceServerSideConversion'  => false,
			),
			'edge_cache'         => array(
				'provider'             => 'cloudflare',
				'ttl'                  => 300,
				'staleWhileRevalidate' => 86400,
				'cloudflareZoneId'     => '',
				'bunnyPullZoneId'      => '',
			),
		);
	}

	/**
	 * Every front-end-saved key must exist in the canonical defaults for its tab.
	 */
	public function test_defaults_include_every_live_frontend_key(): void {
		$defaults = Util::get_default_settings();

		foreach ( self::expected_keys_by_tab() as $tab => $keys ) {
			$this->assertArrayHasKey( $tab, $defaults, "Missing defaults tab: {$tab}" );
			foreach ( $keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$defaults[ $tab ],
					"Canonical defaults for {$tab} must include '{$key}'"
				);
			}
		}
	}

	/**
	 * Default type (array vs scalar) must match the type the SPA stores so the
	 * schema validator's one-level type check cannot flag a healthy install.
	 */
	public function test_defaults_type_match_live_values(): void {
		$defaults = Util::get_default_settings();

		foreach ( self::live_values_by_tab() as $tab => $values ) {
			foreach ( $values as $key => $live_value ) {
				$this->assertSame(
					is_array( $live_value ),
					is_array( $defaults[ $tab ][ $key ] ),
					"Default type for {$tab}.{$key} must match the stored live type"
				);
			}
		}
	}

	/**
	 * `excludeFirstImages` must keep the OD-bridge absent fallback (2).
	 *
	 * OD_Bridge::get_exclude_first_images_count() branches on PRESENCE, not
	 * emptiness: absent → 2, present and <= 0 → 1. A default of 0 would flip
	 * fresh/CLI-merged installs from 2 to 1.
	 */
	public function test_exclude_first_images_defaults_to_od_bridge_absent_fallback(): void {
		$defaults = Util::get_default_settings();

		$this->assertSame( 2, $defaults['image_optimisation']['excludeFirstImages'] );
	}

	/**
	 * A stored shape mirroring the live install must validate as `pass` once
	 * every front-end key is part of the canonical defaults.
	 */
	public function test_validate_settings_schema_passes_on_representative_live_shape(): void {
		// Start from the full canonical shape (all tabs present) then overlay
		// the live values for the previously-omitted front-end keys, exactly
		// reproducing the stored shape that used to fail as unknown sub-keys.
		$stored = array_replace_recursive(
			Util::get_default_settings(),
			self::live_values_by_tab()
		);

		$row = WPPO_CLI_Command::validate_settings_schema( $stored );

		$this->assertSame( 'settings_schema', $row['check'] );
		$this->assertSame( 'pass', $row['status'], $row['detail'] );
	}
}
