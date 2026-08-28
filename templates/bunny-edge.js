/**
 * Bunny Edge — WPPO Edge HTML Cache Adapter
 *
 * Bunny CDN Edge Scripting Cache API (caches.default) — stale-while-revalidate
 * for cache/wppo/{domain}/{path}/index.html semantics. Requires Bunny Edge
 * Scripting with Cache API (public preview 2026-07-28). `caches.default` is
 * supported (CacheStorage: caches.default + caches.open) — see
 * https://bunny.net/docs/scripting/cache — legacy note: older Bunny Perma-Cache
 * pull zone rules do NOT support caches.default; use this script only with
 * Edge Scripting enabled and "Run script before cache" toggled for per-request
 * control.
 *
 * Placeholders replaced by Edge_Cache::get_bunny_edge_js():
 *   {{ORIGIN_URL}} — e.g. https://example.com
 *   {{CACHE_TTL}}   — cache max-age seconds
 *   {{SWR}}         — stale-while-revalidate seconds
 *
 * Deploy: Bunny Dashboard → Pull Zone → Edge Scripting → paste output of
 * Edge_Cache::get_bunny_edge_js() (or upload this template with placeholders
 * replaced). For SDK variant, wrap with `BunnySDK.net.http.serve()` and use
 * `Bunny.v1.waitUntil` instead of `event.waitUntil`.
 *
 * @since NEXT
 */

async function handleRequest(event) {
  const request = event.request;
  if (request.method !== 'GET') {
    return fetch(request);
  }
  const url = new URL(request.url);
  if (url.search && /(?:^|&)(s|ver|v)(?:=|&|$)/.test(url.searchParams.toString())) {
    return fetch(request);
  }
  // Bunny Cache API — caches.default is supported (see docs/scripting/cache).
  const cache = caches.default;
  // Normalize cache key to URL-only GET to avoid Vary fragmentation by
  // request headers (Cookie, User-Agent, etc.).
  const cacheKey = new Request(url.toString(), { method: 'GET' });
  let cachedResponse = await cache.match(cacheKey);
  if (cachedResponse) {
    const waitUntil = event.waitUntil ? event.waitUntil.bind(event) : (typeof Bunny !== 'undefined' && Bunny.v1 && Bunny.v1.waitUntil ? Bunny.v1.waitUntil.bind(Bunny.v1) : (p) => p.catch(() => {}));
    waitUntil(
      (async () => {
        try {
          const originRes = await fetch(request);
          const ct = (originRes.headers.get('content-type') || '').toLowerCase();
          const cc = (originRes.headers.get('cache-control') || '').toLowerCase();
          if (originRes.ok && ct.includes('text/html') && !originRes.headers.has('set-cookie') && !cc.includes('private') && !cc.includes('no-store')) {
            const vary = (originRes.headers.get('vary') || '').toLowerCase();
            if (vary.includes('cookie') || vary === '*') {
              return;
            }
            const res = new Response(originRes.body, originRes);
            res.headers.set('Cache-Control', 'public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');
            res.headers.set('X-Edge-Cache', 'REVALIDATE');
            await cache.put(cacheKey, res.clone());
          }
        } catch (e) {
          // ignore
        }
      })()
    );
    const response = new Response(cachedResponse.body, cachedResponse);
    response.headers.set('Cache-Control', 'public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');
    response.headers.set('X-Edge-Cache', 'HIT');
    response.headers.set('X-WPPO-Edge', 'bunny');
    return response;
  }
  const originResponse = await fetch(request);
  const contentType = (originResponse.headers.get('content-type') || '').toLowerCase();
  const cacheControl = (originResponse.headers.get('cache-control') || '').toLowerCase();
  const varyHeader = (originResponse.headers.get('vary') || '').toLowerCase();
  // Bypass cache when origin marks private/no-store or sets cookies, or Vary: Cookie.
  if (originResponse.ok && contentType.includes('text/html') && !originResponse.headers.has('set-cookie') && !cacheControl.includes('private') && !cacheControl.includes('no-store') && !varyHeader.includes('cookie') && varyHeader !== '*') {
    const response = new Response(originResponse.body, originResponse);
    response.headers.set('Cache-Control', 'public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');
    response.headers.set('X-Edge-Cache', 'MISS');
    response.headers.set('X-WPPO-Edge', 'bunny');
    const waitUntil = event.waitUntil ? event.waitUntil.bind(event) : (typeof Bunny !== 'undefined' && Bunny.v1 && Bunny.v1.waitUntil ? Bunny.v1.waitUntil.bind(Bunny.v1) : (p) => p.catch(() => {}));
    waitUntil(cache.put(cacheKey, response.clone()));
    return response;
  }
  return originResponse;
}

addEventListener('fetch', (event) => {
  event.respondWith(handleRequest(event));
});
