/**
 * Bunny Edge — WPPO Edge HTML Cache Adapter
 *
 * Bunny CDN Edge Rules / pull zone stale-while-revalidate for
 * cache/wppo/{domain}/{path}/index.html semantics.
 *
 * Placeholders replaced by Edge_Cache::get_bunny_edge_js():
 *   {{ORIGIN_URL}} — e.g. https://example.com
 *   {{CACHE_TTL}}   — cache max-age seconds
 *   {{SWR}}         — stale-while-revalidate seconds
 *
 * Deploy: upload to Bunny pull zone Edge Rules or use
 * Edge_Cache::get_bunny_edge_js() output as reference for
 * configuring the Bunny pull zone Cache-Control.
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
  const cache = caches.default;
  let cachedResponse = await cache.match(request);
  if (cachedResponse) {
    event.waitUntil(
      (async () => {
        try {
          const originRes = await fetch(request);
          if (originRes.ok && (originRes.headers.get('content-type') || '').includes('text/html')) {
            const res = new Response(originRes.body, originRes);
            res.headers.set('Cache-Control', 'public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');
            res.headers.set('X-Edge-Cache', 'REVALIDATE');
            await cache.put(request, res.clone());
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
  const contentType = originResponse.headers.get('content-type') || '';
  if (originResponse.ok && contentType.includes('text/html')) {
    const response = new Response(originResponse.body, originResponse);
    response.headers.set('Cache-Control', 'public, max-age={{CACHE_TTL}}, stale-while-revalidate={{SWR}}');
    response.headers.set('X-Edge-Cache', 'MISS');
    response.headers.set('X-WPPO-Edge', 'bunny');
    event.waitUntil(cache.put(request, response.clone()));
    return response;
  }
  return originResponse;
}

addEventListener('fetch', (event) => {
  event.respondWith(handleRequest(event));
});
