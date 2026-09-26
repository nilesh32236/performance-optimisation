<?php
/**
 * Regression tests locking the canonical settings schema to the live shape.
 *
 * `Util::get_settings_schema()` is the single source of truth consumed by
 * `WPPO_CLI_Command::validate_settings_schema()`. It is deliberately decoupled
 * from `Util::get_default_settings()` so keys the SPA persists (but which are
 * NOT runtime defaults) are recognised without seeding them. These tests pin
 * the live front-end keys into the schema, assert the runtime defaults are a
 * subset, and guard against SPA drift with a pragmatic source scan.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */

use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\WPPO_CLI_Command;

require_once __DIR__ . '/stubs/wp-cli.php';

/**
 * Schema completeness + schema-validation regression tests.
 *
 * @package PerformanceOptimise\Tests
 */
class SettingsSchemaDefaultsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Front-end-saved keys that are not runtime defaults.
	 *
	 * Keys are grouped by tab so each is asserted against the matching schema
	 * tab (a key in the wrong tab must fail).
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
	 * install). Used to lock the schema's expected TYPE so the validator's
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
	 * Every front-end-saved key must exist in the schema for its tab.
	 */
	public function test_schema_includes_every_live_frontend_key(): void {
		$schema = Util::get_settings_schema();

		foreach ( self::expected_keys_by_tab() as $tab => $keys ) {
			$this->assertArrayHasKey( $tab, $schema, "Missing schema tab: {$tab}" );
			foreach ( $keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$schema[ $tab ],
					"Settings schema for {$tab} must include '{$key}'"
				);
			}
		}

		// The live values' array/scalar shape must match the schema entry.
		foreach ( self::live_values_by_tab() as $tab => $values ) {
			foreach ( $values as $key => $live_value ) {
				$this->assertSame(
					is_array( $live_value ) ? 'array' : 'scalar',
					$schema[ $tab ][ $key ],
					"Schema type for {$tab}.{$key} must match the stored live type"
				);
			}
		}
	}

	/**
	 * Every runtime default key (and array/scalar shape) must exist in the
	 * schema, so defaults-based validation remains a subset.
	 */
	public function test_defaults_are_subset_of_schema(): void {
		$defaults = Util::get_default_settings();
		$schema   = Util::get_settings_schema();

		foreach ( $defaults as $tab => $values ) {
			$this->assertArrayHasKey( $tab, $schema, "Schema missing defaults tab: {$tab}" );
			$this->assertIsArray( $values );
			foreach ( $values as $key => $value ) {
				$this->assertArrayHasKey(
					$key,
					$schema[ $tab ],
					"Schema missing default key {$tab}.{$key}"
				);
				$this->assertSame(
					is_array( $value ) ? 'array' : 'scalar',
					$schema[ $tab ][ $key ],
					"Schema type mismatch for default {$tab}.{$key}"
				);
			}
		}
	}

	/**
	 * A stored shape mirroring the live install (with the previously-omitted
	 * keys, plus every default tab) must validate as `pass`.
	 */
	public function test_validate_settings_schema_passes_on_representative_live_shape(): void {
		// Start from the full canonical shape (all tabs present) then overlay
		// the live values for the front-end-only keys, reproducing the stored
		// keys that used to fail as unknown sub-keys.
		$stored = array_replace_recursive(
			Util::get_default_settings(),
			self::live_values_by_tab()
		);

		$row = WPPO_CLI_Command::validate_settings_schema( $stored );

		$this->assertSame( 'settings_schema', $row['check'] );
		$this->assertSame( 'pass', $row['status'], $row['detail'] );
	}

	/**
	 * The exact live shape (which omits three default-only tabs) must warn,
	 * never fail with unknown sub-keys.
	 */
	public function test_validate_settings_schema_does_not_fail_on_exact_live_shape(): void {
		$stored = array(
			'cache_settings'     => array(
				'enableLoggedInCache' => false,
				'loggedInCacheRoles'  => array(),
				'enableCache'         => true,
			),
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
				'enabled'              => false,
				'provider'             => 'cloudflare',
				'ttl'                  => 300,
				'staleWhileRevalidate' => 86400,
				'cloudflareZoneId'     => '',
				'bunnyPullZoneId'      => '',
			),
		);

		$row = WPPO_CLI_Command::validate_settings_schema( $stored );

		$this->assertSame( 'warn', $row['status'], $row['detail'] );
		$this->assertStringNotContainsString( 'unknown sub-keys', $row['detail'] );
	}

	/**
	 * Pragmatic SPA→schema drift guard.
	 *
	 * Globs every `src/components/*.js`, extracts the top-level keys of each
	 * component's `defaultSettings` / `settings` / `payload` object, maps the
	 * object to its `tab: '...'`, and asserts the key exists in the schema.
	 *
	 * This is a source-scan guard, not a runtime contract: replace it with a
	 * localized `wppoSettings.schema` when the SPA exports one.
	 */
	public function test_spa_component_keys_are_in_schema(): void {
		$schema  = Util::get_settings_schema();
		$scanned = $this->scan_spa_settings_keys();

		$this->assertNotEmpty( $scanned, 'SPA scan found no settings objects' );

		foreach ( $scanned as $tab => $keys ) {
			$this->assertArrayHasKey( $tab, $schema, "SPA-persisted tab '{$tab}' missing from schema" );
			foreach ( $keys as $key ) {
				$this->assertArrayHasKey(
					$key,
					$schema[ $tab ],
					"SPA key '{$tab}.{$key}' missing from Util::get_settings_schema()"
				);
			}
		}
	}

	/**
	 * Every component exposing a `defaultSettings` literal must be mapped to a
	 * tab so a newly added component cannot silently escape the drift guard.
	 */
	public function test_every_spa_default_settings_component_is_mapped(): void {
		$map   = self::default_settings_tabs();
		$files = glob( WPPO_PLUGIN_PATH . 'src/components/*.js' );
		$this->assertNotEmpty( $files );

		$with_defaults = array();
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			if ( false !== strpos( $src, 'const defaultSettings =' ) ) {
				$with_defaults[] = basename( $file );
			}
		}

		sort( $with_defaults );
		$expected = array_keys( $map );
		sort( $expected );

		$this->assertSame(
			$expected,
			$with_defaults,
			'Every component with a defaultSettings literal must be mapped in SettingsSchemaDefaultsTest::default_settings_tabs()'
		);
	}

	/**
	 * Components whose `defaultSettings` literal is persisted under a tab.
	 *
	 * @return array<string, string> File name => settings tab.
	 */
	private static function default_settings_tabs(): array {
		return array(
			'DatabaseCleanup.js'   => 'database_cleanup',
			'FileOptimization.js'  => 'file_optimisation',
			'ImageOptimization.js' => 'image_optimisation',
			'ObjectCache.js'       => 'object_cache',
			'PreloadSettings.js'   => 'preload_settings',
		);
	}

	/**
	 * Extract top-level keys per tab from the SPA component sources.
	 *
	 * @return array<string, string[]> Map of tab => unique keys.
	 */
	private function scan_spa_settings_keys(): array {
		$dir   = WPPO_PLUGIN_PATH . 'src/components';
		$files = glob( $dir . '/*.js' );
		$this->assertIsArray( $files );

		$default_settings_tabs = self::default_settings_tabs();
		$results               = array();

		foreach ( $files as $file ) {
			$name = basename( $file );
			$src  = (string) file_get_contents( $file );

			$tabs = array();
			if ( preg_match_all( "/\btab\s*:\s*'([a-z_]+)'/", $src, $tm, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $tm[1] as $idx => $hit ) {
					$tabs[] = array(
						'pos' => $tm[0][ $idx ][1],
						'tab' => $hit[0],
					);
				}
			}
			usort( $tabs, static fn( $a, $b ) => $a['pos'] <=> $b['pos'] );

			$nearest_tab = static function ( int $pos ) use ( $tabs ) {
				$found = null;
				foreach ( $tabs as $t ) {
					if ( $t['pos'] < $pos ) {
						$found = $t['tab'];
					}
				}
				return $found;
			};

			// `const defaultSettings = { ... }` blocks.
			if ( preg_match_all( '/const\s+defaultSettings\s*=\s*\{/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
				$tab = $default_settings_tabs[ $name ] ?? null;
				if ( null !== $tab ) {
					foreach ( $m[0] as $hit ) {
						$brace           = strpos( $src, '{', (int) $hit[1] );
						$keys            = self::extract_object_keys( $src, (int) $brace + 1 );
						$results[ $tab ] = array_merge( $results[ $tab ] ?? array(), $keys );
					}
				}
			}

			// `settings: { ... }` / `payload: { ... }` update payloads.
			if ( preg_match_all( '/\b(settings|payload)\s*:\s*\{/', $src, $m2, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m2[0] as $idx => $hit ) {
					$brace = strpos( $src, '{', (int) $hit[1] );
					$keys  = self::extract_object_keys( $src, (int) $brace + 1 );

					// Skip WelcomePanel's wrapper `{ tab, payload }`.
					if ( in_array( 'tab', $keys, true ) && in_array( 'payload', $keys, true ) ) {
						continue;
					}

					$tab = $nearest_tab( (int) $hit[1] );
					if ( null === $tab ) {
						continue;
					}
					$results[ $tab ] = array_merge( $results[ $tab ] ?? array(), $keys );
				}
			}
		}

		foreach ( $results as $tab => $keys ) {
			$keys = array_values( array_unique( $keys ) );
			sort( $keys );
			$results[ $tab ] = $keys;
		}
		ksort( $results );

		return $results;
	}

	/**
	 * Extract the top-level keys of the JS object literal starting just after
	 * an opening brace.
	 *
	 * Tracks object-brace and paren/bracket depth plus string/comment state so
	 * that shorthand properties (`provider,`) and explicit pairs (`ttl: x,`)
	 * are captured while spreads, values, nested keys and call arguments are
	 * ignored. Pragmatic by design (see the drift-guard docblock).
	 *
	 * @param string $src   Source.
	 * @param int    $start Offset just after the opening `{`.
	 * @return string[] Keys in source order.
	 */
	private static function extract_object_keys( string $src, int $start ): array {
		$len  = strlen( $src );
		$i    = $start;
		$objs = 1; // Object-brace depth.
		$grp  = 0; // Paren/bracket depth.
		$keys = array();

		while ( $i < $len ) {
			$ch = $src[ $i ];

			if ( "'" === $ch || '"' === $ch || '`' === $ch ) {
				++$i;
				while ( $i < $len ) {
					if ( '\\' === $src[ $i ] ) {
						$i += 2;
						continue;
					}
					if ( $src[ $i ] === $ch ) {
						++$i;
						break;
					}
					++$i;
				}
				continue;
			}
			if ( '/' === $ch && ( $i + 1 < $len ) && '/' === $src[ $i + 1 ] ) {
				$nl = strpos( $src, "\n", $i );
				$i  = false === $nl ? $len : $nl + 1;
				continue;
			}
			if ( '/' === $ch && ( $i + 1 < $len ) && '*' === $src[ $i + 1 ] ) {
				$end = strpos( $src, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 2;
				continue;
			}

			if ( '{' === $ch ) {
				++$objs;
				++$i;
				continue;
			}
			if ( '}' === $ch ) {
				--$objs;
				++$i;
				if ( 0 === $objs ) {
					break;
				}
				continue;
			}
			if ( '(' === $ch || '[' === $ch ) {
				++$grp;
				++$i;
				continue;
			}
			if ( ')' === $ch || ']' === $ch ) {
				--$grp;
				++$i;
				continue;
			}

			if ( 1 === $objs && 0 === $grp && preg_match( '/[A-Za-z_$]/', $ch ) ) {
				$j = $i - 1;
				while ( $j >= 0 && preg_match( '/\s/', $src[ $j ] ) ) {
					--$j;
				}
				$prev = $j >= 0 ? $src[ $j ] : '{';
				if ( '{' === $prev || ',' === $prev ) {
					$rest = substr( $src, $i );
					if ( preg_match( '/^([A-Za-z_$][A-Za-z0-9_$]*)\s*([:,}])/', $rest, $m ) ) {
						$keys[] = $m[1];
						$i     += strlen( $m[1] );
						continue;
					}
				}
			}
			++$i;
		}

		return array_values( array_unique( $keys ) );
	}
}
