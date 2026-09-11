<?php
/**
 * Tests for the uninstall option list (audit #899).
 *
 * The uninstall entrypoint runs standalone under WP_UNINSTALL_PLUGIN and
 * cannot be executed inside PHPUnit, so these tests exercise the extracted
 * list logic: the canonical `Util::UNINSTALL_OPTIONS` constant plus a static
 * (text-only, never executed) sync check against uninstall.php.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

use PerformanceOptimise\Inc\Util;
use PerformanceOptimise\Inc\WPPO_CLI_Command;

require_once __DIR__ . '/stubs/wp-cli.php';

/**
 * Uninstall option-list membership/completeness/sync tests.
 *
 * @package PerformanceOptimise\Tests
 */
class UninstallOptionsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Option keys added by the option-leak hardening pass.
	 *
	 * Live `wp wppo verify` flagged the salt/marker keys; the audit also found
	 * the RUM counter, the AI anomaly cooldown and the Object_Cache circuit
	 * keys missing. All must be present in both lists and considered known by
	 * `WPPO_CLI_Command::find_unknown_wppo_options()`.
	 *
	 * @var string[]
	 */
	private array $leak_hardening_options = array(
		'wppo_ccss_salt',                          // Critical_CSS::SALT_KEY.
		'wppo_sysinfo_salt',                       // System_Info::DROPIN_SALT_KEY.
		'wppo_rum_top_url_gen',                    // class-rum.php:332.
		'wppo_remove_query_strings_deprecated_logged', // class-main.php:1316/1322.
		'wppo_ai_anomaly_last_alarm',              // AI_Adaptive::ANOMALY_COOLDOWN_KEY.
		'wppo_object_cache_circuit',               // Object_Cache::CIRCUIT_OPTION.
		'wppo_object_cache_circuit_dismissed',     // Object_Cache::CIRCUIT_DISMISSED_OPTION.
	);

	/**
	 * The fixed option names every uninstall must remove per site.
	 *
	 * Completeness baseline: the 24 names that existed before audit #899 plus
	 * the three audit #899 findings (img-scan cursors, blog-prefixed purge
	 * queue) plus the issue #934 autoload-remediation priors option plus the
	 * option-leak hardening additions.
	 *
	 * @var string[]
	 */
	private array $expected_options = array(
		'wppo_settings',
		'wppo_img_info',
		'wppo_transient_index',
		'wppo_preload_cron_offset',
		'wppo_last_db_cleanup',
		'wppo_version',
		'wppo_block_assets_migrated',
		'wppo_cache_last_cleared',
		'wppo_cache_last_cleared_time',
		'wppo_activation_time',
		'wppo_activity_cache_version',
		'wppo_audit_salt',
		'wppo_db_cleanup_salt',
		'wppo_activity_log_salt',
		'wppo_img_info_salt',
		'wppo_review_dismissed',
		'wppo_review_snoozed_until',
		'wppo_web_vitals_rum',
		'wppo_ai_model',
		'wppo_web_vitals_trends',
		'wppo_web_vitals_trends_lock',
		'wppo_web_vitals_last_rescan',
		'wppo_preload_cron_last_id',
		'wppo_preload_cron_migrated',
		// Audit #899 additions.
		'wppo_img_scan_cursor',
		'wppo_img_scan_cursor_max',
		'wppo_litespeed_purge_queue',
		// Issue #934 autoload remediation priors.
		'wppo_autoload_remediated',
		'wppo_autoload_migrated',
		// Option-leak hardening additions.
		'wppo_ccss_salt',
		'wppo_sysinfo_salt',
		'wppo_rum_top_url_gen',
		'wppo_remove_query_strings_deprecated_logged',
		'wppo_ai_anomaly_last_alarm',
		'wppo_object_cache_circuit',
		'wppo_object_cache_circuit_dismissed',
	);

	/**
	 * Membership: the constant must contain every expected name.
	 */
	public function test_uninstall_options_constant_contains_all_expected_names(): void {
		foreach ( $this->expected_options as $name ) {
			$this->assertContains( $name, Util::UNINSTALL_OPTIONS, "Util::UNINSTALL_OPTIONS must contain {$name}" );
		}
	}

	/**
	 * Completeness: the constant must not have drifted beyond the baseline
	 * (no duplicates, no unexpected extras).
	 */
	public function test_uninstall_options_constant_is_exact_and_unique(): void {
		$this->assertSame(
			array_values( $this->expected_options ),
			array_values( Util::UNINSTALL_OPTIONS ),
			'Util::UNINSTALL_OPTIONS drifted — update UninstallOptionsTest::$expected_options intentionally'
		);
		$this->assertCount(
			count( array_unique( Util::UNINSTALL_OPTIONS ) ),
			Util::UNINSTALL_OPTIONS,
			'Util::UNINSTALL_OPTIONS must not contain duplicates'
		);
	}

	/**
	 * Every entry must be plugin-namespaced so the bulk LIKE sweeps never
	 * accidentally become the only deletion path for a fixed option.
	 */
	public function test_uninstall_options_constant_is_wppo_prefixed(): void {
		foreach ( Util::UNINSTALL_OPTIONS as $name ) {
			$this->assertStringStartsWith( 'wppo_', $name );
		}
	}

	/**
	 * Extract the option list from uninstall.php (static text read — the
	 * file itself is never executed here).
	 *
	 * @return array{names: string[], region: string}|null Null when the list region cannot be located.
	 */
	private function extract_uninstall_option_list(): ?array {
		$path   = WPPO_PLUGIN_PATH . 'uninstall.php';
		$source = file_get_contents( $path );
		if ( false === $source ) {
			return null;
		}

		if ( ! preg_match( '/\$wppo_options\s*=\s*array\((.*?)\);/s', $source, $m ) ) {
			return null;
		}

		preg_match_all( "/'([a-z0-9_]+)'/", $m[1], $names );
		return array(
			'names'  => $names[1],
			'region' => $m[1],
		);
	}

	/**
	 * Sync guard: uninstall.php's inline list (it runs standalone, without the
	 * plugin's classes) must match Util::UNINSTALL_OPTIONS exactly.
	 */
	public function test_uninstall_php_option_list_stays_in_sync_with_constant(): void {
		$extracted = $this->extract_uninstall_option_list();
		$this->assertNotNull( $extracted, 'uninstall.php must keep the $wppo_options list for the sync test to guard' );

		$found = $extracted['names'];
		sort( $found );
		$expected = Util::UNINSTALL_OPTIONS;
		sort( $expected );

		// Count checks first: a duplicated entry inside uninstall.php would
		// still pass the unique-comparison below (PR #912 review follow-up).
		$this->assertSame(
			count( $expected ),
			count( $found ),
			'uninstall.php option list length drifted from Util::UNINSTALL_OPTIONS'
		);
		$this->assertSame(
			count( $found ),
			count( array_unique( $found ) ),
			'uninstall.php option list contains duplicate entries'
		);
		$this->assertSame(
			array_values( $expected ),
			array_values( array_unique( $found ) ),
			'uninstall.php option list drifted from Util::UNINSTALL_OPTIONS (audit #899 regression guard)'
		);
	}

	/**
	 * Leak hardening: every newly-added key must be present in the canonical
	 * constant AND in uninstall.php's inline list (the two lists must match).
	 */
	public function test_leak_hardening_options_present_in_both_lists(): void {
		$extracted = $this->extract_uninstall_option_list();
		$this->assertNotNull( $extracted, 'uninstall.php must keep the $wppo_options list' );

		foreach ( $this->leak_hardening_options as $name ) {
			$this->assertContains(
				$name,
				Util::UNINSTALL_OPTIONS,
				"Util::UNINSTALL_OPTIONS must contain the leak-hardening option {$name}"
			);
			$this->assertContains(
				$name,
				$extracted['names'],
				"uninstall.php \$wppo_options must contain the leak-hardening option {$name}"
			);
		}
	}

	/**
	 * `wp wppo verify`'s uninstall check treats Util::UNINSTALL_OPTIONS as its
	 * known-owner set, so every leak-hardening key (plain and blog-prefixed)
	 * must classify as known — otherwise verify keeps warning after uninstall.
	 */
	public function test_leak_hardening_options_are_known_to_verify(): void {
		$probe = $this->leak_hardening_options;
		// Include a multisite blog-prefixed variant to mirror verify's matcher.
		$probe[] = '7_wppo_ccss_salt';

		$this->assertSame(
			array(),
			WPPO_CLI_Command::find_unknown_wppo_options( $probe ),
			'wp wppo verify must classify every hardened option as having a known owner'
		);
	}

	/**
	 * The purge-queue entry in uninstall.php must be blog-prefixed at runtime
	 * (Util::transient_key() shape) — a plain-name delete misses multisite
	 * sites (audit #899).
	 */
	public function test_uninstall_php_purge_queue_option_is_blog_prefixed(): void {
		$extracted = $this->extract_uninstall_option_list();
		$this->assertNotNull( $extracted, 'uninstall.php must keep the $wppo_options list' );

		$region = str_replace( ' ', '', $extracted['region'] );
		$this->assertStringContainsString(
			"\$transient_prefix.'wppo_litespeed_purge_queue'",
			$region,
			'Purge-queue option must be deleted under its blog-prefixed name on multisite'
		);
	}

	/**
	 * Regression guard: the phantom wppo_lscache_tag_queue delete_option must
	 * not come back — the tag queue is a transient (LiteSpeed_Integration::
	 * TAG_QUEUE), already covered by the bulk transient LIKE sweep.
	 */
	public function test_uninstall_php_has_no_phantom_tag_queue_option_delete(): void {
		$path   = WPPO_PLUGIN_PATH . 'uninstall.php';
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );
		$this->assertStringNotContainsString(
			"delete_option( 'wppo_lscache_tag_queue' )",
			(string) $source,
			'wppo_lscache_tag_queue is a transient, never an option (audit #899)'
		);
	}

	/**
	 * The dynamic front-page LCP options (wppo_front_page_lcp_{strategy}) must
	 * be deleted via an options-LIKE sweep whose prefix matches the
	 * Util::FRONT_PAGE_LCP_OPTION_PREFIX canonical value.
	 */
	public function test_uninstall_php_deletes_dynamic_front_page_lcp_options(): void {
		$path   = WPPO_PLUGIN_PATH . 'uninstall.php';
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );

		$this->assertStringContainsString(
			"esc_like( '" . Util::FRONT_PAGE_LCP_OPTION_PREFIX . "' )",
			(string) $source,
			'uninstall.php must LIKE-delete wppo_front_page_lcp_* with the canonical prefix'
		);
		$this->assertSame(
			'wppo_front_page_lcp_',
			Util::FRONT_PAGE_LCP_OPTION_PREFIX,
			'Canonical front-page LCP prefix must match Pagespeed::store_lcp_image_url()'
		);
	}

	/**
	 * Path-containment guard: wppo_delete_directory() must refuse anything
	 * outside WP_CONTENT_DIR (normalised prefix + realpath check) so a
	 * crafted path can never escape to the filesystem root (audit #897).
	 */
	public function test_uninstall_php_delete_directory_has_path_containment_guard(): void {
		$path   = WPPO_PLUGIN_PATH . 'uninstall.php';
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );

		// Scope the assertions to the extracted function body so a stray
		// comment elsewhere in the file cannot satisfy the guard checks.
		$pattern = '/function wppo_delete_directory\([^)]*\)[^{]*\{(.*?)\n\s*\}\s*\}/s';
		if ( ! preg_match( $pattern, (string) $source, $m ) ) {
			$this->fail( 'Could not extract wppo_delete_directory() body from uninstall.php' );
		}
		$body = $m[1];

		$this->assertStringContainsString(
			'wp_normalize_path( WP_CONTENT_DIR )',
			$body,
			'wppo_delete_directory() must normalise WP_CONTENT_DIR as the containment root'
		);
		$this->assertStringContainsString(
			'realpath( $dir )',
			$body,
			'wppo_delete_directory() must resolve symlinks via realpath before the prefix check'
		);
		$this->assertStringContainsString(
			'strpos( $normalized_real_dir, $normalized_real_root )',
			$body,
			'wppo_delete_directory() must require the resolved path to stay inside WP_CONTENT_DIR'
		);
		$this->assertStringContainsString(
			'trailingslashit( $normalized_dir )',
			$body,
			'wppo_delete_directory() must reject WP_CONTENT_DIR itself (root-equality guard)'
		);
		$this->assertStringContainsString(
			'\.\.',
			$body,
			'wppo_delete_directory() must reject dot-dot traversal segments'
		);
	}

	/**
	 * The welcome user-meta cleanup must use the core metadata API.
	 *
	 * The uninstall.php entrypoint cannot be executed inside PHPUnit (it runs
	 * standalone), so this is a static source guard. The previous helper was
	 * not a WordPress core function and would fatal, aborting uninstall.
	 */
	public function test_uninstall_php_deletes_welcome_user_meta_via_core_api(): void {
		$path   = WPPO_PLUGIN_PATH . 'uninstall.php';
		$source = file_get_contents( $path );
		$this->assertNotFalse( $source );
		$source = (string) $source;

		$this->assertStringContainsString(
			"delete_metadata( 'user', null, 'wppo_welcome_dismissed', '', true );",
			$source,
			'uninstall.php must delete the welcome user meta via delete_metadata( \'user\', null, ... )'
		);
		$this->assertStringNotContainsString(
			'delete_user_meta_by_key',
			$source,
			'delete_user_meta_by_key() does not exist in core and fatals uninstall'
		);
	}

	/**
	 * The user-meta key removed on uninstall must match the key the plugin
	 * writes/reads elsewhere (Main::... show_welcome).
	 */
	public function test_uninstall_welcome_meta_key_matches_plugin_usage(): void {
		$source = file_get_contents( WPPO_PLUGIN_PATH . 'includes/class-main.php' );
		$this->assertNotFalse( $source );

		$this->assertStringContainsString(
			"get_user_meta( get_current_user_id(), 'wppo_welcome_dismissed', true )",
			(string) $source,
			'uninstall.php must delete the same user meta key the plugin reads'
		);
	}
}
