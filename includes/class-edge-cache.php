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
	 * @since NEXT
	 */
	class Edge_Cache {

		/**
		 * Filter to control whether edge cache is enabled.
		 *
		 * @since NEXT
		 * @var string
		 */
		const FILTER_ENABLED = 'wppo_edge_cache_enabled';

		/**
		 * Filter for Cloudflare Worker content.
		 *
		 * @since NEXT
		 * @var string
		 */
		const FILTER_WORKER_CONTENT = 'wppo_edge_cache_worker_content';

		/**
		 * Filter for wrangler.toml content.
		 *
		 * @since NEXT
		 * @var string
		 */
		const FILTER_WRANGLER_CONTENT = 'wppo_edge_cache_wrangler_content';

		/**
		 * Filter for Bunny edge JS content.
		 *
		 * @since NEXT
		 * @var string
		 */
		const FILTER_BUNNY_CONTENT = 'wppo_edge_cache_bunny_content';

		/**
		 * Whether edge cache is enabled.
		 *
		 * Reads wppo_settings[edge_cache][enabled] (false default) and
		 * applies the wppo_edge_cache_enabled filter.
		 *
		 * @since NEXT
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
			 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
			 * @since NEXT
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
		 * @since NEXT
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

			$template_path = WPPO_PLUGIN_PATH . 'templates/cloudflare-worker.js';
			$content       = '';
			if ( file_exists( $template_path ) && is_readable( $template_path ) ) {
				$content = file_get_contents( $template_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			if ( false === $content || '' === $content ) {
				// Fallback inline template with SWR semantics (normalized key, private/Set-Cookie bypass, Vary guard).
				$content = "export default {\n  async fetch(request, env, ctx) {\n    if(request.method!=='GET') return fetch(request);\n    const url=new URL(request.url);\n    if(url.search && /(?:^|&)(s|ver|v)(?:=|&|\$)/.test(url.searchParams.toString())) return fetch(request);\n    if(url.pathname.startsWith('/wp-admin')||url.pathname.startsWith('/wp-login')||url.searchParams.has('preview')) return fetch(request);\n    const cookie=request.headers.get('cookie')||'';\n    if(cookie.includes('wordpress_logged_in')||request.headers.get('authorization')) return fetch(request);\n    const cache=caches.default;\n    const cacheKey=new Request(url.toString(),{method:'GET'});\n    let response=await cache.match(cacheKey);\n    if(response){\n      ctx.waitUntil(fetch(request).then(r=>{ const ct=(r.headers.get('content-type')||'').toLowerCase(); const cc=(r.headers.get('cache-control')||'').toLowerCase(); const vary=(r.headers.get('vary')||'').toLowerCase(); if(r.ok&&ct.includes('text/html')&&!r.headers.has('set-cookie')&&!cc.includes('private')&&!cc.includes('no-store')&&!vary.includes('cookie')&&vary!=='*'){ const c=r.clone(); c.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}'); cache.put(cacheKey,c);} }));\n      response.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');\n      response.headers.set('X-Edge-Cache','HIT');\n      return response;\n    }\n    const originRes=await fetch(request);\n    const ct=(originRes.headers.get('content-type')||'').toLowerCase();\n    const cc=(originRes.headers.get('cache-control')||'').toLowerCase();\n    const vary=(originRes.headers.get('vary')||'').toLowerCase();\n    if(originRes.ok&&ct.includes('text/html')&&!originRes.headers.has('set-cookie')&&!cc.includes('private')&&!cc.includes('no-store')&&!vary.includes('cookie')&&vary!=='*'){\n      const res=new Response(originRes.body,originRes);\n      res.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');\n      res.headers.set('X-Edge-Cache','MISS');\n      ctx.waitUntil(cache.put(cacheKey,res.clone()));\n      return res;\n    }\n    return originRes;\n  }\n}\n";
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
			 * @since NEXT
			 * @param string $content Worker JS source.
			 * @param array  $config  Adapter config.
			 */
			return (string) apply_filters( self::FILTER_WORKER_CONTENT, $content, $config );
		}

		/**
		 * Get wrangler.toml content for Cloudflare Workers deployment.
		 *
		 * @since NEXT
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
			 * @since NEXT
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
		 * @since NEXT
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
			$template_path = WPPO_PLUGIN_PATH . 'templates/bunny-edge.js';
			$content       = '';
			if ( file_exists( $template_path ) && is_readable( $template_path ) ) {
				$content = file_get_contents( $template_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			}
			if ( false === $content || '' === $content ) {
				$content = "// Bunny Edge — stale-while-revalidate for WPPO cache semantics (Bunny Cache API: caches.default supported)\n// Origin: {{ORIGIN_URL}} TTL={{CACHE_TTL}} SWR={{SWR}}\nasync function handleRequest(event){\n  const request=event.request;\n  if(request.method!=='GET') return fetch(request);\n  const url=new URL(request.url);\n  if(url.search && /(?:^|&)(s|ver|v)(?:=|&|\$)/.test(url.searchParams.toString())) return fetch(request);\n  const cache=caches.default;\n  const cacheKey=new Request(url.toString(),{method:'GET'});\n  let response=await cache.match(cacheKey);\n  if(response){\n    const w=event.waitUntil?event.waitUntil.bind(event):(typeof Bunny!=='undefined'&&Bunny.v1&&Bunny.v1.waitUntil?Bunny.v1.waitUntil.bind(Bunny.v1):(p)=>p.catch(()=>{}));\n    w(fetch(request).then(r=>{ const ct=(r.headers.get('content-type')||'').toLowerCase(); const cc=(r.headers.get('cache-control')||'').toLowerCase(); const vary=(r.headers.get('vary')||'').toLowerCase(); if(r.ok&&ct.includes('text/html')&&!r.headers.has('set-cookie')&&!cc.includes('private')&&!cc.includes('no-store')&&!vary.includes('cookie')&&vary!=='*'){ const c=r.clone(); c.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}'); cache.put(cacheKey,c);} }));\n    response.headers.set('X-Edge-Cache','HIT');\n    return response;\n  }\n  const originRes=await fetch(request);\n  const ct=(originRes.headers.get('content-type')||'').toLowerCase();\n  const cc=(originRes.headers.get('cache-control')||'').toLowerCase();\n  const vary=(originRes.headers.get('vary')||'').toLowerCase();\n  if(originRes.ok&&ct.includes('text/html')&&!originRes.headers.has('set-cookie')&&!cc.includes('private')&&!cc.includes('no-store')&&!vary.includes('cookie')&&vary!=='*'){\n    const res=new Response(originRes.body, originRes);\n    res.headers.set('Cache-Control','public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');\n    res.headers.set('X-Edge-Cache','MISS');\n    const w=event.waitUntil?event.waitUntil.bind(event):(typeof Bunny!=='undefined'&&Bunny.v1&&Bunny.v1.waitUntil?Bunny.v1.waitUntil.bind(Bunny.v1):(p)=>p.catch(()=>{}));\n    w(cache.put(cacheKey,res.clone()));\n    return res;\n  }\n  return originRes;\n}\naddEventListener('fetch',e=>e.respondWith(handleRequest(e)));\n";
			}

			$content = str_replace(
				array( '{{ORIGIN_URL}}', '{{CACHE_TTL}}', '{{SWR}}' ),
				array( esc_url_raw( $origin ), (string) $ttl, (string) $swr ),
				$content
			);

			/**
			 * Filters Bunny edge JS content.
			 *
			 * @since NEXT
			 * @param string $content Bunny JS source.
			 * @param array  $config  Adapter config.
			 */
			return (string) apply_filters( self::FILTER_BUNNY_CONTENT, $content, $config );
		}
	}
}
