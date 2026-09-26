<?php
/**
 * REST cache service — static-HTML cache administration handlers.
 *
 * ARCH-011: the site-administration slice (`clear_cache()`,
 * `get_preload_status()`, `resume_preload()`, `purge_used_css_cache()`)
 * previously lived on the `Rest` 40-route registrar. The cluster shares one
 * responsibility (cache administration over the static HTML page cache +
 * preload queue), one trigger set (SPA cache buttons, preload UI, coupled
 * purge control), and one correctness contract (throttle gates,
 * traversal refusal, fail-open messaging) — so it lives here.
 *
 * `Rest` keeps thin same-signature proxies delegating to an eagerly
 * constructed instance of this class, so every route callback, every direct
 * caller (`RestTest`, Hook_Registry wiring via `Rest::register_routes()`),
 * and every `get_routes()` reflection stays byte-identical with zero caller
 * migration.
 *
 * State strategy (Option A — ARCH-004/005/006/007 bridge precedent): ALL
 * shared infra stays on `Rest` as the single source of truth
 * (`permission_callback()`, `get_schema_for_route()`,
 * `is_endpoint_throttled()`, `send_response()`, `$cache_dir`); this service
 * holds the owning `Rest` instance and reaches them through the `@internal`
 * `rest_*()` bridges — never a new write path. Handler bodies are verbatim
 * copies of the pre-extraction `Rest` methods (only `$this->` shared-infra
 * accesses re-pointed to `$this->owner->rest_*()`).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Rest_Cache' ) ) {
	/**
	 * Class Rest_Cache
	 *
	 * Owns the REST cache-administration handlers. Constructed with the
	 * owning `Rest` registrar so throttle verdicts, response envelopes, and
	 * the cache directory keep the exact semantics the bodies had on `Rest`
	 * (shared infra stays on `Rest`, accessed through the `@internal`
	 * `rest_*()` bridges — never a new write path).
	 *
	 * Always call via the `Rest` facade proxies, never directly on this
	 * service (route registration stays on `Rest` by design).
	 *
	 * @since 2.4.0
	 */
	final class Rest_Cache {

		/**
		 * Owning registrar (shared-infra owner: throttle, responses, cache dir).
		 *
		 * @since 2.4.0
		 * @var Rest
		 */
		private Rest $owner;

		/**
		 * Constructor.
		 *
		 * @since 2.4.0
		 * @param Rest $owner Owning registrar (shared-infra owner).
		 */
		public function __construct( Rest $owner ) {
			$this->owner = $owner;
		}

		/**
		 * Resumable sitemap preload progress plus bounded-cache cap status.
		 *
		 * Fail-open: queue/cache failures return idle/ok payloads, never fatal.
		 *
		 * @since 2.2.0
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 */
		public function get_preload_status( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$preload = array(
				'queued'      => 0,
				'done'        => 0,
				'failed'      => 0,
				'total'       => 0,
				'status'      => 'idle',
				'failed_urls' => array(),
				'cache_bytes' => 0,
				'cache_files' => 0,
				'stalled'     => false,
			);
			$cache   = array(
				'bytes'      => 0,
				'cap_bytes'  => 0,
				'state'      => 'ok',
				'enforce'    => true,
				'max_mb'     => 0,
				'files'      => 0,
				'cap_files'  => 0,
				'warn_files' => 0,
			);
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cron' ) && method_exists( 'PerformanceOptimise\Inc\Cron', 'get_preload_status' ) ) {
					$preload = Cron::get_preload_status();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'get_cache_cap_status' ) ) {
					$cache = Cache::get_cache_cap_status();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $this->owner->rest_send_response(
				array(
					'preload' => $preload,
					'cache'   => $cache,
				)
			);
		}

		/**
		 * Resume the sitemap preload queue (re-schedule queued + failed URLs).
		 *
		 * @since 2.2.0
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 */
		public function resume_preload( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( $this->owner->rest_throttle_hit( 'resume_preload', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$rescheduled = 0;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cron' ) && method_exists( 'PerformanceOptimise\Inc\Cron', 'resume_preload_queue' ) ) {
					$rescheduled = Cron::resume_preload_queue();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$status = array();
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cron' ) && method_exists( 'PerformanceOptimise\Inc\Cron', 'get_preload_status' ) ) {
					$status = Cron::get_preload_status();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $this->owner->rest_send_response(
				array(
					'rescheduled' => $rescheduled,
					'preload'     => $status,
				),
				true,
				200,
				$rescheduled > 0 ? __( 'Preload queue resumed.', 'performance-optimisation' ) : __( 'Nothing to resume.', 'performance-optimisation' )
			);
		}

		/**
		 * Clears the cache based on the given action.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @since 1.0.0
		 * @return \WP_REST_Response The response object.
		 */
		public function clear_cache( \WP_REST_Request $request ) {
			if ( $this->owner->rest_throttle_hit( 'clear_cache', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			$action = isset( $params['action'] ) ? sanitize_text_field( $params['action'] ) : '';
			$path   = isset( $params['path'] ) ? sanitize_text_field( $params['path'] ) : '';
			$group  = isset( $params['group'] ) ? sanitize_text_field( $params['group'] ) : '';

			// Handle cache group flushing.
			if ( ! empty( $group ) ) {
				$flushed = Cache::flush_group( $group );

				if ( ! $flushed ) {
					if ( function_exists( 'wp_cache_supports' ) && ! wp_cache_supports( 'flush_group' ) ) {
						Log::add(
							sprintf(
								/* translators: %s: The cache group name */
								__( 'Object cache does not support flush_group for %s — no action taken', 'performance-optimisation' ),
								$group
							)
						);
						return $this->owner->rest_send_response( array( 'flushed' => false ), false, 400, __( 'Object cache does not support flush_group — no action taken', 'performance-optimisation' ) );
					}

					Log::add(
						sprintf(
							/* translators: %s: The cache group name */
							__( 'Failed to flush cache group: %s', 'performance-optimisation' ),
							$group
						)
					);
					return $this->owner->rest_send_response( array( 'flushed' => $flushed ), false, 500, __( 'Failed to flush cache group.', 'performance-optimisation' ) );
				}

				Log::add(
					sprintf(
						/* translators: %s: The cache group name */
						__( 'Flushed cache group: %s', 'performance-optimisation' ),
						$group
					)
				);
				return $this->owner->rest_send_response( array( 'flushed' => $flushed ) );
			}

			$path = wp_normalize_path( $path );

			// Reject paths with directory traversal or outside the cache directory.
			// Empty path (clear all) has no traversal risk; realpath() returns false
			// when the cache directory does not exist yet, so it must not be validated.
			if ( '' !== $path ) {
				$normalized_cache_dir       = wp_normalize_path( $this->owner->rest_cache_dir() );
				$normalized_cache_dir_trail = trailingslashit( $normalized_cache_dir );
				// Normalized candidate for fallback when realpath() fails (uncached page).
				$candidate_path = wp_normalize_path( trailingslashit( $this->owner->rest_cache_dir() ) . ltrim( $path, '/\\' ) );

				$real_path = realpath( $this->owner->rest_cache_dir() . $path );
				if ( false !== $real_path ) {
					$normalized_real_path = wp_normalize_path( $real_path );

					$is_exact_match = ( $normalized_real_path === $normalized_cache_dir );
					$is_under_dir   = ( 0 === strpos( $normalized_real_path, $normalized_cache_dir_trail ) );

					if ( ! $is_exact_match && ! $is_under_dir ) {
						return $this->owner->rest_send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
				} else {
					// Fallback when realpath() returns false (uncached page or missing dir).
					// Validates via normalized string prefix so "Clear This Page" works before caching.
					// @since 2.0.0 Added wp_normalize_path fallback for uncached pages.
					$is_exact_match = ( $candidate_path === $normalized_cache_dir );
					$is_under_dir   = ( 0 === strpos( $candidate_path, $normalized_cache_dir_trail ) );

					// Canonicalize percent-encoding with a bounded decode loop
					// (max 5 passes until stable) so double-encoded traversal
					// (e.g. %252e%252e) is exposed before the `..`-segment
					// check instead of slipping through a single decode.
					// @since 2.3.0 Added decode loop for double-encoded traversal.
					$decoded = $candidate_path;
					for ( $i = 0; $i < 5; $i++ ) {
						$next = rawurldecode( $decoded );
						if ( $next === $decoded ) {
							break;
						}
						$decoded = $next;
					}
					// Normalize backslashes post-decode so %5c-encoded
					// separators (..%5c) cannot evade the `/`-split check.
					$decoded       = str_replace( '\\', '/', $decoded );
					$has_traversal = false;
					foreach ( explode( '/', $decoded ) as $segment ) {
						if ( '..' === $segment ) {
							$has_traversal = true;
							break;
						}
					}
					if ( ( ! $is_exact_match && ! $is_under_dir ) || $has_traversal ) {
						return $this->owner->rest_send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
				}
			}

			if ( 'clear_single_page_cache' === $action ) {
				$cleared = Cache::clear_cache( $path );
				if ( ! $cleared ) {
					return $this->owner->rest_send_response( null, false, 400, __( 'Failed to clear cache: Invalid path.', 'performance-optimisation' ) );
				}
				$url = Util::cached_home_url( $path );
				Log::add(
					sprintf(
						/* translators: %s: The URL of the page */
						__( 'Clear cache of <a href="%1$s">%2$s</a>', 'performance-optimisation' ),
						esc_url( $url ),
						esc_html( $url )
					)
				);
			} else {
				Cache::clear_cache();
				Log::add( __( 'Clear all cache', 'performance-optimisation' ) );
			}
			return $this->owner->rest_send_response( true );
		}

		/**
		 * Purge page cache and used CSS together via a single action (issue #1023).
		 *
		 * Shared purge path: delegates to Used_CSS::purge_coupled() which makes
		 * a guarded call into the Cache layer. Accepts an optional `path` for a
		 * single-page coupled purge; empty purges all.
		 *
		 * @param \WP_REST_Request $request The request object.
		 * @return \WP_REST_Response The response object.
		 * @since 2.0.0
		 */
		public function purge_used_css_cache( \WP_REST_Request $request ): \WP_REST_Response {
			if ( $this->owner->rest_throttle_hit( 'purge_used_css_cache', 5, 60 ) ) {
				$response = $this->owner->rest_send_response( null, false, 429, __( 'Too many requests. Please try again shortly.', 'performance-optimisation' ) );
				$response->header( 'Retry-After', '60' );
				return $response;
			}
			$params = $request->get_params();
			$path   = isset( $params['path'] ) ? sanitize_text_field( $params['path'] ) : null;

			$url_path = null;
			if ( null !== $path && '' !== $path ) {
				$canonical_host  = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_canonical_host' ) ? Util::get_canonical_host() : '';
				$normalized_path = function_exists( 'wp_normalize_path' ) ? wp_normalize_path( $path ) : (string) $path;
				if ( '' === $canonical_host ) {
					// Fail closed when the canonical host is unresolvable
					// (early boot/CLI/misconfigured home_url): refuse
					// absolute-form inputs instead of degrading to legacy
					// path-only extraction that would map a foreign host
					// onto the local tree.
					$target = ltrim( substr( ltrim( $normalized_path ), 0, strcspn( ltrim( $normalized_path ), '?#' ) ) );
					if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $target ) || 0 === strpos( $target, '//' ) || (bool) preg_match( '#^[a-zA-Z]:#', $target ) || 0 === strpos( $target, '\\\\' ) ) {
						return $this->owner->rest_send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
					$sanitized = Util::sanitize_cache_url_path( $normalized_path, null );
				} else {
					$sanitized = Util::sanitize_cache_url_path( $normalized_path, $canonical_host );
				}
				if ( '' === $sanitized ) {
					// '' is both the benign homepage ('/') and hostile
					// input: only 400 when the raw path component is
					// non-blank. A benign '/' purges just the homepage
					// ('/' stays non-empty so clear_cache() takes the
					// single-page branch instead of purge-all).
					$component = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $path, PHP_URL_PATH ) : parse_url( (string) $path, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
					if ( null === $component || false === $component ) {
						$component = $path;
					}
					if ( '' !== trim( (string) $component, " \t\n\r\0\x0B/" ) ) {
						return $this->owner->rest_send_response( null, false, 400, __( 'Invalid path provided.', 'performance-optimisation' ) );
					}
					$url_path = '/';
				} else {
					$url_path = $sanitized;
				}
			}

			$result  = Used_CSS::purge_coupled( $url_path );
			$page_ok = ! empty( $result['page_cache'] );
			$css_ok  = ! empty( $result['used_css'] );
			$data    = array(
				'page_cache' => $page_ok,
				'used_css'   => $css_ok,
			);

			if ( ! $page_ok && ! $css_ok ) {
				Log::add( __( 'Coupled purge failed: page cache and used CSS not purged.', 'performance-optimisation' ) );
				return $this->owner->rest_send_response( $data, false, 500, __( 'Failed to purge page cache and used CSS.', 'performance-optimisation' ) );
			}

			if ( $page_ok && $css_ok ) {
				Log::add( __( 'Coupled purge: page cache and used CSS purged.', 'performance-optimisation' ) );
				return $this->owner->rest_send_response(
					$data,
					true,
					200,
					__( 'Page cache and used CSS purged.', 'performance-optimisation' )
				);
			}

			$message = $page_ok
				? __( 'Page cache purged, but used CSS purge failed.', 'performance-optimisation' )
				: __( 'Used CSS purged, but page cache purge failed.', 'performance-optimisation' );
			Log::add( $message );

			return $this->owner->rest_send_response(
				$data,
				true,
				200,
				$message
			);
		}
	}
}
