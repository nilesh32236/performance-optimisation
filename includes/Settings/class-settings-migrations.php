<?php
/**
 * Settings migrations — one-time settings backfills extracted from the orchestrator.
 *
 * ARCH-004: the 17 public `maybe_migrate_*()` one-time settings backfills
 * plus the `migrate_block_assets_setting()` helper previously lived on the
 * orchestrator. They share one responsibility (settings schema migration), one
 * trigger (`admin_init` at priority 10, registration order owned by
 * `Hook_Registry`), one settings state, and one ordering constraint — so they
 * live here, in the Settings domain.
 *
 * The orchestrator keeps thin `maybe_migrate_*()` proxies (facade rule)
 * delegating to a lazy instance of this class, so `Hook_Registry` hook
 * registrations stay byte-identical (no hook churn, callback identity
 * unchanged). The service receives only a callable effective-options reader
 * and a callable invalidation command; it never stores or names the
 * orchestrator. `Settings_Store` remains the persistence and settings-memo
 * owner.
 *
 * Every routine is an idempotent key-presence backfill: guard
 * key-presence → default → persist. Steady-state requests perform zero
 * migration writes. Only `migrate_rum_sample_rate()` (besides the
 * pre-existing `migrate_object_cache_outage_flag()` / `migrate_ai_*()`
 * guards) short-circuits on the already-loaded effective options. The
 * options reader is intentionally a narrow callable contract: migrations
 * read the current effective snapshot, then issue the explicit invalidation
 * command after a successful or concurrently-completed write. All reads use
 * per-site `get_option( 'wppo_settings' )`
 * so multisite sites migrate independently with no cross-site leakage, and
 * fresh installs with no stored option are skipped (constructor defaults
 * already match).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Settings_Migrations' ) ) {
	/**
	 * Class Settings_Migrations
	 *
	 * Owns all one-time settings schema migrations. It receives only the
	 * effective-options reader and invalidation command required by the
	 * migration cluster, with no orchestrator dependency.
	 *
	 * @since 2.4.0
	 * @since NEXT Uses callable options and invalidation contracts (P3-016).
	 */
	final class Settings_Migrations {

		/**
		 * Narrow callable returning the current effective options snapshot.
		 *
		 * @var callable():array
		 * @since NEXT
		 */
		private $options_reader;

		/**
		 * Explicit command invalidating the orchestrator's effective snapshot.
		 *
		 * @var callable():void
		 * @since NEXT
		 */
		private $options_invalidator;

		/**
		 * Constructor.
		 *
		 * @since 2.4.0
		 * @since NEXT Accepts callable settings contracts (P3-016).
		 * @param callable $options_reader     Returns current effective options.
		 * @param callable $options_invalidator Invalidates the owner snapshot.
		 */
		public function __construct( callable $options_reader, callable $options_invalidator ) {
			$this->options_reader      = $options_reader;
			$this->options_invalidator = $options_invalidator;
		}

		/**
		 * Read the current effective options through the narrow contract.
		 *
		 * @since NEXT
		 * @return array Effective options snapshot.
		 */
		private function read_options(): array {
			$options = call_user_func( $this->options_reader );
			return is_array( $options ) ? $options : array();
		}

		/**
		 * Invalidate the owner snapshot after a persisted settings change.
		 *
		 * @since NEXT
		 * @param array $settings Persisted migrated options to adopt.
		 * @return void
		 */
		private function invalidate_options( array $settings ): void {
			call_user_func( $this->options_invalidator, $settings );
		}

		/**
		 * One-time upgrade core for the block-assets toggle on WP 6.9+.
		 *
		 * WP 6.9+ loads core block assets on demand in classic themes by default, but older
		 * installs may store a `wppo_settings` array that predates the `blockAssetsOnDemand`
		 * key (the pre-6.9 default was OFF). Without an upgrade those installs would silently
		 * register the opt-out (forcing the combined `wp-block-library` stylesheet) once they
		 * reach WP 6.9+.
		 *
		 * Only installs that never configured the toggle (key absent) are defaulted to `true`
		 * so they inherit core's new default; any stored explicit value (true or false) is
		 * preserved verbatim, and fresh installs with no stored option are skipped because the
		 * constructor defaults already match. The check is idempotent (key presence is the
		 * marker), so no extra option row is ever allocated — fresh installs create zero
		 * migration rows and steady-state requests perform zero migration writes.
		 *
		 * @since 2.4.0 Relocated verbatim from Main::migrate_block_assets_setting() (ARCH-004).
		 * @param bool $loads_separate_core_block_assets_on_demand Whether WP 6.9+ is active
		 *                                                        (core loads separate core
		 *                                                        block assets on demand).
		 * @return void
		 */
		public function migrate_block_assets_setting( bool $loads_separate_core_block_assets_on_demand ): void {
			$memo = $this->read_options();
			if ( ! $loads_separate_core_block_assets_on_demand ) {
				return;
			}

			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				// Fresh install (or no stored settings): constructor defaults already match
				// WP 6.9+ behavior, so there is nothing to migrate and nothing to record.
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( ! array_key_exists( 'blockAssetsOnDemand', $file ) ) {
				$stored['file_optimisation'] = $file + array( 'blockAssetsOnDemand' => true );
				Util::save_settings( $stored );
				$this->invalidate_options( $stored );

				Util::set_settings_cache( $stored );

				Log::add( __( 'Enabled on-demand block asset loading to match the WordPress 6.9 default.', 'performance-optimisation' ) );
			}
		}

		/**
		 * One-time backfill for the CCSS inline size cap.
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `ccssMaxSize` key (key absent) are backfilled
		 * with the 20 KB default; any stored explicit value is preserved
		 * verbatim, and fresh installs with no stored option are skipped
		 * because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_ccss_max_size() (ARCH-004).
		 * @return void
		 */
		public function migrate_ccss_max_size(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( array_key_exists( 'ccssMaxSize', $file ) ) {
				return;
			}

			$stored['file_optimisation'] = $file + array( 'ccssMaxSize' => 20480 );
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default Critical CSS size cap (20 KB).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the Critical CSS user safelist (issue #1038).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `ccssSafelistExtra` key (key absent) are
		 * backfilled with the empty default; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value. Uses per-site `get_option()` so
		 * multisite sites migrate independently with no cross-site leakage.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_ccss_safelist() (ARCH-004).
		 * @return void
		 */
		public function migrate_ccss_safelist(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( array_key_exists( 'ccssSafelistExtra', $file ) ) {
				return;
			}

			$stored['file_optimisation'] = $file + array( 'ccssSafelistExtra' => '' );
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default Critical CSS safelist (empty, current behaviour kept).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the RUM-weighted CSS queue keys (issue #1164).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate any of the `ccssQueueCap` / `usedCssQueueCap` /
		 * `ccssViewportVariants` / `usedCSSDeliveryMode` / `ccssGenTimeout` keys
		 * (key absent) are backfilled with the fail-open defaults; any stored
		 * explicit value is preserved verbatim, and fresh installs with no
		 * stored option are skipped because the constructor defaults already
		 * match. The check is idempotent (key presence is the marker), so no
		 * extra option row is needed. In-memory options are synced too so the
		 * current request observes the backfilled values. Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @since 2.2.0 Also backfills the 25s `ccssGenTimeout` generation budget.
		 * @since 2.3.0 Also backfills the #1388 keys (`ccssInlineBudgetKb`,
		 *        `ccssCommerceExclude`, `ccssChecksumRegen`).
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_css_queue_defaults() (ARCH-004).
		 * @return void
		 */
		public function migrate_css_queue_defaults(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			$defaults = array(
				'ccssQueueCap'         => 5,
				'usedCssQueueCap'      => 50,
				'ccssViewportVariants' => false,
				'usedCSSDeliveryMode'  => 'file',
				'ccssGenTimeout'       => 25,
				'ccssInlineBudgetKb'   => 14,
				'ccssCommerceExclude'  => true,
				'ccssChecksumRegen'    => true,
			);
			$changed  = false;
			foreach ( $defaults as $key => $default ) {
				if ( ! array_key_exists( $key, $file ) ) {
					$file[ $key ] = $default;
					$changed      = true;
				}
			}
			if ( ! $changed ) {
				return;
			}

			$stored['file_optimisation'] = $file;
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default RUM-weighted CSS queue settings, CCSS generation timeout (25s, single-variant behaviour kept), 14 KB inline budget, commerce exclusion, and checksum regen.', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the RUM-weighted top-URL prefetch cap (issue #1183).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `speculationTopUrlsLimit` key (key absent) are
		 * backfilled with the 2-URL default; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value. Uses per-site `get_option()` so
		 * multisite sites migrate independently with no cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_speculation_top_urls() (ARCH-004).
		 * @return void
		 */
		public function migrate_speculation_top_urls(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$preload = isset( $stored['preload_settings'] ) && is_array( $stored['preload_settings'] ) ? $stored['preload_settings'] : array();

			if ( array_key_exists( 'speculationTopUrlsLimit', $preload ) ) {
				return;
			}

			$preload['speculationTopUrlsLimit'] = 2;
			$stored['preload_settings']         = $preload;
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default RUM-weighted top-URL prefetch limit (2 URLs, prerender stays guarded).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the high-value prerender list toggle (issue #1237).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `speculationPrerenderList` key (key absent) are
		 * backfilled with the off default; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value. Uses per-site `get_option()` so
		 * multisite sites migrate independently with no cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_speculation_prerender_list() (ARCH-004).
		 * @return void
		 */
		public function migrate_speculation_prerender_list(): void {
			$memo = $this->read_options();
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$preload = isset( $stored['preload_settings'] ) && is_array( $stored['preload_settings'] ) ? $stored['preload_settings'] : array();

			if ( array_key_exists( 'speculationPrerenderList', $preload ) ) {
				return;
			}

			$preload['speculationPrerenderList'] = false;
			$stored['preload_settings']          = $preload;
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default high-value prerender list toggle (off, current prefetch behavior kept).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the RUM beacon sample rate (issue #1214).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `rum_sample_rate` key (key absent) are
		 * backfilled with the 100 default (unsampled current behavior); any
		 * stored explicit value is preserved verbatim, and fresh installs with
		 * no stored option are skipped because the constructor defaults already
		 * match. The check is idempotent (key presence is the marker), so no
		 * extra option row is needed. In-memory options are synced too so the
		 * current request observes the backfilled value. Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_rum_sample_rate() (ARCH-004).
		 * @return void
		 */
		public function migrate_rum_sample_rate(): void {
			$memo = $this->read_options();
			// Cheap early-return through the already-loaded memo: after
			// migration completes this avoids one extra option read per
			// admin page.
			if ( isset( $memo['performance_audit'] ) && is_array( $memo['performance_audit'] ) && array_key_exists( 'rum_sample_rate', $memo['performance_audit'] ) ) {
				return;
			}
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$audit = isset( $stored['performance_audit'] ) && is_array( $stored['performance_audit'] ) ? $stored['performance_audit'] : array();

			if ( array_key_exists( 'rum_sample_rate', $audit ) ) {
				return;
			}

			$default_rate = class_exists( 'PerformanceOptimise\Inc\RUM' ) ? \PerformanceOptimise\Inc\RUM::RUM_SAMPLE_RATE_DEFAULT : 100;

			$audit['rum_sample_rate']    = $default_rate;
			$stored['performance_audit'] = $audit;
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default RUM beacon sample rate (100 percent, unsampled current behavior kept).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the missing-alt autofill toggle and the
		 * longest-edge downscale cap (issue #985).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `autoAltText` / `maxLongestEdgePx` keys (key
		 * absent) are backfilled with the fail-open defaults (`false` /
		 * `2560`); any stored explicit value is preserved verbatim, and fresh
		 * installs with no stored option are skipped because the constructor
		 * defaults already match. The check is idempotent (key presence is
		 * the marker), so no extra option row is needed. In-memory options
		 * are synced too so the current request observes the backfilled
		 * values. Uses per-site `get_option()` so multisite sites migrate
		 * independently with no cross-site leakage.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_image_alt_edge_defaults() (ARCH-004).
		 * @return void
		 */
		public function migrate_image_alt_edge_defaults(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$image = isset( $stored['image_optimisation'] ) && is_array( $stored['image_optimisation'] ) ? $stored['image_optimisation'] : array();

			$changed = false;
			if ( ! array_key_exists( 'autoAltText', $image ) ) {
				$image['autoAltText'] = false;
				$changed              = true;
			}
			if ( ! array_key_exists( 'maxLongestEdgePx', $image ) ) {
				$image['maxLongestEdgePx'] = 2560;
				$changed                   = true;
			}

			if ( ! $changed ) {
				return;
			}

			$stored['image_optimisation'] = $image;
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default image alt autofill and longest-edge cap settings.', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the unified safe-mode kill switch (issue #1098).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `safeMode` key (key absent) are backfilled with
		 * the off default (`false`, current behaviour kept); any stored
		 * explicit value is preserved verbatim, and fresh installs with no
		 * stored option are skipped because the constructor defaults already
		 * match. The check is idempotent (key presence is the marker), so no
		 * extra option row is needed. In-memory options are synced too so the
		 * current request observes the backfilled value. Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_safe_mode() (ARCH-004).
		 * @return void
		 */
		public function migrate_safe_mode(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( array_key_exists( 'safeMode', $file ) ) {
				return;
			}

			$stored['file_optimisation'] = $file + array( 'safeMode' => false );
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );
		}

		/**
		 * Backfill the additive elementorSafeMode key (issue #1259).
		 *
		 * Runs on admin_init; in-memory default is applied in __construct so
		 * front-end requests never pay for a DB write. Defaults to on
		 * (builder-proof by default). Multisite-safe: per-site
		 * get_option() so sites migrate independently.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_elementor_safe_mode() (ARCH-004).
		 * @return void
		 */
		public function migrate_elementor_safe_mode(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array".
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}
			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();
			if ( array_key_exists( 'elementorSafeMode', $file ) ) {
				return;
			}
			$stored['file_optimisation'] = $file + array( 'elementorSafeMode' => true );
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );
			Util::set_settings_cache( $stored );
		}

		/**
		 * One-time backfill for automatic LCP hero preload + font discovery (issue #1216).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `autoLcpPreload` / `autoDiscoverFonts` keys
		 * (key absent) are backfilled with the off defaults (`false`, manual
		 * lists keep winning); any stored explicit value is preserved
		 * verbatim, and fresh installs with no stored option are skipped
		 * because the constructor defaults already match. Idempotent (key
		 * presence is the marker). Uses per-site `get_option()` so multisite
		 * sites migrate independently with no cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_preload_auto_defaults() (ARCH-004).
		 * @return void
		 */
		public function migrate_preload_auto_defaults(): void {
			$memo = $this->read_options();
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$preload = isset( $stored['preload_settings'] ) && is_array( $stored['preload_settings'] ) ? $stored['preload_settings'] : array();

			$changed = false;
			if ( ! array_key_exists( 'autoLcpPreload', $preload ) ) {
				$preload['autoLcpPreload'] = false;
				$changed                   = true;
			}
			if ( ! array_key_exists( 'autoDiscoverFonts', $preload ) ) {
				$preload['autoDiscoverFonts'] = false;
				$changed                      = true;
			}

			if ( ! $changed ) {
				return;
			}

			$stored['preload_settings'] = $preload;
			Util::save_settings( $stored );
			$this->invalidate_options( $stored );

			Util::set_settings_cache( $stored );

			Log::add( __( 'Added default automatic LCP preload and font discovery settings (both off; manual lists keep winning).', 'performance-optimisation' ) );
		}

		/**
		 * Backfill the additive Redis outage status flag (issue #1233).
		 *
		 * Adds `object_cache.outage_bypassed = false` to stored settings that
		 * predate the key. Runs on `admin_init` (not the constructor) so a
		 * cacheable front-end request never triggers a settings write. Fresh
		 * installs with no stored option are skipped (absent key reads as
		 * "not bypassed"). Idempotent (key presence is the marker). Uses
		 * per-site `get_option()` so multisite sites migrate independently
		 * with no cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_object_cache_outage_flag() (ARCH-004).
		 * @return void
		 */
		public function migrate_object_cache_outage_flag(): void {
			$memo = $this->read_options();
			try {
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// Cheap early-return through the already-loaded memo: after
				// migration completes this avoids one extra option read per
				// admin page.
				if ( isset( $memo['object_cache'] ) && is_array( $memo['object_cache'] ) && array_key_exists( 'outage_bypassed', $memo['object_cache'] ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}

				$oc = isset( $stored['object_cache'] ) && is_array( $stored['object_cache'] ) ? $stored['object_cache'] : array();

				if ( array_key_exists( 'outage_bypassed', $oc ) ) {
					return;
				}

				$stored['object_cache'] = $oc + array( 'outage_bypassed' => false );
				Util::save_settings( $stored );
				$this->invalidate_options( $stored );

				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * One-time backfill for RUM-segmented speculation auto-tune (issue #1425).
		 *
		 * Adds the additive `ai_adaptive.speculation_autotune_enabled`,
		 * `ai_adaptive.speculation_min_samples`, and
		 * `ai_adaptive.speculation_max_urls` keys to stored settings that
		 * predate them. Runs on `admin_init` (not the constructor) so a
		 * cacheable front-end request never triggers a settings write. Only
		 * installs missing a key are backfilled (opt-in off, min 20, max 5);
		 * any stored explicit value is preserved verbatim, and fresh installs
		 * with no stored option are skipped because the constructor defaults
		 * already match. Idempotent (key presence is the marker). Uses
		 * per-site `get_option()` so multisite sites migrate independently
		 * with no cross-site leakage.
		 *
		 * @since 2.3.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_ai_speculation_autotune() (ARCH-004).
		 * @return void
		 */
		public function migrate_ai_speculation_autotune(): void {
			$memo = $this->read_options();
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return;
				}
				// Cheap early-return through the already-loaded memo: after
				// migration completes this avoids one extra option read per
				// admin page.
				if ( isset( $memo['ai_adaptive'] ) && is_array( $memo['ai_adaptive'] )
					&& array_key_exists( 'speculation_autotune_enabled', $memo['ai_adaptive'] )
					&& array_key_exists( 'speculation_min_samples', $memo['ai_adaptive'] )
					&& array_key_exists( 'speculation_max_urls', $memo['ai_adaptive'] ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}

				$ai = isset( $stored['ai_adaptive'] ) && is_array( $stored['ai_adaptive'] ) ? $stored['ai_adaptive'] : array();

				$changed = false;
				if ( ! array_key_exists( 'speculation_autotune_enabled', $ai ) ) {
					$ai['speculation_autotune_enabled'] = false;
					$changed                            = true;
				}
				if ( ! array_key_exists( 'speculation_min_samples', $ai ) ) {
					$ai['speculation_min_samples'] = 20;
					$changed                       = true;
				}
				if ( ! array_key_exists( 'speculation_max_urls', $ai ) ) {
					$ai['speculation_max_urls'] = 5;
					$changed                    = true;
				}
				if ( ! $changed ) {
					return;
				}

				$stored['ai_adaptive'] = $ai;
				Util::save_settings( $stored );
				$this->invalidate_options( $stored );

				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
				Log::add( __( 'Added default RUM-segmented speculation auto-tune settings (off; manual speculation settings untouched).', 'performance-optimisation' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * One-time backfill for anomaly detector v2 keys (issue #1313).
		 *
		 * Adds the additive `ai_adaptive.anomaly_band_window`,
		 * `ai_adaptive.anomaly_recovery_days`, and
		 * `ai_adaptive.deploy_notes` keys to stored settings that predate
		 * them. Runs on `admin_init` (not the constructor) so a cacheable
		 * front-end request never triggers a settings write. Only installs
		 * missing a key are backfilled; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match.
		 * Idempotent (key presence is the marker). Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @since 2.3.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_ai_anomaly_v2() (ARCH-004).
		 * @return void
		 */
		public function migrate_ai_anomaly_v2(): void {
			$memo = $this->read_options();
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return;
				}
				if ( isset( $memo['ai_adaptive'] ) && is_array( $memo['ai_adaptive'] )
					&& array_key_exists( 'anomaly_band_window', $memo['ai_adaptive'] )
					&& array_key_exists( 'anomaly_recovery_days', $memo['ai_adaptive'] )
					&& array_key_exists( 'deploy_notes', $memo['ai_adaptive'] ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}

				$ai = isset( $stored['ai_adaptive'] ) && is_array( $stored['ai_adaptive'] ) ? $stored['ai_adaptive'] : array();

				$changed = false;
				if ( ! array_key_exists( 'anomaly_band_window', $ai ) ) {
					$ai['anomaly_band_window'] = 10;
					$changed                   = true;
				}
				if ( ! array_key_exists( 'anomaly_recovery_days', $ai ) ) {
					$ai['anomaly_recovery_days'] = 3;
					$changed                     = true;
				}
				if ( ! array_key_exists( 'deploy_notes', $ai ) ) {
					$ai['deploy_notes'] = array();
					$changed            = true;
				}
				if ( ! $changed ) {
					return;
				}

				$stored['ai_adaptive'] = $ai;
				Util::save_settings( $stored );
				$this->invalidate_options( $stored );

				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
				Log::add( __( 'Added default anomaly detector v2 settings (band window, recovery days, deploy notes).', 'performance-optimisation' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * One-time backfill for comment-image hardening (issue #1271).
		 *
		 * Adds `image_optimisation.hardenCommentImages = true` to stored
		 * settings that predate the key so hostile comment markup (img
		 * onerror, picture source, inline on* handlers, scriptable URLs)
		 * is stripped before next-gen/lazy rewriting. Runs on `admin_init`
		 * (not the constructor) so a cacheable front-end request never
		 * triggers a settings write. Fresh installs with no stored option
		 * are skipped (absent key reads as enabled via the in-memory
		 * default). Idempotent (key presence is the marker). Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_comment_image_hardening() (ARCH-004).
		 * @return void
		 */
		public function migrate_comment_image_hardening(): void {
			$memo = $this->read_options();
			try {
				// Capability-gated: the migration performs a settings
				// write plus full local + edge cache purges, so it must
				// only run for administrators (first admin_init by an
				// editor must not trigger it).
				if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
					return;
				}
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// NOTE: no in-memory `$memo` early-return here —
				// the constructor default (`hardenCommentImages => true`)
				// would make such a guard always hit and the DB backfill
				// dead code. The stored option is the only marker. A memo
				// guard would also skip the first-migration cache purges
				// below, leaving poisoned edge copies in place.
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}
				$image = isset( $stored['image_optimisation'] ) && is_array( $stored['image_optimisation'] ) ? $stored['image_optimisation'] : array();

				if ( array_key_exists( 'hardenCommentImages', $image ) ) {
					return;
				}

				$stored['image_optimisation'] = $image + array( 'hardenCommentImages' => true );
				Util::save_settings( $stored );
				$this->invalidate_options( $stored );

				// Keep the in-request settings memo in parity (sibling
				// migration paths call set_settings_cache(); without it a
				// get_settings() memo loaded earlier in this admin_init
				// request would stay pre-migration).
				try {
					if ( class_exists( 'PerformanceOptimise\\Inc\\Util' ) ) {
						\PerformanceOptimise\Inc\Util::set_settings_cache( $stored );
					}
				} catch ( \Throwable $cache_error ) {
					unset( $cache_error );
				}

				// First migration only: purge the static HTML cache so
				// pages poisoned before hardening (served verbatim by
				// advanced-cache.php) are regenerated sanitized. Fan out
				// to edge caches (Cloudflare/Bunny/Varnish) so poisoned
				// edge copies do not survive the local purge.
				try {
					if ( class_exists( 'PerformanceOptimise\\Inc\\Cache' ) ) {
						\PerformanceOptimise\Inc\Cache::clear_cache();
					}
				} catch ( \Throwable $purge_error ) {
					unset( $purge_error );
				}
				try {
					if ( class_exists( 'PerformanceOptimise\\Inc\\Edge_Purger' ) ) {
						\PerformanceOptimise\Inc\Edge_Purger::purge_all();
					}
				} catch ( \Throwable $edge_error ) {
					unset( $edge_error );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Backfill the additive builder purge watcher keys (issue #1288).
		 *
		 * Adds `file_optimisation.builderPurgeWatcher = true` and
		 * `file_optimisation.builderPurgeDriftLog = true` to stored settings
		 * that predate the keys. Runs on `admin_init` (not the constructor)
		 * so a cacheable front-end request never triggers a settings write.
		 * Fresh installs with no stored option are skipped (absent keys read
		 * as enabled). Idempotent (key presence is the marker). Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_builder_watcher() (ARCH-004).
		 * @return void
		 */
		public function migrate_builder_watcher(): void {
			$memo = $this->read_options();
			try {
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				// Gate on the persisted row (not the in-memory backfill) so the
				// migration branch stays reachable and single-key rows heal.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}
				$file_opts = $stored['file_optimisation'] ?? null;
				if ( is_array( $file_opts ) && array_key_exists( 'builderPurgeWatcher', $file_opts ) && array_key_exists( 'builderPurgeDriftLog', $file_opts ) ) {
					return;
				}

				$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

				$changed = false;
				if ( ! array_key_exists( 'builderPurgeWatcher', $file ) ) {
					$file['builderPurgeWatcher'] = true;
					$changed                     = true;
				}
				if ( ! array_key_exists( 'builderPurgeDriftLog', $file ) ) {
					$file['builderPurgeDriftLog'] = true;
					$changed                      = true;
				}

				if ( ! $changed ) {
					return;
				}

				$stored['file_optimisation'] = $file;
				$updated                     = Util::save_settings( $stored );
				if ( ! $updated ) {
					// save_settings() mirrors update_option(): false means the
					// write failed OR the value was already identical (e.g. a
					// concurrent request persisted the same backfill first).
					// Re-read the row: when it already holds the migrated keys,
					// still sync the in-request memos so this request does not
					// observe stale pre-migration values. On a genuine failure
					// the row still lacks the keys and the memos stay untouched.
					// allowlist(settings-read-guard): verification re-read
					// distinguishing a concurrent backfill (keys present) from a
					// write failure (keys absent).
					$refreshed = get_option( 'wppo_settings' );
					$re_file   = is_array( $refreshed ) && isset( $refreshed['file_optimisation'] ) && is_array( $refreshed['file_optimisation'] ) ? $refreshed['file_optimisation'] : array();
					if ( is_array( $refreshed ) && array_key_exists( 'builderPurgeWatcher', $re_file ) && array_key_exists( 'builderPurgeDriftLog', $re_file ) ) {
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
							Util::set_settings_cache( $refreshed );
						}
						$this->invalidate_options( $refreshed );
					}
					return;
				}

				$this->invalidate_options( $stored );
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Backfill the additive auto third-party delay key (issue #1314).
		 *
		 * Runs on admin_init; in-memory default is applied in __construct so
		 * front-end requests never pay for a DB write. Defaults to off so
		 * upgraded installs keep current behaviour. Also backfills the
		 * additive one-click preset level key (issue #1385, defaults to
		 * safe). Multisite-safe: per-site get_option() so sites migrate
		 * independently. Fail-open: never fatals.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated verbatim from Main::maybe_migrate_third_party_auto() (ARCH-004).
		 * @return void
		 */
		public function migrate_third_party_auto(): void {
			$memo = $this->read_options();
			try {
				// Capability-gated like sibling migrations: the migration
				// performs a settings write, so it must only run for
				// administrators (first admin_init by an editor must not
				// trigger the DB-write path).
				if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
					return;
				}
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				// Gate on the persisted row (not the in-memory backfill) so the
				// migration branch stays reachable and single-key rows heal.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}
				$file_opts = $stored['file_optimisation'] ?? null;
				$auto_ok   = is_array( $file_opts ) && isset( $file_opts['delayJSThirdPartyAuto'] ) && is_bool( $file_opts['delayJSThirdPartyAuto'] );
				$preset_ok = is_array( $file_opts ) && isset( $file_opts['delayJSPreset'] ) && is_string( $file_opts['delayJSPreset'] ) && in_array( strtolower( trim( $file_opts['delayJSPreset'] ) ), array( 'safe', 'balanced', 'aggressive' ), true );
				if ( $auto_ok && $preset_ok ) {
					return;
				}

				$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

				if ( ! $auto_ok ) {
					$file['delayJSThirdPartyAuto'] = false;
				}
				if ( ! $preset_ok ) {
					$file['delayJSPreset'] = 'safe';
				}

				$stored['file_optimisation'] = $file;
				$updated                     = Util::save_settings( $stored );
				if ( ! $updated ) {
					// save_settings() mirrors update_option(): false means the
					// write failed OR the value was already identical (e.g. a
					// concurrent request persisted the same backfill first).
					// Re-read the row: when it already holds valid migrated
					// values, still sync the in-request memos so this request
					// does not observe stale pre-migration values. On a
					// genuine failure the row still lacks valid values and the
					// memos stay untouched.
					// allowlist(settings-read-guard): verification re-read
					// distinguishing a concurrent backfill (values present)
					// from a write failure (values absent).
					$refreshed = get_option( 'wppo_settings' );
					$re_file   = is_array( $refreshed ) && isset( $refreshed['file_optimisation'] ) && is_array( $refreshed['file_optimisation'] ) ? $refreshed['file_optimisation'] : array();
					$re_auto   = is_array( $re_file ) && isset( $re_file['delayJSThirdPartyAuto'] ) && is_bool( $re_file['delayJSThirdPartyAuto'] );
					$re_preset = is_array( $re_file ) && isset( $re_file['delayJSPreset'] ) && is_string( $re_file['delayJSPreset'] ) && in_array( strtolower( trim( $re_file['delayJSPreset'] ) ), array( 'safe', 'balanced', 'aggressive' ), true );
					if ( is_array( $refreshed ) && $re_auto && $re_preset ) {
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
							Util::set_settings_cache( $refreshed );
						}
						$this->invalidate_options( $refreshed );
					}
					return;
				}

				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
				$this->invalidate_options( $stored );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
