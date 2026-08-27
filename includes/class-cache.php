<?php
/**
 * Cache class for handling caching functionalities in PerformanceOptimise plugin.
 *
 * This class is responsible for caching tasks such as combining CSS files,
 * generating static HTML files, and managing cache files in the WordPress
 * content directory. It also provides mechanisms to clear the cache and
 * retrieve cached files when necessary.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;
use PerformanceOptimise\Inc\Minify\CSS;
use PerformanceOptimise\Inc\Google_Fonts;
use MatthiasMullie\Minify\CSS as CSSMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
	/**
	 * Class Cache
	 *
	 * Handles caching functionalities such as combining CSS and generating static HTML.
	 *
	 * @since 1.0.0
	 */
	class Cache {
		/**
		 * The directory where cache files are stored.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private const CACHE_DIR = '/cache/wppo';

		/**
		 * The domain name of the site.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $domain;

		/**
		 * The root directory for cache files.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $cache_root_dir;

		/**
		 * The URL to access cache files.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $cache_root_url;

		/**
		 * The URL path for the current request.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $url_path;

		/**
		 * Whether the inline-CSS budget prediction drifted from core this request.
		 *
		 * Set when {@see core_will_inline()} finds its legacy accounting disagrees
		 * with a core-faithful re-derivation, e.g. when a queued path-data style
		 * lacks a `src` or exceeds the inline budget. Causes the combined file to be
		 * served externally instead of inlined so core cannot double-inline it.
		 *
		 * @var bool
		 * @since 2.22.0
		 */
		private bool $inline_drift_detected = false;

		/**
		 * Whether the budget-drift notice has been logged this PHP process.
		 *
		 * Rate-limits {@see log_inline_budget_drift()} to a single activity-log
		 * entry per request regardless of how many handles drift during one render.
		 * A condition-keyed transient (see {@see log_inline_budget_drift()})
		 * additionally throttles persistent drift across requests.
		 *
		 * @var bool
		 * @since 2.22.0
		 */
		private static bool $inline_drift_logged = false;

		/**
		 * Cached handle => file-size/readability map for the inline-budget simulation.
		 *
		 * Built lazily on the first {@see core_inline_budget_will_inline()} call of
		 * the request so every simulation reuses a single `is_file()`/`filesize()`
		 * pass over the queue instead of repeating it per handle (up to 6*n per
		 * request via the freshness, generation, and combined-handle loops). The
		 * map is reset whenever a style's `path` data changes, i.e. after
		 * {@see register_combine_css_path()} registers the combined file.
		 *
		 * Each entry stores the file size alongside an `is_readable()` flag so the
		 * core-faithful reference can mirror WP 7.0+ core, which skips unreadable
		 * styles in its budget loop without charging their size.
		 *
		 * @var array<string,array{size:int,readable:bool}>|null
		 * @since 2.22.0
		 */
		private ?array $inline_size_map = null;

		/**
		 * The filesystem object used for file operations.
		 *
		 * @var object|null
		 * @since 1.0.0
		 */
		private $filesystem;

		/**
		 * Whether the filesystem has been initialized.
		 *
		 * @var bool
		 * @since 1.6.0
		 */
		private bool $fs_initialized = false;

		/**
		 * The sanitized request URI from $_SERVER.
		 *
		 * @var string
		 * @since 1.6.0
		 */
		private string $request_uri;

		/**
		 * The options/settings for the cache system.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private $options;

		/**
		 * Image_Optimisation instance for buffer processing.
		 *
		 * @var Image_Optimisation|null
		 * @since NEXT
		 */
		private $image_optimisation;

		/**
		 * Google_Fonts instance for buffer-level font interception.
		 *
		 * @var Google_Fonts|null
		 * @since NEXT
		 */
		private $google_fonts;

		/**
		 * Role hash for the current request, set during buffer processing.
		 *
		 * @var string
		 * @since NEXT
		 */
		private string $current_role_hash = '';

		/**
		 * Whether the DONOTCACHEPAGE marker has been written for this request.
		 *
		 * Ensures the marker write and stale-file purge happen at most once per request.
		 *
		 * @var bool
		 * @since 1.9.0
		 */
		private bool $no_cache_marker_written = false;

		/**
		 * Preload URL of the combined stylesheet, set during {@see combine_css()}
		 * and emitted on `wp_head` by {@see maybe_preload_combine_css()}.
		 *
		 * @var string
		 * @since NEXT
		 */
		private string $combine_css_preload_url = '';

		/**
		 * Constructor to initialize cache settings and configurations.
		 *
		 * @param array $options Plugin options (optional). When empty, loaded from DB.
		 * @since 1.0.0
		 */
		public function __construct( array $options = array() ) {
			$domain = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

			// Convert internationalized domain names to ASCII (punycode) to support IDN chars.
			if ( function_exists( 'idn_to_ascii' ) ) {
				$converted = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( false !== $converted ) {
					$domain = $converted;
				}
			}

			// Strip the port before validation.
			$host = explode( ':', $domain, 2 )[0];

			$valid_domain = ! (
				strpos( $host, '..' ) !== false ||
				strpos( $host, '/' ) !== false ||
				strpos( $host, '\\' ) !== false ||
				! preg_match( '/^[a-z0-9\.\-]+$/i', $host )
			);

			if ( ! $valid_domain ) {
				$domain = '';
			} else {
				$domain = strtolower( $host );
			}

			$this->domain = $domain;

			// Define cache root directory and URL. Guard empty WP_CONTENT_DIR to prevent writing to filesystem root.
			if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR ) {
				$this->cache_root_dir = '';
				$this->cache_root_url = '';
			} else {
				$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_DIR );
				$this->cache_root_url = WP_CONTENT_URL . self::CACHE_DIR;
			}

			$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$url_path          = wp_normalize_path( trim( rawurldecode( (string) wp_parse_url( $this->request_uri, PHP_URL_PATH ) ), '/' ) );

			// Reject directory traversal.
			if ( strpos( $url_path, '..' ) !== false ) {
				$url_path = '';
			}

			$this->url_path = $url_path;

			// Initialize filesystem lazily via get_filesystem().
			$this->options = ! empty( $options ) ? $options : get_option( 'wppo_settings', array() );

			if ( ! $valid_domain && ! empty( $this->options['debug'] ) ) {
				do_action( 'wppo_debug_log', 'Cache domain validation failed' );
			}
		}

		/**
		 * Check whether page caching is allowed for the current (possibly logged-in) user.
		 *
		 * - Not logged in: always allowed.
		 * - Logged in + setting off: not allowed (preserves legacy skip-for-all-logged-in).
		 * - Logged in + setting on + no roles selected: allowed for all logged-in.
		 * - Logged in + setting on + roles selected: only if current user has an allowed role.
		 *
		 * @since 1.9.0
		 * @return bool True if the current user may receive a cached page.
		 */
		private function is_cache_allowed_for_current_user(): bool {
			return Util::is_cache_eligible_for_current_user(
				$this->options['cache_settings'] ?? array()
			);
		}

		/**
		 * Compute a stable 12-char hex hash of the current user's sorted roles.
		 * Returns empty string for visitors (no role to hash).
		 *
		 * @since 1.9.0
		 * @return string Role hash or empty string.
		 */
		private function get_logged_in_role_hash(): string {
			if ( ! is_user_logged_in() ) {
				return '';
			}

			$user = wp_get_current_user();
			return Util::get_role_hash( $user );
		}

		/**
		 * Set the Image_Optimisation instance to reuse instead of creating a new one.
		 *
		 * @param Image_Optimisation $image_optimisation The existing instance.
		 * @return void
		 * @since NEXT
		 */
		public function set_image_optimisation( Image_Optimisation $image_optimisation ): void {
			$this->image_optimisation = $image_optimisation;
		}

		/**
		 * Set the Google_Fonts instance to reuse instead of creating a new one.
		 *
		 * @param Google_Fonts $google_fonts The existing instance.
		 * @return void
		 * @since NEXT
		 */
		public function set_google_fonts( Google_Fonts $google_fonts ): void {
			$this->google_fonts = $google_fonts;
		}

		/**
		 * Lazily initializes and returns the WP_Filesystem object.
		 *
		 * @return object|false The filesystem object or false on failure.
		 * @since 1.6.0
		 */
		private function get_filesystem() {
			if ( ! $this->fs_initialized ) {
				$this->filesystem     = Util::init_filesystem();
				$this->fs_initialized = true;
			}
			return $this->filesystem;
		}

		/**
		 * Whether WP 6.9+ core is loading separate (on-demand) core block assets.
		 *
		 * Single source of truth for the separate-assets state shared by the
		 * combined-CSS cache filename variant and every combine/preload loop. The
		 * 6.9+ gate keeps pre-6.9 cores (which have no such function) on the
		 * legacy monolith path.
		 *
		 * @return bool True when core loads separate core block assets on demand.
		 * @since NEXT
		 */
		private function block_assets_are_separate(): bool {
			return function_exists( 'wp_should_load_separate_core_block_assets' ) && wp_should_load_separate_core_block_assets();
		}

		/**
		 * Whether a style handle belongs to the core block-assets family.
		 *
		 * On WP 6.9+ with separate block assets active, the combined stylesheet
		 * (`wp-block-library`) and every per-block stylesheet (`wp-block-cover`,
		 * `wp-block-group`, …) are loaded on demand for the blocks actually present
		 * on the page. Folding them into the combined file would re-monolithize what
		 * core ships conditionally and make the combined file churn as the block set
		 * changes across pages, so they are excluded from combining in that mode.
		 *
		 * @param string $handle                The registered style handle.
		 * @param bool   $separate_block_assets Whether core loads separate block assets.
		 * @return bool True if the handle is a core block asset under separate assets.
		 * @since NEXT
		 */
		private function is_core_block_asset( $handle, bool $separate_block_assets ): bool {
			return $separate_block_assets && str_starts_with( (string) $handle, 'wp-block-' );
		}

		/**
		 * Combines all enqueued CSS files into a single file.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public function combine_css() {
			// TODO(#624): when WP 7.2 removes script/style concatenation in favour
			// of core preload emission, reassess whether this concat pipeline should
			// be dropped / relegated to an opt-in legacy toggle in favour of core
			// preloads (wp_resource_hints). No runtime change until the core API lands.
			if ( ! $this->is_cache_allowed_for_current_user() || is_404() || $this->is_not_cacheable() ) {
				return;
			}

			global $wp_styles;
			$styles = $wp_styles->queue;

			if ( empty( $styles ) ) {
				return;
			}

			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}

			$exclude_combine_css = array();
			if ( ! empty( $this->options['file_optimisation']['excludeCombineCSS'] ) ) {
				$exclude_combine_css = Util::process_urls( $this->options['file_optimisation']['excludeCombineCSS'] );
			}

			// On WP 6.9+ with separate (on-demand) core block assets active, never
			// fold any core block-asset stylesheet into the combined file — doing so
			// would force the wp-block-library monolith (or per-block styles for
			// blocks not even on the page) back into the head and fight core's
			// conditional loading. Belt-and-suspenders: these handles are normally
			// not in the queue on 6.9 anyway.
			$separate_block_assets = $this->block_assets_are_separate();

			// The effective separate-assets state is baked into the combined-CSS
			// cache filename, so a 6.8 -> 6.9 upgrade (which flips separate block
			// assets on by default for classic themes) cannot keep serving a stale
			// combined monolith built while wp-block-library was still in the queue.
			$css_variant = $separate_block_assets ? 'separate' : '';

			// The set of handles this request would pull into the combined file. The
			// same skip rules are applied below during generation so the two branches
			// stay consistent about which styles belong in the file.
			$eligible_handles = $this->get_combined_handles( $styles, $exclude_combine_css );

			// On small block-theme bundles core's 40KB inline budget (WP 6.9+)
			// already inlines the eligible styles cheaply — skip creating the
			// combined file and let core inline instead (one fewer request).
			if ( $this->should_skip_combine_for_inline_budget( $eligible_handles ) ) {
				return;
			}

			// Reuse cached CSS only if it is still fresh (no source file is newer and
			// the set of combined handles is unchanged).
			$css_file_path = $this->get_cache_file_path( 'css', '', $css_variant );

			if ( $fs->exists( $css_file_path ) ) {
				$cache_mtime  = (int) $fs->mtime( $css_file_path );
				$source_newer = false;

				foreach ( $styles as $handle ) {
					if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
						continue;
					}

					// Styles core inlines are not part of the combined file, so their
					// mtime must not force a regeneration.
					if ( $this->core_will_inline( $handle ) ) {
						continue;
					}

					if ( $this->is_core_block_asset( $handle, $separate_block_assets ) ) {
						continue;
					}

					$src      = $wp_styles->registered[ $handle ]->src;
					$src_path = Util::get_local_path( (string) $src );
					if ( '' !== $src_path && $fs->exists( $src_path ) && $fs->mtime( $src_path ) > $cache_mtime ) {
						$source_newer = true;
						break;
					}
				}

				// A combined file built before this inline-CSS support may embed styles
				// that core now inlines, or the set of handles may have changed; such a
				// file would duplicate inlined rules, so regenerate instead of reusing.
				if ( ! $source_newer && ! $this->combined_handles_match( $css_file_path, $eligible_handles ) ) {
					$source_newer = true;
				}

				if ( ! $source_newer ) {
					$css_url = $this->get_cache_file_url( 'css', $css_variant );
					$version = $cache_mtime;
					wp_enqueue_style( 'wppo-combine-css', $css_url, array(), $version, 'all' );
					$this->register_combine_css_path( $css_file_path );
					$this->set_combine_css_preload( $css_url, $version, $css_file_path );
					return;
				}
			}

			$combined_css = '';

			foreach ( $styles as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$style_data = $wp_styles->registered[ $handle ];

				// Skip styles core will inline itself (registered with 'path' data and
				// small enough to fit the inline budget). Leaving them enqueued lets
				// core inline them at their own queue position; pulling them into the
				// combined file would duplicate their rules and inflate its size.
				if ( $this->core_will_inline( $handle ) ) {
					continue;
				}

				if ( $this->is_core_block_asset( $handle, $separate_block_assets ) ) {
					continue;
				}

				if ( ! empty( $exclude_combine_css ) ) {
					if ( in_array( $handle, $exclude_combine_css, true ) ) {
						continue;
					}

					$should_exclude = false;
					foreach ( $exclude_combine_css as $exclude_css ) {
						if ( false !== strpos( $style_data->src, $exclude_css ) ) {
							$should_exclude = true;
						}
					}

					if ( $should_exclude ) {
						continue;
					}
				}

				if ( ! isset( $style_data->args ) || 'all' !== $style_data->args ) {
					continue;
				}

				$src = $wp_styles->registered[ $handle ]->src;

				$css_content = $this->fetch_remote_css( $src );

				if ( false === $css_content ) {
					continue;
				}

				if ( ! empty( $style_data->extra['before'] ) ) {
					$combined_css .= implode( "\n", $style_data->extra['before'] ) . "\n";
				}

				if ( ! empty( $css_content ) ) {
					$combined_css .= $css_content . "\n";
				}

				if ( ! empty( $style_data->extra['after'] ) ) {
					$combined_css .= implode( "\n", $style_data->extra['after'] ) . "\n";
				}

				wp_dequeue_style( $handle ); // Remove individual style.
			}

			if ( ! empty( $combined_css ) ) {
				$combined_css = preg_replace( '/font-display\s*:\s*block\s*;?/', 'font-display: swap;', $combined_css );

				$combined_css = Minify\CSS::inject_font_display_swap( $combined_css );

				$css_minifier  = new CSSMinifier( $combined_css );
				$combined_css  = $css_minifier->minify();
				$css_file_path = $this->get_cache_file_path( 'css', '', $css_variant );

				$this->prepare_cache_dir();
				$this->save_cache_files( $combined_css, $css_file_path, 'css' );

				$css_url = $this->get_cache_file_url( 'css', $css_variant );

				$version = $fs->mtime( $css_file_path );
				wp_enqueue_style( 'wppo-combine-css', $css_url, array(), $version, 'all' );
				$this->register_combine_css_path( $css_file_path );
				$this->write_combined_handles( $css_file_path, $eligible_handles );

				$this->set_combine_css_preload( $css_url, $version, $css_file_path );
			}
		}

		/**
		 * Record the combined-CSS preload URL for emission on `wp_head`.
		 *
		 * Shared by the cached-file and fresh-generation branches of
		 * {@see combine_css()} so the resource hint is emitted for every request
		 * that enqueues the combined stylesheet, not only on regeneration.
		 * Preloading a stylesheet core is about to inline is a wasted request, so
		 * the URL is skipped in that case.
		 *
		 * @param string     $css_url       URL of the combined stylesheet.
		 * @param int|string $version       Cache-busting version suffix.
		 * @param string     $css_file_path Absolute path to the combined CSS file.
		 * @return void
		 * @since NEXT
		 */
		private function set_combine_css_preload( $css_url, $version, $css_file_path ): void {
			if ( $this->will_combine_css_inline( $css_file_path ) ) {
				return;
			}
			$this->combine_css_preload_url = $css_url . '?ver=' . $version;
		}

		/**
		 * Emit the combined-CSS `rel="preload"` resource hint on `wp_head`.
		 *
		 * Core's `wp_resource_hints()` does not emit `preload` relations, so the
		 * hint is printed directly via {@see Util::generate_preload_link()} at
		 * `wp_head` priority 1 — after `wp_enqueue_scripts` has populated the URL
		 * and before core prints the stylesheet `<link>` at priority 8.
		 *
		 * @return void
		 * @since NEXT
		 */
		public function maybe_preload_combine_css(): void {
			// TODO(#624): once WP 7.2 removes concatenation in favour of preloads,
			// reassess whether this plugin-emitted preload should defer to core
			// preload emission (wp_resource_hints) instead. No runtime change.
			if ( '' === $this->combine_css_preload_url ) {
				return;
			}
			Util::generate_preload_link( $this->combine_css_preload_url, 'preload', 'style' );
			$this->combine_css_preload_url = '';
		}

		/**
		 * Computes the set of handles that belong in the combined CSS file.
		 *
		 * Mirrors the skip rules applied in {@see combine_css()} generation: styles
		 * core inlines itself, core block-asset styles under the 6.9+ separate-assets
		 * mode, handles excluded from combining, and non-'all' media styles stay out
		 * of the combined file. Used both to build the file and to detect when a
		 * previously cached file is stale.
		 *
		 * @since 1.9.0
		 *
		 * @param array $styles             The enqueued style handles.
		 * @param array $exclude_combine_css Handles/URL fragments excluded from combining.
		 * @return array The handles that would be combined.
		 */
		private function get_combined_handles( $styles, $exclude_combine_css ): array {
			global $wp_styles;

			$separate_block_assets = $this->block_assets_are_separate();

			$handles = array();
			foreach ( $styles as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$style_data = $wp_styles->registered[ $handle ];

				if ( $this->core_will_inline( $handle ) ) {
					continue;
				}

				if ( $this->is_core_block_asset( $handle, $separate_block_assets ) ) {
					continue;
				}

				if ( ! empty( $exclude_combine_css ) ) {
					if ( in_array( $handle, $exclude_combine_css, true ) ) {
						continue;
					}

					$should_exclude = false;
					foreach ( $exclude_combine_css as $exclude_css ) {
						if ( false !== strpos( $style_data->src, $exclude_css ) ) {
							$should_exclude = true;
						}
					}

					if ( $should_exclude ) {
						continue;
					}
				}

				if ( ! isset( $style_data->args ) || 'all' !== $style_data->args ) {
					continue;
				}

				$handles[] = $handle;
			}

			return $handles;
		}

		/**
		 * Whether a cached combined file was generated from the same handle set.
		 *
		 * A missing sidecar (e.g. a combined file built before this inline-CSS
		 * support shipped) is treated as a mismatch so stale files regenerate.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path  Absolute path to the combined CSS file.
		 * @param array  $eligible_handles The handles expected in the combined file.
		 * @return bool True if the cached file matches the current handle set.
		 */
		private function combined_handles_match( $css_file_path, array $eligible_handles ): bool {
			$fs     = $this->get_filesystem();
			$handle = $css_file_path . '.handles';

			if ( ! $fs || ! $fs->exists( $handle ) ) {
				return false;
			}

			$contents = $fs->get_contents( $handle );
			if ( false === $contents ) {
				return false;
			}

			$stored = json_decode( $contents, true );
			return is_array( $stored ) && $stored === $eligible_handles;
		}

		/**
		 * Persists the set of combined handles next to the combined CSS file.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path  Absolute path to the combined CSS file.
		 * @param array  $eligible_handles The handles combined into the file.
		 * @return void
		 */
		private function write_combined_handles( $css_file_path, array $eligible_handles ): void {
			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}

			$handle_path = $css_file_path . '.handles';
			$fs->put_contents( $handle_path, wp_json_encode( $eligible_handles ), FS_CHMOD_FILE );
		}

		/**
		 * Whether a queued style will be inlined by core instead of combined.
		 *
		 * Core (WP 5.8+) inlines any enqueued stylesheet that carries `path` data
		 * and fits within the `styles_inline_size_limit` budget (20KB default before
		 * WP 6.9, 40KB on 6.9+). Such styles must be left in the queue for core to
		 * inline at their own position rather than pulled into the combined file
		 * (which would duplicate their rules).
		 *
		 * Core applies the budget cumulatively: path-data styles are sorted
		 * smallest-first and inlined greedily until the running total exceeds the
		 * limit, so a style that individually fits can still be served externally on
		 * style-heavy pages. This helper replicates that accounting.
		 *
		 * @since 1.9.0
		 *
		 * @param string $handle The registered style handle.
		 * @return bool True if core will inline the style, false otherwise.
		 */
		private function core_will_inline( $handle ): bool {
			if ( ! function_exists( 'wp_maybe_inline_styles' ) ) {
				return false;
			}

			global $wp_styles;
			if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
				return false;
			}

			// Since WP 6.3 core only inlines styles that carry a `src`; on 5.8-6.2
			// any queued style with `path` data was a candidate, so a src-less
			// handle can still be inlined there.
			if ( $this->inline_candidates_require_src() && empty( $wp_styles->registered[ $handle ]->src ) ) {
				return false;
			}

			$limit = $this->get_styles_inline_limit();

			// Prediction using the plugin's long-standing budget accounting.
			$prediction = $this->core_inline_budget_will_inline( $handle, $limit, false );

			// Re-derivation of core's own candidate collection and budget pass. Any
			// divergence (a queued path-data style without a `src`, an over-budget
			// sibling, or tie-order differences) means the prediction is unreliable,
			// so the request degrades to the safe outcome below instead.
			$reference = $this->core_inline_budget_will_inline( $handle, $limit, true );

			if ( $prediction !== $reference ) {
				$this->inline_drift_detected = true;
				$this->log_inline_budget_drift( $handle, $limit );

				// Conservative downgrade: assume core WILL inline the style. Leaving it
				// out of the combined file is safe in every direction — either core
				// inlines it (correct), or it is served as its own external <link>
				// (a minor perf loss, never duplicated rules). Returning false here
				// would risk pulling an inlined style into the combined file.
				return true;
			}

			return $prediction;
		}

		/**
		 * Simulates core's greedy smallest-first inline-CSS budget for a handle.
		 *
		 * When `$core_faithful` is true the candidate set mirrors core's
		 * `wp_maybe_inline_styles()`: a queued handle only counts when it carries
		 * path data, its file size is within the inline limit, and (since WP 6.3)
		 * it also has a `src`. Styles over the budget are excluded up-front
		 * (equivalent to core's ascending sort + `break` on first overflow). On
		 * WP 7.0+ unreadable styles are skipped inside the budget loop without
		 * charging their size, which mirrors core's own `is_readable()` gate. When
		 * false, the plugin's legacy accounting is reproduced exactly (any
		 * path-data handle with a usable file size counts, regardless of `src`,
		 * readability, or budget).
		 *
		 * The handle => size/readability map is cached for the request (see
		 * {@see $inline_size_map}) so repeated simulations pay the filesystem stat
		 * cost once per queue snapshot instead of once per call.
		 *
		 * @since 2.22.0
		 *
		 * @param string $handle       The registered style handle.
		 * @param int    $limit        The inline size limit in bytes.
		 * @param bool   $core_faithful Whether to mirror core's candidate collection.
		 * @return bool True if core's budget pass would inline the style.
		 */
		private function core_inline_budget_will_inline( $handle, $limit, $core_faithful ): bool {
			global $wp_styles;

			if ( null === $this->inline_size_map ) {
				$this->inline_size_map = array();
				foreach ( $wp_styles->queue as $queued_handle ) {
					$path = $wp_styles->get_data( $queued_handle, 'path' );
					if ( empty( $path ) || ! is_file( $path ) ) {
						continue;
					}
					$size = (int) filesize( $path );
					if ( $size > 0 ) {
						$this->inline_size_map[ $queued_handle ] = array(
							'size'     => $size,
							'readable' => is_readable( $path ),
						);
					}
				}
			}

			$entries = array();
			foreach ( $this->inline_size_map as $queued_handle => $entry ) {
				if ( $core_faithful ) {
					// Core skips handles that are not registered.
					if ( ! isset( $wp_styles->registered[ $queued_handle ] ) ) {
						continue;
					}
					$registered = $wp_styles->registered[ $queued_handle ];
					// Core only considers a queued style a candidate when it carries
					// a `src` (the `path && src` gate landed in 6.3) and is found on
					// disk; on 5.8-6.2 path data alone was sufficient. Styles over
					// the limit are excluded up-front — they sort last and would only
					// ever trigger the loop's overflow `break`, which stops nothing
					// that fits the budget.
					if ( ( $this->inline_candidates_require_src() && empty( $registered->src ) ) || $entry['size'] > $limit ) {
						continue;
					}
				}

				$entries[ $queued_handle ] = $entry;
			}

			// Replicate core's greedy smallest-first cumulative budget. Core uses an
			// unstable usort; the stable uasort is retained here because the drift
			// check + conservative downgrade neutralize the residual tie-order
			// ambiguity.
			uasort(
				$entries,
				static function ( $a, $b ) {
					return $a['size'] <=> $b['size'];
				}
			);

			$total = 0;
			foreach ( $entries as $queued_handle => $entry ) {
				$size = $entry['size'];

				// Overflow check first, exactly as core orders it: an unreadable
				// but in-budget file is skipped below without charging its size, but
				// one that would push the running total over the limit still stops
				// the pass.
				if ( $total + $size > $limit ) {
					return false;
				}

				// WP 7.0+ core skips unreadable styles in its budget loop without
				// charging their size; earlier core versions charged them regardless.
				if ( $core_faithful && $this->inline_candidates_require_readable() && ! $entry['readable'] ) {
					continue;
				}

				$total += $size;
				if ( $queued_handle === $handle ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Logs that the inline-CSS budget prediction drifted from core.
		 *
		 * Rate-limited to at most one activity-log entry per PHP process so a single
		 * drifted request cannot flood the log, and — because drift conditions are
		 * deterministic and persistent (e.g. a queued path-data style without a
		 * `src` on WP 6.3+, or an unreadable over-limit peer on WP 7.0+) — at most
		 * one entry per rolling window per drift condition via a transient, so a
		 * persistent drift cannot grow the log by one row per pageview. Cache and
		 * Log share the `PerformanceOptimise\Inc` namespace, so no import is
		 * required.
		 *
		 * @since 2.22.0
		 *
		 * @param string $handle The handle whose prediction drifted.
		 * @param int    $limit  The inline size limit in bytes.
		 * @return void
		 */
		private function log_inline_budget_drift( $handle, $limit ): void {
			if ( self::$inline_drift_logged ) {
				return;
			}
			self::$inline_drift_logged = true;

			if ( ! class_exists( Log::class ) ) {
				return;
			}

			// The same drift condition is reproduced on every pageview, so a once
			// per-process flag alone would still append one row per request. Key a
			// transient by the condition (handle + core version) and skip repeats
			// within the rolling window; a change in WP or an operator theme fix
			// re-arms the notice.
			$version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : 'unknown';
			$log_key = Util::transient_key( 'wppo_inline_drift_' . md5( $handle . '|' . $version ) );
			if ( get_transient( $log_key ) ) {
				return;
			}
			set_transient( $log_key, 1, DAY_IN_SECONDS );

			Log::add(
				sprintf(
					/* translators: %1$s: style handle, %2$d: inline size limit in bytes. */
					__( 'Inline-CSS budget prediction drifted from core for %1$s (limit %2$d); degraded to safe fallback.', 'performance-optimisation' ),
					$handle,
					$limit
				)
			);
		}

		/**
		 * Registers the combined CSS file with `path` data for core's inline pass.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path Absolute path to the combined CSS file.
		 * @return void
		 */
		private function register_combine_css_path( $css_file_path ): void {
			if ( ! function_exists( 'wp_maybe_inline_styles' ) || empty( $css_file_path ) || ! is_file( $css_file_path ) ) {
				return;
			}

			// Inlining is disabled while removeUnusedCSS is active because used-CSS
			// reads the combined stylesheet via its `src`; once core inlines a handle
			// it clears `src`, which would silently skip the combined file and ship the
			// full (unpurged) CSS instead.
			if ( ! empty( $this->options['file_optimisation']['removeUnusedCSS'] ) ) {
				return;
			}

			// Site operators can opt out of inlining entirely (e.g. when serving the
			// combined file from a CDN, since inlined CSS bypasses the CDN).
			if ( ! apply_filters( 'wppo_inline_combined_css', true ) ) {
				return;
			}

			// When the inline-CSS budget prediction drifted from core this request,
			// serve the combined file externally rather than register `path` data: a
			// wrongly-registered file would either be unexpectedly inlined or left
			// external despite the preload decision made earlier.
			if ( $this->inline_drift_detected ) {
				return;
			}

			wp_style_add_data( 'wppo-combine-css', 'path', $css_file_path );

			// The combined handle now carries `path` data, so the size map cached
			// for the inline-budget simulation is stale; rebuild it on the next call.
			$this->inline_size_map = null;
		}

		/**
		 * Whether the combined CSS file will be inlined by core.
		 *
		 * Delegates to {@see core_will_inline()} so the cumulative, smallest-first
		 * budget core applies across all queued path-data styles is honoured for the
		 * combined file as well. The combined handle must carry `path` data (set by
		 * {@see register_combine_css_path()} before this is called).
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path Absolute path to the combined CSS file.
		 * @return bool True if core will inline the combined file, false otherwise.
		 */
		private function will_combine_css_inline( $css_file_path ): bool {
			if ( empty( $css_file_path ) ) {
				return false;
			}

			// When the budget prediction drifted this request the combined file is
			// served externally (see register_combine_css_path()), so keep the
			// preload hint for the now-external stylesheet instead of delegating.
			if ( $this->inline_drift_detected ) {
				return false;
			}

			// core_will_inline() performs the function_exists() gate itself.
			return $this->core_will_inline( 'wppo-combine-css' );
		}

		/**
		 * Reads the core `styles_inline_size_limit` budget.
		 *
		 * Core's default is version-dependent: 20KB before WP 6.9, 40KB on 6.9+.
		 * Site-level overrides through the `styles_inline_size_limit` filter always
		 * win. `$GLOBALS['wp_version']` may be absent in some contexts, in which
		 * case the newest default is used.
		 *
		 * @since 1.9.0
		 *
		 * @return int The inline size limit in bytes.
		 */
		private function get_styles_inline_limit(): int {
			$default = 40000;
			if ( isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], '6.9', '<' ) ) {
				$default = 20000;
			}

			return (int) apply_filters( 'styles_inline_size_limit', $default );
		}

		/**
		 * Whether inline candidates must carry a `src` on this core version.
		 *
		 * WP 6.3 introduced the `path && src` gate in `wp_maybe_inline_styles()`;
		 * before that (5.8-6.2) any queued style with `path` data was a candidate
		 * regardless of `src`. An absent `$wp_version` assumes the newest behavior,
		 * matching {@see get_styles_inline_limit()}.
		 *
		 * @since 2.22.0
		 *
		 * @return bool True when inline candidates must carry a `src`.
		 */
		private function inline_candidates_require_src(): bool {
			return ! isset( $GLOBALS['wp_version'] ) || version_compare( $GLOBALS['wp_version'], '6.3', '>=' );
		}

		/**
		 * Whether inline candidates must be readable on this core version.
		 *
		 * WP 7.0 added a `_doing_it_wrong` notice and an unreadable-path skip
		 * (`continue`) inside the budget loop of `wp_maybe_inline_styles()`, so an
		 * unreadable stylesheet no longer consumes the inline budget. On earlier
		 * versions its size was charged regardless of readability, matching the
		 * plugin's legacy accounting. An absent `$wp_version` assumes the newest
		 * behavior.
		 *
		 * @since 2.22.0
		 *
		 * @return bool True when the core-faithful pass must skip unreadable styles.
		 */
		private function inline_candidates_require_readable(): bool {
			return ! isset( $GLOBALS['wp_version'] ) || version_compare( $GLOBALS['wp_version'], '7.0', '>=' );
		}

		/**
		 * Whether the combined-CSS file should be skipped on small block-theme bundles.
		 *
		 * On block themes with a small total payload (≤ styles_inline_size_limit,
		 * 40KB on WP 6.9+) core's greedy smallest-first inline budget will already
		 * inline the eligible styles at their queue positions. Creating a combined
		 * file would add an extra request without benefit, so it is skipped and the
		 * styles are left enqueued for core to inline. Classic themes always combine.
		 *
		 * @since NEXT
		 *
		 * @param string[] $eligible_handles Handles that would be combined.
		 * @return bool True when combining should be skipped.
		 */
		private function should_skip_combine_for_inline_budget( array $eligible_handles ): bool {
			if ( empty( $eligible_handles ) ) {
				return false;
			}
			if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
				return false;
			}
			$limit = $this->get_styles_inline_limit();
			/**
			 * Filter whether to skip the combined-CSS file on small block-theme bundles.
			 *
			 * @since NEXT
			 *
			 * @param bool     $skip             Whether to skip combining (default true on block themes with small bundles).
			 * @param string[] $eligible_handles The handles that would be combined.
			 * @param int      $limit            The current styles_inline_size_limit in bytes.
			 */
			if ( ! apply_filters( 'wppo_skip_combine_on_small_block_theme', true, $eligible_handles, $limit ) ) {
				return false;
			}
			global $wp_styles;
			$total = 0;
			foreach ( $eligible_handles as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$src = (string) ( $wp_styles->registered[ $handle ]->src ?? '' );
				if ( '' === $src ) {
					continue;
				}
				$path = Util::get_local_path( $src );
				if ( '' === $path || ! is_readable( $path ) ) {
					// Unreadable/remote styles cannot be measured — do not skip.
					return false;
				}
				$size = filesize( $path );
				if ( false === $size ) {
					return false;
				}
				if ( $size > $limit ) {
					// A single handle exceeds the inline limit — core cannot inline it, so combine remains useful.
					return false;
				}
				$total += (int) $size;
				if ( $total > $limit ) {
					return false;
				}
			}
			return $total > 0 && $total <= $limit;
		}

		/**
		 * Fetches CSS content from a remote URL or local path.
		 *
		 * @param string $url The URL of the CSS file.
		 * @return string|false The CSS content or false if fetching fails.
		 *
		 * @since 1.0.0
		 */
		private function fetch_remote_css( $url ) {
			if ( empty( $url ) ) {
				return '';
			}

			$css_file = Util::get_local_path( $url );
			$fs       = $this->get_filesystem();
			if ( $fs ) {
				$css_content = $fs->get_contents( $css_file );

				if ( false !== $css_content ) {
					$css_content = CSS::update_image_paths( $css_content, $css_file );
					return $css_content;
				}
			}

			return false;
		}

		/**
		 * Start output buffer for static HTML cache (WP < 6.9 fallback).
		 *
		 * Creates a static HTML version of the page if not logged in and not a 404 page.
		 *
		 * @return void
		 *
		 * @since 1.0.0
		 */
		public function start_output_buffer(): void {
			// TODO(#553): remove when minimum supported WP is raised to 6.9.
			if ( ! $this->is_cache_allowed_for_current_user() || $this->is_not_cacheable() ) {
				return;
			}

			$role_hash = $this->get_logged_in_role_hash();
			$file_path = $this->get_cache_file_path( 'html', $role_hash );

			ob_start(
				function ( $buffer ) use ( $file_path ) {
					$buffer = $this->process_buffer_only( $buffer );
					$this->save_processed_buffer( $buffer, $file_path );
					return $buffer;
				}
			);
		}

		/**
		 * Process the buffer (image optimisation, minification, CDN rewrite) without saving.
		 *
		 * @param string $buffer The content to be processed.
		 * @return string The processed buffer content.
		 *
		 * @since NEXT
		 */
		private function process_buffer_only( $buffer ) {
			$image_optimisation = $this->image_optimisation ? $this->image_optimisation : new Image_Optimisation( $this->options );

			$buffer = $image_optimisation->maybe_serve_next_gen_images( $buffer );
			$buffer = $image_optimisation->add_delay_load_img( $buffer );
			$buffer = $image_optimisation->add_delay_load_backgrounds( $buffer );
			$buffer = $image_optimisation->lazy_load_videos( $buffer );

			// Host Google Fonts locally via buffer-level interception.
			if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
				$google_fonts = $this->google_fonts ? $this->google_fonts : new Google_Fonts( $this->options );
				$buffer       = $google_fonts->process_buffer( $buffer );
			}

			$file_opts         = $this->options['file_optimisation'] ?? array();
			$needs_minify_pass = ! empty( $file_opts['minifyHTML'] )
				|| ! empty( $file_opts['delayJS'] )
				|| ! empty( $file_opts['minifyInlineCSS'] )
				|| ! empty( $file_opts['minifyInlineJS'] );

			if ( $needs_minify_pass ) {
				$buffer = $this->minify_buffer( $buffer );
			}

				// Apply used-CSS before CDN so href matching works.
			$buffer = $this->maybe_apply_used_css( $buffer );

			// Apply CDN rewriting.
			$buffer = $this->maybe_apply_cdn( $buffer );

			return $buffer;
		}

		/**
		 * Filter callback for wp_template_enhancement_output_buffer (WP 6.9+).
		 *
		 * Processes the output buffer without saving to cache.
		 *
		 * @param string $filtered_output The filtered output from previous callbacks.
		 * @param string $output          The raw output buffer content.
		 * @return string The processed output buffer.
		 *
		 * @since NEXT
		 */
		public function process_buffer_for_cache( $filtered_output, $output ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( ! $this->is_cache_allowed_for_current_user() || $this->is_not_cacheable() ) {
				return $filtered_output;
			}

			$this->current_role_hash = $this->get_logged_in_role_hash();

			return $this->process_buffer_only( $filtered_output );
		}

		/**
		 * Action callback for wp_finalized_template_enhancement_output_buffer (WP 6.9+).
		 *
		 * Stashes the processed output to the static cache files.
		 *
		 * @param string $output The finalized output buffer content.
		 * @return void
		 *
		 * @since NEXT
		 */
		public function stash_cache( $output ) {
			if ( ! $this->is_cache_allowed_for_current_user() || $this->is_not_cacheable() ) {
				return;
			}

			$role_hash = ! empty( $this->current_role_hash ) ? $this->current_role_hash : $this->get_logged_in_role_hash();
			$file_path = $this->get_cache_file_path( 'html', $role_hash );

			$this->save_processed_buffer( $output, $file_path );
		}

		/**
		 * Rewrite local asset URLs in HTML to use the configured CDN for wp-content and wp-includes resources.
		 *
		 * Scans img, script, link, source, and video tags and replaces attribute values that start with the site URL
		 * and contain `/wp-content/` or `/wp-includes/`. The attributes handled are `src`, `href`, `data-src`,
		 * `srcset`, and `data-srcset`. If no CDN is configured or `\WP_HTML_Tag_Processor` is unavailable, the
		 * buffer is returned unchanged.
		 *
		 * @param string $buffer The HTML content to process.
		 * @return string The HTML with applicable asset URLs rewritten to the CDN, or the original HTML if no changes were made.
		 *
		 * @since 1.2.0
		 */
		public function maybe_apply_cdn( string $buffer ): string {
			$cdn_url = $this->options['file_optimisation']['cdnURL'] ?? '';

			if ( empty( $cdn_url ) ) {
				return $buffer;
			}

			$site_url = Util::cached_home_url();
			$cdn_url  = rtrim( $cdn_url, '/' );

			if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
				return $buffer;
			}

			// Cache the expensive regex generation outside the loop to improve performance.
			$site_url_regex = '#^' . preg_quote( $site_url, '#' ) . '(/|$)#';
			$tags           = new \WP_HTML_Tag_Processor( $buffer );

			while ( $tags->next_tag() ) {
				$tag_name = strtolower( $tags->get_tag() );
				if ( ! in_array( $tag_name, array( 'img', 'script', 'link', 'source', 'video' ), true ) ) {
					continue;
				}

				$attributes = array( 'src', 'href', 'data-src' );

				foreach ( $attributes as $attr ) {
					$val = $tags->get_attribute( $attr );

					if ( $val && preg_match( $site_url_regex, $val ) && preg_match( '#\/(?:wp-content|wp-includes)\/#', $val ) ) {
						$tags->set_attribute( $attr, str_replace( $site_url, $cdn_url, $val ) );
					}
				}

				foreach ( array( 'srcset', 'data-srcset' ) as $attr ) {
					$srcset_attr = $tags->get_attribute( $attr );
					if ( $srcset_attr ) {
						$candidates = explode( ',', $srcset_attr );
						$new_srcset = array();

						foreach ( $candidates as $candidate ) {
							$candidate = trim( $candidate );
							$parts     = preg_split( '/\s+/', $candidate, 2 );
							$url       = $parts[0];
							$suffix    = isset( $parts[1] ) ? ' ' . $parts[1] : '';

							if ( preg_match( $site_url_regex, $url ) && preg_match( '#\/(?:wp-content|wp-includes)\/#', $url ) ) {
								$url = str_replace( $site_url, $cdn_url, $url );
							}
							$new_srcset[] = $url . $suffix;
						}

						$tags->set_attribute( $attr, implode( ', ', $new_srcset ) );
					}
				}
			}

			return $tags->get_updated_html();
		}

		/**
		 * Minify the output buffer.
		 *
		 * @param string $buffer The HTML content to be minified.
		 * @return string The minified HTML content.
		 *
		 * @since 1.0.0
		 */
		private function minify_buffer( $buffer ) {
			$minifier = new Minify\HTML( $buffer, $this->options );
			$buffer   = $minifier->get_minified_html();

			return $buffer;
		}


		/**
		 * Record the DONOTCACHEPAGE decision on disk and purge stale static files for the current URL.
		 *
		 * Writes a `.wppo-no-cache` marker file next to the cached HTML so the
		 * advanced-cache.php drop-in (which boots before WordPress) can skip serving
		 * a stale static copy. Runs at most once per request. Best-effort: this only
		 * engages for pages that are actually rendered by WordPress at least once
		 * after the constant is set — a page that is already cached and never re-
		 * rendered stays stale until a cache clear, post invalidation, or the
		 * one-time version-upgrade purge removes it.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		private function maybe_mark_page_not_cacheable(): void {
			if ( $this->no_cache_marker_written ) {
				return;
			}
			$this->no_cache_marker_written = true;

			$html_file_path = $this->get_cache_file_path( 'html' );
			$marker_path    = trailingslashit( dirname( $html_file_path ) ) . '.wppo-no-cache';

			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}

			// Marker already on disk — write and purge already ran previously.
			if ( $fs->exists( $marker_path ) ) {
				return;
			}

			if ( ! $this->prepare_cache_dir() ) {
				return;
			}

			$written = $fs->put_contents( $marker_path, (string) time(), FS_CHMOD_FILE );
			if ( ! $written ) {
				return;
			}

			// Purge any pre-existing static files for this URL so the marker takes effect immediately.
			$this->delete_cache_files( $html_file_path );
			$this->delete_role_variant_files( dirname( $html_file_path ) );
		}

		/**
		 * Check if the page is not cacheable.
		 *
		 * Note: for pages opted out via the DONOTCACHEPAGE constant this also records
		 * the decision on disk (see maybe_mark_page_not_cacheable()). This coupling is
		 * intentional: every render of an opted-out page runs through this predicate
		 * before any buffer/storage path can react, so it is the only reliable place
		 * to write the marker the drop-in checks. Such pages also skip output-buffer
		 * optimisations, matching how every other non-cacheable page behaves.
		 *
		 * @return bool
		 *
		 * @since 1.0.0
		 */
		private function is_not_cacheable(): bool {
			if ( '' === $this->cache_root_dir ) {
				return true;
			}
			if ( empty( $this->domain ) ) {
				return true;
			}

			// Core, WooCommerce, and third-party plugins signal dynamic pages via DONOTCACHEPAGE.
			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				$this->maybe_mark_page_not_cacheable();
				return true;
			}

			$parsed_path    = wp_parse_url( $this->request_uri, PHP_URL_PATH );
			$local_url_path = wp_normalize_path( trim( rawurldecode( (string) $parsed_path ), '/' ) );

			if ( strpos( $local_url_path, '..' ) !== false ) {
				return true;
			}

			// Exclude RSS feeds and XML sitemaps.
			if ( function_exists( 'is_feed' ) && is_feed() ) {
				return true;
			}
			if ( preg_match( '/(?:sitemap[^\/]*\.xml|wp-sitemap[^\/]*\.xml|\.xml)$/i', $local_url_path ) ) {
				return true;
			}

			// Exclude WooCommerce cart, checkout, and account pages.
			if ( function_exists( 'is_cart' ) && is_cart() ) {
				return true;
			}
			if ( function_exists( 'is_checkout' ) && is_checkout() ) {
				return true;
			}
			if ( function_exists( 'is_account_page' ) && is_account_page() ) {
				return true;
			}
			if ( preg_match( '#^/(?:cart|checkout|my-account)(?:/|$)#i', '/' . $local_url_path ) ) {
				return true;
			}

			// Exclude if active WooCommerce cart session cookies exist.
			if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) || ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
				return true;
			}

			$path_info = pathinfo( $local_url_path, PATHINFO_EXTENSION );
			return is_404() || ! empty( $path_info );
		}

		/**
		 * Get the cache file path based on the URL path.
		 *
		 * @param string $type       The file type (default: 'html').
		 * @param string $role_hash  Optional role hash for logged-in user cache variant.
		 * @param string $variant    Optional variant suffix (e.g. combined-CSS state) baked into the file name.
		 * @return string The cache file path.
		 *
		 * @since 1.0.0
		 */
		private function get_cache_file_path( $type = 'html', string $role_hash = '', string $variant = '' ): string {
			if ( '' === $this->cache_root_dir ) {
				return '';
			}
			$suffix = $role_hash ? "-{$role_hash}" : '';
			if ( $variant ) {
				$suffix .= "-{$variant}";
			}
			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $this->url_path ? "index{$suffix}.{$type}" : "{$this->url_path}/index{$suffix}.{$type}" );
		}

		/**
		 * Get the cache file URL based on the URL path.
		 *
		 * @param string $type    The file type (default: 'html').
		 * @param string $variant Optional variant suffix baked into the file name.
		 * @return string The cache file URL.
		 *
		 * @since 1.0.0
		 */
		public function get_cache_file_url( $type = 'html', string $variant = '' ): string {
			if ( '' === $this->cache_root_url ) {
				return '';
			}
			$suffix = $variant ? "-{$variant}" : '';
			return "{$this->cache_root_url}/{$this->domain}/" . ( '' === $this->url_path ? "index{$suffix}.{$type}" : "{$this->url_path}/index{$suffix}.{$type}" );
		}

		/**
		 * Apply used-CSS to the buffer if the setting is enabled.
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The processed buffer.
		 *
		 * @since 1.9.0
		 */
		private function maybe_apply_used_css( string $buffer ): string {
			$used_css = new Used_CSS( $this->options );
			return $used_css->process_buffer( $buffer );
		}

		/**
		 * Prepare the cache directory for storing files.
		 *
		 * @return bool True if successful, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function prepare_cache_dir(): bool {
			return Util::prepare_cache_dir( "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $this->url_path ? '' : "/{$this->url_path}" ) );
		}

		/**
		 * Save cache files with optional gzip compression.
		 *
		 * @param string $buffer The content to save.
		 * @param string $file_path The file path for saving.
		 * @param string $type The file type (default: 'html').
		 * @return void
		 *
		 * @since 1.0.0
		 */
		private function save_cache_files( $buffer, $file_path, $type = 'html' ): void {

			// Only evaluate the storage decision for HTML writes so the DONOTCACHEPAGE
			// side effects never fire for CSS/JS file saves.
			if ( 'html' === $type && ! $this->maybe_store_cache() ) {
				return;
			}

			if ( 'html' === $type ) {
				$current_url = Util::cached_home_url( $this->request_uri );
				$buffer      = apply_filters( 'wppo_cache_page_html', $buffer, $current_url );
			}

			$gzip_file_path = $file_path . '.gz';

			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}
			$fs->put_contents( $file_path, $buffer, FS_CHMOD_FILE );

			if ( function_exists( 'gzencode' ) ) {
				$gzip_output = gzencode( $buffer, 9 );
				if ( false !== $gzip_output ) {
					$fs->put_contents( $gzip_file_path, $gzip_output, FS_CHMOD_FILE );
				}
			}

			// A cacheable page was just written: clear any stale DONOTCACHEPAGE marker
			// so static caching resumes automatically once a plugin stops setting the
			// constant (self-healing). A later request that sets the constant again
			// re-creates the marker and purges these files.
			if ( 'html' === $type ) {
				$this->delete_cache_files( trailingslashit( dirname( $file_path ) ) . '.wppo-no-cache' );
			}
		}

		/**
		 * Save processed buffer with filesystem guard (shared by legacy and WP 6.9+ paths).
		 *
		 * @param string $buffer   The processed buffer content.
		 * @param string $file_path The file path for saving.
		 * @return void
		 *
		 * @since NEXT
		 */
		private function save_processed_buffer( string $buffer, string $file_path ): void {
			if ( ! $this->get_filesystem() || ! $this->prepare_cache_dir() ) {
				return;
			}
			$this->save_cache_files( $buffer, $file_path );
		}

		/**
		 * Determine if cache storage is allowed.
		 *
		 * @return bool True if cache can be stored, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function maybe_store_cache() {
			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				$this->maybe_mark_page_not_cacheable();
				return false;
			}

			if ( empty( $this->domain ) || strpos( $this->url_path, '..' ) !== false ) {
				// Prevent empty domain caching which could occur after traversal sanitation.
				return false;
			}

			if ( ! empty( $_SERVER['QUERY_STRING'] ) &&
				preg_match( '/(?:^|&)(s|ver|v)(?:=|&|$)/', sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) )
			) {
				return false;
			}

			if ( ! empty( $this->options['preload_settings']['enablePreloadCache'] ) ) {
				if ( ! empty( $this->options['preload_settings']['excludePreloadCache'] ) ) {
					$exclude_urls = Util::process_urls( $this->options['preload_settings']['excludePreloadCache'] );

					$request_uri = $this->request_uri;
					$home_path   = wp_parse_url( Util::cached_home_url(), PHP_URL_PATH ) ?? '';
					if ( $home_path && '/' !== $home_path && 0 === strpos( $request_uri, $home_path ) ) {
						$request_uri = substr( $request_uri, strlen( $home_path ) );
					}
					$current_url = Util::cached_home_url( $request_uri );

					if ( Util::is_url_excluded( $current_url, $exclude_urls ) ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Invalidate dynamic static HTML cache for a specific page and global archives.
		 *
		 * @param int $page_id The page ID.
		 * @return void
		 *
		 * @since 1.0.0
		 */
		public function invalidate_dynamic_static_html( $page_id ): void {
			$path = wp_make_link_relative( get_permalink( $page_id ) );

			$html_file_path = $this->get_file_path( $path, 'html' );
			$css_file_path  = $this->get_file_path( $path, 'css' );
			$used_css_path  = $this->get_file_path( $path, 'used-css' );
			$this->delete_cache_files( $html_file_path );
			$this->delete_role_variant_files( dirname( $html_file_path ) );
			$this->delete_cache_files( $css_file_path );
			$this->delete_cache_files( $used_css_path );
			$this->delete_no_cache_marker( $html_file_path );

			// Smart Purging: Always clear the home page and blog archive.
			$home_path = wp_make_link_relative( Util::cached_home_url( '/' ) );
			$home_html = $this->get_file_path( $home_path, 'html' );
			$this->delete_cache_files( $home_html );
			$this->delete_role_variant_files( dirname( $home_html ) );
			$this->delete_no_cache_marker( $home_html );

			if ( 'page' === get_option( 'show_on_front' ) ) {
				$posts_page_id = get_option( 'page_for_posts' );
				if ( $posts_page_id ) {
					$posts_path = wp_make_link_relative( get_permalink( $posts_page_id ) );
					$posts_html = $this->get_file_path( $posts_path, 'html' );
					$this->delete_cache_files( $posts_html );
					$this->delete_role_variant_files( dirname( $posts_html ) );
					$this->delete_no_cache_marker( $posts_html );
				}
			}

			// Extended Smart Purging: Clear archives for public taxonomies and the current post type.
			$post_type = get_post_type( $page_id );

			if ( $post_type ) {
				$archive_link = get_post_type_archive_link( $post_type );
				if ( ! empty( $archive_link ) && ! is_wp_error( $archive_link ) ) {
					$archive_path = wp_make_link_relative( $archive_link );
					$archive_html = $this->get_file_path( $archive_path, 'html' );
					$this->delete_cache_files( $archive_html );
					$this->delete_role_variant_files( dirname( $archive_html ) );
					$this->delete_no_cache_marker( $archive_html );
				}

				$taxonomy_names = get_object_taxonomies( $post_type, 'names' );
				if ( ! empty( $taxonomy_names ) ) {
					$all_terms = wp_get_object_terms( $page_id, $taxonomy_names );
					if ( ! empty( $all_terms ) && ! is_wp_error( $all_terms ) ) {
						$terms_by_taxonomy = array();
						foreach ( $all_terms as $term ) {
							$terms_by_taxonomy[ $term->taxonomy ][] = $term;
						}
						foreach ( $terms_by_taxonomy as $taxonomy_name => $terms ) {
							$taxonomy_obj = get_taxonomy( $taxonomy_name );
							if ( ! $taxonomy_obj || ! $taxonomy_obj->public ) {
								continue;
							}
							foreach ( $terms as $term ) {
								$term_link = get_term_link( $term );
								if ( ! empty( $term_link ) && ! is_wp_error( $term_link ) ) {
									$term_path = wp_make_link_relative( $term_link );
									$term_html = $this->get_file_path( $term_path, 'html' );
									$this->delete_cache_files( $term_html );
									$this->delete_role_variant_files( dirname( $term_html ) );
									$this->delete_no_cache_marker( $term_html );
								}
							}
						}
					}
				}
			}

			if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + wp_rand( 0, 5 ), 'wppo_generate_static_page', array( $page_id ) );
			}
		}

		/**
		 * Get the file path for a specific page.
		 *
		 * @param string|null $url_path The URL path (optional).
		 * @param string      $type The file type (default: 'html').
		 * @return string The file path.
		 *
		 * @since 1.1.1
		 */
		private function get_file_path( ?string $url_path = null, string $type = 'html' ): string {
			$url_path = wp_normalize_path( trim( (string) $url_path, '/' ) );

			if ( strpos( $url_path, '..' ) !== false ) {
				return ''; // Return empty string to prevent deletion or creation outside cache root.
			}

			$filename = 'used-css' === $type ? 'used-css.css' : "index.{$type}";

			return "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $url_path ? $filename : "{$url_path}/{$filename}" );
		}

		/**
		 * Delete used-CSS file for a specific file path.
		 *
		 * @param string $file_path The used-css file path.
		 * @return bool True if successful (or not exists), false otherwise.
		 *
		 * @since 1.9.0
		 */
		private function delete_used_css_file( string $file_path ): bool {
			return $this->delete_cache_files( $file_path );
		}

		/**
		 * Delete the DONOTCACHEPAGE marker that lives beside a cached HTML file.
		 *
		 * @param string $html_file_path The HTML cache file path whose directory holds the marker.
		 * @return void
		 *
		 * @since 1.9.0
		 */
		private function delete_no_cache_marker( string $html_file_path ): void {
			$this->delete_cache_files( trailingslashit( dirname( $html_file_path ) ) . '.wppo-no-cache' );
		}

		/**
		 * Delete cache files for a specific file path.
		 *
		 * @param string $file_path The file path.
		 * @return bool True if successful (or not exists), false otherwise.
		 *
		 * @since 1.1.0
		 */
		private function delete_cache_files( $file_path ): bool {
			$gzip_file_path = $file_path . '.gz';

			$fs = $this->get_filesystem();
			if ( $fs ) {
				$res1 = ! $fs->exists( $file_path ) || $fs->delete( $file_path );
				$res2 = ! $fs->exists( $gzip_file_path ) || $fs->delete( $gzip_file_path );
				return $res1 && $res2;
			}

			return false;
		}

		/**
		 * Delete all index-{hash}.html role-variant cache files in a directory.
		 *
		 * @param string $dir Directory to scan.
		 * @return void
		 * @since 1.9.0
		 */
		private function delete_role_variant_files( string $dir ): void {
			$fs = $this->get_filesystem();
			if ( ! $fs || ! $fs->is_dir( $dir ) ) {
				return;
			}

			$files = $fs->dirlist( $dir );
			if ( ! $files ) {
				return;
			}

			foreach ( $files as $file ) {
				if ( preg_match( '/^index-[a-f0-9]{12}\.html(\.gz)?$/', $file['name'] ) ) {
					$file_path = trailingslashit( $dir ) . $file['name'];
					$fs->delete( $file_path );
				}
			}
		}

		/**
		 * Clear the cache for a specific page or all pages.
		 *
		 * Also flushes any static HTML pages that speculative prerendering
		 * (speculation rules) may have requested and cached: such requests are
		 * ordinary GETs that produce the same per-URL static files (plus their
		 * `.gz` variants and role variants) served to every other visitor, so
		 * the full clear below removes the whole domain directory and the
		 * single-page clear removes the page's HTML, gzip, and role-variant
		 * copies. A stale prerendered copy is therefore never served after
		 * invalidation.
		 *
		 * @param string|null $url_path The URL path of the page for which to clear the cache. If null, all cache will be cleared.
		 * @return bool True on success, false on failure.
		 *
		 * @since 1.1.1
		 */
		public static function clear_cache( $url_path = null ): bool {
			$type = $url_path ? 'single_page' : 'all';
			do_action( 'wppo_before_cache_clear', $type, $url_path );

			$instance = new self();

			if ( ! $instance->get_filesystem() ) {
				return false;
			}

			if ( $url_path ) {
				$url_path = wp_normalize_path( $url_path );

				if ( strpos( $url_path, '..' ) !== false ) {
					return false;
				}

				$html_file_path = $instance->get_file_path( $url_path, 'html' );
				$css_file_path  = $instance->get_file_path( $url_path, 'css' );
				$used_css_path  = $instance->get_file_path( $url_path, 'used-css' );

				if ( empty( $html_file_path ) || empty( $css_file_path ) || empty( $used_css_path ) ) {
					return false;
				}

				$res_html = $instance->delete_cache_files( $html_file_path );
				$instance->delete_role_variant_files( dirname( $html_file_path ) );
				$instance->delete_no_cache_marker( $html_file_path );
				$res_css  = $instance->delete_cache_files( $css_file_path );
				$res_used = $instance->delete_used_css_file( $used_css_path );
				$result   = $res_html && $res_css && $res_used;
			} else {
				$result = $instance->delete_all_cache_files();
			}

			if ( $result ) {
				if ( function_exists( 'wp_cache_get_salted' ) ) {
					$salt = (int) get_option( 'wppo_cache_last_cleared', 0 ) + 1;
					update_option( 'wppo_cache_last_cleared', $salt, false );
				} else {
					delete_transient( Util::transient_key( 'wppo_cache_size' ) );
					delete_transient( Util::transient_key( 'wppo_total_js_css' ) );
				}
				update_option( 'wppo_cache_last_cleared_time', current_time( 'mysql' ), false );
				do_action( 'wppo_after_cache_clear', $type, $url_path );
			}

			return $result;
		}

		/**
		 * Delete all cache files.
		 *
		 * @return bool True if successful, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function delete_all_cache_files(): bool {
			$cache_dir = "{$this->cache_root_dir}/{$this->domain}";
			$res1      = true;
			$res2      = true;

			$fs = $this->get_filesystem();

			if ( $fs && $fs->is_dir( $cache_dir ) ) {
				$res1 = $fs->delete( $cache_dir, true );
			}

			// Minified JS/CSS files are blog-scoped so a network-wide clear cannot
			// wipe other sites' assets (whose min files may embed site-specific URLs).
			$min_dir = Util::min_cache_dir();

			if ( $fs && $fs->is_dir( $min_dir ) ) {
				$res2 = $fs->delete( $min_dir, true );
			}

			// One-time idempotent cleanup of the pre-namespacing shared directories;
			// harmless on later clears and never touches other sites' scoped dirs.
			$legacy_min_dirs = array(
				Util::min_cache_base_dir() . '/css',
				Util::min_cache_base_dir() . '/js',
			);

			foreach ( $legacy_min_dirs as $legacy_min_dir ) {
				if ( $fs && $fs->is_dir( $legacy_min_dir ) ) {
					$fs->delete( $legacy_min_dir, true );
				}
			}

			Used_CSS::delete_all_used_css();

			return $res1 && $res2;
		}

		/**
		 * Clear all CCSS files.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		public static function clear_ccss(): void {
			$instance = new self();
			$fs       = $instance->get_filesystem();
			$ccss_dir = "{$instance->cache_root_dir}/ccss";
			if ( $fs && $fs->is_dir( $ccss_dir ) ) {
				$fs->delete( $ccss_dir, true );
			}
		}

		/**
		 * Get the size of the cache.
		 *
		 * @return string
		 *
		 * @since 1.0.0
		 */
		public static function get_cache_size(): string {
			$instance = new self();

			if ( ! $instance->get_filesystem() ) {
				return __( 'Unable to initialize filesystem.', 'performance-optimisation' );
			}

			$cache_dir = "{$instance->cache_root_dir}/{$instance->domain}";

			if ( ! $instance->filesystem->is_dir( $cache_dir ) ) {
				return __( 'Cache directory does not exist.', 'performance-optimisation' );
			}

			$total_size = $instance->calculate_directory_size( $cache_dir );
			return size_format( $total_size );
		}

		/**
		 * Get detailed cache statistics.
		 *
		 * Returns size, cached page count, last-cleared timestamp, and cache directory path.
		 *
		 * @since NEXT
		 * @return array{size: string, cached_pages: int, last_cleared: string, cache_dir: string}
		 */
		public static function get_cache_stats(): array {
			$instance = new self();
			$stats    = array(
				'size'         => __( 'N/A', 'performance-optimisation' ),
				'cached_pages' => 0,
				'last_cleared' => '',
				'cache_dir'    => '',
			);

			if ( ! $instance->get_filesystem() ) {
				return $stats;
			}

			$cache_dir          = "{$instance->cache_root_dir}/{$instance->domain}";
			$stats['cache_dir'] = $cache_dir;

			if ( ! $instance->filesystem->is_dir( $cache_dir ) ) {
				return $stats;
			}

			$total_size            = $instance->calculate_directory_size( $cache_dir );
			$stats['size']         = size_format( $total_size );
			$stats['cached_pages'] = $instance->count_cached_pages( $cache_dir );
			$stats['last_cleared'] = get_option( 'wppo_cache_last_cleared_time', '' );

			return $stats;
		}

		/**
		 * Calculate the size of a directory.
		 *
		 * @param string $directory The path to the directory whose size is to be calculated.
		 * @return int The total size of the directory in bytes.
		 *
		 * @since 1.0.0
		 */
		private function calculate_directory_size( string $directory ): int {
			$total_size = 0;
			$fs         = $this->get_filesystem();

			if ( ! $fs ) {
				return $total_size;
			}

			$files = $fs->dirlist( $directory );

			if ( ! $files ) {
				return $total_size;
			}

			foreach ( $files as $file ) {
				$file_path   = trailingslashit( $directory ) . $file['name'];
				$total_size += ( 'd' === $file['type'] )
					? $this->calculate_directory_size( $file_path )
					: $fs->size( $file_path );
			}

			return $total_size;
		}

		/**
		 * Recursively count cached pages by counting index.html files in the cache directory.
		 *
		 * @param string $directory The directory to scan.
		 * @return int Number of index.html files found.
		 *
		 * @since 1.9.0
		 */
		private function count_cached_pages( string $directory ): int {
			$fs = $this->get_filesystem();

			if ( ! $fs ) {
				return 0;
			}

			$files = $fs->dirlist( $directory );
			if ( ! $files ) {
				return 0;
			}
			$count = 0;
			foreach ( $files as $file ) {
				if ( 'd' === $file['type'] ) {
					$count += $this->count_cached_pages( trailingslashit( $directory ) . $file['name'] );
				} elseif ( 'index.html' === $file['name'] ) {
					++$count;
				}
			}
			return $count;
		}

		/**
		 * Flush a specific cache group via wp_cache_flush_group().
		 *
		 * Allows targeted flushing of object cache groups (e.g. wppo_minify_check,
		 * wppo_activity_logs) instead of a full cache flush.
		 *
		 * @since NEXT
		 *
		 * @param string $group The cache group to flush.
		 * @return bool True if the flush succeeded, false if the cache implementation
		 *              does not support flush_group or the function is unavailable.
		 */
		public static function flush_group( string $group ): bool {
			// WP 6.1+: use wp_cache_supports() to check capability.
			if ( function_exists( 'wp_cache_supports' ) ) {
				if ( ! wp_cache_supports( 'flush_group' ) ) {
					return false;
				}

				return wp_cache_flush_group( $group );
			}

			// Legacy fallback for WP < 6.1.
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				global $wp_object_cache;

				if ( isset( $wp_object_cache ) && method_exists( $wp_object_cache, 'flush_group' ) ) {
					return wp_cache_flush_group( $group );
				}
			}

			return false;
		}

		/**
		 * Evict legacy WP 6.9 pre-salt query-group cache keys.
		 *
		 * Core's 6.9+ single-key-per-group cache leaves unsalted post-queries /
		 * term-queries / comment-queries / user-queries / site-queries / network-queries
		 * keys behind once wp_cache_add_salt() has been called. A full wp_cache_flush()
		 * is the only reliable eviction (flush_group() patterns are salt-prefixed and
		 * miss the legacy unsalted keys). On cores without a persistent object cache
		 * this only flushes the in-memory cache and is harmless.
		 *
		 * @since 1.9.0
		 * @return bool True if the flush ran (or nothing needed evicting on cores
		 *              without the WP 6.9+ salt API), false if the cache API is
		 *              unavailable.
		 */
		public static function flush_legacy_query_cache_keys(): bool {
			// The stale unsalted key layout only exists where core can salt the
			// cache (WP 6.9+). On older cores every key is unsalted and current,
			// so there is nothing stale to evict.
			if ( ! function_exists( 'wp_cache_get_salted' ) ) {
				return true;
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				return wp_cache_flush();
			}

			return false;
		}

		/**
		 * Flush runtime (in-memory) cache via wp_cache_flush_runtime().
		 *
		 * Avoids unnecessary persistent cache (Redis/Memcached) flushes when only
		 * in-memory cached data has changed (e.g. admin UI settings).
		 *
		 * @since 1.9.0
		 *
		 * @return bool True if the flush succeeded, false if the function is
		 *              unavailable (WP < 6.0).
		 */
		public static function flush_runtime(): bool {
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				return wp_cache_flush_runtime();
			}
			return false;
		}
	}
}
