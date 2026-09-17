<?php
/**
 * Builder-purge watcher — purge stale page-builder CSS on builder updates.
 *
 * Elementor/Divi/Bricks/WPBakery updates can rename or delete generated CSS
 * files (e.g. Elementor post-css in uploads/elementor/css/) while WPPO's
 * static HTML cache still references them, producing 404 stylesheets and
 * broken layouts. This watcher hooks upgrader_process_complete, gated to
 * known builder plugin/theme slugs via a data-driven, filter-extensible map,
 * and purges the affected builder cache directories plus WPPO's derived
 * caches (page cache, used-CSS, critical-CSS).
 *
 * No settings UI and no REST route: the watcher is always on and self-gates
 * to builder updates only (early return otherwise, including WPPO's own
 * update path, whose plugin slug is not in the map).
 *
 * @package PerformanceOptimise\Inc
 * @since   2.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) ) {
	/**
	 * Purge builder + WPPO caches after a page-builder update.
	 *
	 * @since 2.0.0
	 */
	class Builder_Purge_Watcher {

		/**
		 * Transient key for the one-time admin notice after a purge.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const NOTICE_TRANSIENT = 'wppo_builder_purge_notice';

		/**
		 * Hook + group for the deferred builder-drift purge.
		 *
		 * The post-less `elementor/core/files/clear_cache` signal is fired
		 * inside ordinary editor/front-end requests, where the heavy
		 * full-site purge (page cache + all used-CSS + queued regeneration)
		 * must not run synchronously (issue #1023 follow-up, audit #6). The
		 * signal only schedules this single background event; the callback
		 * runs the heavy path later.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const DRIFT_PURGE_HOOK = 'wppo_builder_drift_purge';

		/**
		 * Transient key for the drift-purge enqueue lock.
		 *
		 * Prevents a burst of builder drift signals (or two concurrent
		 * requests) from enqueueing duplicate background purges. Keyed per
		 * site via Util::transient_key() for multisite safety.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const DRIFT_PURGE_LOCK = 'wppo_builder_drift_purge_lock';

		/**
		 * Known page builders, keyed by builder slug.
		 *
		 * Each entry holds a human label, plugin-file slugs, theme directory
		 * slugs (Divi/Bricks ship as themes), regenerable cache directories
		 * relative to the uploads basedir (upload_subdirs) or to
		 * WP_CONTENT_DIR (content_subdirs, e.g. Divi's et-cache), and
		 * builder-native regeneration actions fired best-effort when a
		 * listener is registered. Entries whose directory layout is not
		 * documented as fully regenerable set css_only so only *.css files
		 * (never the whole directory tree) are removed.
		 *
		 * Slugs verified against wordpress.org / vendor distributions at
		 * implementation time (issue #907); hosts and themes can extend or
		 * correct the map via the wppo_builder_purge_map filter.
		 *
		 * @since 2.0.0
		 * @var array<string,array{label:string,plugins:string[],themes:string[],upload_subdirs:string[],content_subdirs:string[],clear_hooks:string[],css_only?:bool}>
		 */
		private const BUILDER_MAP = array(
			'elementor' => array(
				'label'           => 'Elementor',
				'plugins'         => array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' ),
				'themes'          => array(),
				'upload_subdirs'  => array( 'elementor/css' ),
				'content_subdirs' => array(),
				'clear_hooks'     => array( 'elementor/core/files/clear_cache' ),
			),
			'divi'      => array(
				'label'           => 'Divi',
				'plugins'         => array( 'divi-builder/divi-builder.php' ),
				'themes'          => array( 'Divi' ),
				'upload_subdirs'  => array(),
				'content_subdirs' => array( 'et-cache' ),
				'clear_hooks'     => array( 'et_core_cache_clear' ),
			),
			'bricks'    => array(
				'label'           => 'Bricks',
				'plugins'         => array(),
				'themes'          => array( 'bricks' ),
				'upload_subdirs'  => array( 'bricks' ),
				'content_subdirs' => array(),
				'clear_hooks'     => array(),
				'css_only'        => true,
			),
			'wpbakery'  => array(
				'label'           => 'WPBakery',
				'plugins'         => array( 'js_composer/js_composer.php' ),
				'themes'          => array(),
				'upload_subdirs'  => array( 'js_composer' ),
				'content_subdirs' => array(),
				'clear_hooks'     => array(),
				'css_only'        => true,
			),
		);

		/**
		 * Option key for the last derived-cache purge reason (issue #1276).
		 *
		 * Per-site via get_option()/update_option() (multisite-safe),
		 * non-autoloaded. Surfaced to the SPA so admins can see why the
		 * last auto-purge ran after a plugin/theme/core update.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const LAST_PURGE_OPTION = 'wppo_last_purge';

		/**
		 * Hook + group for the deferred generic-upgrade purge (issue #1276).
		 *
		 * The generic upgrader path defers the heavy derived-cache wipe so
		 * routine auto-updates never block the upgrader on file I/O.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const UPGRADE_PURGE_HOOK = 'wppo_upgrade_purge';

		/**
		 * Transient key for the cross-request upgrade-purge cooldown.
		 *
		 * N separate auto-update/cron requests for N plugins must not each
		 * run a full wipe plus a regenerate_all fan-out; the short cooldown
		 * mirrors the drift-path lock. Keyed per site via
		 * Util::transient_key() for multisite safety.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const UPGRADE_PURGE_LOCK = 'wppo_upgrade_purge_lock';

		/**
		 * Per-request dedupe keyed by upgrade-payload hash (issue #1276).
		 *
		 * A plain boolean would suppress ALL later
		 * upgrader_process_complete firings in the same PHP process, so
		 * WP-CLI bulk updates, cron/auto-update workers, and multisite
		 * network upgrades (elementor then akismet) would purge once and
		 * skip the second firing — leaving stale HTML/used-CSS. Keying by
		 * payload hash purges once per distinct payload while still
		 * collapsing the double-observe (builder at 10 + generic at 20)
		 * of the same firing.
		 *
		 * @since NEXT
		 * @var array<string,true>
		 */
		private static array $upgrade_purged_hashes = array();

		/**
		 * Per-request cache of the resolved builder map keyed by payload hash.
		 *
		 * Both upgrader handlers (builder at 10, generic at 20) observe the
		 * same firing; resolving the map once per payload keeps routing
		 * consistent even if the wppo_builder_purge_map filter is
		 * non-deterministic between calls.
		 *
		 * @since NEXT
		 * @var array<string,array>
		 */
		private static array $resolved_builder_map_cache = array();

		/**
		 * Re-entrancy guard for the drift fan-out (issue #1023).
		 *
		 * The regeneration fan-out fires elementor/core/files/clear_cache,
		 * which this watcher also listens to via on_builder_drift(). The flag is
		 * set around the fan-out do_action() so the upgrader path cannot re-enter
		 * on_builder_drift() and purge/queue twice.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $drift_suspended = false;

		/**
		 * Per-request dedupe for the drift signal (issue #1023).
		 *
		 * Elementor/core/files/clear_cache can fire several times per request
		 * during editing; the full derived-cache purge below runs at most once
		 * per request so a hot signal cannot stampede the cache.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $drift_handled_this_request = false;

		/**
		 * Per-request set of already-purged Elementor post IDs (issue #1259).
		 *
		 * Bulk Elementor regen (global style change, import) fires
		 * elementor/css-file/post/parse_after N times, and an editor save
		 * typically fires both elementor/editor/after_save and parse_after
		 * for the same post. Each purge costs a Cache + settings read,
		 * get_permalink, filesystem deletes, a Used-CSS requeue, and
		 * stats-bump writes — so both handlers share this set and skip a
		 * post already purged this request.
		 *
		 * @since NEXT
		 * @var array<int,bool>
		 */
		private static array $elementor_purged = array();

		/**
		 * Shared Cache instance for per-post purges (issue #1259).
		 *
		 * Bulk Elementor regen fires N times per request; reusing one
		 * instance avoids N settings reads + N Cache constructions.
		 * Reset by reset_elementor_purge_memo() for test isolation.
		 *
		 * @since NEXT
		 * @var mixed|null
		 */
		private static $shared_purge_cache = null;

		/**
		 * Whether the bulk-regen archive fan-out was already scheduled.
		 *
		 * Set once per request when the purged set grows past the bulk
		 * threshold so N distinct posts schedule exactly one deferred
		 * full purge (archive/home coverage) and one targeted Used-CSS
		 * regen instead of N per-post scheduler writes.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $bulk_regen_coalesced = false;

		/**
		 * Distinct-post threshold for bulk-regen coalescing (issue #1259).
		 *
		 * At or below this many distinct posts the per-post fast path
		 * (single-URL purge + Used_CSS::requeue_for_post()) applies;
		 * beyond it the deferred full purge + targeted regen own the
		 * remaining work (archive fan-out + scheduler stampede guard).
		 *
		 * @since NEXT
		 * @var int
		 */
		private const BULK_REGEN_THRESHOLD = 5;

		/**
		 * Reset the Elementor per-request purge set (for tests).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_elementor_purge_memo(): void {
			self::$elementor_purged     = array();
			self::$shared_purge_cache   = null;
			self::$bulk_regen_coalesced = false;
		}

		/**
		 * Reset the drift-signal per-request state (for tests).
		 *
		 * Named reset so tests never reach into private statics via
		 * reflection to clear the drift dedupe flag.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_drift_state(): void {
			self::$drift_handled_this_request = false;
		}

		/**
		 * Whether the builder purge watcher is enabled (issue #1288).
		 *
		 * Additive `file_optimisation.builderPurgeWatcher` key, default on
		 * (current behaviour). Fail-open: any read failure keeps the watcher
		 * enabled so stale builder CSS still self-heals.
		 *
		 * @since NEXT
		 * @return bool True when the watcher may run.
		 */
		public static function is_watcher_enabled(): bool {
			return self::is_file_flag_enabled( 'builderPurgeWatcher' );
		}

		/**
		 * Whether drift-path activity-log entries are enabled (issue #1288).
		 *
		 * Additive `file_optimisation.builderPurgeDriftLog` key, default on so
		 * the "activity log records it" criterion passes on fresh installs.
		 * Fail-open to logging.
		 *
		 * @since NEXT
		 * @return bool True when drift purges should write a log entry.
		 */
		public static function is_drift_log_enabled(): bool {
			return self::is_file_flag_enabled( 'builderPurgeDriftLog' );
		}

		/**
		 * Read an additive file_optimisation flag, fail-open to true (issue #1288).
		 *
		 * Single helper behind is_watcher_enabled() / is_drift_log_enabled()
		 * so the fail-open shape (class/method guards, is_array guard, key
		 * presence check, bool cast) cannot drift between the two callers.
		 *
		 * @since NEXT
		 * @param string $key Flag key inside file_optimisation.
		 * @return bool True when the flag is enabled or unreadable.
		 */
		private static function is_file_flag_enabled( string $key ): bool {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					return true;
				}
				$settings = Util::get_settings();
				if ( ! is_array( $settings ) ) {
					return true;
				}
				$file = $settings['file_optimisation'] ?? null;
				if ( ! is_array( $file ) || ! array_key_exists( $key, $file ) ) {
					return true;
				}
				return (bool) $file[ $key ];
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Register the upgrader hook plus builder-drift hooks.
		 *
		 * Drift hooks (issue #1023) listen to Elementor asset-regen signals
		 * so editing in a builder requeues used-CSS regeneration instead of
		 * leaving stale used CSS until manual regen/cron.
		 *
		 * Hardened (issue #1288): no-ops when the WP hook API is unavailable
		 * (very old core / bare unit bootstrap) and when the watcher is
		 * disabled via settings. Each registration is individually guarded so
		 * one failing hook can never break the rest; fail-open keeps full CSS
		 * serving.
		 *
		 * @since 2.0.0
		 * @since NEXT Guarded behind function_exists + watcher setting.
		 * @return void
		 */
		public function register(): void {
			if ( ! function_exists( 'add_action' ) ) {
				return;
			}
			if ( ! self::is_watcher_enabled() ) {
				return;
			}
			// Elementor-safe mode (issue #1259): per-post CSS-regen signal.
			// Fired by Elementor after a post CSS file is (re)generated; the
			// callback purges that post's static HTML cache so stale HTML
			// never points at renamed/deleted post-*.css. Registered
			// unconditionally — WP tolerates unknown hooks, and the callback
			// is fully guarded so non-Elementor sites pay nothing. Two
			// accepted args so the resolvable post ID is not dropped when
			// Elementor passes ($css_file, $post_id).
			$hooks = array(
				array( 'upgrader_process_complete', array( $this, 'on_builder_update' ), 10, 2 ),
				array( 'upgrader_process_complete', array( $this, 'on_any_upgrade' ), 20, 2 ),
				array( 'elementor/core/files/clear_cache', array( $this, 'on_builder_drift' ), 10, 0 ),
				array( 'elementor/editor/after_save', array( $this, 'on_builder_drift_save' ), 10, 2 ),
				array( 'elementor/css-file/post/parse_after', array( $this, 'on_elementor_css_regen' ), 10, 2 ),
				array( self::DRIFT_PURGE_HOOK, array( $this, 'run_deferred_drift_purge' ), 10, 0 ),
				array( self::UPGRADE_PURGE_HOOK, array( $this, 'run_deferred_upgrade_purge' ), 10, 1 ),
			);
			foreach ( $hooks as $spec ) {
				try {
					add_action( $spec[0], $spec[1], $spec[2], $spec[3] );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		/**
		 * Handle Elementor asset-regen signals: defer the derived-cache purge.
		 *
		 * Fires on elementor/core/files/clear_cache (CSS regen). The signal
		 * carries no post context, so the only safe action is a full-site
		 * purge — but this signal can fire inside an ordinary editor /
		 * front-end request, where the heavy path must not run inline.
		 * Instead this schedules a single background purge
		 * (DRIFT_PURGE_HOOK) and returns immediately:
		 *
		 *  - Action Scheduler is used when available (the plugin bundles it),
		 *    deduped via as_has_scheduled_action().
		 *  - Otherwise `wp_schedule_single_event()` is used, deduped via
		 *    `wp_next_scheduled()`.
		 *
		 * A short-lived transient lock throttles the enqueue across
		 * concurrent requests, and a per-request flag collapses repeated
		 * signals in one request. Skipped while the upgrader fan-out is
		 * firing (re-entrancy guard) — the upgrader path already purges via
		 * purge_for_builders(). Guarded so a builder failure can never break
		 * the request; fail-open keeps full CSS serving. Post-scoped drift is
		 * handled by on_builder_drift_save() (the fast path).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function on_builder_drift(): void {
			if ( ! self::is_watcher_enabled() ) {
				return;
			}
			if ( self::$drift_suspended || self::$drift_handled_this_request ) {
				return;
			}
			if ( function_exists( 'doing_action' ) && doing_action( 'upgrader_process_complete' ) ) {
				return;
			}
			self::$drift_handled_this_request = true;
			try {
				if ( ! $this->schedule_deferred_drift_purge() ) {
					// A transient lock-hit stays retryable later in the same
					// request (issue #1288): the lock may clear and the heal
					// must retry. Every other schedule failure latches the
					// per-request dedupe flag since retrying is futile
					// (no scheduler available / already pending).
					if ( $this->is_drift_purge_locked() ) {
						self::$drift_handled_this_request = false;
					}
					return;
				}
				if ( function_exists( 'do_action' ) ) {
					/**
					 * Fires after builder-drift requeue (issue #1023).
					 *
					 * @since 2.0.0
					 */
					do_action( 'wppo_builder_drift_requeue' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Schedule the heavy drift purge as a single background event.
		 *
		 * De-duplicates with a short-lived transient lock plus the scheduler's
		 * own pending check so repeated signals cannot enqueue duplicates.
		 * Fail-open: any error reports false and the derived caches simply
		 * stay as they are (full CSS keeps serving).
		 *
		 * @since 2.0.0
		 * @return bool True when an event was enqueued or already pending.
		 */
		protected function schedule_deferred_drift_purge(): bool {
			try {
				if ( function_exists( 'get_transient' ) && get_transient( Util::transient_key( self::DRIFT_PURGE_LOCK ) ) ) {
					return false;
				}

				$ttl       = defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS + 30 : 90;
				$scheduled = false;
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::DRIFT_PURGE_HOOK, array(), 'performance_optimisation' ) ) {
						return false;
					}
					as_enqueue_async_action( self::DRIFT_PURGE_HOOK, array(), 'performance_optimisation' );
					$scheduled = true;
				} elseif ( function_exists( 'wp_schedule_single_event' ) ) {
					if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::DRIFT_PURGE_HOOK ) ) {
						return false;
					}
					wp_schedule_single_event( time() + ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ), self::DRIFT_PURGE_HOOK );
					$scheduled = true;
				}

				if ( $scheduled && function_exists( 'set_transient' ) ) {
					set_transient( Util::transient_key( self::DRIFT_PURGE_LOCK ), 1, $ttl );
				}

				return $scheduled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the drift-purge transient lock is currently held (issue #1288).
		 *
		 * A held lock means another request is handling the purge, so a
		 * failed schedule stays retryable later in the same request once
		 * the lock clears. Fail-open: any error reports unlocked.
		 *
		 * @since NEXT
		 * @return bool True when the drift-purge lock transient exists.
		 */
		protected function is_drift_purge_locked(): bool {
			try {
				return function_exists( 'get_transient' ) && (bool) get_transient( Util::transient_key( self::DRIFT_PURGE_LOCK ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Background callback: run the heavy derived-cache purge.
		 *
		 * Registered on DRIFT_PURGE_HOOK and executed by Action Scheduler or
		 * WP-Cron, never inline in the originating request. Guarded and
		 * fail-open like the other purge seams.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function run_deferred_drift_purge(): void {
			try {
				// No watcher gate here by design (issue #1288): disable stops
				// future scheduling, it must not abandon an already-queued heal.
				// Release the dedupe lock so a second genuine drift 1-5 min
				// later is not swallowed cross-request; the lock only needs to
				// cover the schedule delay.
				try {
					if ( function_exists( 'delete_transient' ) ) {
						delete_transient( Util::transient_key( self::DRIFT_PURGE_LOCK ) );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$purged = $this->purge_wppo_derived_caches();
				if ( $purged ) {
					$this->write_drift_purge_log( true );
					$this->store_admin_notice( array( 'Elementor' ) );
				} else {
					$this->write_drift_purge_log( false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Handle Elementor editor saves: requeue the saved post's used CSS.
		 *
		 * An explicit editor save requeues via Used_CSS::requeue_for_post():
		 * the save bumps the modification time, so the post-modification
		 * freshness check (issue #1107) still requeues genuine changes
		 * while repeat signals without changes are skipped (returns false,
		 * no action fired). Global source-CSS drift with no post edit is
		 * owned by the deferred full purge (on_builder_drift() →
		 * DRIFT_PURGE_HOOK): cached archives served by advanced-cache.php
		 * never boot WordPress, so they cannot heal lazily on HITs.
		 * Missing variants fail open to queueing. This is the post-scoped fast path.
		 * The post-less elementor/core/files/clear_cache signal handled by
		 * on_builder_drift() has no post context and schedules a background
		 * full-site purge instead. The wppo_builder_drift_requeue action fires
		 * only when a job was actually queued or already scheduled, never
		 * when the variant was skipped as fresh.
		 *
		 * @since 2.0.0
		 * @param int   $post_id Post ID saved in the editor.
		 * @param mixed $editor_data Editor data (unused).
		 * @return void
		 */
		public function on_builder_drift_save( $post_id, $editor_data ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match the elementor/editor/after_save action.
			unset( $editor_data );
			try {
				// Shared coercion (not a blind cast): floats and
				// float-like strings resolve to 0 instead of truncating
				// to a wrong post, matching resolve_elementor_post_id().
				$post_id = $this->coerce_post_id( $post_id );
				if ( $post_id <= 0 ) {
					return;
				}
				if ( ! self::is_watcher_enabled() ) {
					return;
				}
				// Elementor-safe mode (issue #1259): an editor save renames or
				// deletes uploads/elementor/css/post-*.css, so purge this
				// post's static HTML cache alongside the Used-CSS requeue.
				// Purge failures degrade to uncached dynamic, never stale-broken.
				// Shares the per-request dedupe set with on_elementor_css_regen():
				// an editor save typically fires both signals for the same post.
				// Dedupe is marked AFTER the purge attempt so a failed purge is
				// retried by the second signal instead of skipped (purge is
				// fail-open; marking before would poison the retry).
				if ( isset( self::$elementor_purged[ $post_id ] ) ) {
					return;
				}
				if ( self::$bulk_regen_coalesced ) {
					// A deferred full purge is already scheduled and owns
					// the remaining work (archive fan-out + background
					// targeted regen): record dedupe and skip per-post file
					// I/O for posts past the coalescing threshold.
					self::$elementor_purged[ $post_id ] = true;
					return;
				}
				// URL-scoped coupled purge first (issue #1288): drop the stale
				// static HTML + combined-CSS + used-CSS sidecars for the
				// affected URL only, so the next anonymous hit rebuilds clean
				// instead of serving a 404 stylesheet. Runs even when the
				// requeue below skips as fresh — freshness skips must never
				// leave stale combined artifacts behind.
				$purged = $this->purge_post_url_caches( $post_id );
				$this->purge_post_static_cache( $post_id );
				self::$elementor_purged[ $post_id ] = true;
				$this->maybe_coalesce_bulk_regen();
				$queued = false;
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
					$queued = $this->requeue_used_css_for_elementor_post( $post_id );
				}
				if ( ! $purged && ! $queued ) {
					return;
				}
				$this->write_drift_save_log( $post_id, $purged, $queued );
				if ( $purged ) {
					$this->store_admin_notice( array( 'Elementor' ) );
				}
				if ( $queued && function_exists( 'do_action' ) ) {
					/**
					 * Fires after builder-drift requeue for a saved post (issue #1023).
					 *
					 * @since 2.0.0
					 *
					 * @param int $post_id Post ID saved in the builder.
					 */
					do_action( 'wppo_builder_drift_requeue', $post_id );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Handle Elementor per-post CSS regeneration (issue #1259).
		 *
		 * Fired by Elementor's `elementor/css-file/post/parse_after` action
		 * after a post CSS file is (re)generated. Purges that post's static
		 * HTML cache so stale HTML never references renamed or deleted
		 * `uploads/elementor/css/post-*.css` files (no post-css 404s), and
		 * requeues Used-CSS for the post best-effort. The post ID is resolved
		 * from the CSS-file object (`get_post_id()` when available), from a
		 * plain integer first argument, or from the second action payload
		 * when Elementor passes ($css_file, $post_id); unresolvable payloads
		 * fall back to the deferred full purge (on_builder_drift()) rather
		 * than silently keeping stale HTML that references renamed or
		 * deleted post-*.css. Deduped per request via the shared
		 * $elementor_purged set (bulk regen fires N times; editor saves
		 * typically fire both after_save and parse_after for the same post;
		 * the mark lands after the purge attempt so a failed purge is
		 * retried by the next signal). Past BULK_REGEN_THRESHOLD distinct
		 * posts the deferred full purge + targeted Used-CSS regen take over
		 * (archive fan-out + scheduler stampede guard).
		 * Fully guarded and fail-open: any failure degrades to uncached
		 * dynamic output, never stale-broken pages or fatal errors.
		 *
		 * @since NEXT
		 *
		 * @param mixed $css_file Elementor post CSS-file object or post ID.
		 * @param mixed $post_id  Optional second action payload (post ID) when Elementor passes two args.
		 * @return void
		 */
		public function on_elementor_css_regen( $css_file, $post_id = null ): void {
			try {
				$post_id = $this->resolve_elementor_post_id( $css_file, $post_id );
				if ( $post_id <= 0 ) {
					// Unresolvable payload (e.g. a path/null first arg with
					// no second payload): fail toward the deferred full
					// purge (deduped, background) instead of leaving
					// stale-broken HTML pointing at deleted post-*.css.
					$this->on_builder_drift();
					return;
				}
				if ( isset( self::$elementor_purged[ $post_id ] ) ) {
					return;
				}
				if ( self::$bulk_regen_coalesced ) {
					// Same coalesced fast-skip as on_builder_drift_save():
					// the scheduled deferred full purge owns archives and
					// the background targeted regen owns Used-CSS.
					self::$elementor_purged[ $post_id ] = true;
					return;
				}
				// Dedupe is marked AFTER the purge attempt so a failed purge
				// is retried by a later signal for the same post.
				$this->purge_post_static_cache( $post_id );
				self::$elementor_purged[ $post_id ] = true;
				$this->maybe_coalesce_bulk_regen();
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
					$this->requeue_used_css_for_elementor_post( $post_id );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Resolve an Elementor CSS-regen payload to a post ID (issue #1259).
		 *
		 * Accepts the CSS-file object (via `get_post_id()`), an int, or a
		 * digit-only numeric string for either payload. Floats and
		 * float-like strings ('12.9', 12.9) are rejected: a blind (int) cast
		 * would truncate them and purge the wrong post's URL.
		 *
		 * @since NEXT
		 *
		 * @param mixed $css_file CSS-file object or post ID.
		 * @param mixed $post_id  Optional second action payload (post ID fallback).
		 * @return int Post ID, or 0 when unresolvable.
		 */
		protected function resolve_elementor_post_id( $css_file, $post_id = null ): int {
			try {
				if ( is_object( $css_file ) && method_exists( $css_file, 'get_post_id' ) ) {
					// Foreign method call: guarded on its own since only
					// this invocation can throw; a throw falls through to
					// the second-payload fallback below.
					try {
						$id = $this->coerce_post_id( $css_file->get_post_id() );
					} catch ( \Throwable $e ) {
						unset( $e );
						$id = 0;
					}
					if ( $id > 0 ) {
						return $id;
					}
				} else {
					$id = $this->coerce_post_id( $css_file );
					if ( $id > 0 ) {
						return $id;
					}
				}
				$id = $this->coerce_post_id( $post_id );
				return $id > 0 ? $id : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Coerce a scalar payload to a post ID (issue #1259).
		 *
		 * Only ints and digit-only strings (after trim) are accepted; floats,
		 * float-like strings, bools, arrays, and objects without get_post_id()
		 * resolve to 0 so a truncated cast can never purge the wrong URL.
		 *
		 * @since NEXT
		 *
		 * @param mixed $value Raw payload.
		 * @return int Post ID, or 0 when not a clean integer payload.
		 */
		private function coerce_post_id( $value ): int {
			if ( is_int( $value ) ) {
				return $value > 0 ? $value : 0;
			}
			if ( is_string( $value ) ) {
				$trimmed = trim( $value );
				if ( '' !== $trimmed && ctype_digit( $trimmed ) ) {
					$id = (int) $trimmed;
					return $id > 0 ? $id : 0;
				}
			}
			return 0;
		}

		/**
		 * Purge a single post's static HTML + derived CSS caches (issue #1259).
		 *
		 * Uses the domain-based single-URL purge path
		 * (`Cache::invalidate_single_static_html()`), which is inherently
		 * multisite-safe. Fail-open: missing Cache class or any error simply
		 * leaves the cache as-is (full CSS keeps serving). One shared Cache
		 * instance is reused across all per-post purges in the request so
		 * bulk regen (N distinct posts) pays one settings read + one
		 * construction instead of N.
		 *
		 * Scope note: only the post permalink's `index.html` plus sidecars
		 * are deleted here — home, post-type/date archives, taxonomy pages,
		 * and translated permalinks embedding the same `post-*.css` URL are
		 * NOT fanned out per post (bounded per-edit cost). Cached
		 * archives served by advanced-cache.php never boot WordPress, so
		 * they cannot heal lazily via process_buffer() on HITs: archive
		 * coverage after bulk regen is owned by the deferred full-purge
		 * path (`elementor/core/files/clear_cache` → DRIFT_PURGE_HOOK),
		 * which maybe_coalesce_bulk_regen() schedules once the purged set
		 * grows past BULK_REGEN_THRESHOLD.
		 *
		 * @since NEXT
		 *
		 * @param int  $post_id Post ID whose cache must be purged.
		 * @param bool $bump_stats Whether to bump dashboard stats. Bulk-regen
		 *                         callers pass false: the deferred full purge
		 *                         bumps once instead of N inline option writes.
		 * @return void
		 */
		protected function purge_post_static_cache( int $post_id, bool $bump_stats = true ): void {
			try {
				if ( $post_id <= 0 ) {
					return;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
					return;
				}
				$cache = self::$shared_purge_cache;
				if ( ! is_object( $cache ) || ! method_exists( $cache, 'invalidate_single_static_html' ) ) {
					$settings = array();
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
						try {
							$settings = (array) Util::get_settings();
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					$cache = new Cache( $settings );
					if ( ! method_exists( $cache, 'invalidate_single_static_html' ) ) {
						return;
					}
					self::$shared_purge_cache = $cache;
				}
				$cache->invalidate_single_static_html( $post_id, $bump_stats );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Requeue Used-CSS for one Elementor post, coalesced for bulk regen (issue #1259).
		 *
		 * Fast path (at or below BULK_REGEN_THRESHOLD distinct posts):
		 * per-post Used_CSS::requeue_for_post(). Beyond the threshold the
		 * deferred full purge (scheduled by maybe_coalesce_bulk_regen())
		 * owns the remaining work — its background callback runs the
		 * targeted regen off the editor request — so per-post calls after
		 * coalescing are skipped instead of stalling the save with a
		 * 40-post query plus up to 20 freshness probes inline.
		 *
		 * @since NEXT
		 *
		 * @param int $post_id Post ID whose Used-CSS must be requeued.
		 * @return bool True when a job was queued or already scheduled.
		 */
		protected function requeue_used_css_for_elementor_post( int $post_id ): bool {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
					return false;
				}
				if ( self::$bulk_regen_coalesced ) {
					return true;
				}
				return (bool) Used_CSS::requeue_for_post( $post_id );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Purge page-cache + used-CSS sidecars for a single post URL (issue #1288).
		 *
		 * Couples to `Used_CSS::purge_coupled()` for the affected URL only, so
		 * an Elementor save heals within one tick without a full-site purge.
		 * Legacy fallback: when the coupled seam is unavailable, throws, or
		 * reports an empty (false-false) result, falls back to
		 * `Cache::clear_cache( $path )` (which already clears html +
		 * combined-css + used-css sidecars for one page). Fail-open: any
		 * failure returns false and full CSS keeps serving. The boolean is
		 * the OR of the page-cache/used-CSS stores, so callers must log it
		 * as "derived caches" rather than naming both stores.
		 *
		 * @since NEXT
		 * @param int $post_id Post ID just saved in the builder.
		 * @return bool True when a purge seam ran without throwing.
		 */
		protected function purge_post_url_caches( int $post_id ): bool {
			try {
				$path = $this->resolve_post_url_path( $post_id );
				if ( '' === $path ) {
					return false;
				}
				// Coupled seam first; on throw or an empty (false-false)
				// result fall through to the legacy Cache seam instead of
				// returning early so the documented fallback always runs.
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Used_CSS', 'purge_coupled' ) ) {
					try {
						$result = Used_CSS::purge_coupled( $path );
					} catch ( \Throwable $e ) {
						unset( $e );
						$result = array();
					}
					if ( ! empty( $result['page_cache'] ) || ! empty( $result['used_css'] ) ) {
						return true;
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'clear_cache' ) ) {
					return (bool) Cache::clear_cache( $path );
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolve a post ID to its cache URL path (issue #1288).
		 *
		 * Fail-open: returns '' when the permalink API is unavailable or the
		 * URL cannot be parsed, in which case callers skip the scoped purge.
		 *
		 * @since NEXT
		 * @param int $post_id Post ID.
		 * @return string URL path (e.g. '/my-page/') or '' when unresolvable.
		 */
		protected function resolve_post_url_path( int $post_id ): string {
			try {
				if ( ! function_exists( 'get_permalink' ) ) {
					return '';
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'memoized_permalink' ) ) {
					$url = Util::memoized_permalink( $post_id );
				} else {
					$url = get_permalink( $post_id );
				}
				if ( ! is_string( $url ) || '' === $url ) {
					return '';
				}
				if ( function_exists( 'wp_parse_url' ) ) {
					$parts = wp_parse_url( $url );
				} else {
					$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				}
				if ( ! is_array( $parts ) ) {
					return '';
				}
				// Reject query-based plain permalinks (?p=, ?page_id=,
				// ?attachment_id=) regardless of path so a subdir install
				// (/subdir/?p=8) never purges the subdir homepage instead.
				if ( ! empty( $parts['query'] ) && is_string( $parts['query'] ) && 1 === preg_match( '/(^|&)(p|page_id|attachment_id)=\d+/i', $parts['query'] ) ) {
					return '';
				}
				// Homepage '/' is explicitly allowed through: Cache::clear_cache()
				// resolves it to the homepage file, so an Elementor save on the
				// static front page still heals within one tick.
				if ( empty( $parts['path'] ) || ! is_string( $parts['path'] ) ) {
					return '';
				}
				return $parts['path'];
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Schedule the deferred full purge once bulk regen is detected (issue #1259).
		 *
		 * Per-post purges above cover single-post permalinks only; cached
		 * home/archives served by advanced-cache.php never boot WordPress
		 * and would keep referencing renamed `post-*.css` files. When the
		 * purged set grows past BULK_REGEN_THRESHOLD, the post-less
		 * deferred full purge (on_builder_drift() → DRIFT_PURGE_HOOK) is
		 * scheduled once per request to fan out to those archives.
		 * Fail-open: scheduling failures are swallowed.
		 *
		 * @since NEXT
		 * @return void
		 */
		protected function maybe_coalesce_bulk_regen(): void {
			try {
				if ( self::$bulk_regen_coalesced ) {
					return;
				}
				if ( count( self::$elementor_purged ) <= self::BULK_REGEN_THRESHOLD ) {
					return;
				}
				self::$bulk_regen_coalesced = true;
				// Deferred only: on_builder_drift() schedules the background
				// full purge, whose callback (purge_wppo_derived_caches())
				// runs the targeted Used-CSS regen off the editor request.
				// No inline request_targeted_regen() here — it costs a
				// 40-post query plus up to 20 freshness probes synchronously
				// in the save action that tripped the threshold.
				$this->on_builder_drift();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Log a builder-drift purge outcome (issue #1288).
		 *
		 * Gated by the additive `builderPurgeDriftLog` setting (default on).
		 * Fail-open: logging never breaks the purge. `$succeeded` tracks the
		 * page-cache clear only (used-CSS/critical-CSS run best-effort), so
		 * the success message reports the page-cache purge plus a requested
		 * (not guaranteed) used/critical regeneration.
		 *
		 * @since NEXT
		 * @param bool $succeeded Whether the page-cache clear succeeded.
		 * @return void
		 */
		protected function write_drift_purge_log( bool $succeeded = true ): void {
			if ( ! self::is_drift_log_enabled() ) {
				return;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				return;
			}
			try {
				if ( $succeeded ) {
					Log::add(
						__( 'Builder CSS regeneration detected (Elementor): page cache purged and used-CSS/critical-CSS regeneration requested; full CSS keeps serving meanwhile.', 'performance-optimisation' )
					);
				} else {
					Log::add(
						__( 'Builder CSS regeneration detected (Elementor): derived-cache purge attempted but page cache was not cleared; full CSS keeps serving.', 'performance-optimisation' )
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Write the drift-save audit entry for an editor save (issue #1288).
		 *
		 * Gated by the additive `builderPurgeDriftLog` setting (default on).
		 * `$purged` is the OR of the page-cache/used-CSS stores, so the
		 * message reports neutral "derived caches" instead of claiming both
		 * stores when only one healed (partial-heal transparency).
		 *
		 * @since NEXT
		 * @param int  $post_id Post ID saved in the builder.
		 * @param bool $purged Whether the URL-scoped purge succeeded.
		 * @param bool $queued Whether regeneration was requeued.
		 * @return void
		 */
		protected function write_drift_save_log( int $post_id, bool $purged = true, bool $queued = true ): void {
			if ( ! self::is_drift_log_enabled() ) {
				return;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				return;
			}
			try {
				if ( $purged && $queued ) {
					$message = sprintf(
						/* translators: %d: post ID saved in the builder */
						__( 'Builder drift detected (post %d): derived caches purged for the affected URL, regeneration requeued.', 'performance-optimisation' ),
						$post_id
					);
				} elseif ( $purged ) {
					$message = sprintf(
						/* translators: %d: post ID saved in the builder */
						__( 'Builder drift detected (post %d): derived caches purged for the affected URL.', 'performance-optimisation' ),
						$post_id
					);
				} elseif ( $queued ) {
					$message = sprintf(
						/* translators: %d: post ID saved in the builder */
						__( 'Builder drift detected (post %d): regeneration requeued for the affected URL.', 'performance-optimisation' ),
						$post_id
					);
				} else {
					return;
				}
				Log::add( $message );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Get the builder map, filterable by hosts and themes.
		 *
		 * @since 2.0.0
		 * @return array<string,array> Builder map keyed by builder slug.
		 */
		public static function get_builder_map(): array {
			$map = self::BUILDER_MAP;
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the builder-update purge map.
				 *
				 * Lets hosts and themes add builders or correct slugs and
				 * cache directories without editing the plugin.
				 *
				 * @since 2.0.0
				 *
				 * @param array<string,array> $map Builder map keyed by builder slug.
				 */
				$filtered = apply_filters( 'wppo_builder_purge_map', $map );
				if ( is_array( $filtered ) ) {
					$map = $filtered;
				}
			}
			return $map;
		}

		/**
		 * Handle upgrader_process_complete: purge when a builder was updated.
		 *
		 * Returns early for non-update actions, non-plugin/theme types, and
		 * updates whose slugs intersect nothing in the builder map (this
		 * keeps the blast radius to builder updates only — WPPO's own update
		 * path included, its slug is not in the map).
		 *
		 * @since 2.0.0
		 * @param mixed $upgrader   Upgrader instance (unused).
		 * @param mixed $hook_extra Update context (action/type/plugin/plugins/theme/themes).
		 * @return void
		 */
		public function on_builder_update( $upgrader, $hook_extra ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match the upgrader_process_complete action.
			unset( $upgrader );
			if ( ! self::is_watcher_enabled() ) {
				return;
			}
			if ( ! is_array( $hook_extra ) ) {
				return;
			}
			if ( 'update' !== ( $hook_extra['action'] ?? '' ) ) {
				return;
			}
			if ( ! in_array( ( $hook_extra['type'] ?? '' ), array( 'plugin', 'theme' ), true ) ) {
				return;
			}

			// Builder path stays unconditional: builder-dir deletion plus
			// regen hooks are not covered by the generic path, so this
			// handler never consults the generic dedupe — it only records
			// its payload hash so on_any_upgrade() (priority 20) skips the
			// same firing.
			list( $updated_plugins, $updated_themes ) = $this->collect_updated_slugs( $hook_extra );

			// Resolve the map once so routing and purging use the same map even
			// if the wppo_builder_purge_map filter is non-deterministic. The
			// hash is computed once (no double encode) and the resolved map is
			// cached for the generic handler observing the same firing.
			$hash                                      = $this->upgrade_payload_hash( $hook_extra );
			$map                                       = self::get_builder_map();
			self::$resolved_builder_map_cache[ $hash ] = $map;
			$matched                                   = $this->match_builders( $updated_plugins, $updated_themes, $map );
			if ( empty( $matched ) ) {
				return;
			}

			$this->purge_for_builders( $matched, $map );
			self::$upgrade_purged_hashes[ $hash ] = true;
		}

		/**
		 * Intersect updated plugin/theme slugs with the builder map.
		 *
		 * Comparisons are case-insensitive (theme directory slugs such as
		 * Divi vary in case across installs).
		 *
		 * @since 2.0.0
		 * @param string[] $plugins Updated plugin files.
		 * @param string[] $themes  Updated theme slugs.
		 * @param array    $map     Builder map (resolved once per update).
		 * @return string[] Matched builder keys.
		 */
		private function match_builders( array $plugins, array $themes, array $map ): array {
			$matched = array();

			$plugins_lc = array_map( 'strtolower', $plugins );
			$themes_lc  = array_map( 'strtolower', $themes );

			foreach ( $map as $key => $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$entry_plugins = array();
				if ( isset( $entry['plugins'] ) && is_array( $entry['plugins'] ) ) {
					$entry_plugins = array_map( 'strtolower', array_filter( $entry['plugins'], 'is_string' ) );
				}
				$entry_themes = array();
				if ( isset( $entry['themes'] ) && is_array( $entry['themes'] ) ) {
					$entry_themes = array_map( 'strtolower', array_filter( $entry['themes'], 'is_string' ) );
				}

				if ( ! empty( array_intersect( $entry_plugins, $plugins_lc ) ) || ! empty( array_intersect( $entry_themes, $themes_lc ) ) ) {
					$matched[] = (string) $key;
				}
			}

			return array_values( array_unique( $matched ) );
		}

		/**
		 * Collect updated plugin/theme slugs from an upgrader payload.
		 *
		 * Shared by on_builder_update() and on_any_upgrade() so the two
		 * handlers cannot drift out of sync.
		 *
		 * @since NEXT
		 * @param mixed $hook_extra Update context.
		 * @return array{0:string[],1:string[]} Tuple of (plugins, themes).
		 */
		protected function collect_updated_slugs( $hook_extra ): array {
			$updated_plugins = array();
			$updated_themes  = array();
			if ( ! is_array( $hook_extra ) ) {
				return array( $updated_plugins, $updated_themes );
			}
			if ( ! empty( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
				$updated_plugins[] = $hook_extra['plugin'];
			}
			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				foreach ( $hook_extra['plugins'] as $plugin_file ) {
					if ( is_string( $plugin_file ) && '' !== $plugin_file ) {
						$updated_plugins[] = $plugin_file;
					}
				}
			}
			if ( ! empty( $hook_extra['theme'] ) && is_string( $hook_extra['theme'] ) ) {
				$updated_themes[] = $hook_extra['theme'];
			}
			if ( ! empty( $hook_extra['themes'] ) && is_array( $hook_extra['themes'] ) ) {
				foreach ( $hook_extra['themes'] as $theme_slug ) {
					if ( is_string( $theme_slug ) && '' !== $theme_slug ) {
						$updated_themes[] = $theme_slug;
					}
				}
			}
			return array( $updated_plugins, $updated_themes );
		}

		/**
		 * Hash an upgrader payload for per-request dedupe.
		 *
		 * @since NEXT
		 * @param mixed $hook_extra Update context.
		 * @return string Payload hash.
		 */
		protected function upgrade_payload_hash( $hook_extra ): string {
			try {
				if ( function_exists( 'wp_json_encode' ) ) {
					$encoded = wp_json_encode( $hook_extra );
					if ( is_string( $encoded ) ) {
						return md5( $encoded );
					}
				}
				return md5( serialize( $hook_extra ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Dedupe-hash fallback only.
			} catch ( \Throwable $e ) {
				unset( $e );
				// Ultimate non-throwing fallback: type + keys only, so an
				// unserializable payload (object/Closure via filter) still
				// dedupes fail-open instead of skipping the purge.
				return md5( gettype( $hook_extra ) . '|' . implode( ',', array_keys( (array) $hook_extra ) ) );
			}
		}

		/**
		 * Check whether this payload already purged this request.
		 *
		 * @since NEXT
		 * @param mixed $hook_extra Update context.
		 * @return bool True when the payload hash was already recorded.
		 */
		protected function is_upgrade_purged( $hook_extra ): bool {
			return isset( self::$upgrade_purged_hashes[ $this->upgrade_payload_hash( $hook_extra ) ] );
		}

		/**
		 * Record a payload hash as purged for this request.
		 *
		 * @since NEXT
		 * @param mixed $hook_extra Update context.
		 * @return void
		 */
		protected function mark_upgrade_purged( $hook_extra ): void {
			self::$upgrade_purged_hashes[ $this->upgrade_payload_hash( $hook_extra ) ] = true;
		}

		/**
		 * Run the purge chain for the matched builders.
		 *
		 * Protected (not private) so unit tests can subclass and observe the
		 * routing without touching the filesystem.
		 *
		 * @since 2.0.0
		 * @param string[] $matched Matched builder keys.
		 * @param array    $map     Builder map (resolved once per update).
		 * @return void
		 */
		protected function purge_for_builders( array $matched, array $map ): void {
			$labels = array();
			foreach ( $matched as $key ) {
				$labels[] = isset( $map[ $key ]['label'] ) && is_string( $map[ $key ]['label'] ) ? $map[ $key ]['label'] : (string) $key;
			}

			$this->purge_builder_directories( $matched, $map );
			$this->fire_builder_regeneration_hooks( $matched, $map );
			$this->purge_wppo_derived_caches();
			$this->bump_combined_asset_versions();
			$this->record_last_purge( sprintf( 'Builder update (%s)', implode( ', ', $labels ) ) );
			$this->write_purge_log( $labels );
			$this->store_admin_notice( $labels );

			/**
			 * Fires after WPPO purges builder + page caches for a builder update.
			 *
			 * @since 2.0.0
			 *
			 * @param string[] $matched Matched builder keys (e.g. array( 'elementor' )).
			 */
			if ( function_exists( 'do_action' ) ) {
				do_action( 'wppo_after_builder_purge', $matched );
			}
		}

		/**
		 * Handle any plugin/theme/core update: auto-purge derived caches (issue #1276).
		 *
		 * Distinct from on_builder_update() (issue #907, builder slugs only):
		 * this is the generic upgrade trigger so a non-builder plugin, theme,
		 * or core update never leaves a first-incognito-hit FOUC from stale
		 * static HTML + stale used/critical CSS + stale combined/minified
		 * files (old `?ver=filemtime` URLs). Builder updates are skipped here
		 * (already handled by on_builder_update() at priority 10); a
		 * per-request payload-hash dedupe collapses the double-observe of
		 * the same firing while still purging distinct payloads (bulk
		 * WP-CLI/cron/multisite upgrades) in one process. Only cheap
		 * routing runs inline — the heavy wipe plus regenerate_all fan-out
		 * is deferred to a background event (like the drift path), with a
		 * synchronous fallback when no scheduler exists. A short transient
		 * cooldown debounces N separate auto-update requests. Guarded and
		 * fail-open so an upgrader failure can never break the update.
		 *
		 * @since NEXT
		 * @param mixed $upgrader   Upgrader instance (unused).
		 * @param mixed $hook_extra Update context (action/type/plugin/plugins/theme/themes).
		 * @return void
		 */
		public function on_any_upgrade( $upgrader, $hook_extra ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Signature must match the upgrader_process_complete action.
			unset( $upgrader );
			try {
				if ( ! is_array( $hook_extra ) ) {
					return;
				}
				// Single hash per firing: check/set the per-request map
				// directly instead of hashing twice via
				// is_upgrade_purged() + mark_upgrade_purged().
				$hash = $this->upgrade_payload_hash( $hook_extra );
				if ( isset( self::$upgrade_purged_hashes[ $hash ] ) ) {
					return;
				}
				if ( 'update' !== ( $hook_extra['action'] ?? '' ) ) {
					return;
				}
				if ( ! in_array( ( $hook_extra['type'] ?? '' ), array( 'plugin', 'theme', 'core' ), true ) ) {
					return;
				}

					list( $updated_plugins, $updated_themes ) = $this->collect_updated_slugs( $hook_extra );

				// Builder updates already purged via on_builder_update().
				// Reuse the map resolved by the priority-10 handler for the
				// same firing so a non-deterministic filter cannot route
				// the two handlers differently.
				if ( isset( self::$resolved_builder_map_cache[ $hash ] ) ) {
					$map = self::$resolved_builder_map_cache[ $hash ];
				} else {
					$map = self::get_builder_map();
				}
				$matched = $this->match_builders( $updated_plugins, $updated_themes, $map );
				if ( ! empty( $matched ) ) {
					return;
				}

				// Nothing identifiable to report (e.g. translation-only bulk
				// payload with no slugs) — still purge, labelled generically.
				$reason = $this->describe_upgrade( (string) ( $hook_extra['type'] ?? 'update' ), $updated_plugins, $updated_themes );

				// Cross-request debounce: N separate auto-update/cron
				// requests for N plugins must not each wipe + fan-out. Under
				// cooldown still ensure a deferred wipe is pending before
				// returning — otherwise a scheduler run between updates
				// would leave the later update's files unpurged.
				if ( function_exists( 'get_transient' ) && get_transient( Util::transient_key( self::UPGRADE_PURGE_LOCK ) ) ) {
					$this->schedule_deferred_upgrade_purge();
					self::$upgrade_purged_hashes[ $hash ] = true;
					return;
				}

				self::$upgrade_purged_hashes[ $hash ] = true;
				if ( function_exists( 'set_transient' ) && defined( 'MINUTE_IN_SECONDS' ) ) {
					try {
						set_transient( Util::transient_key( self::UPGRADE_PURGE_LOCK ), 1, 5 * MINUTE_IN_SECONDS );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				if ( ! $this->schedule_deferred_upgrade_purge() ) {
					// No scheduler available — purge inline so the update
					// never leaves stale derived caches.
					$this->purge_wppo_derived_caches();
					$this->bump_combined_asset_versions();
					$this->record_last_purge( $reason );
				} else {
					// Deferred: record a scheduled marker now; the
					// background callback re-records completion so the SPA
					// never shows success for a job that never ran.
					$this->record_last_purge( $reason . ' (scheduled)' );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					try {
						Log::add(
							sprintf(
								/* translators: %s: upgrade description, e.g. "plugin akismet/akismet.php" */
								__( 'Upgrade detected (%s): page cache, used-CSS and critical-CSS purged; combined assets version-bumped.', 'performance-optimisation' ),
								$reason
							)
						);
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'do_action' ) ) {
					try {
						/**
						 * Fires after WPPO auto-purges derived caches for a generic upgrade.
						 *
						 * @since NEXT
						 *
						 * @param string $reason Human-readable upgrade description.
						 */
						do_action( 'wppo_after_upgrade_purge', $reason );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Describe an upgrade payload for logs + SPA last-purge reason.
		 *
		 * @since NEXT
		 * @param string   $type    Upgrade type (plugin/theme/core).
		 * @param string[] $plugins Updated plugin files.
		 * @param string[] $themes  Updated theme slugs.
		 * @return string Human-readable description (bounded length).
		 */
		protected function describe_upgrade( string $type, array $plugins, array $themes ): string {
			// Stored reason is intentionally locale-independent (slugs + type
			// are machine identifiers surfaced verbatim in the SPA banner);
			// only the "+N more" suffix is translatable.
			$slugs = array_merge( array_values( $plugins ), array_values( $themes ) );
			$slugs = array_values( array_filter( array_map( 'strval', $slugs ) ) );
			if ( empty( $slugs ) ) {
				return 'core' === $type ? 'core update' : $type . ' update';
			}
			$shown = array_slice( $slugs, 0, 3 );
			$label = implode( ', ', $shown );
			if ( count( $slugs ) > 3 ) {
				$label .= sprintf(
					/* translators: %d: number of additional updated plugins/themes. */
					__( ' (+%d more)', 'performance-optimisation' ),
					count( $slugs ) - 3
				);
			}
			$label = $type . ' ' . $label;
			if ( function_exists( 'mb_substr' ) ) {
				return mb_substr( $label, 0, 200 );
			}
			return substr( $label, 0, 200 );
		}

		/**
		 * Schedule the heavy generic-upgrade purge as a background event.
		 *
		 * Mirrors the drift path: Action Scheduler when available (deduped
		 * via as_has_scheduled_action()), else wp_schedule_single_event()
		 * (deduped via wp_next_scheduled()). Dedupe uses fixed empty args —
		 * reason-keyed args would defeat dedupe (describe_upgrade() embeds
		 * slugs, so N distinct plugins would enqueue N heavy purges); the
		 * reason travels out-of-band via record_last_purge(). Fail-open: any
		 * error or a missing scheduler reports false and the caller purges
		 * inline.
		 *
		 * @since NEXT
		 * @param string $reason Optional upgrade description (ignored, kept for backward compatibility).
		 * @return bool True when an event was enqueued or already pending.
		 */
		protected function schedule_deferred_upgrade_purge( string $reason = '' ): bool {
			unset( $reason );
			try {
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::UPGRADE_PURGE_HOOK, array(), 'performance_optimisation' ) ) {
						return true;
					}
					as_enqueue_async_action( self::UPGRADE_PURGE_HOOK, array(), 'performance_optimisation' );
					return true;
				}
				if ( function_exists( 'wp_schedule_single_event' ) ) {
					if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::UPGRADE_PURGE_HOOK, array() ) ) {
						return true;
					}
					wp_schedule_single_event( time() + ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ), self::UPGRADE_PURGE_HOOK, array() );
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Background callback: run the heavy generic-upgrade purge.
		 *
		 * Registered on UPGRADE_PURGE_HOOK and executed by Action Scheduler
		 * or WP-Cron, never inline in the upgrader request. Re-records the
		 * last-purge marker on completion: the inline path stores a
		 * "(scheduled)" marker, which is promoted here to the completed
		 * reason (fresh timestamp) so the SPA never shows success for a job
		 * that never ran. An explicit legacy reason arg (enqueued with args
		 * by older versions) is recorded verbatim. Guarded and fail-open
		 * like the other purge seams.
		 *
		 * @since NEXT
		 * @param string $reason Optional legacy upgrade description.
		 * @return void
		 */
		public function run_deferred_upgrade_purge( string $reason = '' ): void {
			try {
				$this->purge_wppo_derived_caches();
				$this->bump_combined_asset_versions();
				if ( '' !== $reason ) {
					$this->record_last_purge( $reason );
					return;
				}
				$last   = self::get_last_purge();
				$suffix = ' (scheduled)';
				if ( '' !== $last['reason'] && substr( $last['reason'], -strlen( $suffix ) ) === $suffix ) {
					$this->record_last_purge( substr( $last['reason'], 0, -strlen( $suffix ) ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Manual purge entry point for the SPA button + REST route (issue #1276).
		 *
		 * Clears the page cache, purges coupled used/critical CSS, bumps
		 * combined-asset versions, and records the SPA-visible last-purge
		 * reason. Fail-open: never throws.
		 *
		 * @since NEXT
		 * @param string $reason Human-readable reason stored for the SPA.
		 * @return void
		 */
		public function purge_derived_caches( string $reason = 'manual purge' ): void {
			try {
				$this->purge_wppo_derived_caches();
				$this->bump_combined_asset_versions();
				$this->record_last_purge( '' !== $reason ? $reason : 'manual purge' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Invalidate cached asset manifests/stats after a purge (issue #1276).
		 *
		 * This is a stats/manifest invalidation only — not a `?ver=` change
		 * on combined files. Combined CSS/JS URLs carry `?ver=filemtime()`,
		 * so cache-busting depends on Cache::clear_cache() having deleted
		 * the min dir; this explicit bump via Cache::bump_stats_cache()
		 * (method_exists-guarded, fail-open) only clears stats transients and
		 * bumps wppo_cache_last_cleared so the first post-update hit cannot
		 * reuse stale manifest data (and no-ops harmlessly when the clear
		 * already bumped stats).
		 *
		 * @since NEXT
		 * @return void
		 */
		protected function bump_combined_asset_versions(): void {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'bump_stats_cache' ) ) {
					Cache::bump_stats_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Get the last derived-cache purge record for the SPA (issue #1276).
		 *
		 * Per-site via get_option() (multisite-safe). Fail-open to an empty
		 * record when the option is missing or malformed.
		 *
		 * @since NEXT
		 * @return array{reason:string,time:int} Last-purge record.
		 */
		public static function get_last_purge(): array {
			try {
				$stored = function_exists( 'get_option' ) ? get_option( self::LAST_PURGE_OPTION, array() ) : array();
				if ( ! is_array( $stored ) ) {
					return array(
						'reason' => '',
						'time'   => 0,
					);
				}
				return array(
					'reason' => isset( $stored['reason'] ) && is_string( $stored['reason'] ) ? $stored['reason'] : '',
					'time'   => isset( $stored['time'] ) ? (int) $stored['time'] : 0,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'reason' => '',
					'time'   => 0,
				);
			}
		}

		/**
		 * Record the last derived-cache purge reason (issue #1276).
		 *
		 * Non-autoloaded per-site option write. Fail-open: never throws.
		 *
		 * @since NEXT
		 * @param string $reason Human-readable purge reason.
		 * @return void
		 */
		protected function record_last_purge( string $reason ): void {
			try {
				if ( ! function_exists( 'update_option' ) ) {
					return;
				}
				$reason = trim( $reason );
				if ( function_exists( 'mb_substr' ) ) {
					$reason = mb_substr( $reason, 0, 200 );
				} else {
					$reason = substr( $reason, 0, 200 );
				}
				update_option(
					self::LAST_PURGE_OPTION,
					array(
						'reason' => $reason,
						'time'   => time(),
					),
					false
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Safe-mode preview URL that bypasses minify (issue #1276).
		 *
		 * Appends `?wppo_nocache=1` (honoured by
		 * Main::is_aggressive_bypass_active()) so delay/defer/used-CSS and
		 * minify are skipped for the preview hit — proving the post-update
		 * page renders styled even with aggressive optimisations on.
		 * Fail-open to '' when home_url() is unavailable.
		 *
		 * @since NEXT
		 * @return string Preview URL, or '' when unresolvable.
		 */
		public static function get_safe_preview_url(): string {
			try {
				if ( ! function_exists( 'home_url' ) ) {
					return '';
				}
				$url = home_url( '/?wppo_nocache=1' );
				return is_string( $url ) ? $url : '';
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Delete the matched builders' regenerable cache directories.
		 *
		 * Only the listed subdirectories are removed — never the uploads or
		 * content base directories themselves. Every candidate is normalized
		 * and verified to stay inside its base (containment prefix check plus
		 * dot-segment rejection); missing directories are skipped. Entries
		 * flagged css_only (Bricks/WPBakery, whose directory layout is not
		 * documented as fully regenerable) delete only top-level *.css files
		 * so templates, assets, or custom files in the same root survive.
		 *
		 * @since 2.0.0
		 * @param string[] $matched Matched builder keys.
		 * @param array    $map     Builder map.
		 * @return string[] Deleted paths (for tests and logging).
		 */
		protected function purge_builder_directories( array $matched, array $map ): array {
			$deleted = array();

			$fs = Util::init_filesystem();
			if ( ! $fs ) {
				return $deleted;
			}

			$bases = array();
			if ( function_exists( 'wp_upload_dir' ) ) {
				try {
					$upload = wp_upload_dir();
					if ( is_array( $upload ) && isset( $upload['basedir'] ) && is_string( $upload['basedir'] ) && '' !== $upload['basedir'] ) {
						$bases['uploads'] = wp_normalize_path( $upload['basedir'] );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( defined( 'WP_CONTENT_DIR' ) ) {
				$bases['content'] = wp_normalize_path( WP_CONTENT_DIR );
			}

			foreach ( $matched as $key ) {
				if ( ! isset( $map[ $key ] ) || ! is_array( $map[ $key ] ) ) {
					continue;
				}
				$entry = $map[ $key ];
				$jobs  = array();
				if ( isset( $bases['uploads'], $entry['upload_subdirs'] ) && is_array( $entry['upload_subdirs'] ) ) {
					foreach ( $entry['upload_subdirs'] as $sub ) {
						$jobs[] = array( $bases['uploads'], $sub );
					}
				}
				if ( isset( $bases['content'], $entry['content_subdirs'] ) && is_array( $entry['content_subdirs'] ) ) {
					foreach ( $entry['content_subdirs'] as $sub ) {
						$jobs[] = array( $bases['content'], $sub );
					}
				}

				foreach ( $jobs as $job ) {
					list( $base, $sub ) = $job;
					$dir                = $this->scoped_cache_dir( $base, $sub );
					if ( '' === $dir ) {
						continue;
					}
					try {
						if ( method_exists( $fs, 'is_dir' ) && ! $fs->is_dir( $dir ) ) {
							continue;
						}
						if ( ! empty( $entry['css_only'] ) ) {
							$this->delete_css_files_only( $fs, $dir, $deleted );
							continue;
						}
						if ( $fs->delete( $dir, true ) ) {
							$deleted[] = $dir;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}

			return $deleted;
		}

		/**
		 * Resolve a builder cache subdirectory strictly inside its base dir.
		 *
		 * @since 2.0.0
		 * @param string $base Base directory (normalized).
		 * @param mixed  $sub  Relative subdirectory from the map.
		 * @return string Normalized absolute path, or '' when unsafe.
		 */
		private function scoped_cache_dir( string $base, $sub ): string {
			if ( ! is_string( $sub ) ) {
				return '';
			}
			$sub = trim( str_replace( '\\', '/', $sub ), '/' );
			if ( '' === $sub || false !== strpos( $sub, '..' ) ) {
				return '';
			}
			// Reject dot-only segments ('.', '...') so a crafted map entry
			// can never resolve delete() against the base directory itself.
			foreach ( explode( '/', $sub ) as $segment ) {
				if ( '' === trim( (string) $segment, '.' ) ) {
					return '';
				}
			}
			$base = rtrim( wp_normalize_path( $base ), '/' );
			if ( '' === $base ) {
				return '';
			}
			$dir = wp_normalize_path( $base . '/' . $sub );
			if ( $dir === $base || 0 !== strpos( $dir, $base . '/' ) ) {
				return '';
			}
			return $dir;
		}

		/**
		 * Delete only top-level *.css files inside a builder directory.
		 *
		 * Used for css_only map entries (Bricks/WPBakery): non-CSS files and
		 * subdirectories are left untouched so anything non-regenerable in
		 * the same root survives the purge. Top-level-only is sufficient
		 * because the same purge chain also clears the full page cache plus
		 * used-CSS and critical-CSS, so any stale nested reference is
		 * dropped with the HTML that pointed at it and rebuilt on next visit.
		 *
		 * @since 2.0.0
		 * @param object   $fs      WP_Filesystem instance.
		 * @param string   $dir     Scoped directory (already containment-checked).
		 * @param string[] $deleted Deleted paths accumulator.
		 * @return void
		 */
		private function delete_css_files_only( $fs, string $dir, array &$deleted ): void {
			try {
				if ( ! method_exists( $fs, 'dirlist' ) || ! method_exists( $fs, 'delete' ) ) {
					return;
				}
				$entries = $fs->dirlist( $dir );
				if ( ! is_array( $entries ) ) {
					return;
				}
				foreach ( $entries as $name => $entry ) {
					if ( ! is_string( $name ) || '.css' !== strtolower( substr( $name, -4 ) ) ) {
						continue;
					}
					if ( isset( $entry['type'] ) && 'f' !== $entry['type'] ) {
						continue;
					}
					$file = wp_normalize_path( rtrim( $dir, '/' ) . '/' . $name );
					if ( 0 !== strpos( $file, rtrim( $dir, '/' ) . '/' ) ) {
						continue;
					}
					try {
						if ( $fs->delete( $file ) ) {
							$deleted[] = $file;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Fire builder-native cache-regeneration hooks (best-effort).
		 *
		 * Each hook fires only when a listener is registered (has_action
		 * guard) so unknown or removed hooks are strict no-ops; everything
		 * runs guarded so a builder failure can never break the upgrader.
		 * The drift fan-out is suspended around do_action() (see
		 * self::$drift_suspended) so firing elementor/core/files/clear_cache
		 * here cannot re-enter on_builder_drift() and double-purge.
		 *
		 * @since 2.0.0
		 * @param string[] $matched Matched builder keys.
		 * @param array    $map     Builder map.
		 * @return void
		 */
		protected function fire_builder_regeneration_hooks( array $matched, array $map ): void {
			if ( ! function_exists( 'has_action' ) || ! function_exists( 'do_action' ) ) {
				return;
			}
			foreach ( $matched as $key ) {
				if ( ! isset( $map[ $key ]['clear_hooks'] ) || ! is_array( $map[ $key ]['clear_hooks'] ) ) {
					continue;
				}
				foreach ( $map[ $key ]['clear_hooks'] as $hook ) {
					if ( ! is_string( $hook ) || '' === $hook ) {
						continue;
					}
					try {
						if ( has_action( $hook ) ) {
							self::$drift_suspended = true;
							try {
								do_action( $hook );
							} finally {
								self::$drift_suspended = false;
							}
						}
					} catch ( \Throwable $e ) {
						self::$drift_suspended = false;
						unset( $e );
					}
				}
			}
		}

		/**
		 * Purge WPPO's derived caches (page cache, used-CSS, critical-CSS).
		 *
		 * Critical-CSS regeneration is queued via Action Scheduler (async)
		 * instead of running synchronously so the upgrader request is never
		 * blocked; without Action Scheduler the caches are simply cleared and
		 * rebuilt lazily on the next visits. Used-CSS uses a cooldown-gated
		 * targeted requeue (issue #1220) — only stale variants, bounded —
		 * instead of a full-site requeue, so a burst of builder updates cannot
		 * flood the scheduler; remaining pages rebuild lazily and serve the
		 * full stylesheet meanwhile (fail-open, never unstyled).
		 *
		 * @since 2.0.0
		 * @since NEXT Used-CSS path switched from forced full regen to targeted regen.
		 * @since NEXT Returns whether the page-cache clear succeeded.
		 * @return bool True when the page-cache clear succeeded.
		 */
		protected function purge_wppo_derived_caches(): bool {
			$page_cache_ok = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
					$page_cache_ok = (bool) Cache::clear_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
					$full = false;
					if ( function_exists( 'apply_filters' ) ) {
						try {
							/**
							 * Restore the legacy forced full used-CSS requeue after a builder purge.
							 *
							 * @since NEXT
							 *
							 * @param bool $full Whether to force a full requeue. Default false (targeted).
							 */
							$full = (bool) apply_filters( 'wppo_builder_used_css_full_regen', false );
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					if ( $full ) {
						Used_CSS::delete_all_used_css();
						if ( function_exists( 'as_enqueue_async_action' ) ) {
							// Note: regenerate_all() writes its own 'N jobs queued'
							// audit entry; write_purge_log() below adds the
							// builder-purge summary. Two entries per update is
							// intentional — distinct facts on a rare event.
							// Forced: the wipe above deleted every variant, so
							// per-post freshness would find nothing fresh and
							// the cooldown must not suppress the requeue (issue #1107).
							( new Used_CSS( Util::get_settings() ) )->regenerate_all( true );
						}
					} elseif ( method_exists( 'PerformanceOptimise\Inc\Used_CSS', 'request_targeted_regen' ) ) {
						// Targeted path (issue #1220): do NOT wipe all variants
						// here. A wipe followed by a bounded (20-post) requeue
						// would leave most of the site without used-CSS until
						// lazy rebuild; instead let requeue_for_post() freshness
						// filtering drive per-post regeneration while untouched
						// pages keep serving their existing used-CSS (fail-open).
						Used_CSS::request_targeted_regen( 'builder-update' );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
					if ( function_exists( 'as_enqueue_async_action' ) ) {
						// Clears now and queues per-template regeneration (see
						// the audit-entry note above).
						Critical_CSS::regenerate_all();
					} else {
						Critical_CSS::clear_all();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return $page_cache_ok;
		}

		/**
		 * Write the audit-trail entry for a builder purge.
		 *
		 * @since 2.0.0
		 * @param string[] $labels Human-readable builder names.
		 * @return void
		 */
		protected function write_purge_log( array $labels ): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
				return;
			}
			try {
				Log::add(
					sprintf(
						/* translators: %s: comma-separated builder names */
						__( 'Builder update detected (%s): builder CSS, page cache, used-CSS and critical-CSS purged.', 'performance-optimisation' ),
						implode( ', ', $labels )
					)
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Stage the one-time admin success notice for the purge.
		 *
		 * Multisite-safe via Util::transient_key(). The notice renderer reads
		 * and deletes the transient, so it shows once; the TTL is only a
		 * backstop for auto-expiry when no admin page loads.
		 *
		 * @since 2.0.0
		 * @param string[] $labels Human-readable builder names.
		 * @return void
		 */
		protected function store_admin_notice( array $labels ): void {
			if ( ! function_exists( 'set_transient' ) ) {
				return;
			}
			try {
				$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
				set_transient(
					Util::transient_key( self::NOTICE_TRANSIENT ),
					array(
						'builders' => array_values( $labels ),
						'time'     => time(),
					),
					$ttl
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
