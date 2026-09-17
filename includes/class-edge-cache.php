<?php
/**
 * Edge HTML Cache Adapter — Cloudflare Workers / Bunny Edge.
 *
 * Deploys cache/wppo/{domain}/{path}/index.html semantics to the edge via
 * generated wrangler.toml + Cloudflare Worker (stale-while-revalidate) and
 * Bunny Edge (pull zone) configs. Purge is bridged via wppo_after_cache_clear
 * alongside CDN_Purger using Util::transient_key locks.
 *
 * TTFB target: <30ms global (edge) vs LS-local ~90ms. Host-agnostic: when
 * edge_cache.enabled is false (default) the plugin falls back to file cache
 * with zero behaviour change.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Edge_Cache' ) ) {
	/**
	 * Edge cache adapter.
	 *
	 * @since 2.0.0
	 */
	class Edge_Cache {

		/**
		 * Filter to control whether edge cache is enabled.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const FILTER_ENABLED = 'wppo_edge_cache_enabled';

		/**
		 * Filter for Cloudflare Worker content.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const FILTER_WORKER_CONTENT = 'wppo_edge_cache_worker_content';

		/**
		 * Filter for wrangler.toml content.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const FILTER_WRANGLER_CONTENT = 'wppo_edge_cache_wrangler_content';

		/**
		 * Filter for Bunny edge JS content.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		const FILTER_BUNNY_CONTENT = 'wppo_edge_cache_bunny_content';

		/**
		 * Upper bound for reading an edge template file into memory.
		 *
		 * The shipped templates are a few KB; anything larger (or
		 * missing/unreadable) falls back to the inline template instead
		 * of being read uncapped into memory.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const MAX_TEMPLATE_BYTES = 262144;

		/**
		 * Read an edge template file with a size cap.
		 *
		 * Returns the file contents, or an empty string when the file is
		 * missing, unreadable, empty, or oversized (callers fall back to
		 * their inline template).
		 *
		 * @since NEXT
		 * @param string $filename Template filename under templates/.
		 * @return string Template contents, or empty string on any failure.
		 */
		private static function read_template( string $filename ): string {
			try {
				// Allowlist guard: only the two shipped templates may be
				// read, so a future variable caller cannot turn this into
				// an arbitrary local read emitted into worker JS.
				$filename = basename( $filename );
				if ( ! in_array( $filename, array( 'cloudflare-worker.js', 'bunny-edge.js' ), true ) ) {
					return '';
				}
				$template_path = WPPO_PLUGIN_PATH . 'templates/' . $filename;
				if ( ! file_exists( $template_path ) || ! is_readable( $template_path ) ) {
					return '';
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesize() emits warnings on races; guarded with strict checks below.
				$size = @filesize( $template_path );
				if ( ! is_int( $size ) || $size <= 0 || $size > self::MAX_TEMPLATE_BYTES ) {
					return '';
				}
				$content = file_get_contents( $template_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				// Post-read cap: a concurrent replace/symlink swap between
				// stat and read must not load an oversized body.
				if ( ! is_string( $content ) || '' === $content || strlen( $content ) > self::MAX_TEMPLATE_BYTES ) {
					return '';
				}
				return $content;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether edge cache is enabled.
		 *
		 * Reads wppo_settings[edge_cache][enabled] (false default) and
		 * applies the wppo_edge_cache_enabled filter.
		 *
		 * @since 2.0.0
		 * @return bool True when edge cache is enabled.
		 */
		public static function is_enabled(): bool {
			$settings = Util::get_settings();
			$enabled  = false;
			if ( isset( $settings['edge_cache'] ) && is_array( $settings['edge_cache'] ) && isset( $settings['edge_cache']['enabled'] ) ) {
				$enabled = (bool) $settings['edge_cache']['enabled'];
			}
			/**
			 * Filters whether edge HTML cache is enabled.
			 *
			 * @since 2.0.0
			 * @param bool $enabled Whether edge cache is enabled.
			 */
			return (bool) apply_filters( self::FILTER_ENABLED, $enabled );
		}

		/**
		 * Whether edge purge has valid configuration.
		 *
		 * For Cloudflare: zone ID + token constant. For Bunny: pull zone ID + token constant.
		 * At least one provider configured is considered "configured" when edge is enabled;
		 * purge is still safe as no-op when unconfigured.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function is_configured(): bool {
			if ( ! self::is_enabled() ) {
				return false;
			}
			$settings = Util::get_settings();
			$cache    = isset( $settings['edge_cache'] ) && is_array( $settings['edge_cache'] ) ? $settings['edge_cache'] : array();

			// Cloudflare via existing CDN_Purger config is also valid.
			$has_cf    = ! empty( $cache['cloudflareZoneId'] ) && defined( 'WPPO_CLOUDFLARE_API_TOKEN' ) && '' !== (string) constant( 'WPPO_CLOUDFLARE_API_TOKEN' );
			$has_bunny = ! empty( $cache['bunnyPullZoneId'] ) && defined( 'WPPO_BUNNY_API_KEY' ) && '' !== (string) constant( 'WPPO_BUNNY_API_KEY' );

			// Also consider CDN_Purger Cloudflare config as fallback.
			if ( ! $has_cf ) {
				$cs     = isset( $settings['cache_settings'] ) && is_array( $settings['cache_settings'] ) ? $settings['cache_settings'] : array();
				$has_cf = ( isset( $cs['cdnPurgeService'] ) && 'cloudflare' === $cs['cdnPurgeService'] ) && ! empty( $cs['cloudflareZoneId'] ) && defined( 'WPPO_CLOUDFLARE_API_TOKEN' );
			}

			return $has_cf || $has_bunny;
		}

		/**
		 * Get adapter config for template generation.
		 *
		 * @since 2.0.0
		 * @return array{origin_url:string, cache_ttl:int, swr:int, provider:string}
		 */
		public static function get_config(): array {
			$origin   = Util::cached_home_url();
			$settings = Util::get_settings();
			$edge     = isset( $settings['edge_cache'] ) && is_array( $settings['edge_cache'] ) ? $settings['edge_cache'] : array();
			$ttl      = isset( $edge['ttl'] ) ? absint( $edge['ttl'] ) : 300;
			if ( $ttl <= 0 ) {
				$ttl = 300;
			}
			$swr      = isset( $edge['staleWhileRevalidate'] ) ? absint( $edge['staleWhileRevalidate'] ) : 86400;
			$provider = isset( $edge['provider'] ) ? sanitize_text_field( (string) $edge['provider'] ) : 'cloudflare';
			if ( ! in_array( $provider, array( 'cloudflare', 'bunny', 'both' ), true ) ) {
				$provider = 'cloudflare';
			}
			/**
			 * Filters edge cache adapter config.
			 *
			 * @since 2.0.0
			 * @param array $config Config array.
			 */
			$config = apply_filters(
				'wppo_edge_cache_config',
				array(
					'origin_url' => $origin,
					'cache_ttl'  => $ttl,
					'swr'        => $swr,
					'provider'   => $provider,
				)
			);
			return is_array( $config ) ? $config : array(
				'origin_url' => $origin,
				'cache_ttl'  => $ttl,
				'swr'        => $swr,
				'provider'   => $provider,
			);
		}

		/**
		 * Get Cloudflare Worker JS content.
		 *
		 * Replaces {{ORIGIN_URL}} / {{CACHE_TTL}} / {{SWR}} placeholders in
		 * templates/cloudflare-worker.js. Falls back to an inline template when
		 * the file is missing.
		 *
		 * @since 2.0.0
		 * @param array $config Optional override config.
		 * @return string Worker JS source.
		 */
		public static function get_worker_js( array $config = array() ): string {
			if ( empty( $config ) ) {
				$config = self::get_config();
			}
			$origin = $config['origin_url'] ?? Util::cached_home_url();
			$ttl    = isset( $config['cache_ttl'] ) ? (int) $config['cache_ttl'] : 300;
			$swr    = isset( $config['swr'] ) ? (int) $config['swr'] : 86400;

			$content = self::read_template( 'cloudflare-worker.js' );
			if ( '' === $content ) {
				// Fallback inline template with SWR semantics.
				$content = "export default {\n  async fetch(request, env, ctx) {\n    const cache = caches.default;\n    let response = await cache.match(request);\n    if (response) {\n      ctx.waitUntil(fetch(request).then(r=>{ if(r.ok){ const c=r.clone(); c.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}'); cache.put(request,c);} }).catch(()=>{}));\n      response.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');\n      response.headers.set('X-Edge-Cache','HIT');\n      return response;\n    }\n    const originRes = await fetch(request);\n    if (originRes.ok) {\n      const res = new Response(originRes.body, originRes);\n      res.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');\n      res.headers.set('X-Edge-Cache','MISS');\n      ctx.waitUntil(cache.put(request, res.clone()).catch(()=>{}));\n      return res;\n    }\n    return originRes;\n  }\n}\n";
			}

			$replacements = array(
				'{{ORIGIN_URL}}' => esc_url_raw( $origin ),
				'{{CACHE_TTL}}'  => (string) $ttl,
				'{{SWR}}'        => (string) $swr,
			);
			$content      = str_replace( array_keys( $replacements ), array_values( $replacements ), $content );

			/**
			 * Filters Cloudflare Worker JS content.
			 *
			 * @since 2.0.0
			 * @param string $content Worker JS source.
			 * @param array  $config  Adapter config.
			 */
			return (string) apply_filters( self::FILTER_WORKER_CONTENT, $content, $config );
		}

		/**
		 * Get wrangler.toml content for Cloudflare Workers deployment.
		 *
		 * @since 2.0.0
		 * @param array $config Optional override config.
		 * @return string wrangler.toml source.
		 */
		public static function get_wrangler_toml( array $config = array() ): string {
			if ( empty( $config ) ) {
				$config = self::get_config();
			}
			$origin = $config['origin_url'] ?? Util::cached_home_url();
			$ttl    = isset( $config['cache_ttl'] ) ? (int) $config['cache_ttl'] : 300;

			$host = wp_parse_url( $origin, PHP_URL_HOST );
			$name = sanitize_title( ! empty( $host ) ? $host : 'wppo-edge' );
			if ( '' === $name ) {
				$name = 'wppo-edge';
			}

			$toml = sprintf(
				"name = \"%s\"\nmain = \"cloudflare-worker.js\"\ncompatibility_date = \"2024-01-01\"\n\n[vars]\nORIGIN_URL = \"%s\"\nCACHE_TTL = %d\n",
				$name,
				esc_url_raw( $origin ),
				$ttl
			);

			/**
			 * Filters wrangler.toml content.
			 *
			 * @since 2.0.0
			 * @param string $toml   wrangler.toml source.
			 * @param array  $config Adapter config.
			 */
			return (string) apply_filters( self::FILTER_WRANGLER_CONTENT, $toml, $config );
		}

		/**
		 * Get Bunny Edge JS content (Edge Rules / pull zone adapter).
		 *
		 * Semantics mirror Cloudflare worker: cache/wppo/{domain}/{path}/index.html
		 * stale-while-revalidate at the Bunny edge.
		 *
		 * @since 2.0.0
		 * @param array $config Optional override config.
		 * @return string Bunny edge JS source.
		 */
		public static function get_bunny_edge_js( array $config = array() ): string {
			if ( empty( $config ) ) {
				$config = self::get_config();
			}
			$origin = $config['origin_url'] ?? Util::cached_home_url();
			$ttl    = isset( $config['cache_ttl'] ) ? (int) $config['cache_ttl'] : 300;
			$swr    = isset( $config['swr'] ) ? (int) $config['swr'] : 86400;

			// Try template file if present, else inline fallback.
			$content = self::read_template( 'bunny-edge.js' );
			if ( '' === $content ) {
				$content = "// Bunny Edge — stale-while-revalidate for WPPO cache semantics\n// Origin: {{ORIGIN_URL}} TTL={{CACHE_TTL}} SWR={{SWR}}\nasync function handleRequest(event){\n  const request=event.request;\n  const cache=caches.default;\n  let response=await cache.match(request);\n  if(response){\n    event.waitUntil(fetch(request).then(r=>{ if(r.ok){ const c=r.clone(); c.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}'); cache.put(request,c);} }).catch(()=>{}));\n    response.headers.set('X-Edge-Cache','HIT');\n    return response;\n  }\n  const originRes=await fetch(request);\n  if(originRes.ok){\n    const res=new Response(originRes.body, originRes);\n    res.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');\n    res.headers.set('X-Edge-Cache','MISS');\n    event.waitUntil(cache.put(request,res.clone()).catch(()=>{}));\n    return res;\n  }\n  return originRes;\n}\naddEventListener('fetch',e=>e.respondWith(handleRequest(e)));\n";
			}

			$content = str_replace(
				array( '{{ORIGIN_URL}}', '{{CACHE_TTL}}', '{{SWR}}' ),
				array( esc_url_raw( $origin ), (string) $ttl, (string) $swr ),
				$content
			);

			/**
			 * Filters Bunny edge JS content.
			 *
			 * @since 2.0.0
			 * @param string $content Bunny JS source.
			 * @param array  $config  Adapter config.
			 */
			return (string) apply_filters( self::FILTER_BUNNY_CONTENT, $content, $config );
		}
	}
}
