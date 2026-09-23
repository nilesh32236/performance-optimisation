<?php
/**
 * Cache invalidation/purge service — invalidation policy, deletion mechanics,
 * purge-fallback serving, and LiteSpeed swap purge.
 *
 * ARCH-007: the invalidation/purge cluster (`invalidate_*()` trio,
 * `clear_cache()` body, the purge-fallback serving set, all `delete_*`
 * helpers plus the LiteSpeed swap purge) previously lived on the `Cache`
 * buffer/storage orchestrator. The cluster shares one responsibility (cache
 * invalidation), one trigger set (post-save smart purge, explicit
 * `clear_cache()` calls, template_redirect fallback serving), and one
 * correctness contract (action sequencing, traversal refusal, multisite
 * isolation) — so it lives here.
 *
 * `Cache` keeps thin proxies (facade rule) delegating to a lazy instance of
 * this class, so every static caller (`Rest`, `CLI`, `Deactivate`,
 * `LiteSpeed_Integration`, `Abilities`, `Builder_Purge_Watcher`,
 * `Used_CSS`), every instance caller (`LiteSpeed_Integration`,
 * `Builder_Purge_Watcher` with `method_exists()` guards), and every
 * `Hook_Registry` hook callback (`maybe_serve_purge_fallback` at
 * `template_redirect`/1) stays byte-identical with zero caller migration.
 * Service methods are public (widened from private, ARCH-005/006 precedent)
 * so the private `Cache` proxies can delegate; `Cache` keeps the original
 * visibility contract.
 * Collaboration: buffer/storage policy (`is_path_contained()`,
 * `safe_path_for_url()` via `get_file_path()`, directory/file primitives,
 * stats) stays on `Cache` and is reached through the `@internal`
 * `invalidator_*()` bridges. Path/containment primitives additionally owned
 * by `Filesystem` (REF-012) are untouched — this service never duplicates
 * them (no double-ownership; the `Cache` bridges stay the single call path).
 *
 * State strategy (Option A — ARCH-004/005/006 bridge precedent): ALL
 * instance state stays on `Cache` as the single source of truth; this
 * service holds the `Cache` instance by reference target (not a copy) so
 * domain/root/URL reads land on the live request state exactly as
 * `$this->prop` accesses did before the extraction. No memo semantic
 * change: there is no memo state in this cluster (the only static memo,
 * `purge_fallback_limits()`, keeps its per-request lifetime on this class,
 * which is instantiated once per owning `Cache` exactly as before).
 * Multisite/blog-keying is unchanged: domain-keyed paths resolve through
 * the owning instance, stats bump through `Cache::bump_stats_cache()`, and
 * throttle/transient keys through `Util::transient_key()` (blog-prefixed
 * on multisite) exactly as before.
 *
 * Action sequencing is byte-identical: `wppo_before_cache_clear` → deletes
 * → stats bump → `wppo_after_cache_clear` (edge/CDN listeners fire exactly
 * once as today, including the Deactivate double-fire note).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache_Invalidator' ) ) {
	/**
	 * Class Cache_Invalidator
	 *
	 * Owns cache invalidation policy, deletion mechanics, purge-fallback
	 * serving, and the LiteSpeed swap purge. Constructed with the `Cache`
	 * instance so domain/root/URL state, filesystem access, and
	 * path-containment verdicts keep the exact semantics the bodies had on
	 * `Cache` (instance state stays on `Cache` as the single source of
	 * truth, accessed through the `@internal` `invalidator_*()` bridges —
	 * never a new write path).
	 *
	 * @since NEXT
	 */
	final class Cache_Invalidator {

		/**
		 * Cache instance owning buffer/store state, settings, and policy.
		 *
		 * Held by reference target (not a copy) so state reads land on the
		 * live request state exactly as `$this->prop` accesses did before
		 * the extraction.
		 *
		 * @since NEXT
		 * @var   Cache
		 */
		private Cache $cache;

		/**
		 * Constructor.
		 *
		 * @since NEXT
		 * @param Cache $cache Cache instance (state + storage/policy owner).
		 */
		public function __construct( Cache $cache ) {
			$this->cache = $cache;
		}

		/**
		 * Post-purge fallback snapshot bounds (issue #1275).
		 *
		 * A full-cache wipe snapshots retained fallback siblings so the
		 * first post-purge hit can 302 instead of flashing unstyled. The
		 * guarantee is intentionally bounded (memory + I/O): larger sites
		 * keep the first N fallbacks only (see {@see purge_fallback_limits()}
		 * and the `wppo_purge_fallback_limits` filter). Staging spills the
		 * snapshot to a temp dir outside the wipe tree so a fatal between
		 * delete and restore cannot destroy both copies.
		 *
		 * @since 2.2.0
		 */
		public const PURGE_FALLBACK_MAX_FILES = 25;
		public const PURGE_FALLBACK_MAX_DEPTH = 6;
		public const PURGE_FALLBACK_MAX_BYTES = 512 * 1024; // 512 KiB per file.
		public const PURGE_FALLBACK_MAX_DIRS  = 200;
		/** Cap on total snapshot bytes held in memory (OOM guard for shared hosts). */
		public const PURGE_FALLBACK_MAX_TOTAL_BYTES = 2 * 1024 * 1024; // 2 MiB total.
		/** Redirect status for fallback serves (filterable, @see serve_purge_fallback_response()). */
		public const PURGE_FALLBACK_REDIRECT_STATUS = 302;

		/** Fallback basenames recognized by the snapshot/restore path. */
		public const PURGE_FALLBACK_NAMES = array( 'fallback.css', 'fallback.js', 'fallback.mobile.css', 'fallback.desktop.css', 'fallback.mobile.js', 'fallback.desktop.js' );

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

			// Collect canonical invalidation URLs; filtered below for extensibility.
			$urls   = array();
			$urls[] = $path;

			// Smart Purging: Always clear the home page and blog archive.
			$home_path = wp_make_link_relative( Util::cached_home_url( '/' ) );
			$urls[]    = $home_path;

			if ( 'page' === get_option( 'show_on_front' ) ) {
				$posts_page_id = get_option( 'page_for_posts' );
				if ( $posts_page_id ) {
					$posts_path = wp_make_link_relative( get_permalink( $posts_page_id ) );
					$urls[]     = $posts_path;
				}
			}

			// Extended Smart Purging: Clear archives for public taxonomies and the current post type.
			$post_type = get_post_type( $page_id );

			if ( $post_type ) {
				$archive_link = get_post_type_archive_link( $post_type );
				if ( ! empty( $archive_link ) && ! is_wp_error( $archive_link ) ) {
					$archive_path = wp_make_link_relative( $archive_link );
					$urls[]       = $archive_path;
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
									$urls[]    = $term_path;
								}
							}
						}
					}
				}
			}

			/**
			 * Filter the list of URLs to invalidate.
			 *
			 * @since 2.0.0
			 *
			 * @param string[] $urls    List of URL paths to purge.
			 * @param int      $post_id The post ID being invalidated.
			 */
			$urls = (array) apply_filters( 'wppo_invalidation_urls', $urls, $page_id );

			// Sanitize via the shared helper (single-decode, null-byte/
			// dot-dot/drive/UNC rejection) so encoded vectors a literal
			// `..`/`\0` check would miss are dropped before any delete.
			// '' is the benign homepage and is kept; a hostile input that
			// sanitizes to '' is skipped. The sanitized entries pass through
			// get_file_path() (a second sanitize pass); that double-sanitize
			// is fail-closed — the first pass output can only decode to a
			// benign literal or refuse (see clear_cache()).
			$sanitized = array();
			foreach ( $urls as $u ) {
				$u              = is_string( $u ) ? $u : (string) $u;
				$sanitized_path = Util::sanitize_cache_url_path( $u, '' !== $this->cache->invalidator_domain() ? $this->cache->invalidator_domain() : null );
				// Homepage-vs-probe: only re-parse on the empty path (benign
				// homepage '' is kept, hostile '' is skipped).
				if ( '' === $sanitized_path ) {
					if ( function_exists( 'wp_parse_url' ) ) {
						$raw_component = wp_parse_url( $u, PHP_URL_PATH );
					} else {
						$raw_component = parse_url( $u, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
					}
					if ( null === $raw_component || false === $raw_component ) {
						$raw_component = $u;
					}
					if ( '' !== trim( (string) $raw_component, " \t\n\r\0\x0B/" ) ) {
						continue;
					}
				}
				$sanitized[] = $sanitized_path;
			}
			// Note: the purge loop below re-parses each already-sanitized path;
			// an $already_sanitized flag on get_file_path() /
			// safe_path_for_url() could skip that second pass (see #553).
			$sanitized = array_values( array_unique( $sanitized ) );

			// Purge collected URLs via filesystem; primary URL also clears css/used-css.
			$primary_normalized = Util::sanitize_cache_url_path( (string) $path, '' !== $this->cache->invalidator_domain() ? $this->cache->invalidator_domain() : null );
			foreach ( $sanitized as $url_path ) {
				$html_file_path = $this->cache->invalidator_get_file_path( $url_path, 'html' );
				if ( '' === $html_file_path ) {
					continue;
				}
				$norm            = wp_normalize_path( $html_file_path );
				$cache_root_norm = '' !== $this->cache->invalidator_cache_root_dir() ? wp_normalize_path( $this->cache->invalidator_cache_root_dir() ) : '';
				$abspath_norm    = defined( 'ABSPATH' ) ? wp_normalize_path( ABSPATH ) : '';
				if ( '' !== $cache_root_norm && 0 !== strpos( $norm, $cache_root_norm ) ) {
					continue;
				}
				if ( '' !== $abspath_norm && 0 !== strpos( $norm, $abspath_norm ) ) {
					continue;
				}
				$this->delete_cache_files( $html_file_path );
				$this->delete_role_variant_files( dirname( $html_file_path ) );
				$this->delete_no_cache_marker( $html_file_path );
				if ( $url_path === $primary_normalized ) {
					$css_file_path = $this->cache->invalidator_get_file_path( $url_path, 'css' );
					$used_css_path = $this->cache->invalidator_get_file_path( $url_path, 'used-css' );
					if ( '' !== $css_file_path ) {
						$this->delete_cache_files( $css_file_path );
					}
					if ( '' !== $used_css_path ) {
						$this->delete_cache_files( $used_css_path );
					}
				}
			}

			if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $page_id ) ) ) {
				wp_schedule_single_event( time() + wp_rand( 0, 5 ), 'wppo_generate_static_page', array( $page_id ) );
			}

			// LS-201: invalidate → LSCache purge sync for the post with full fan-out D/B/C/W/REST.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {
				LiteSpeed_Integration::sync_purge_post_to_litespeed( (int) $page_id );
				// P3: queued tag purge with taxonomy fan-out (public,private,stale + blog_id_).
				$tags = array( 'F', 'H', 'PGS', 'Po.' . (int) $page_id );
				$pt   = get_post_type( $page_id );
				if ( $pt ) {
					$tags[] = 'PT.' . sanitize_text_field( $pt );
					$taxes  = get_object_taxonomies( $pt, 'names' );
					if ( ! empty( $taxes ) ) {
						$terms = wp_get_object_terms( $page_id, $taxes );
						if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
							foreach ( $terms as $term ) {
								if ( isset( $term->term_id ) ) {
									$tags[] = 'T.' . (int) $term->term_id;
								}
							}
						}
					}
					$author = get_post_field( 'post_author', $page_id );
					if ( $author ) {
						$tags[] = 'A.' . (int) $author;
					}
					// D. date Ymd.
					if ( function_exists( 'get_the_date' ) ) {
						$date = get_the_date( 'Ymd', $page_id );
						if ( $date ) {
							$tags[] = 'D.' . sanitize_text_field( (string) $date );
						}
					}
				}
				// B. blog_id fan-out (multisite, via Util::transient_key() blog prefix, also queued explicitly for LS tag).
				if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) ) {
					try {
						if ( is_multisite() ) {
							$bid = (int) get_current_blog_id();
							if ( $bid > 0 ) {
								$tags[] = 'B.' . $bid;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// C. comment tag placeholder (purge comment-related cache).
				$tags[] = 'C.' . (int) $page_id;
				// W. widget tags (W.{hash}) are only emitted by the ESI bridge —
				// a bare 'W.' tag has an empty value and is intentionally skipped.
				// REST.
				$tags[] = 'REST';
				// MIN.
				$tags[] = 'MIN';
				// HTTP.404 placeholder for invalidation context (no-cache).
				// Stale/private split: private if LiteSpeed private request, stale if TTL 0 or feed/404 context.
				$scope = 'public';
				if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_private_request' ) ) {
					try {
						if ( LiteSpeed_Integration::is_private_request() ) {
							$scope = 'private';
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( 'public' === $scope && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'get_litespeed_ttl' ) ) {
					try {
						$ttl = LiteSpeed_Integration::get_litespeed_ttl();
						if ( 0 === $ttl ) {
							$scope = 'stale';
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				LiteSpeed_Integration::queue_purge_tags( array_unique( $tags ), $scope );
			}

			// The smart purge removed pages/files — dashboard stats must not
			// stay frozen until the 15-min TTL (audit #874 finding 6).
			Cache::bump_stats_cache();
		}

		/**
		 * Invalidate the static HTML cache for a single post URL only.
		 *
		 * Used by the per-page delay kill-switch (#1037) so toggling
		 * `_wppo_delay_disabled` takes effect on that URL without a full purge
		 * and without the home/archive fan-out of
		 * {@see invalidate_dynamic_static_html()}: only the post permalink's
		 * `index.html` (+ gzip/brotli variants, role variants, no-cache marker,
		 * and css/used-css sidecars) is deleted. Multisite-safe: per-site
		 * `get_permalink()` plus domain-based `get_file_path()`, so no
		 * cross-site leakage. Fail-open: any failure is swallowed — callers must
		 * never fatal a meta save. No new WP/PHP APIs; safe on WP 6.2+ / PHP 8.2+.
		 *
		 * Bulk callers (e.g. Elementor bulk regen, issue #1259) pass
		 * $bump_stats=false and rely on the deferred full purge — which bumps
		 * once — instead of paying 6x delete_transient + get_option +
		 * update_option per post inline.
		 *
		 * @since 2.0.0
		 * @param int  $page_id Post ID whose single URL cache must be purged.
		 * @param bool $bump_stats Whether to bump dashboard stats (6x transient
		 *                         deletes + option write). Default true.
		 * @return void
		 */
		public function invalidate_single_static_html( int $page_id, bool $bump_stats = true ): void {
			try {
				if ( $page_id <= 0 ) {
					return;
				}
				$permalink = function_exists( 'get_permalink' ) ? get_permalink( $page_id ) : '';
				if ( ! is_string( $permalink ) || '' === $permalink || is_wp_error( $permalink ) ) {
					return;
				}
				$path = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $permalink ) : (string) wp_parse_url( $permalink, PHP_URL_PATH );
				if ( ! is_string( $path ) || '' === trim( $path ) ) {
					return;
				}
				$url_path = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( trim( (string) $path, '/' ) ) : trim( (string) $path, '/' );
				if ( false !== strpos( $url_path, "\0" ) || false !== strpos( $url_path, '..' ) ) {
					return;
				}

				$html_file_path = $this->cache->invalidator_get_file_path( $url_path, 'html' );
				if ( '' !== $html_file_path ) {
					$norm            = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $html_file_path ) : $html_file_path;
					$cache_root_norm = '' !== $this->cache->invalidator_cache_root_dir() && function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $this->cache->invalidator_cache_root_dir() ) : $this->cache->invalidator_cache_root_dir();
					$abspath_norm    = defined( 'ABSPATH' ) && function_exists( 'wp_normalize_path' ) ? wp_normalize_path( ABSPATH ) : ( defined( 'ABSPATH' ) ? ABSPATH : '' );
					if ( ( '' === $cache_root_norm || 0 === strpos( $norm, $cache_root_norm ) )
					&& ( '' === $abspath_norm || 0 === strpos( $norm, $abspath_norm ) ) ) {
						$this->delete_cache_files( $html_file_path );
						$this->delete_role_variant_files( dirname( $html_file_path ) );
						$this->delete_no_cache_marker( $html_file_path );
					}
				}
				$css_file_path = $this->cache->invalidator_get_file_path( $url_path, 'css' );
				if ( '' !== $css_file_path ) {
					$this->delete_cache_files( $css_file_path );
				}
				$used_css_path = $this->cache->invalidator_get_file_path( $url_path, 'used-css' );
				if ( '' !== $used_css_path ) {
					$this->delete_cache_files( $used_css_path );
					// Viewport variants (issue #1220): a single-page purge must
					// also invalidate used-css.{variant}.css (+ compressed
					// siblings) or the resolver may keep serving a stale
					// variant. Derived from the choke-point path; each delete
					// re-checks containment. Variant slugs come from
					// Used_CSS::VIEWPORT_VARIANTS with a hardcoded fallback
					// when the class is unavailable.
					$used_css_dir  = dirname( $used_css_path );
					$variant_slugs = array( 'mobile', 'desktop' );
					if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {
						try {
							$from_const = \PerformanceOptimise\Inc\Used_CSS::VIEWPORT_VARIANTS;
							if ( is_array( $from_const ) && ! empty( $from_const ) ) {
								$filtered = array_values( array_filter( array_map( 'strval', $from_const ) ) );
								if ( ! empty( $filtered ) ) {
									$variant_slugs = $filtered;
								}
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
					foreach ( $variant_slugs as $variant_slug ) {
						if ( '' === $variant_slug ) {
							continue;
						}
						$variant_path = trailingslashit( $used_css_dir ) . 'used-css.' . $variant_slug . '.css';
						$this->delete_cache_files( $variant_path );
					}
				}

				if ( $bump_stats ) {
					Cache::bump_stats_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Surgically invalidate cache for a WooCommerce product, order, or coupon.
		 *
		 * Purges only the object's own permalink path (+ css/used-css sidecars)
		 * plus, for products, its product-category/tag archive paths and the
		 * shop page path. Never purges the home page, never calls
		 * clear_cache() (no full-cache wipe), and never schedules preload for
		 * Woo-excluded permalinks. Multisite-safe: per-site get_permalink() +
		 * domain-based get_file_path(), no cross-site purge.
		 *
		 * @since 2.0.0
		 * @param int    $object_id Woo object (product/order/coupon) ID.
		 * @param string $kind      Object kind: 'product', 'order', or 'coupon'.
		 * @return void
		 */
		public function invalidate_woo_object( int $object_id, string $kind ): void {
			$kind = sanitize_text_field( (string) $kind );
			if ( ! in_array( $kind, array( 'product', 'order', 'coupon' ), true ) ) {
				$kind = 'product';
			}
			if ( $object_id <= 0 ) {
				return;
			}

			$urls = array();
			try {
				$permalink = function_exists( 'get_permalink' ) ? get_permalink( $object_id ) : '';
				if ( is_string( $permalink ) && '' !== $permalink && ! is_wp_error( $permalink ) ) {
					$rel = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $permalink ) : (string) wp_parse_url( $permalink, PHP_URL_PATH );
					if ( is_string( $rel ) && '' !== $rel ) {
						$urls[] = $rel;
					}
				}

				if ( 'product' === $kind ) {
					// Product-category/tag archives for this product only.
					if ( function_exists( 'get_object_taxonomies' ) && function_exists( 'wp_get_object_terms' ) ) {
						$taxonomies = get_object_taxonomies( 'product', 'names' );
						if ( ! empty( $taxonomies ) ) {
							$terms = wp_get_object_terms( $object_id, $taxonomies );
							if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
								foreach ( (array) $terms as $term ) {
									if ( ! isset( $term->taxonomy ) ) {
										continue;
									}
									if ( function_exists( 'get_taxonomy' ) ) {
										$tax_obj = get_taxonomy( $term->taxonomy );
										if ( ! $tax_obj || empty( $tax_obj->public ) ) {
											continue;
										}
									}
									if ( function_exists( 'get_term_link' ) ) {
										$term_link = get_term_link( $term );
										if ( ! empty( $term_link ) && ! is_wp_error( $term_link ) ) {
											$term_rel = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $term_link ) : (string) wp_parse_url( (string) $term_link, PHP_URL_PATH );
											if ( is_string( $term_rel ) && '' !== $term_rel ) {
												$urls[] = $term_rel;
											}
										}
									}
								}
							}
						}
					}
					// Shop page path.
					if ( function_exists( 'wc_get_page_id' ) && function_exists( 'get_permalink' ) ) {
						try {
							$shop_id = (int) wc_get_page_id( 'shop' );
							if ( $shop_id > 0 ) {
								$shop_link = get_permalink( $shop_id );
								if ( is_string( $shop_link ) && '' !== $shop_link && ! is_wp_error( $shop_link ) ) {
									$shop_rel = function_exists( 'wp_make_link_relative' ) ? wp_make_link_relative( $shop_link ) : (string) wp_parse_url( $shop_link, PHP_URL_PATH );
									if ( is_string( $shop_rel ) && '' !== $shop_rel ) {
										$urls[] = $shop_rel;
									}
								}
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			/**
			 * Filter the surgical Woo invalidation URL list.
			 *
			 * @since 2.0.0
			 * @param string[] $urls      List of URL paths to purge.
			 * @param int      $object_id The Woo object ID being invalidated.
			 * @param string   $kind      Object kind ('product', 'order', 'coupon').
			 */
			$urls = (array) apply_filters( 'wppo_woo_invalidation_urls', $urls, $object_id, $kind ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Filter documented in docs/hooks.md.

			$sanitized          = array();
			$primary_normalized = '';
			// Track the object's own permalink separately from filtered extras
			// so a filter entry cannot redefine sidecar/regen decisions.
			// Shared-helper sanitization (single-decode, null-byte/dot-dot/
			// drive/UNC rejection) so encoded vectors never reach delete.
			if ( ! empty( $urls ) && is_string( $urls[0] ) ) {
				$primary_normalized = Util::sanitize_cache_url_path( $urls[0], '' !== $this->cache->invalidator_domain() ? $this->cache->invalidator_domain() : null );
			}
			foreach ( $urls as $u ) {
				$u = is_string( $u ) ? $u : (string) $u;
				// Accept full URLs/query strings from the filter: purge by path only.
				if ( function_exists( 'wp_parse_url' ) ) {
					$path_only = (string) wp_parse_url( $u, PHP_URL_PATH );
				} else {
					$path_only = (string) parse_url( $u, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( '' === trim( (string) $path_only, " \t\n\r\0\x0B/" ) && false !== strpos( $u, '?' ) ) {
					continue;
				}
				$sanitized_path = Util::sanitize_cache_url_path( $u, '' !== $this->cache->invalidator_domain() ? $this->cache->invalidator_domain() : null );
				// Homepage-vs-probe: keep the benign homepage (''), skip
				// hostile inputs that sanitize to ''.
				if ( '' === $sanitized_path && '' !== trim( (string) $path_only, " \t\n\r\0\x0B/" ) ) {
					continue;
				}
				$sanitized[] = $sanitized_path;
			}
			$sanitized = array_values( array_unique( $sanitized ) );

			if ( '' === $primary_normalized && ! empty( $sanitized ) ) {
				$primary_normalized = $sanitized[0];
			}
			foreach ( $sanitized as $url_path ) {
				$html_file_path = $this->cache->invalidator_get_file_path( $url_path, 'html' );
				if ( '' === $html_file_path ) {
					continue;
				}
				$norm            = wp_normalize_path( $html_file_path );
				$cache_root_norm = '' !== $this->cache->invalidator_cache_root_dir() ? wp_normalize_path( $this->cache->invalidator_cache_root_dir() ) : '';
				$abspath_norm    = defined( 'ABSPATH' ) ? wp_normalize_path( ABSPATH ) : '';
				if ( '' !== $cache_root_norm && 0 !== strpos( $norm, $cache_root_norm ) ) {
					continue;
				}
				if ( '' !== $abspath_norm && 0 !== strpos( $norm, $abspath_norm ) ) {
					continue;
				}
				$this->delete_cache_files( $html_file_path );
				$this->delete_role_variant_files( dirname( $html_file_path ) );
				$this->delete_no_cache_marker( $html_file_path );
				if ( $url_path === $primary_normalized ) {
					$css_file_path = $this->cache->invalidator_get_file_path( $url_path, 'css' );
					$used_css_path = $this->cache->invalidator_get_file_path( $url_path, 'used-css' );
					if ( '' !== $css_file_path ) {
						$this->delete_cache_files( $css_file_path );
					}
					if ( '' !== $used_css_path ) {
						$this->delete_cache_files( $used_css_path );
					}
				}
			}

			// Regenerate only when the primary permalink is cacheable (never
			// schedule preload work for Woo-excluded dynamic paths) and only
			// when there is something to purge — an empty list means URL
			// collection failed, so skip regen instead of warming a
			// potentially dynamic or non-existent URL.
			if ( empty( $sanitized ) ) {
				Cache::bump_stats_cache();
				return;
			}
			$skip_regen = false;
			if ( '' !== $primary_normalized && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_dynamic_path' ) ) {
				try {
					$skip_regen = Util::is_woo_dynamic_path( $primary_normalized );
				} catch ( \Throwable $e ) {
					unset( $e );
					$skip_regen = true;
				}
			}
			if ( ! $skip_regen && function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_rand' ) ) {
				if ( ! wp_next_scheduled( 'wppo_generate_static_page', array( $object_id ) ) ) {
					wp_schedule_single_event( time() + wp_rand( 0, 5 ), 'wppo_generate_static_page', array( $object_id ) );
				}
			}

			Cache::bump_stats_cache();
		}

		/**
		 * Map a derived asset path to its sibling last-good fallback path.
		 *
		 * Thin wrapper over {@see Util::get_purge_fallback_path_for()} so the
		 * purge/miss path can be unit-tested via the Cache surface. Returns
		 * `''` for non CSS/JS paths and for paths that already point at a
		 * fallback file (loop guard).
		 *
		 * @param string $file_path Absolute derived-asset path.
		 * @return string Sibling fallback path, or '' when not applicable.
		 *
		 * @since 2.2.0
		 */
		public static function get_purge_fallback_path( string $file_path ): string {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return '';
				}
				return Util::get_purge_fallback_path_for( $file_path );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Retain a last-good fallback copy before a derived file is purged.
		 *
		 * Thin wrapper over {@see Util::retain_purge_fallback_file()} (the
		 * single shared implementation — see also
		 * `Used_CSS::retain_purge_fallback()`). No-op when disabled, for
		 * non CSS/JS paths, on containment failure, or when the base file
		 * is missing/empty. Never throws.
		 *
		 * @param string $file_path The derived file about to be deleted.
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function retain_purge_fallback( string $file_path ): void {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return;
				}
				$fs = $this->cache->invalidator_filesystem();
				if ( ! $fs ) {
					return;
				}
				Util::retain_purge_fallback_file(
					$fs,
					function ( string $path ): bool {
						return $this->cache->invalidator_is_path_contained( $path );
					},
					$file_path
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Resolve a post-purge miss under the cache path to its fallback.
		 *
		 * Returns `served=true` with a redirect status (default 302 via
		 * {@see purge_fallback_redirect_status()}) and the fallback
		 * path/URL only when every guard holds: the gate is on, the requested path is
		 * contained, it is not itself a fallback file (no loops), the base
		 * file is missing, and a non-empty fallback sibling exists. A single
		 * throttled log entry is written per fallback directory per day so a
		 * sustained miss storm cannot flood the activity log. All other cases
		 * return `served=false` with status 404 (legacy hard-404 preserved,
		 * including when the gate is off). Never throws.
		 *
		 * @param string $requested_path Absolute requested derived-asset path.
		 * @return array{served: bool, status: int, fallback_path: string, location: string} Resolution.
		 *
		 * @since 2.2.0
		 */
		public function get_purge_fallback_response( string $requested_path ): array {
			$miss = array(
				'served'        => false,
				'status'        => 404,
				'fallback_path' => '',
				'location'      => '',
			);
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! Util::is_purge_fallback_enabled() ) {
					return $miss;
				}
				if ( '' === (string) $requested_path ) {
					return $miss;
				}
				// Domain tree and min tree ({root}/min/) are both servable:
				// the min tree lives outside the per-domain prefix so
				// is_path_contained() alone would miss it (layer divergence
				// with the Nginx snippet, which matches both).
				$in_domain = $this->cache->invalidator_is_path_contained( (string) $requested_path );
				$in_min    = $this->is_min_path( (string) $requested_path );
				if ( ! $in_domain && ! $in_min ) {
					return $miss;
				}
				$fallback = self::get_purge_fallback_path( (string) $requested_path );
				if ( '' === $fallback ) {
					return $miss;
				}
				$fallback_ok = $this->cache->invalidator_is_path_contained( $fallback ) || $this->is_min_path( $fallback );
				if ( ! $fallback_ok ) {
					return $miss;
				}
				$fs = $this->cache->invalidator_filesystem();
				if ( ! $fs ) {
					return $miss;
				}
				if ( $fs->exists( $requested_path ) ) {
					return $miss;
				}
				// Compressed-variant miss with a live base: Nginx forwards
				// `.css.gz`/`.br` misses, so a missing `.gz` beside a live
				// base must NOT 302 to a stale fallback — the live base (or
				// the normal 404 path) wins.
				if ( preg_match( '/\.(?:gz|br)$/i', (string) $requested_path ) ) {
					$stripped = (string) preg_replace( '/\.(?:gz|br)$/i', '', (string) $requested_path );
					if ( '' !== $stripped && $fs->exists( $stripped ) ) {
						return $miss;
					}
				}
				if ( ! $fs->exists( $fallback ) ) {
					return $miss;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! Util::is_purge_fallback_payload_valid( $fs, $fallback ) ) {
					return $miss;
				}
				$this->log_purge_fallback();
				$location = '';
				try {
					if ( '' !== $this->cache->invalidator_cache_root_dir() && '' !== $this->cache->invalidator_domain() && 0 === strpos( $fallback, $this->cache->invalidator_cache_root_dir() ) ) {
						$relative = ltrim( substr( $fallback, strlen( $this->cache->invalidator_cache_root_dir() ) ), '/' );
						if ( '' !== $relative && '' !== $this->cache->invalidator_cache_root_url() ) {
							$location = rtrim( $this->cache->invalidator_cache_root_url(), '/' ) . '/' . $relative;
						}
					} elseif ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'min_cache_base_dir' ) ) {
						// Min-tree fallback ({root}/min/...): map to the
						// content URL so the PHP resolver serves the same
						// tree the Nginx snippet matches (no layer divergence).
						$min_base = rtrim( Util::min_cache_base_dir(), '/' ) . '/';
						if ( '' !== $min_base && 0 === strpos( $fallback, $min_base ) ) {
							$relative = ltrim( substr( $fallback, strlen( $min_base ) ), '/' );
							if ( '' !== $relative && method_exists( 'PerformanceOptimise\Inc\Util', 'cached_content_url' ) ) {
								$location = Util::cached_content_url( 'cache/wppo/min/' . $relative );
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$location = '';
				}
				if ( '' === $location ) {
					return $miss;
				}
				return array(
					'served'        => true,
					'status'        => self::purge_fallback_redirect_status(),
					'fallback_path' => $fallback,
					'location'      => $location,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $miss;
			}
		}

		/**
		 * Resolve the redirect status for a fallback serve.
		 *
		 * Filterable via `wppo_purge_fallback_redirect_status` (default
		 * {@see PURGE_FALLBACK_REDIRECT_STATUS}); invalid values fall back
		 * to the constant. Never throws.
		 *
		 * @since 2.2.0
		 * @return int Redirect status code.
		 */
		public static function purge_fallback_redirect_status(): int {
			$status = self::PURGE_FALLBACK_REDIRECT_STATUS;
			try {
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters the redirect status used when serving a purge fallback.
					 *
					 * @since 2.2.0
					 * @param int $status Redirect status code.
					 */
					$filtered = apply_filters( 'wppo_purge_fallback_redirect_status', $status );
					$filtered = (int) $filtered;
					if ( $filtered >= 300 && $filtered <= 399 ) {
						$status = $filtered;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $status;
		}

		/**
		 * Build the headers for a fallback redirect (pure, testable).
		 *
		 * The `Cache-Control: no-store` line is deliberate: without it
		 * browsers/proxies may heuristically cache the 302 and keep
		 * redirecting to `fallback.css` after the base regenerates — a
		 * stale-serve window regeneration cannot clear client-side.
		 *
		 * @since 2.2.0
		 * @param string $location Absolute fallback URL.
		 * @param int    $status   Redirect status code.
		 * @return array{location: string, status: int, headers: string[]} Headers to send.
		 */
		public static function build_purge_fallback_headers( string $location, int $status ): array {
			if ( $status < 300 || $status > 399 ) {
				$status = self::PURGE_FALLBACK_REDIRECT_STATUS;
			}
			return array(
				'location' => $location,
				'status'   => $status,
				'headers'  => array( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' ),
			);
		}

		/**
		 * Serve a resolved purge fallback with a redirect.
		 *
		 * Thin sender for {@see get_purge_fallback_response()}: no-op unless
		 * the resolution carries `served=true` with a non-empty location.
		 * Uses `wp_safe_redirect()` when available, `header()` otherwise,
		 * and sends `Cache-Control: no-store` so the redirect itself is
		 * never cached past regeneration. The location is stripped of
		 * literal and encoded CR/LF before sending (defense-in-depth: it is
		 * internally built, but this method is public). Never throws;
		 * terminates the request on success via `exit` (skipped when the
		 * `WPPO_PURGE_FALLBACK_NO_EXIT` test seam is set, returning true).
		 *
		 * @param array{served: bool, status: int, fallback_path: string, location: string} $response Resolver output.
		 * @return bool True when the fallback was served (or would be, under the test seam).
		 *
		 * @since 2.2.0
		 */
		public function serve_purge_fallback_response( array $response ): bool {
			try {
				if ( empty( $response['served'] ) || empty( $response['location'] ) || ! is_string( $response['location'] ) ) {
					return false;
				}
				$location = str_replace( array( "\r", "\n", '%0d', '%0D', '%0a', '%0A' ), '', $response['location'] );
				if ( '' === $location ) {
					return false;
				}
				$status = isset( $response['status'] ) ? (int) $response['status'] : self::PURGE_FALLBACK_REDIRECT_STATUS;
				if ( $status < 300 || $status > 399 ) {
					$status = self::PURGE_FALLBACK_REDIRECT_STATUS;
				}
				$built = self::build_purge_fallback_headers( $location, $status );
				if ( defined( 'WPPO_PURGE_FALLBACK_NO_EXIT' ) && WPPO_PURGE_FALLBACK_NO_EXIT ) {
					return true;
				}
				// Headers already sent: fall through to the legacy 404 flow
				// instead of exiting with a truncated blank response.
				if ( headers_sent() ) {
					return false;
				}
				if ( function_exists( 'wp_safe_redirect' ) ) {
					foreach ( $built['headers'] as $header ) {
						header( $header, false ); // phpcs:ignore WordPress.PHP.HeaderURLColon.NoSpaceAfterColon,WordPress.WP.AlternativeFunctions.header_header -- Intentional no-store header format.
					}
					wp_safe_redirect( $built['location'], $built['status'] );
					exit;
				}
				foreach ( $built['headers'] as $header ) {
					header( $header, false ); // phpcs:ignore WordPress.PHP.HeaderURLColon.NoSpaceAfterColon,WordPress.WP.AlternativeFunctions.header_header -- Intentional no-store header format.
				}
				header( 'Location: ' . $built['location'], true, $built['status'] ); // phpcs:ignore WordPress.PHP.HeaderURLColon.NoSpaceAfterColon -- Intentional canonical header format.
				exit;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Serve the last-good fallback for Nginx `?wppo_purge_fallback=` misses.
		 *
		 * Paired with the Nginx snippet emitted by
		 * {@see Server_Rules::get_nginx_rules()}: `try_files` falls through to
		 * `index.php?wppo_purge_fallback=$uri` on a miss under the cache path,
		 * and this handler 302s to the sibling fallback when one was retained.
		 * No-op when the gate is off, when the query var is absent, or when no
		 * fallback resolves (legacy 404 flow continues). Never throws.
		 *
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function maybe_serve_purge_fallback(): void {
			try {
				if ( ! isset( $_GET['wppo_purge_fallback'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only miss handler, no state change.
					return;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! Util::is_purge_fallback_enabled() ) {
					return;
				}
				$raw = isset( $_GET['wppo_purge_fallback'] ) && is_string( $_GET['wppo_purge_fallback'] ) ? wp_unslash( $_GET['wppo_purge_fallback'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only miss routing; path-extracted and containment-checked below, never output.
				if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
					return;
				}
				$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $raw, PHP_URL_PATH ) : parse_url( $raw, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url unavailable.
				if ( ! is_string( $path ) || '' === $path ) {
					$path = (string) $raw;
				}
				if ( '' === $this->cache->invalidator_cache_root_dir() ) {
					return;
				}
				$marker = $this->cache->invalidator_cache_dir() . '/';
				$pos    = strpos( $path, $marker );
				if ( false === $pos ) {
					return;
				}
				$relative = ltrim( substr( $path, $pos + strlen( $marker ) ), '/' );
				if ( '' === $relative || false !== strpos( $relative, "\0" ) || false !== strpos( $relative, '..' ) || false !== strpbrk( $relative, "\r\n" ) || false !== stripos( $relative, '%0d' ) || false !== stripos( $relative, '%0a' ) ) {
					return;
				}
				// Single-decode re-check: PHP has already urldecoded $_GET,
				// so catch %252e-style double-encoding before path math.
				$decoded = rawurldecode( $relative );
				if ( false !== strpos( $decoded, "\0" ) || false !== strpos( $decoded, '..' ) || false !== strpbrk( $decoded, "\r\n" ) ) {
					return;
				}
				$requested = rtrim( $this->cache->invalidator_cache_root_dir(), '/' ) . '/' . $relative;
				$response  = $this->get_purge_fallback_response( $requested );
				if ( ! empty( $response['served'] ) ) {
					$this->serve_purge_fallback_response( $response );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Log a purge-fallback serve (global once-per-day throttle).
		 *
		 * Shares the single `wppo_purge_fallback_served` transient via
		 * {@see Util::purge_fallback_should_log()} so a sustained post-purge
		 * miss storm writes one activity-log row per day total (not one per
		 * directory). Fail-open: logging failures never affect serving.
		 *
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function log_purge_fallback(): void {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) || ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return;
				}
				if ( ! Util::purge_fallback_should_log() ) {
					return;
				}
				$message = function_exists( '__' ) ? __( 'Purge fallback: served last-good fallback after purge (302).', 'performance-optimisation' ) : 'Purge fallback: served last-good fallback after purge (302).';
				Log::add( $message );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Delete used-CSS file for a specific file path.
		 *
		 * @param string $file_path The used-css file path.
		 * @return bool True if successful (or not exists), false otherwise.
		 *
		 * @since 1.9.0
		 */
		public function delete_used_css_file( string $file_path ): bool {
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
		public function delete_no_cache_marker( string $html_file_path ): void {
			$marker = trailingslashit( dirname( $html_file_path ) ) . '.wppo-no-cache';
			if ( ! $this->cache->invalidator_is_path_contained( $marker ) ) {
				$this->cache->invalidator_log_traversal_probe( $html_file_path );
				return;
			}
			$this->delete_cache_files( $marker );
		}

		/**
		 * Delete cache files for a specific file path.
		 *
		 * The file path must originate from {@see safe_path_for_url()}; the
		 * base plus `.gz`/`.br` sibling containment checks below are
		 * defense-in-depth on resolved paths.
		 *
		 * @param string $file_path The file path.
		 * @return bool True if successful (or not exists), false otherwise.
		 *
		 * @since 1.1.0
		 */
		public function delete_cache_files( $file_path ): bool {
			if ( '' === (string) $file_path || ! $this->cache->invalidator_is_path_contained( (string) $file_path ) ) {
				$this->cache->invalidator_log_traversal_probe( (string) $file_path );
				return false;
			}
			$gzip_file_path = $file_path . '.gz';
			$br_file_path   = $file_path . '.br';
			// Sibling re-check (defense-in-depth): appending an extension can
			// never escape, but every unlink funnels through containment.
			if ( ! $this->cache->invalidator_is_path_contained( $gzip_file_path ) || ! $this->cache->invalidator_is_path_contained( $br_file_path ) ) {
				$this->cache->invalidator_log_traversal_probe( (string) $file_path );
				return false;
			}

			// Post-purge fallback (issue #1275): retain the last-good copy
			// before deleting so a first hit pre-regen stays styled (302).
			// No-op when the gate is off or the path is not a CSS/JS asset.
			$this->retain_purge_fallback( (string) $file_path );

			$fs = $this->cache->invalidator_filesystem();
			if ( $fs ) {
				$res1 = ! $fs->exists( $file_path ) || $fs->delete( $file_path );
				$res2 = ! $fs->exists( $gzip_file_path ) || $fs->delete( $gzip_file_path );
				$res3 = ! $fs->exists( $br_file_path ) || $fs->delete( $br_file_path );
				return $res1 && $res2 && $res3;
			}

			return false;
		}

		/**
		 * Delete all index-{hash}.html role-variant cache files in a directory.
		 *
		 * The directory must derive from a {@see safe_path_for_url()}-resolved
		 * path (e.g. `dirname()` of a choke-point result); the directory and
		 * per-file containment checks below are defense-in-depth.
		 *
		 * @param string $dir Directory to scan.
		 * @return void
		 * @since 1.9.0
		 */
		public function delete_role_variant_files( string $dir ): void {
			if ( '' === $dir || ! $this->cache->invalidator_is_path_contained( trailingslashit( $dir ) ) ) {
				$this->cache->invalidator_log_traversal_probe( $dir );
				return;
			}
			$fs = $this->cache->invalidator_filesystem();
			if ( ! $fs || ! $fs->is_dir( $dir ) ) {
				return;
			}

			$files = $fs->dirlist( $dir );
			if ( ! $files ) {
				return;
			}

			foreach ( $files as $file ) {
				if ( preg_match( '/^index-[a-f0-9]{12}\.html(\.gz|\.br)?$/', $file['name'] ) ) {
					$file_path = trailingslashit( $dir ) . $file['name'];
					// Per-file containment (defense-in-depth): the directory
					// itself came from a choke-point-resolved path, but every
					// unlink is re-checked so a crafted dirlist entry can never
					// escape the cache tree.
					if ( ! $this->cache->invalidator_is_path_contained( $file_path ) ) {
						$this->cache->invalidator_log_traversal_probe( $file_path );
						continue;
					}
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
		public function clear_cache( $url_path = null ): bool {
			$type = $url_path ? 'single_page' : 'all';
			do_action( 'wppo_before_cache_clear', $type, $url_path );

			if ( ! $this->cache->invalidator_filesystem() ) {
				return false;
			}

			if ( $url_path ) {
				$raw_clear_path = (string) $url_path;
				// Single choke-point: get_file_path() delegates to
				// safe_path_for_url() (single-decode, NUL/dot-dot/drive/UNC
				// rejection, filename allowlist, dual-prefix containment). A
				// benign homepage ('/') resolves to the homepage file; a
				// hostile input resolves to '' (probe already logged) and the
				// purge is refused. The triple is_path_contained() check below
				// stays as defense-in-depth on already-resolved paths.
				$html_file_path = $this->cache->invalidator_get_file_path( $raw_clear_path, 'html' );
				$css_file_path  = $this->cache->invalidator_get_file_path( $raw_clear_path, 'css' );
				$used_css_path  = $this->cache->invalidator_get_file_path( $raw_clear_path, 'used-css' );

				if ( empty( $html_file_path ) || empty( $css_file_path ) || empty( $used_css_path ) ) {
					return false;
				}

				if ( ! $this->cache->invalidator_is_path_contained( $html_file_path ) || ! $this->cache->invalidator_is_path_contained( $css_file_path ) || ! $this->cache->invalidator_is_path_contained( $used_css_path ) ) {
					$this->cache->invalidator_log_traversal_probe( $raw_clear_path );
					return false;
				}

				$res_html = $this->delete_cache_files( $html_file_path );
				$this->delete_role_variant_files( dirname( $html_file_path ) );
				$this->delete_no_cache_marker( $html_file_path );
				$res_css  = $this->delete_cache_files( $css_file_path );
				$res_used = $this->delete_used_css_file( $used_css_path );
				$result   = $res_html && $res_css && $res_used;
			} else {
				$result = $this->delete_all_cache_files();
			}

			if ( $result ) {
				// bump_stats_cache() also bumps the salted object-cache salt
				// (wppo_cache_last_cleared) when the drop-in is active.
				Cache::bump_stats_cache();
				update_option( 'wppo_cache_last_cleared_time', current_time( 'mysql' ), false );
				do_action( 'wppo_after_cache_clear', $type, $url_path );

				// LS-201: WPPO → LSCache purge sync (all or single URL). Gated
				// by is_litespeed() + is_lscache_active() + purgeSync via
				// LiteSpeed_Integration::is_purge_sync_enabled(); loop-safe via
				// wppo_litespeed_purge_lock (60s, blog-prefixed). Includes D/B/C/W/REST.
				if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {
					if ( null === $url_path || '' === $url_path ) {
						LiteSpeed_Integration::sync_purge_all_to_litespeed();
						// Also queue full fan-out for tag-based purge (stale scope for all).
						$tags_all = array( 'F', 'H', 'PGS', 'D', 'B', 'C', 'W', 'REST', 'HTTP.404', 'MIN', 'WPPO' );
						if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) ) {
							try {
								if ( is_multisite() ) {
									$bid = (int) get_current_blog_id();
									if ( $bid > 0 ) {
										$tags_all[] = 'B.' . $bid;
									}
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
						// Stale for full purge (double-cache stale prevention).
						LiteSpeed_Integration::queue_purge_tags( $tags_all, 'stale' );
					} else {
						$path_for_url = '/' . ltrim( (string) $url_path, '/' );
						LiteSpeed_Integration::sync_purge_url_to_litespeed( $path_for_url );
						// Single URL also gets D/B/C/W/REST stale/private split tags.
						$tags_single = array( 'D', 'B', 'C', 'W', 'REST', 'MIN', 'WPPO' );
						if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) ) {
							try {
								if ( is_multisite() ) {
									$bid = (int) get_current_blog_id();
									if ( $bid > 0 ) {
										$tags_single[] = 'B.' . $bid;
									}
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
						$scope = 'public';
						if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_private_request' ) ) {
							try {
								if ( LiteSpeed_Integration::is_private_request() ) {
									$scope = 'private';
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
						LiteSpeed_Integration::queue_purge_tags( $tags_single, $scope );
					}
				}

				// P0 fallback: when on LiteSpeed but LSCWP purge action not available,
				// purge OLS swap directory and emit X-LiteSpeed-Purge header with stale/private split.
				self::purge_litespeed_swap_fallback( $url_path );
			}

			return $result;
		}

		/**
		 * Recursively delete files under an allowlisted swap dir via PHP API.
		 *
		 * @since 2.2.0
		 * @param string $swap_dir Allowlisted directory.
		 * @return void
		 */
		public static function delete_swap_dir_files( string $swap_dir ): void {
			try {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $swap_dir, \FilesystemIterator::SKIP_DOTS ),
					\RecursiveIteratorIterator::CHILD_FIRST
				);
				foreach ( $iterator as $file ) {
					$path = wp_normalize_path( (string) $file->getPathname() );
					$root = wp_normalize_path( $swap_dir );
					// Containment: never delete outside the allowlisted root.
					if ( 0 !== strpos( $path, trailingslashit( $root ) ) && $path !== $root ) {
						continue;
					}
					if ( $file->isFile() || $file->isLink() ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_unlink,WordPress.WP.AlternativeFunctions.unlink_unlink
						@unlink( $path );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Fallback purge for LiteSpeed/OLS when LSCWP not active (P0).
		 *
		 * Clears allowlisted swap dirs via PHP API and emits
		 * X-LiteSpeed-Purge header. Gated by is_litespeed() and no-op when
		 * has_action('litespeed_purge_all') exists (handled via sync above).
		 * Filterable via wppo_litespeed_swap_purge.
		 *
		 * @since 2.0.0
		 * @param string|null $url_path URL path or null for all.
		 * @return void
		 */
		public static function purge_litespeed_swap_fallback( $url_path ): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) || ! LiteSpeed_Integration::is_litespeed() ) {
				return;
			}
			if ( has_action( 'litespeed_purge_all' ) ) {
				return;
			}
			/**
			 * Filter whether swap fallback purge should run.
			 *
			 * @since 2.0.0
			 * @param bool $enable Whether to run swap purge.
			 * @param string|null $url_path URL path being purged.
			 */
			$enable = (bool) apply_filters( 'wppo_litespeed_swap_purge', true, $url_path );
			if ( ! $enable ) {
				return;
			}
			$swap_dirs = array( '/tmp/lshttpd/swap', '/usr/local/lsws/cachedata' );
			foreach ( $swap_dirs as $swap_dir ) {
				// Allowlisted dirs only; containment asserted before delete.
				if ( ! in_array( $swap_dir, array( '/tmp/lshttpd/swap', '/usr/local/lsws/cachedata' ), true ) ) {
					continue;
				}
				if ( is_dir( $swap_dir ) && is_writable( $swap_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir,WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
					self::delete_swap_dir_files( $swap_dir );
				}
			}
			// Stale/private split: decide scope for header.
			$scope = 'public';
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_private_request' ) ) {
				try {
					if ( LiteSpeed_Integration::is_private_request() ) {
						$scope = 'private';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( ! headers_sent() ) {
				if ( null === $url_path || '' === $url_path ) {
					header( 'X-LiteSpeed-Purge: *' );
					// Also emit stale header for LS to know to serve stale while revalidate.
					if ( 'stale' === $scope || 'private' === $scope ) {
						header( 'X-LiteSpeed-Purge: stale,*', false );
					}
				} else {
					$clean = '/' . ltrim( (string) $url_path, '/' );
					$clean = str_replace( array( "\r", "\n" ), '', $clean );
					if ( '' !== $clean && 1 !== preg_match( '#^/[A-Za-z0-9/_\.\-?=&%]*$#', $clean ) ) {
						$clean = '/';
					}
					header( 'X-LiteSpeed-Purge: ' . $clean );
					if ( 'private' === $scope ) {
						header( 'X-LiteSpeed-Purge: private,' . $clean, false );
					} elseif ( 'stale' === $scope ) {
						header( 'X-LiteSpeed-Purge: stale,' . $clean, false );
					}
				}
			} elseif ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'queue_purge_tags' ) ) {
				// headers_sent fallback: queue via DB_QUEUE as stale/private.
					$tags = array( 'WPPO' );
				if ( null === $url_path || '' === $url_path ) {
					$tags[] = '*';
				} else {
					$clean  = str_replace( array( "\r", "\n" ), '', '/' . ltrim( (string) $url_path, '/' ) );
					$tags[] = $clean;
				}
					LiteSpeed_Integration::queue_purge_tags( $tags, 'stale' === $scope ? 'stale' : ( 'private' === $scope ? 'private' : 'public' ) );
			}
		}

		/**
		 * Whether a minify-cache directory may be recursively deleted.
		 *
		 * The min dirs live outside the per-domain tree, so
		 * {@see is_path_contained()} does not apply; instead the target must
		 * sit lexically under the plugin-owned min base dir
		 * (`{WP_CONTENT_DIR}/cache/wppo/min/`) and, when resolvable, its
		 * realpath must stay under the resolved base (a symlinked min dir
		 * pointing outside fails closed). Fail closed on any anomaly.
		 *
		 * @since 2.2.0
		 * @param string $dir Absolute directory candidate.
		 * @return bool True when the recursive delete may proceed.
		 */
		public function is_min_dir_allowed( string $dir ): bool {
			if ( '' === $dir || false !== strpos( $dir, "\0" ) || false !== strpos( $dir, '..' ) ) {
				return false;
			}
			try {
				if ( function_exists( 'wp_normalize_path' ) ) {
					$norm = wp_normalize_path( $dir );
				} else {
					$norm = str_replace( '\\', '/', $dir );
				}
				$base      = rtrim( Util::min_cache_base_dir(), '/' ) . '/';
				$dir_slash = rtrim( $norm, '/' ) . '/';
				if ( 0 !== strpos( $dir_slash, $base ) || $dir_slash === $base ) {
					return false;
				}
				$base_resolved = Util::resolve_realpath( rtrim( $base, '/' ) );
				$dir_resolved  = Util::resolve_realpath( rtrim( $norm, '/' ) );
				if ( null !== $base_resolved && null !== $dir_resolved ) {
					$base_dir = rtrim( $base_resolved, '/' ) . '/';
					$dir_dir  = rtrim( $dir_resolved, '/' ) . '/';
					if ( 0 !== strpos( $dir_dir, $base_dir ) ) {
						return false;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return true;
		}

		/**
		 * Whether a file path lives under the min tree (for the PHP resolver).
		 *
		 * The min tree (`{WP_CONTENT_DIR}/cache/wppo/min/...`) sits outside
		 * the per-domain prefix, so {@see is_path_contained()} cannot cover
		 * it; this routes min-tree misses through {@see is_min_dir_allowed()}
		 * on the parent dir instead. Fail-closed. Never throws.
		 *
		 * @since 2.2.0
		 * @param string $path Absolute file candidate.
		 * @return bool True when the path is min-tree-contained.
		 */
		public function is_min_path( string $path ): bool {
			try {
				if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, '..' ) ) {
					return false;
				}
				$parent = (string) preg_replace( '#/[^/]*$#', '', $path ) . '/';
				return $this->is_min_dir_allowed( $parent );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolve bounded purge-fallback snapshot limits.
		 *
		 * Defaults come from the `PURGE_FALLBACK_*` constants; the
		 * `wppo_purge_fallback_limits` filter may override any key
		 * (`max_files`, `max_depth`, `max_bytes`, `max_dirs`,
		 * `max_total_bytes`). Fail-closed: missing/invalid values fall back
		 * to the constant defaults, and filter values are clamped to sane
		 * ceilings so a buggy filter cannot force an OOM
		 * (`max_files × max_bytes` is otherwise buffered in memory). Result
		 * is memoized per request. Never throws.
		 *
		 * @since 2.2.0
		 * @return array{max_files: int, max_depth: int, max_bytes: int, max_dirs: int, max_total_bytes: int} Limits.
		 */
		public static function purge_fallback_limits(): array {
			static $memo = null;
			if ( null !== $memo ) {
				return $memo;
			}
			$defaults = array(
				'max_files'       => self::PURGE_FALLBACK_MAX_FILES,
				'max_depth'       => self::PURGE_FALLBACK_MAX_DEPTH,
				'max_bytes'       => self::PURGE_FALLBACK_MAX_BYTES,
				'max_dirs'        => self::PURGE_FALLBACK_MAX_DIRS,
				'max_total_bytes' => self::PURGE_FALLBACK_MAX_TOTAL_BYTES,
			);
			$ceilings = array(
				'max_files'       => 200,
				'max_depth'       => 20,
				'max_bytes'       => 2 * 1024 * 1024,
				'max_dirs'        => 2000,
				'max_total_bytes' => 8 * 1024 * 1024,
			);
			try {
				if ( ! function_exists( 'apply_filters' ) ) {
					$memo = $defaults;
					return $memo;
				}
				/**
				 * Filters purge-fallback snapshot bounds.
				 *
				 * Controls how many fallback files (max_files), how deep the
				 * walk goes (max_depth), the per-file size cap (max_bytes),
				 * how many directories are visited (max_dirs), and the total
				 * in-memory snapshot budget (max_total_bytes) kept on a
				 * full-cache wipe. Values are clamped to sane ceilings.
				 *
				 * @since 2.2.0
				 * @param array{max_files: int, max_depth: int, max_bytes: int, max_dirs: int, max_total_bytes: int} $defaults Snapshot bounds.
				 */
				$filtered = apply_filters( 'wppo_purge_fallback_limits', $defaults );
				if ( ! is_array( $filtered ) ) {
					$memo = $defaults;
					return $memo;
				}
				foreach ( $defaults as $key => $fallback ) {
					$value = isset( $filtered[ $key ] ) ? (int) $filtered[ $key ] : $fallback;
					if ( $value <= 0 ) {
						$value = $fallback;
					} else {
						$value = min( $value, $ceilings[ $key ] );
					}
					$defaults[ $key ] = $value;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$memo = $defaults;
			return $memo;
		}

		/**
		 * Throttled log when the snapshot bounds are hit.
		 *
		 * A full wipe restores only the bounded snapshot (see
		 * {@see purge_fallback_limits()}); without a signal larger sites
		 * would silently lose fallbacks. Reuses the per-day transient
		 * throttle so a wipe storm writes a single row. Never throws.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public function log_snapshot_cap(): void {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) || ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return;
				}
				$key = Util::transient_key( 'wppo_purge_fallback_snapshot_cap' );
				if ( function_exists( 'get_transient' ) && get_transient( $key ) ) {
					return;
				}
				if ( function_exists( 'set_transient' ) ) {
					$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
					set_transient( $key, 1, $ttl );
				}
				$message = function_exists( '__' ) ? __( 'Purge fallback: snapshot cap reached; some fallbacks not restored after full wipe.', 'performance-optimisation' ) : 'Purge fallback: snapshot cap reached; some fallbacks not restored after full wipe.';
				Log::add( $message );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Snapshot domain-tree fallbacks under a directory slated for wipe.
		 *
		 * Domain-tree entry point over {@see snapshot_purge_fallbacks_worker()}.
		 *
		 * @param string $dir Absolute domain directory about to be deleted.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 */
		public function snapshot_domain_purge_fallbacks( string $dir ): array {
			return $this->snapshot_purge_fallbacks_worker(
				$dir,
				function ( string $path ): bool {
					return $this->cache->invalidator_is_path_contained( $path );
				}
			);
		}

		/**
		 * Snapshot min-tree fallbacks under a directory slated for wipe.
		 *
		 * Min-tree entry point over {@see snapshot_purge_fallbacks_worker()}.
		 *
		 * @param string $dir Absolute min directory about to be deleted.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 */
		public function snapshot_min_purge_fallbacks( string $dir ): array {
			return $this->snapshot_purge_fallbacks_worker(
				$dir,
				function ( string $path ): bool {
					return $this->is_min_dir_allowed( (string) preg_replace( '#/[^/]*$#', '', $path ) . '/' );
				}
			);
		}

		/**
		 * Shared snapshot worker behind the domain/min entry points.
		 *
		 * @param string   $dir        Absolute directory about to be deleted.
		 * @param callable $is_allowed Containment validator: fn( string $path ): bool.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 */
		public function snapshot_purge_fallbacks_worker( string $dir, callable $is_allowed ): array {
			$snapshot = array();
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! Util::is_purge_fallback_enabled() ) {
					return $snapshot;
				}
				$fs = $this->cache->invalidator_filesystem();
				if ( ! $fs || ! method_exists( $fs, 'dirlist' ) || ! method_exists( $fs, 'get_contents' ) ) {
					return $snapshot;
				}
				$limits      = self::purge_fallback_limits();
				$max_files   = $limits['max_files'];
				$max_depth   = $limits['max_depth'];
				$max_bytes   = $limits['max_bytes'];
				$max_dirs    = $limits['max_dirs'];
				$max_total   = $limits['max_total_bytes'];
				$queue       = array( array( $dir, 0 ) );
				$head        = 0;
				$visited     = 0;
				$collected   = 0;
				$total_bytes = 0;
				$capped      = false;
				$queue_total = count( $queue );
				while ( $head < $queue_total && $collected < $max_files && $visited < $max_dirs && $total_bytes < $max_total ) {
					$current = $queue[ $head ];
					++$head;
					++$visited;
					$path  = (string) $current[0];
					$depth = (int) $current[1];
					if ( $depth > $max_depth ) {
						$capped = true;
						continue;
					}
					$entries = $fs->dirlist( $path );
					if ( ! is_array( $entries ) ) {
						continue;
					}
					foreach ( $entries as $name => $entry ) {
						if ( $collected >= $max_files || $total_bytes >= $max_total ) {
							$capped = true;
							break;
						}
						$full = rtrim( $path, '/' ) . '/' . $name;
						if ( ! empty( $entry['type'] ) && 'd' === $entry['type'] ) {
							$queue[]     = array( $full, $depth + 1 );
							$queue_total = count( $queue );
							continue;
						}
						$lower = strtolower( (string) $name );
						if ( ! in_array( $lower, self::PURGE_FALLBACK_NAMES, true ) ) {
							continue;
						}
						// Never follow a symlinked entry out of the tree:
						// validate the exact fallback basename and
						// containment before any stat/read.
						try {
							$allowed = $is_allowed( $full );
						} catch ( \Throwable $e ) {
							unset( $e );
							$allowed = false;
						}
						if ( false !== strpos( $full, "\0" ) || false !== strpos( $full, '..' ) || ! $allowed ) {
							continue;
						}
						// Size-check before the full read so oversize
						// fallbacks are skipped without loading them.
						if ( method_exists( $fs, 'size' ) ) {
							try {
								$size = (int) $fs->size( $full );
								if ( $size <= 0 || $size > $max_bytes || $total_bytes + $size > $max_total ) {
									$capped = true;
									continue;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
						try {
							$contents = $fs->get_contents( $full );
						} catch ( \Throwable $e ) {
							unset( $e );
							continue;
						}
						if ( ! is_string( $contents ) || '' === $contents || strlen( $contents ) > $max_bytes || $total_bytes + strlen( $contents ) > $max_total ) {
							$capped = true;
							continue;
						}
						$snapshot[ $full ] = $contents;
						$total_bytes      += strlen( $contents );
						++$collected;
					}
				}
				if ( $capped ) {
					// Throttled signal: the bounded guarantee means larger
					// sites keep only the first N fallbacks on full wipe.
					// Only the truncation flag logs — exactly filling a
					// quota on a complete walk is not a loss.
					$this->log_snapshot_cap();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $snapshot;
		}

		/**
		 * Snapshot last-good fallback files under a directory slated for wipe.
		 *
		 * Backward-compatible wrapper over the split domain/min entry
		 * points (kept for existing callers/tests): delegates to
		 * {@see snapshot_domain_purge_fallbacks()} or
		 * {@see snapshot_min_purge_fallbacks()} based on $min_tree.
		 * Never throws.
		 *
		 * @param string $dir      Absolute directory about to be deleted.
		 * @param bool   $min_tree Whether $dir lives under the min tree.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 */
		public function snapshot_purge_fallbacks( string $dir, bool $min_tree = false ): array {
			if ( $min_tree ) {
				return $this->snapshot_min_purge_fallbacks( $dir );
			}
			return $this->snapshot_domain_purge_fallbacks( $dir );
		}

		/**
		 * Retain live bases to sibling fallbacks before a full-tree wipe.
		 *
		 * A full wipe otherwise snapshots only the previous fallback
		 * generation (or nothing on first wipe), defeating the last-good
		 * guarantee. This bounded pre-pass copies each live `.css`/`.js`
		 * base to its sibling fallback via the shared
		 * {@see Util::retain_purge_fallback_file()} helper so the snapshot
		 * that follows captures the current generation. Uses the same
		 * limits (max_dirs walk budget) and never throws.
		 *
		 * @param string   $dir        Absolute directory about to be deleted.
		 * @param callable $is_allowed Containment validator: fn( string $path ): bool.
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function retain_live_bases_for_wipe( string $dir, callable $is_allowed ): void {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! Util::is_purge_fallback_enabled() ) {
					return;
				}
				$fs = $this->cache->invalidator_filesystem();
				if ( ! $fs || ! method_exists( $fs, 'dirlist' ) ) {
					return;
				}
				$limits      = self::purge_fallback_limits();
				$max_dirs    = $limits['max_dirs'];
				$queue       = array( array( $dir, 0 ) );
				$head        = 0;
				$visited     = 0;
				$max_depth   = $limits['max_depth'];
				$queue_total = count( $queue );
				while ( $head < $queue_total && $visited < $max_dirs ) {
					$current = $queue[ $head ];
					++$head;
					++$visited;
					$path  = (string) $current[0];
					$depth = (int) $current[1];
					if ( $depth > $max_depth ) {
						continue;
					}
					try {
						$entries = $fs->dirlist( $path );
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
					if ( ! is_array( $entries ) ) {
						continue;
					}
					foreach ( $entries as $name => $entry ) {
						$full = rtrim( $path, '/' ) . '/' . $name;
						if ( ! empty( $entry['type'] ) && 'd' === $entry['type'] ) {
							$queue[]     = array( $full, $depth + 1 );
							$queue_total = count( $queue );
							continue;
						}
						// Only live bases: skip existing fallbacks (loop
						// guard handled in the mapper) and non CSS/JS.
						$lower = strtolower( (string) $name );
						if ( in_array( $lower, self::PURGE_FALLBACK_NAMES, true ) ) {
							continue;
						}
						if ( ! preg_match( '/\.(?:css|js)(?:\.(?:gz|br))?$/i', $lower ) ) {
							continue;
						}
						Util::retain_purge_fallback_file( $fs, $is_allowed, $full );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Restore domain-tree fallbacks after a full-cache wipe.
		 *
		 * @param array<string, string> $snapshot Path => contents from {@see snapshot_purge_fallbacks()}.
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function restore_purge_fallbacks( array $snapshot ): void {
			$this->restore_snapshot(
				$snapshot,
				function ( string $path ): bool {
					return $this->cache->invalidator_is_path_contained( $path );
				}
			);
		}

		/**
		 * Restore min-tree fallbacks after a full-cache wipe.
		 *
		 * @param array<string, string> $snapshot Path => contents from {@see snapshot_purge_fallbacks()}.
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function restore_min_fallbacks( array $snapshot ): void {
			$this->restore_snapshot(
				$snapshot,
				function ( string $path ): bool {
					return $this->is_min_dir_allowed( (string) preg_replace( '#/[^/]*$#', '', $path ) . '/' );
				}
			);
		}

		/**
		 * Shared restore worker behind the domain/min entry points.
		 *
		 * Recreates parent directories via {@see Util::prepare_cache_dir()}
		 * and rewrites each captured fallback. Skips entries that fail the
		 * basename allowlist or the caller-supplied containment check so a
		 * snapshot can never plant files outside its tree. Never throws.
		 *
		 * @param array<string, string> $snapshot   Path => contents.
		 * @param callable              $is_allowed Containment validator: fn( string $path ): bool.
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function restore_snapshot( array $snapshot, callable $is_allowed ): void {
			if ( empty( $snapshot ) ) {
				return;
			}
			try {
				$fs = $this->cache->invalidator_filesystem();
				if ( ! $fs || ! method_exists( $fs, 'put_contents' ) ) {
					return;
				}
				foreach ( $snapshot as $path => $contents ) {
					try {
						if ( '' === (string) $path || ! is_string( $contents ) || '' === $contents ) {
							continue;
						}
						if ( false !== strpos( (string) $path, "\0" ) || false !== strpos( (string) $path, '..' ) ) {
							continue;
						}
						$base = strtolower( basename( (string) $path ) );
						if ( ! in_array( $base, self::PURGE_FALLBACK_NAMES, true ) ) {
							continue;
						}
						try {
							if ( ! $is_allowed( (string) $path ) ) {
								continue;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
							continue;
						}
						$parent = (string) preg_replace( '#/[^/]*$#', '', (string) $path );
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
							Util::prepare_cache_dir( $parent );
						}
						if ( $fs->exists( $path ) ) {
							continue;
						}
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'atomic_file_put_contents' ) ) {
							Util::atomic_file_put_contents( $fs, (string) $path, $contents );
							continue;
						}
						$fs->put_contents( $path, $contents, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Stage a snapshot to a temp dir outside the wipe tree.
		 *
		 * The in-memory snapshot alone is lost on a fatal/OOM/timeout
		 * between `delete()` and restore — exactly the outage the fallback
		 * exists to prevent. Spilling each entry to a temp file before the
		 * delete means the bytes survive the wipe even if this request
		 * dies (a later wipe restores from its own fresh snapshot; stale
		 * temp files are always cleaned up after a successful restore).
		 * Returns original-path => temp-path. Never throws.
		 *
		 * @param array<string, string> $snapshot Path => contents.
		 * @return array<string, string> Original path => staged temp path.
		 *
		 * @since 2.2.0
		 */
		public function stage_snapshot_to_temp( array $snapshot ): array {
			$staged = array();
			if ( empty( $snapshot ) ) {
				return $staged;
			}
			try {
				if ( function_exists( 'get_temp_dir' ) ) {
					$base = (string) get_temp_dir();
				} else {
					$base = (string) sys_get_temp_dir();
				}
				if ( '' === $base ) {
					return $staged;
				}
				$suffix = function_exists( 'wp_generate_password' ) ? wp_generate_password( 8, false ) : uniqid( '', false );
				$suffix = (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $suffix );
				if ( '' === $suffix ) {
					$suffix = (string) getmypid();
				}
				$staging = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/wppo-fb-' . $suffix;
				if ( function_exists( 'wp_mkdir_p' ) ) {
					wp_mkdir_p( $staging );
				} elseif ( ! is_dir( $staging ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Temp staging outside the wipe tree.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Temp staging outside the wipe tree.
					mkdir( $staging, 0700, true );
				}
				if ( ! is_dir( $staging ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Temp staging outside the wipe tree.
					return $staged;
				}
				$i = 0;
				foreach ( $snapshot as $path => $contents ) {
					if ( ! is_string( $contents ) || '' === $contents ) {
						continue;
					}
					$tmp = $staging . '/fb-' . $i . '.bin';
					++$i;
					$written = file_put_contents( $tmp, $contents, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					if ( false === $written || 0 === $written ) {
						continue;
					}
					$staged[ (string) $path ] = $tmp;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $staged;
		}

		/**
		 * Restore staged temp files back to their original paths.
		 *
		 * Reads each temp file (bounded by the snapshot limits) and
		 * delegates to {@see restore_snapshot()} with the caller-supplied
		 * validator, then removes the staging dir. Callers fall back to
		 * the in-memory snapshot when staging produced nothing. Never
		 * throws.
		 *
		 * @param array<string, string> $staged     Original path => staged temp path.
		 * @param callable              $is_allowed Containment validator: fn( string $path ): bool.
		 * @return void
		 *
		 * @since 2.2.0
		 */
		public function restore_staged_snapshot( array $staged, callable $is_allowed ): void {
			if ( empty( $staged ) ) {
				return;
			}
			try {
				$limits    = self::purge_fallback_limits();
				$max_bytes = $limits['max_bytes'];
				$snapshot  = array();
				$staging   = '';
				foreach ( $staged as $path => $tmp ) {
					if ( '' === (string) $tmp || ! is_file( (string) $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file
						continue;
					}
					if ( '' === $staging ) {
						$staging = (string) preg_replace( '#/[^/]*$#', '', (string) $tmp );
					}
					$size = filesize( (string) $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
					if ( false === $size || $size <= 0 || $size > $max_bytes ) {
						continue;
					}
					$contents = file_get_contents( (string) $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temp staging file, not a remote URL.
					if ( ! is_string( $contents ) || '' === $contents ) {
						continue;
					}
					$snapshot[ (string) $path ] = $contents;
				}
				$this->restore_snapshot( $snapshot, $is_allowed );
				if ( '' !== $staging && false !== strpos( $staging, 'wppo-fb-' ) ) {
					foreach ( array_values( $staged ) as $tmp ) {
						if ( is_file( (string) $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_file
							// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Local temp staging cleanup.
							unlink( (string) $tmp );
						}
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
					rmdir( $staging );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Delete all cache files.
		 *
		 * @return bool True if successful, false otherwise.
		 *
		 * @since 1.0.0
		 */
		public function delete_all_cache_files(): bool {
			$cache_dir = ( $this->cache->invalidator_cache_root_dir() . '/' . $this->cache->invalidator_domain() );
			$res1      = true;
			$res2      = true;

			$fs = $this->cache->invalidator_filesystem();

			if ( $fs && $fs->is_dir( $cache_dir ) ) {
				// Containment gate (fail closed): a symlinked domain
				// directory pointing outside the root must never be
				// followed by the recursive delete. The trailing slash is
				// appended explicitly (not via trailingslashit()) so the
				// dual-prefix directory check holds even where
				// trailingslashit() is filtered or stubbed.
				if ( ! $this->cache->invalidator_is_path_contained( rtrim( $cache_dir, '/' ) . '/' ) ) {
					$this->cache->invalidator_log_traversal_probe( $cache_dir );
					$res1 = false;
				} else {
					// Post-purge fallback (issue #1275): retain live bases
					// first (so the current generation becomes last-good),
					// then snapshot, stage to a temp dir outside the wipe
					// tree (so a fatal between delete and restore cannot
					// destroy both copies), then restore. No-op when off.
					$this->retain_live_bases_for_wipe(
						$cache_dir,
						function ( string $path ): bool {
							return $this->cache->invalidator_is_path_contained( $path );
						}
					);
					$domain_snapshot = $this->snapshot_domain_purge_fallbacks( $cache_dir );
					$domain_staged   = $this->stage_snapshot_to_temp( $domain_snapshot );
					if ( ! empty( $domain_staged ) ) {
						// Staging succeeded: drop the in-memory copy so the
						// bytes are held once (temp files), not twice.
						unset( $domain_snapshot );
					}
					$res1 = $fs->delete( $cache_dir, true );
					if ( ! empty( $domain_staged ) ) {
						$this->restore_staged_snapshot(
							$domain_staged,
							function ( string $path ): bool {
								return $this->cache->invalidator_is_path_contained( $path );
							}
						);
					} else {
						$this->restore_purge_fallbacks( isset( $domain_snapshot ) ? $domain_snapshot : array() );
					}
				}
			}

			// Minified JS/CSS files are blog-scoped so a network-wide clear cannot
			// wipe other sites' assets (whose min files may embed site-specific URLs).
			$min_dir = Util::min_cache_dir();

			if ( $fs && $fs->is_dir( $min_dir ) ) {
				if ( ! $this->is_min_dir_allowed( $min_dir ) ) {
					$this->cache->invalidator_log_traversal_probe( $min_dir );
					$res2 = false;
				} else {
					$this->retain_live_bases_for_wipe(
						$min_dir,
						function ( string $path ): bool {
							return $this->is_min_path( $path );
						}
					);
					$min_snapshot = $this->snapshot_min_purge_fallbacks( $min_dir );
					$min_staged   = $this->stage_snapshot_to_temp( $min_snapshot );
					if ( ! empty( $min_staged ) ) {
						unset( $min_snapshot );
					}
					$res2 = $fs->delete( $min_dir, true );
					if ( ! empty( $min_staged ) ) {
						$this->restore_staged_snapshot(
							$min_staged,
							function ( string $path ): bool {
								return $this->is_min_dir_allowed( (string) preg_replace( '#/[^/]*$#', '', $path ) . '/' );
							}
						);
					} else {
						$this->restore_min_fallbacks( isset( $min_snapshot ) ? $min_snapshot : array() );
					}
				}
			}

			// One-time idempotent cleanup of the pre-namespacing shared directories;
			// harmless on later clears and never touches other sites' scoped dirs.
			$legacy_min_dirs = array(
				Util::min_cache_base_dir() . '/css',
				Util::min_cache_base_dir() . '/js',
			);

			foreach ( $legacy_min_dirs as $legacy_min_dir ) {
				if ( $fs && $fs->is_dir( $legacy_min_dir ) ) {
					if ( ! $this->is_min_dir_allowed( $legacy_min_dir ) ) {
						$this->cache->invalidator_log_traversal_probe( $legacy_min_dir );
						continue;
					}
					$fs->delete( $legacy_min_dir, true );
				}
			}

			Used_CSS::delete_all_used_css();

			// Purge-coupled CCSS invalidation (issue #1102): a full cache
			// clear drops per-template critical-CSS variants alongside the
			// page cache and used-CSS so stale above-fold output cannot
			// survive a purge. Single-page clears leave CCSS alone (per
			// template, not per URL — wiping it there would only churn).
			// Fail-open: never fatal when the class is unavailable.
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {
					Critical_CSS::clear_all();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return $res1 && $res2;
		}
	}
}
