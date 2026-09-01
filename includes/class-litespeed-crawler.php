<?php
/**
 * LiteSpeed Crawler — curl_multi variant-matrix preloader.
 *
 * Mirrors LSCWP crawler.cls.php BLACKLIST_THRESHOLD=3, lane throttling,
 * and variant matrix (Accept webp/avif × mobile/desktop × guest/role).
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_multi_init,WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_exec,WordPress.WP.AlternativeFunctions.curl_curl_multi_info_read,WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_close,WordPress.WP.AlternativeFunctions.curl_curl_close,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_getinfo

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {

	/**
	 * Class LiteSpeed_Crawler
	 *
	 * Provides curl_multi batch preloading with variant matrix and
	 * blacklist handling. All settings are opt-in via litespeed_integration.crawler.
	 *
	 * @since NEXT
	 */
	final class LiteSpeed_Crawler {

		/**
		 * Blacklist threshold (failures before skipping URL).
		 *
		 * Mirrors LSCWP crawler.cls.php:26 BLACKLIST_THRESHOLD=3.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const BLACKLIST_THRESHOLD = 3;

		/**
		 * Default concurrency (2-4).
		 *
		 * @since NEXT
		 * @var int
		 */
		public const DEFAULT_CONCURRENCY = 2;

		/**
		 * Transient prefix for blacklist counts.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const BLACKLIST_PREFIX = 'wppo_crawler_blacklist_';

		/**
		 * Get concurrency (2-4) filtered via wppo_crawler_concurrency.
		 *
		 * @since NEXT
		 * @return int
		 */
		public static function get_concurrency(): int {
			$options     = get_option( 'wppo_settings', array() );
			$concurrency = isset( $options['litespeed_integration']['crawler']['concurrency'] ) ? (int) $options['litespeed_integration']['crawler']['concurrency'] : self::DEFAULT_CONCURRENCY;
			$concurrency = max( 1, min( 4, $concurrency ) );
			/**
			 * Filter crawler concurrency.
			 *
			 * @since NEXT
			 * @param int $concurrency Concurrency 1-4.
			 */
			$concurrency = (int) apply_filters( 'wppo_crawler_concurrency', $concurrency );
			return max( 1, min( 4, $concurrency ) );
		}

		/**
		 * Get blacklist threshold filtered via wppo_crawler_blacklist_threshold.
		 *
		 * @since NEXT
		 * @return int
		 */
		public static function get_blacklist_threshold(): int {
			$options   = get_option( 'wppo_settings', array() );
			$threshold = isset( $options['litespeed_integration']['crawler']['blacklistThreshold'] ) ? (int) $options['litespeed_integration']['crawler']['blacklistThreshold'] : self::BLACKLIST_THRESHOLD;
			$threshold = max( 1, $threshold );
			/**
			 * Filter blacklist threshold.
			 *
			 * @since NEXT
			 * @param int $threshold Threshold.
			 */
			$threshold = (int) apply_filters( 'wppo_crawler_blacklist_threshold', $threshold );
			return max( 1, $threshold );
		}

		/**
		 * Whether URL is blacklisted (failures >= threshold).
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return bool
		 */
		public static function is_blacklisted( string $url ): bool {
			$key   = Util::transient_key( self::BLACKLIST_PREFIX . md5( $url ) );
			$count = (int) get_transient( $key );
			return $count >= self::get_blacklist_threshold();
		}

		/**
		 * Record failure for URL; increment blacklist counter.
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return void
		 */
		public static function record_failure( string $url ): void {
			$key   = Util::transient_key( self::BLACKLIST_PREFIX . md5( $url ) );
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, DAY_IN_SECONDS );
		}

		/**
		 * Clear blacklist for URL on success.
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return void
		 */
		public static function clear_blacklist( string $url ): void {
			$key = Util::transient_key( self::BLACKLIST_PREFIX . md5( $url ) );
			delete_transient( $key );
		}

		/**
		 * Build variant matrix for a URL.
		 *
		 * Variants: Accept webp/avif × mobile/desktop × guest/role_hash.
		 * Filterable via wppo_crawler_variants.
		 *
		 * @since NEXT
		 * @param string $url Base URL.
		 * @return array<int, array{url:string,headers:array<string,string>}>
		 */
		public static function build_variant_matrix( string $url ): array {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				return array();
			}
			$variants = array();

			$accepts = array( 'image/webp', 'image/avif' );
			$mobiles = array( 'desktop', 'mobile' );
			$guests  = array( 'guest', 'role' );

			foreach ( $accepts as $accept ) {
				foreach ( $mobiles as $mobile ) {
					foreach ( $guests as $guest ) {
						$headers = array(
							'Accept'     => $accept,
							'User-Agent' => 'mobile' === $mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
						);
						if ( 'guest' === $guest ) {
							$headers['Cookie'] = '';
						}
						$variants[] = array(
							'url'     => $url,
							'headers' => $headers,
						);
					}
				}
			}

			/**
			 * Filter crawler variant matrix.
			 *
			 * @since NEXT
			 * @param array  $variants Variant list.
			 * @param string $url Base URL.
			 */
			$variants = (array) apply_filters( 'wppo_crawler_variants', $variants, $url );
			return $variants;
		}

		/**
		 * Execute curl_multi batch for URLs (variant matrix).
		 *
		 * Each URL is expanded to variant matrix; requests are run via curl_multi
		 * with timeout 5s per handle and 15s wall-clock budget (mirroring get_sitemap_urls:555).
		 * Lane throttling via concurrency param; blacklist threshold 3 skips failing URLs.
		 *
		 * @since NEXT
		 * @param string[] $urls URLs to crawl.
		 * @param int|null $concurrency Concurrency override or null for setting.
		 * @return array{success:int,failed:int,skipped:int}
		 */
		public static function crawl_batch( array $urls, ?int $concurrency = null ): array {
			$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
			if ( empty( $urls ) ) {
				return array(
					'success' => 0,
					'failed'  => 0,
					'skipped' => 0,
				);
			}

			$concurrency = $concurrency ?? self::get_concurrency();
			$concurrency = max( 1, min( 4, $concurrency ) );

			// Expand to variant matrix, but for performance only crawl primary variant per URL
			// unless wppo_crawler_full_matrix filter true.
			$full_matrix = (bool) apply_filters( 'wppo_crawler_full_matrix', false );
			$requests    = array();
			foreach ( $urls as $url ) {
				if ( self::is_blacklisted( $url ) ) {
					continue;
				}
				$matrix = self::build_variant_matrix( $url );
				if ( $full_matrix ) {
					foreach ( $matrix as $variant ) {
						$requests[] = $variant;
					}
				} else {
					// Primary variant only (guest + desktop + webp) to avoid thundering herd.
					$requests[] = $matrix[0] ?? array(
						'url'     => $url,
						'headers' => array(),
					);
				}
			}

			if ( empty( $requests ) ) {
				$skipped = count( $urls ) - 0;
				return array(
					'success' => 0,
					'failed'  => 0,
					'skipped' => $skipped,
				);
			}

			// Prefer curl_multi if available, else fallback to wp_remote_get sequentially.
			if ( ! function_exists( 'curl_multi_init' ) ) {
				$success = 0;
				$failed  = 0;
				foreach ( $requests as $req ) {
					$resp = wp_remote_get(
						$req['url'],
						array(
							'timeout' => 5,
							'headers' => $req['headers'],
						)
					);
					if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 400 ) {
						self::record_failure( $req['url'] );
						++$failed;
					} else {
						self::clear_blacklist( $req['url'] );
						++$success;
					}
				}
				return array(
					'success' => $success,
					'failed'  => $failed,
					'skipped' => 0,
				);
			}

			$mh           = curl_multi_init();
			$handles      = array();
			$queue        = $requests;
			$active       = 0;
			$success      = 0;
			$failed       = 0;
			$deadline     = microtime( true ) + 15;
			$index_to_url = array();

			// Helper to add next handle.
			$add_next = function () use ( &$queue, &$handles, &$index_to_url, $mh ) {
				if ( empty( $queue ) ) {
					return;
				}
				$req = array_shift( $queue );
				$ch  = curl_init( $req['url'] );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
				curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
				$headers = array();
				foreach ( $req['headers'] as $k => $v ) {
					if ( '' !== $v ) {
						$headers[] = $k . ': ' . $v;
					}
				}
				if ( ! empty( $headers ) ) {
					curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
				}
				curl_multi_add_handle( $mh, $ch );
				$handles[]                 = $ch;
				$index_to_url[ (int) $ch ] = $req['url'];
			};

			// Prime initial batch.
			for ( $i = 0; $i < $concurrency && ! empty( $queue ); ++$i ) {
				$add_next();
			}

			do {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				$status = curl_multi_exec( $mh, $active );
				if ( $active ) {
					curl_multi_select( $mh, 1 );
				}
				// Process completed handles.
				while ( $info = curl_multi_info_read( $mh ) ) {
					$ch   = $info['handle'];
					$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
					$err  = curl_error( $ch );
					$url  = $index_to_url[ (int) $ch ] ?? '';
					if ( '' !== $url ) {
						if ( '' !== $err || $code >= 400 || 0 === $code ) {
							self::record_failure( $url );
							++$failed;
						} else {
							self::clear_blacklist( $url );
							++$success;
						}
					}
					curl_multi_remove_handle( $mh, $ch );
					curl_close( $ch );
					// Remove from handles.
					$key = array_search( $ch, $handles, true );
					if ( false !== $key ) {
						unset( $handles[ $key ] );
					}
					// Add next from queue if within deadline.
					if ( microtime( true ) < $deadline && ! empty( $queue ) ) {
						$add_next();
					}
				}
			} while ( $active && CURLM_OK === $status && microtime( true ) < $deadline );

			// Cleanup remaining.
			foreach ( $handles as $ch ) {
				curl_multi_remove_handle( $mh, $ch );
				curl_close( $ch );
			}
			curl_multi_close( $mh );

			return array(
				'success' => $success,
				'failed'  => $failed,
				'skipped' => 0,
			);
		}

		/**
		 * Lane wrapper for single URL preload (wraps process_url).
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return void
		 */
		public static function crawl_single( string $url ): void {
			if ( self::is_blacklisted( $url ) ) {
				return;
			}
			$variants = self::build_variant_matrix( $url );
			$primary  = $variants[0] ?? array(
				'url'     => $url,
				'headers' => array(),
			);
			$resp     = wp_remote_get(
				$primary['url'],
				array(
					'timeout' => 5,
					'headers' => $primary['headers'],
				)
			);
			if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 400 ) {
				self::record_failure( $url );
			} else {
				self::clear_blacklist( $url );
			}
		}
	}
}
