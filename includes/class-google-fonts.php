<?php
/**
 * Google Fonts Local Hosting class.
 *
 * Detects Google Fonts loaded from fonts.googleapis.com, downloads the CSS
 * and font files (woff2), and serves them locally to eliminate external
 * DNS lookups, improve GDPR compliance, and apply font-display: swap.
 *
 * @package PerformanceOptimise\Inc
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Google_Fonts' ) ) {

	/**
	 * Class Google_Fonts
	 *
	 * @since 2.0.0
	 */
	class Google_Fonts {

		/**
		 * Font cache subdirectory under WP_CONTENT_DIR /cache/wppo/.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private const FONTS_CACHE_DIR = '/cache/wppo/fonts';

		/**
		 * Chrome 120+ user-agent to request woff2 format from Google Fonts API.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

		/**
		 * Plugin settings.
		 *
		 * @var array
		 * @since 2.0.0
		 */
		private array $options;

		/**
		 * Font cache directory path.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private string $font_cache_dir;

		/**
		 * Font cache directory URL.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private string $font_cache_url;

		/**
		 * Constructor.
		 *
		 * @param array $options Plugin settings array.
		 * @since 2.0.0
		 */
		public function __construct( array $options ) {
			$this->options  = $options;
			$font_cache_dir = wp_normalize_path( WP_CONTENT_DIR . self::FONTS_CACHE_DIR );
			$font_cache_url = content_url( self::FONTS_CACHE_DIR );

			$this->font_cache_dir = is_string( $font_cache_dir ) ? $font_cache_dir : '';
			$this->font_cache_url = is_string( $font_cache_url ) ? $font_cache_url : '';
		}

		/**
		 * Failure-backoff TTL (seconds) for the Google Fonts fetch sentinels.
		 *
		 * Filterable via `wppo_google_fonts_backoff` so operators can tune the
		 * backoff for slow or persistently blocked endpoints.
		 *
		 * @since 2.0.0
		 * @return int
		 */
		public static function backoff_ttl(): int {
			/**
			 * Filters the Google Fonts failure-backoff TTL in seconds.
			 *
			 * @since 2.0.0
			 * @param int $ttl Default 300 (5 minutes).
			 */
			return max( 60, (int) apply_filters( 'wppo_google_fonts_backoff', 5 * MINUTE_IN_SECONDS ) );
		}

		/**
		 * Resolve the configured font-display value for self-hosted CSS.
		 *
		 * Defaults to `swap`; filterable via `wppo_font_display` for opt-out
		 * (return a falsy value to skip injection) or an alternate strategy.
		 * Fail-open: unknown values fall back to `swap`, any throwable
		 * returns `swap` so fonts never block rendering.
		 *
		 * @since NEXT
		 * @return string font-display value, or '' when injection is disabled.
		 */
		public static function get_font_display(): string {
			try {
				$display = 'swap';
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_font_display', $display );
					if ( empty( $filtered ) ) {
						return '';
					}
					$display = $filtered;
				}
				if ( ! is_string( $display ) ) {
					return 'swap';
				}
				$display = strtolower( trim( $display ) );
				$allowed = array(
					'swap'     => true,
					'block'    => true,
					'fallback' => true,
					'optional' => true,
					'auto'     => true,
				);
				return isset( $allowed[ $display ] ) ? $display : 'swap';
			} catch ( \Throwable $e ) {
				unset( $e );
				return 'swap';
			}
		}

		/**
		 * Reorder @font-face src lists so WOFF2 sources come first.
		 *
		 * Stable reorder only: no entries are added or dropped, so a
		 * woff-only block is untouched and metric-fallback + preload
		 * behavior is unchanged (no FOUT regression).
		 *
		 * @since NEXT
		 * @param string $css Stylesheet CSS.
		 * @return string CSS with woff2-first src ordering.
		 */
		public static function order_font_sources( string $css ): string {
			try {
				if ( '' === $css ) {
					return $css;
				}
				$reordered = preg_replace_callback(
					'/src\s*:\s*([^;{}]+);/i',
					static function ( $matches ) {
						$src = $matches[1];
						if ( false === stripos( $src, '.woff2' ) && false === stripos( $src, "format('woff2')" ) && false === stripos( $src, 'format("woff2")' ) ) {
							return $matches[0];
						}
						$parts = preg_split( '/,(?![^(]*\))/', $src );
						if ( ! is_array( $parts ) || count( $parts ) < 2 ) {
							return $matches[0];
						}
						$woff2 = array();
						$rest  = array();
						foreach ( $parts as $part ) {
							if ( false !== stripos( $part, '.woff2' ) || false !== stripos( $part, "format('woff2'" ) || false !== stripos( $part, 'format("woff2"' ) ) {
								$woff2[] = $part;
							} else {
								$rest[] = $part;
							}
						}
						if ( empty( $woff2 ) || empty( $rest ) ) {
							return $matches[0];
						}
						return 'src:' . implode( ',', array_merge( $woff2, $rest ) ) . ';';
					},
					$css
				);
				return is_string( $reordered ) ? $reordered : $css;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $css;
			}
		}

		/**
		 * Optionally keep only requested unicode-range subsets.
		 *
		 * Opt-in via `file_optimisation.fontSubset` (default off). Google CSS
		 * annotates each @font-face block with a `/* subset *\/` comment; when
		 * enabled, blocks whose comment is not in `fontSubsetSubsets`
		 * (comma-separated, default `latin`) are dropped. Fail-open: disabled
		 * by default, and any parse failure returns the CSS unchanged.
		 *
		 * @since NEXT
		 * @param string $css Stylesheet CSS.
		 * @return string Possibly subset-filtered CSS.
		 */
		public function maybe_subset_css( string $css ): string {
			try {
				if ( '' === $css ) {
					return $css;
				}
				$file_opt = $this->options['file_optimisation'] ?? array();
				if ( empty( $file_opt['fontSubset'] ) ) {
					return $css;
				}
				$raw  = strtolower( (string) ( $file_opt['fontSubsetSubsets'] ?? 'latin' ) );
				$raw  = (string) preg_replace( '/[^a-z0-9-,\s]/', '', $raw );
				$raw  = substr( $raw, 0, 200 );
				$keep = array();
				foreach ( explode( ',', $raw ) as $subset ) {
					$subset = trim( $subset );
					if ( '' !== $subset ) {
						$keep[ $subset ] = true;
					}
					if ( count( $keep ) >= 10 ) {
						break;
					}
				}
				if ( empty( $keep ) ) {
					return $css;
				}
				$filtered = preg_replace_callback(
					'~/\*\s*([a-z0-9-]+)\s*\*/\s*(@font-face\s*\{[^}]+\})~i',
					static function ( $matches ) use ( $keep ) {
						return isset( $keep[ strtolower( $matches[1] ) ] ) ? $matches[0] : '';
					},
					$css
				);
				if ( ! is_string( $filtered ) || '' === trim( $filtered ) ) {
					return $css;
				}
				// Only blocks carrying a subset comment were considered; if
				// none matched the pattern, keep the original CSS untouched.
				if ( trim( (string) preg_replace( '~/\*\s*[a-z0-9-]+\s*\*/\s*@font-face\s*\{[^}]+\}~i', '', $css ) ) === trim( $css ) ) {
					return $css;
				}
				return $filtered;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $css;
			}
		}

		/**
		 * Process a stylesheet <link> tag to replace Google Fonts URL with local cache.
		 *
		 * Hooked to style_loader_tag filter (priority 9, before minify_css at 10).
		 *
		 * @param mixed $tag    The link tag HTML.
		 * @param mixed $handle The stylesheet handle.
		 * @param mixed $href   The stylesheet URL (guarded with is_string: style_loader_tag can pass non-strings).
		 * @return string Modified link tag with local URL or original tag.
		 * @since 2.0.0
		 */
		public function process_style_tag( $tag, $handle, $href ): string {
			if ( ! is_string( $tag ) || '' === $tag ) {
				return is_string( $tag ) ? $tag : '';
			}
			if ( ! is_string( $handle ) ) {
				return $tag;
			}
			if ( is_admin() ) {
				return $tag;
			}
			if ( ! Util::is_cache_eligible_for_current_user(
				$this->options['cache_settings'] ?? array()
			) ) {
				return $tag;
			}

			$enabled = $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			if ( empty( $enabled ) ) {
				return $tag;
			}

			// Guard before wp_parse_url(): style_loader_tag callbacks can hand
			// back a non-string $href, and null reaches the string parameter
			// of wp_parse_url() as a PHP 8.1+ deprecation.
			if ( ! is_string( $href ) || '' === $href ) {
				return $tag;
			}

			// Exact host allowlist — not strpos (prevents evil.com/fonts.googleapis.com or fonts.googleapis.com.evil.com).
			// Caller: style_loader_tag filter; $href is the queued stylesheet URL.
			// @since 2.0.0.
			if ( 'fonts.googleapis.com' !== wp_parse_url( $href, PHP_URL_HOST ) ) {
				return $tag;
			}

			$local_url = $this->download_and_rewrite( $href );
			if ( '' === $local_url ) {
				return $tag;
			}

			return str_replace( $href, $local_url, $tag );
		}

		/**
		 * Process HTML buffer to intercept @import and inline <link> Google Fonts references.
		 *
		 * Catches patterns that bypass style_loader_tag, such as @import in CSS
		 * or inline <link> tags added via wp_head or theme templates.
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The modified HTML buffer.
		 * @since 2.0.0
		 */
		public function process_buffer( string $buffer ): string {
			$enabled = $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			if ( empty( $enabled ) ) {
				return $buffer;
			}

			// Replace <link> tags with Google Fonts URLs.
			// preg_replace_callback() returns null on regex failure — bail
			// with the original buffer so a PCRE error never wipes the page.
			$replaced = preg_replace_callback(
				'#<link\b[^>]*\bhref\s*=\s*["\']([^"\']*fonts\.googleapis\.com[^"\']*)["\'][^>]*>#is',
				function ( $matches ) {
					// Exact-host validation mirrors process_style_tag() so a
					// lookalike URL (evil.com?fonts.googleapis.com) is never
					// treated as a Google Fonts stylesheet (audit #888
					// finding 14); download_and_rewrite() re-validates too.
					if ( ! $this->is_google_fonts_url( $matches[1] ) ) {
						return $matches[0];
					}
					$local_url = $this->download_and_rewrite( $matches[1] );
					if ( '' !== $local_url ) {
						return str_replace( $matches[1], $local_url, $matches[0] );
					}
					return $matches[0];
				},
				$buffer
			);
			if ( ! is_string( $replaced ) ) {
				return $buffer;
			}
			$buffer = $replaced;

			// Replace @import url(...) and @import '...' with Google Fonts URLs.
			$replaced = preg_replace_callback(
				'#@import\s+(?:url\(\s*["\']?|["\'])([^"\';)]*fonts\.googleapis\.com[^"\';)]*)(?:["\']?\)\s*|["\'])\s*;#is',
				function ( $matches ) {
					if ( ! $this->is_google_fonts_url( $matches[1] ) ) {
						return $matches[0];
					}
					$local_url = $this->download_and_rewrite( $matches[1] );
					if ( '' !== $local_url ) {
						return str_replace( $matches[1], $local_url, $matches[0] );
					}
					return $matches[0];
				},
				$buffer
			);
			if ( ! is_string( $replaced ) ) {
				return $buffer;
			}
			$buffer = $replaced;

			return $buffer;
		}

		/**
		 * Whether a URL points at the Google Fonts CSS host (exact host match).
		 *
		 * Substring matches alone would let lookalike URLs such as
		 * `https://evil.com/?fonts.googleapis.com` enter the local-font
		 * pipeline. Mirrors the allowlist used by process_style_tag().
		 *
		 * @since 2.0.0
		 * @param string $url Candidate URL.
		 * @return bool True when the host is exactly fonts.googleapis.com.
		 */
		private function is_google_fonts_url( string $url ): bool {
			// Hostnames are DNS case-insensitive: FONTS.GOOGLEAPIS.COM must
			// match too (Part 2 review).
			return 'fonts.googleapis.com' === strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		}

		/**
		 * Action Scheduler hook for out-of-band Google Fonts downloads.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const AS_HOOK = 'wppo_google_fonts_download';

		/**
		 * Download Google Fonts CSS, fetch font files, rewrite URLs, and cache locally.
		 *
		 * Hot-path safe: this method never performs a synchronous remote
		 * fetch. On a cache miss it returns '' (the caller keeps the
		 * original tag) and queues a single deduped Action Scheduler job
		 * that performs the 20s CSS fetch plus the per-file downloads
		 * out-of-band; the local URL is served on the next request. A
		 * recent failure sentinel (wppo_gf_fail_*) still short-circuits the
		 * queue attempt. Mirrors the PageSpeed store_failure() sentinel.
		 *
		 * @param string $url The Google Fonts CSS URL.
		 * @return string Local CSS URL on success, empty string on failure/cache-miss.
		 * @since 2.0.0 Failure sentinel transient (wppo_gf_fail_*). Out-of-band download via Action Scheduler.
		 */
		public function download_and_rewrite( $url ): string {
			$url = $this->normalize_google_fonts_url( $url );
			if ( '' === $url ) {
				return '';
			}

			$key      = md5( $url );
			$css_file = $this->font_cache_dir . '/css/' . $key . '.css';
			$css_url  = $this->font_cache_url . '/css/' . $key . '.css';

			// Return cached CSS if it exists.
			if ( file_exists( $css_file ) ) {
				return $css_url;
			}

			$fail_key = Util::transient_key( 'wppo_gf_fail_' . $key );

			// A recent failure — skip the remote call this cycle (backoff).
			if ( get_transient( $fail_key ) ) {
				return '';
			}

			$this->maybe_queue_download( $key, $url );

			return '';
		}

		/**
		 * Queue an out-of-band Google Fonts download job (deduped by CSS key).
		 *
		 * Falls back to a WP-Cron single event when Action Scheduler is
		 * unavailable so the download still happens off the hot path.
		 *
		 * @since 2.0.0
		 * @param string $key CSS md5 key.
		 * @param string $url Normalized Google Fonts CSS URL.
		 * @return void
		 */
		private function maybe_queue_download( string $key, string $url ): void {
			// Action Scheduler (and wp_schedule_single_event) forward args
			// positionally via array_values()/do_action_ref_array(), so the
			// key/url pair must travel as ONE positional array element.
			// Passing the associative pair directly would split it and the
			// handler would only ever receive the md5 key.
			$payload = array(
				array(
					'key' => $key,
					'url' => $url,
				),
			);
			if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_enqueue_async_action' ) ) {
				try {
					if ( ! as_has_scheduled_action( self::AS_HOOK, $payload, 'performance_optimisation' ) ) {
						as_enqueue_async_action( self::AS_HOOK, $payload, 'performance_optimisation' );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return;
			}
			if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
				try {
					if ( false === wp_next_scheduled( self::AS_HOOK, $payload ) ) {
						wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::AS_HOOK, $payload );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		/**
		 * Action Scheduler / cron callback: fetch CSS + font files out-of-band.
		 *
		 * Contains the former synchronous body of download_and_rewrite():
		 * 20s CSS fetch, per-file gstatic downloads, font-display:swap
		 * injection, and atomic cache write. Never called from the
		 * output-buffer hot path.
		 *
		 * @since 2.0.0
		 * @param string $key CSS md5 key.
		 * @param string $url Normalized Google Fonts CSS URL.
		 * @return bool True when the local CSS file now exists.
		 */
		public function process_queued_download( string $key, string $url ): bool {
			$url = $this->normalize_google_fonts_url( $url );
			if ( '' === $url || '' === $key || md5( $url ) !== $key ) {
				return false;
			}

			$css_file = $this->font_cache_dir . '/css/' . $key . '.css';
			$css_url  = $this->font_cache_url . '/css/' . $key . '.css';
			if ( '' === $css_url ) {
				return false;
			}

			// Backed-off run must never delete the existing cached CSS: check
			// the failure sentinel before touching the good capped file.
			$fail_key = Util::transient_key( 'wppo_gf_fail_' . $key );
			if ( get_transient( $fail_key ) ) {
				return false;
			}

			if ( file_exists( $css_file ) ) {
				// Capped runs leave remote gstatic URLs in the cached CSS
				// (at most 3 files per run). When no remote URLs remain the
				// cache is converged — return early. Otherwise fall through
				// and regenerate: the write below overwrites $css_file via
				// WP_Filesystem, so no unlink is needed (avoids a TOCTOU
				// window where concurrent hot-path readers see a missing
				// file and queue duplicate work).
				// Read through WP_Filesystem (FTP/SSH-method hosts) with a
				// direct-read fallback; an unreadable cache is treated as
				// unconverged (fall through and regenerate) rather than
				// converged, so remote URLs can never get stuck.
				$cached           = null;
				$filesystem_probe = Util::init_filesystem();
				if ( $filesystem_probe && method_exists( $filesystem_probe, 'get_contents' ) ) {
					$probe = $filesystem_probe->get_contents( $css_file );
					if ( is_string( $probe ) ) {
						$cached = $probe;
					}
				}
				if ( null === $cached ) {
					$direct = file_get_contents( $css_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache staleness probe fallback when WP_Filesystem is unavailable; writes still go through WP_Filesystem.
					if ( is_string( $direct ) ) {
						$cached = $direct;
					}
				}
				if ( is_string( $cached ) && false === strpos( $cached, 'fonts.gstatic.com' ) ) {
					return true;
				}
				// Otherwise fall through and regenerate below: an unreadable
				// cache is treated as unconverged rather than converged, so
				// remote URLs can never get stuck.
			}

			// Fetch CSS from Google Fonts API (out-of-band only).
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 20,
					'user-agent' => self::CHROME_UA,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				set_transient( $fail_key, 1, self::backoff_ttl() );
				return false;
			}

			$css = wp_remote_retrieve_body( $response );
			if ( empty( $css ) ) {
				set_transient( $fail_key, 1, self::backoff_ttl() );
				return false;
			}

			// Success — clear any prior failure sentinel.
			delete_transient( $fail_key );

			// Ensure cache directories exist.
			Util::prepare_cache_dir( $this->font_cache_dir . '/css' );
			Util::prepare_cache_dir( $this->font_cache_dir . '/files' );

			// Rewrite url(...) to the local file only when it already
			// exists; the queued job downloads missing files out-of-band
			// and the original gstatic URL is kept until then so no
			// request ever blocks on a 30s streamed fetch.
			// Per-run cap: at most 3 missing files are fetched per job so
			// one Action Scheduler run never performs N sequential 30s
			// remote fetches (worker starvation). Files beyond the cap keep
			// their remote URL in the cached CSS — after the write below,
			// the key is re-queued while remote URLs remain so later runs
			// converge to fully local CSS without a manual cache clear.
			$downloads = 0;
			$succeeded = 0;
			$css       = preg_replace_callback(
				'#(url\()\s*(["\']?)(https://fonts\.gstatic\.com[^"\')]+)\2\s*\)#i',
				function ( $matches ) use ( &$downloads, &$succeeded ) {
					$file_url = $matches[3];
					$hash     = md5( $file_url );
					$local    = $this->font_cache_dir . '/files/' . $hash . '.woff2';

					if ( file_exists( $local ) ) {
						return 'url(' . $this->font_cache_url . '/files/' . $hash . '.woff2)';
					}

					if ( $downloads >= 3 ) {
						return $matches[0];
					}
					++$downloads;

					if ( $this->download_font_file( $file_url, $local ) && file_exists( $local ) ) {
						++$succeeded;
						return 'url(' . $this->font_cache_url . '/files/' . $hash . '.woff2)';
					}

					return $matches[0];
				},
				$css
			);

			if ( null === $css ) {
				return false;
			}

			// Serve WOFF2 first (WOFF fallback preserved) without touching
			// metric-fallback or preload behavior (no FOUT regression).
			$css = self::order_font_sources( $css );

			// Opt-in unicode-range subsetting (default off, fail-open).
			$css = $this->maybe_subset_css( $css );

			// Inject font-display: swap by default (opt-out via wppo_font_display).
			$font_display = self::get_font_display();
			if ( '' !== $font_display ) {
				$css = Minify\CSS::inject_font_display_swap( $css, $font_display );
			}

			// Save the rewritten CSS.
			$filesystem = Util::init_filesystem();
			if ( $filesystem ) {
				$filesystem->put_contents( $css_file, $css, FS_CHMOD_FILE );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $css_file, $css );
			}

			// Capped-run convergence: the per-run cap above can leave remote
			// gstatic URLs in the CSS just written. Re-queue the key while
			// remote URLs remain so later runs converge to fully local CSS
			// without requiring a manual cache clear. Convergence guard: only
			// re-queue when at least one download succeeded this run — if
			// gstatic fetches persistently fail, re-queueing every run would
			// churn Action Scheduler forever (the failure sentinel backoff
			// still applies to the next attempt).
			$has_remote = is_string( $css ) && false !== strpos( $css, 'fonts.gstatic.com' );
			$retry_key  = Util::transient_key( 'wppo_gf_retry_' . $key );
			if ( $has_remote && $succeeded > 0 ) {
				// Progress was made: reset the zero-success retry budget and
				// queue the follow-up run.
				delete_transient( $retry_key );
				$this->maybe_queue_download( $key, $url );
			} elseif ( $has_remote ) {
				// Zero-success stall guard: a run where every fetch failed
				// schedules no follow-up above, and download_and_rewrite()
				// short-circuits on file_exists() forever, leaving partial
				// remote CSS until a manual clear. Re-queue with a bounded
				// attempt counter so persistently failing hosts stop retrying.
				$attempts = (int) get_transient( $retry_key );
				if ( $attempts < 3 ) {
					set_transient( $retry_key, $attempts + 1, DAY_IN_SECONDS );
					$this->maybe_queue_download( $key, $url );
				}
			} else {
				delete_transient( $retry_key );
			}

			return file_exists( $css_file );
		}

		/**
		 * Static entry point for the Action Scheduler / WP-Cron hook.
		 *
		 * Rebuilds settings-owned state (cache dir/URL) from defaults so
		 * the job works without a constructed instance. Accepts either the
		 * args array scheduled by maybe_queue_download() or discrete args
		 * from direct calls/tests.
		 *
		 * @since 2.0.0
		 * @param array|string $args Args array with key/url, or the CSS key.
		 * @param string       $url  Normalized Google Fonts CSS URL (when $args is a key).
		 * @return void
		 */
		public static function process_queued_download_static( $args = array(), string $url = '' ): void {
			try {
				$key = '';
				if ( is_array( $args ) ) {
					$key = isset( $args['key'] ) ? (string) $args['key'] : '';
					$url = isset( $args['url'] ) ? (string) $args['url'] : $url;
				} else {
					$key = (string) $args;
				}
				if ( '' === $key || '' === $url ) {
					return;
				}
				$options  = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::get_settings() : array();
				$instance = new self( is_array( $options ) ? $options : array() );
				$instance->process_queued_download( $key, $url );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Action Scheduler callback wrapper (single-arg hook signature).
		 *
		 * @since 2.0.0
		 * @param array $args Job args with key/url.
		 * @return void
		 */
		public static function handle_queued_download_action( $args = array() ): void {
			self::process_queued_download_static( $args );
		}

		/**
		 * Normalize a Google Fonts URL.
		 *
		 * For v1 API URLs (/css), keeps the original URL to avoid format conversion
		 * issues with weight/style syntax. For v2 (/css2), returns as-is.
		 * Exact host check prevents substring bypass (e.g. evil.com/fonts.googleapis.com).
		 *
		 * Callers: {@see download_and_rewrite()} ← {@see process_style_tag()} and {@see process_buffer()}.
		 * Only fonts.googleapis.com is allowed for CSS; fonts.gstatic.com is handled
		 * separately in {@see download_font_file()}.
		 *
		 * @param string $url The raw URL.
		 * @return string Normalized URL or empty string if not a Google Fonts URL.
		 * @since 2.0.0
		 */
		private function normalize_google_fonts_url( $url ) {
			// Guard before wp_parse_url(): the parameter is untyped, and
			// passing null/false to its string parameter emits a
			// "Passing null to parameter" deprecation on PHP 8.1+ on every
			// request that reaches this path.
			if ( ! is_string( $url ) || '' === $url ) {
				return '';
			}
			// Exact host allowlist — replaces strpos substring check.
			// @since 2.0.0.
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( 'fonts.googleapis.com' !== $host && 'fonts.gstatic.com' !== $host ) {
				return '';
			}
			// CSS endpoint must be googleapis; gstatic URLs are font files, not CSS — reject them here.
			if ( 'fonts.gstatic.com' === $host ) {
				return '';
			}

			// Already css2 format — return as-is.
			if ( false !== strpos( $url, '/css2' ) ) {
				return $url;
			}

			// For v1 URLs, keep using the /css endpoint with the same URL to avoid
			// format conversion issues (v1 uses ':weight' syntax which differs from
			// v2's '@' syntax).
			return $url;
		}

		/**
		 * Download a single font file from Google's CDN.
		 *
		 * @param string $url   The font file URL.
		 * @param string $dest  Local destination path.
		 * @return bool True on success, false on failure.
		 * @since 2.0.0
		 */
		private function download_font_file( $url, $dest ) {
			// Guard before wp_parse_url(): the parameter is untyped and a
			// non-string/empty URL would raise a PHP 8.1+ deprecation.
			if ( ! is_string( $url ) || '' === $url ) {
				return false;
			}
			// Exact host allowlist — only fonts.gstatic.com may be fetched as a font file.
			// @since 2.0.0.
			if ( 'fonts.gstatic.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
				return false;
			}

			$fail_key = Util::transient_key( 'wppo_gf_fail_' . md5( $url ) );

			// A recent fetch failure — skip the remote call this cycle so a
			// blocked fonts.gstatic.com cannot stall page generation on every
			// request (audit #874 finding 3).
			if ( get_transient( $fail_key ) ) {
				return false;
			}

			$tmp = $dest . '.tmp.' . wp_rand();

			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 30,
					'user-agent' => self::CHROME_UA,
					'stream'     => true,
					'filename'   => $tmp,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				set_transient( $fail_key, 1, self::backoff_ttl() );
				return false;
			}

			if ( ! file_exists( $tmp ) ) {
				set_transient( $fail_key, 1, self::backoff_ttl() );
				return false;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesize() emits warnings on races; guarded with a false check below.
			$size = @filesize( $tmp );
			if ( false === $size || 0 === $size ) {
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				set_transient( $fail_key, 1, self::backoff_ttl() );
				return false;
			}

			$result = rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! $result ) {
				// The file $tmp is guaranteed to exist here due to prior checks,
				// but rename failure means it was not moved. Clean it up
				// unconditionally and back off — a persistent disk failure must
				// not re-issue the remote fetch per request.
				wp_delete_file( $tmp );
				set_transient( $fail_key, 1, self::backoff_ttl() );
			} else {
				// Success — clear any prior failure sentinel.
				delete_transient( $fail_key );
			}

			return file_exists( $dest );
		}

		/**
		 * Generate metric-matched fallback CSS for a font family (size-adjust etc).
		 *
		 * Uses hardcoded metrics for common Google Fonts to reduce CLS vs system fallback.
		 * Filterable via wppo_font_metric_fallback_css.
		 *
		 * @since 2.0.0
		 * @param string $family Font family name.
		 * @return string Fallback @font-face CSS or empty string.
		 */
		public function generate_metric_fallback( string $family ): string {
			$family_key = strtolower( trim( $family ) );
			// Common metrics table: size-adjust, ascent-override, descent-override, line-gap-override.
			$metrics = array(
				'inter'      => array(
					'size-adjust'       => '107%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				),
				'roboto'     => array(
					'size-adjust'       => '100%',
					'ascent-override'   => '92%',
					'descent-override'  => '24%',
					'line-gap-override' => '0%',
				),
				'open sans'  => array(
					'size-adjust'       => '105%',
					'ascent-override'   => '88%',
					'descent-override'  => '20%',
					'line-gap-override' => '0%',
				),
				'lato'       => array(
					'size-adjust'       => '100%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				),
				'montserrat' => array(
					'size-adjust'       => '107%',
					'ascent-override'   => '92%',
					'descent-override'  => '24%',
					'line-gap-override' => '0%',
				),
				'poppins'    => array(
					'size-adjust'       => '105%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				),
			);
			if ( ! isset( $metrics[ $family_key ] ) ) {
				// Generic fallback for unknown fonts.
				$metrics[ $family_key ] = array(
					'size-adjust'       => '100%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				);
			}
			$m = $metrics[ $family_key ];
			// Allowlist-sanitize the family name before injecting it into
			// the <style> block: esc_html() alone does not stop a crafted
			// family from breaking out of the quoted font-family value
			// (quotes, semicolons, braces are stripped here).
			$sanitized_family = preg_replace( '/[^A-Za-z0-9 \-]/', '', $family );
			if ( ! is_string( $sanitized_family ) ) {
				return '';
			}
			$sanitized_family = trim( substr( $sanitized_family, 0, 100 ) );
			if ( '' === $sanitized_family ) {
				return '';
			}
			$css = sprintf(
				"@font-face{font-family:'%s Fallback';src:local('Arial');size-adjust:%s;ascent-override:%s;descent-override:%s;line-gap-override:%s;}",
				$sanitized_family,
				$m['size-adjust'],
				$m['ascent-override'],
				$m['descent-override'],
				$m['line-gap-override']
			);
			/**
			 * Filters metric fallback CSS.
			 *
			 * @since 2.0.0
			 * @param string $css    Fallback CSS.
			 * @param string $family Font family.
			 */
			return (string) apply_filters( 'wppo_font_metric_fallback_css', $css, $family );
		}

		/**
		 * Inject metric-matched fallback style into buffer when enabled.
		 *
		 * @since 2.0.0
		 * @param string $buffer HTML buffer.
		 * @return string Modified buffer.
		 */
		public function inject_metric_fallback( string $buffer ): string {
			if ( empty( $this->options['file_optimisation']['fontMetricFallback'] ) ) {
				return $buffer;
			}
			// Extract font-family names from buffer Google Fonts links or cached CSS references.
			preg_match_all( '/font-family:\s*[\'"]?([^\'";,]+)[\'"]?/i', $buffer, $matches );
			if ( empty( $matches[1] ) ) {
				return $buffer;
			}
			$families     = array_unique( array_map( 'trim', $matches[1] ) );
			$fallback_css = '';
			foreach ( $families as $fam ) {
				$fallback_css .= $this->generate_metric_fallback( $fam ) . "\n";
			}
			// generate_metric_fallback() returns '' for fully-stripped
			// (e.g. non-Latin) family names, so test the trimmed join:
			// an '' comparison alone still injects an empty <style> of
			// newlines.
			if ( '' === trim( $fallback_css ) ) {
				return $buffer;
			}
			$style_tag = '<style id="wppo-font-fallback">' . $fallback_css . '</style>';
			// Inject before </head> if present, else prepend.
			if ( false !== stripos( $buffer, '</head>' ) ) {
				$out = preg_replace( '/<\/head>/i', $style_tag . '</head>', $buffer, 1 );
				if ( ! is_string( $out ) ) {
					return $buffer;
				}
				$buffer = $out;
			} else {
				$buffer = $style_tag . $buffer;
			}
			return $buffer;
		}

		/**
		 * Clear the entire Google Fonts cache directory.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public static function clear_font_cache() {
			$font_cache_dir = wp_normalize_path( WP_CONTENT_DIR . self::FONTS_CACHE_DIR );

			$filesystem = Util::init_filesystem();
			if ( $filesystem && $filesystem->is_dir( $font_cache_dir ) ) {
				$filesystem->delete( $font_cache_dir, true );
			}

			// Recreate empty directory structure.
			Util::prepare_cache_dir( $font_cache_dir . '/css' );
			Util::prepare_cache_dir( $font_cache_dir . '/files' );

			Log::add( 'Google Fonts cache cleared' );
		}
	}
}
