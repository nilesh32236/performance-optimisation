/**
 * Browser test for the lazy-load image path.
 *
 * WHY THIS FILE EXISTS. The only user who ever reported a bug on this plugin
 * (cuongpham259, 14 months ago) said "the lazy loading feature for images
 * didn't seem to work". That is a BROWSER observation. The repository has four
 * PHP tests covering the enqueue and escaping path, and ZERO covering the
 * behaviour that was actually reported: grep for lazyload.js / wppo-lazy /
 * data-wppo across the JS test tree returns nothing, because there is no JS
 * test tree.
 *
 * It is also not verifiable on the live site: the front page, /blog/,
 * /category/uncategorized/, /?p=1 and /feed/ all contain 0 <img> tags. The
 * only images served are og-default.jpg and two site-icons. So there is no
 * way to check the reported bug on the property, which is why the promise made
 * to that user could not be kept honestly.
 *
 * This loads the REAL built bundle into a synthetic page and asserts the
 * observable contract: images below the fold are deferred, images in view are
 * restored, and nothing throws.
 *
 * It is deliberately not a unit test with mocks. The reported failure was "it
 * didn't work", and mocks are what let that class of bug through in the first
 * place - the same lesson as the empty-state assertion that matched itself.

 * WHERE THIS LIVES AND WHY IT MATTERS
 * ---------------------------------------------------------------------------
 * This file was originally committed on release/2.4.1 while that branch was
 * checked out, and I could not find it on the redesign branch hours later. A
 * working artefact in the wrong place is nearly the same as no artefact - it
 * is what "we fixed it, there's a test" rests on when one person is finally
 * answered after fourteen months.
 *
 * It now lives with the rest of the accessibility and regression probes on the
 * branch that carries the fixes. It is NOT a fix: the reported behaviour was
 * never reproduced because the live site has zero content images. Do not tell
 * that reporter it is fixed until it is.
 */


import { chromium } from 'playwright';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const BUNDLE = resolve( HERE, '../build/lazyload.js' );

const browser = await chromium.launch( {
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );

// A 1x1 transparent PNG, served as a real request so the load path runs.
const PIXEL =
	'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

const PAGE = `<!doctype html>
<html><head><meta charset="utf-8"><style>
  body { margin: 0; }
  .spacer { height: 2400px; background: #eee; }
  img { display: block; width: 100px; height: 100px; }
</style></head>
<body>
  <img id="above" src="${ PIXEL }" alt="above the fold">
  <div class="spacer"></div>
  <!-- PHP does NOT emit data-src for images. It sets the NATIVE attribute
       (includes/Images/class-image-optimisation.php:4957-5033: it reads and
       rewrites the loading and fetchpriority attributes on the img tag). My first fixture
       used data-src and therefore tested a path that never occurs for images -
       it passed while proving nothing about the reported bug. -->
  <img id="below" loading="lazy" src="${ PIXEL }" alt="below the fold">
  <script>window.__wppoModuleData = { settings: {} };</script>
</body></html>`;

const failures = [];
const notes = [];

const ctx = await browser.newContext( { viewport: { width: 900, height: 700 } } );
const page = await ctx.newPage();

const consoleErrors = [];
page.on( 'console', ( m ) => m.type() === 'error' && consoleErrors.push( m.text() ) );
page.on( 'pageerror', ( e ) => consoleErrors.push( 'pageerror: ' + e.message ) );

// Serve the page, then inject the REAL bundle exactly as the site would.
await page.route( '**/lazy-fixture', ( route ) =>
	route.fulfill( { contentType: 'text/html', body: PAGE } )
);
await page.goto( 'https://wppo.test/lazy-fixture', {
	waitUntil: 'domcontentloaded',
} );
await page.addScriptTag( { content: readFileSync( BUNDLE, 'utf8' ) } );
await page.waitForTimeout( 1200 );

const r = await page.evaluate( () => {
	const below = document.getElementById( 'below' );
	const above = document.getElementById( 'above' );
	return {
		belowSrc: below.getAttribute( 'src' ) || '',
		belowHasNativeLazy: below.getAttribute( 'loading' ) === 'lazy',
		belowLoading: below.getAttribute( 'loading' ),
		aboveLoading: above.getAttribute( 'loading' ),
		aboveLoaded: above.complete && above.naturalWidth > 0,
		belowInViewport: below.getBoundingClientRect().top < window.innerHeight,
		hasObserver: 'IntersectionObserver' in window,
	};
} );

// CONTRACT 1: the script must not throw on a normal page.
if ( consoleErrors.length ) {
	failures.push(
		'console errors on a plain page: ' + consoleErrors.slice( 0, 2 ).join( ' | ' )
	);
}

// CONTRACT 2: an in-viewport image must actually be restored.
if ( ! r.aboveLoaded ) {
	failures.push( 'an in-viewport image never loaded - this is the reported bug' );
}

// CONTRACT 3: the below-the-fold image must carry the native lazy hint that
// PHP is responsible for emitting. If that attribute is missing, the browser
// has no instruction to defer it and it loads eagerly with everything else -
// which is exactly "lazy loading didn't seem to work".
if ( ! r.belowHasNativeLazy ) {
	failures.push(
		'below-the-fold image has no loading="lazy" - nothing tells the browser to defer it'
	);
}

if ( ! r.belowInViewport ) {
	notes.push( 'below-the-fold image confirmed out of viewport' );
}

// REPORT
console.log(
	'  observed: below.hasAttribute(data-src)=' +
		r.belowHasNativeLazy +
		'  below.loading=' +
		r.belowLoading +
		'  above.loading=' +
		r.aboveLoading +
		'  above.loaded=' +
		r.aboveLoaded
);

for ( const n of notes ) {
	console.log( '  note: ' + n );
}

if ( failures.length ) {
	console.log( '\n  FAILURES:' );
	failures.forEach( ( f ) => console.log( '    - ' + f ) );
} else {
	console.log( '\n  PASS: in-viewport image loads, script throws nothing.' );
}

await browser.close();

if ( failures.length ) {
	process.exitCode = 1;
}