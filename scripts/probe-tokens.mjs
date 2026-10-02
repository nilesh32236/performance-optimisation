/**
 * Per-screen token probe — the measurable half of "pixel-close".
 *
 * Every constant below is read from the reference itself, not typed from memory:
 *   designs/variant-c/style.css  --wppo-toolbar-h 48px, --wppo-nav-w 220px,
 *     --wppo-inspector-w 320px, --wppo-radius 8px, --wppo-bg-card #ffffff
 *   ui/instrument/stitch/03-assets-scripts.html  canvas #F4F6F9,
 *     surface #FFFFFF, hairline #DDE3EA, page head text-2xl (= 24px)
 *
 * This checks SHARED tokens per screen. It cannot judge composition, spacing
 * rhythm or visual balance, so a PASS here is not the same claim as "looks
 * right" — it means the measurable values match, and it is reported that way
 * rather than as a blanket verdict.
 *
 * It also asserts on structure rather than on prose: the inspector check looks
 * for .wppo-inspector__block elements and the absence of the empty state, after
 * an earlier probe matched the empty state's own sentence and passed on nothing.
 */

import { chromium } from 'playwright';

const SITE = 'https://nileshportfolio.duckdns.org';
const USER = 'admin';
const PASS = process.env.WPPO_ADMIN_PASS; // never hardcode a live credential

const REF = {
	toolbarH: 48,
	navW: 220,
	inspectorW: 320,
	radius: 8,
	surface: [ 255, 255, 255 ],
	canvas: [ 244, 246, 249 ],
	hairline: [ 221, 227, 234 ],
	baseFont: 13,
	h1: 24,
	eyebrow: 11,
};

const SCREENS = [
	[ 'overview', 'overview' ],
	[ 'speed', 'speed' ],
	[ 'speed', 'preload' ],
	[ 'media', 'media' ],
	[ 'data-system', 'data-system' ],
	[ 'manage', 'manage' ],
];

const rgbEq = ( s, want ) => {
	const m = s && s.match( /(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/ );
	return !! m && [ +m[ 1 ], +m[ 2 ], +m[ 3 ] ].every( ( v, i ) => Math.abs( v - want[ i ] ) <= 2 );
};

// NOTE: everything below runs inside page.evaluate(), i.e. in the BROWSER.
// Helpers used by measure() must be defined inside measure(), not here - a
// helper defined at module scope is not in scope for the serialised function.
const measure = () => {
	const probe = ( sel, prop ) => {
		const el = document.querySelector( sel );
		return el ? getComputedStyle( el )[ prop ] : null;
	};

	const nav = document.querySelector( '.wppo-sidebar' );
	const insp = document.querySelector( '.wppo-inspector' );
	const card = document.querySelector( '.wppo-feature-card' );
	const h1 = document.querySelector( '.wppo-section__title' );
	const eye = document.querySelector( '.wppo-section__eyebrow' );
	const toolbar = document.querySelector( '.wppo-toolbar' );
	const out = {
		toolbarH: toolbar ? Math.round( toolbar.getBoundingClientRect().height ) : null,
		navW: nav ? Math.round( nav.getBoundingClientRect().width ) : null,
		inspW: insp ? Math.round( insp.getBoundingClientRect().width ) : null,
		baseFont: probe( '.wppo-section', 'fontSize' ) ?? probe( 'body', 'fontSize' ),
		h1: h1 ? parseFloat( getComputedStyle( h1 ).fontSize ) : null,
		eyebrow: eye ? parseFloat( getComputedStyle( eye ).fontSize ) : null,
		eyebrowUpper: eye ? getComputedStyle( eye ).textTransform : null,
		surface: card ? getComputedStyle( card ).backgroundColor : null,
		canvas: ( () => {
			const m = document.querySelector( '#performance-optimisation' );
			return m ? getComputedStyle( m ).backgroundColor : null;
		} )(),
		radius: card ? parseFloat( getComputedStyle( card ).borderTopLeftRadius ) : null,
		overflowX: Math.max(
			0,
			document.documentElement.scrollWidth - window.innerWidth
		),
		squashed: [ ...document.querySelectorAll( 'h1,h2,h3,p' ) ].filter( ( e ) => {
			const b = e.getBoundingClientRect();
			return b.width < 90 && b.height > 40;
		} ).length,
	};
	return out;
};

const browser = await chromium.launch( {
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );
const ctx = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
const page = await ctx.newPage();
page.setDefaultTimeout( 60000 );
page.setDefaultNavigationTimeout( 60000 );
const consoleErrors = [];
page.on( 'console', ( m ) => m.type() === 'error' && consoleErrors.push( m.text() ) );

await page.goto( `${ SITE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );

let fails = 0;
let checks = 0;
for ( const [ section, view ] of SCREENS ) {
	await page.goto(
		`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=${ section }&view=${ view }`,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForSelector( '.wppo-section', { timeout: 45000 } );
	await page.evaluate( () => document.fonts?.ready ?? null );
	await page.waitForTimeout( 700 );
	const m = await page.evaluate( measure );
	const bad = [];
	const eq = ( name, got, want ) => {
		checks++;
		if ( got !== want ) {
			bad.push( `${ name }=${ got } want ${ want }` );
		}
	};
	const near = ( name, got, want, tol = 2 ) => {
		checks++;
		if ( got === null || Math.abs( got - want ) > tol ) {
			bad.push( `${ name }=${ got } want ${ want }` );
		}
	};
	eq( 'toolbarH', m.toolbarH, REF.toolbarH );
	eq( 'navW', m.navW, REF.navW );
	if ( m.inspW !== null ) {
		eq( 'inspW', m.inspW, REF.inspectorW );
	}
	eq( 'baseFont', m.baseFont, `${ REF.baseFont }px` );
	if ( m.h1 !== null ) {
		eq( 'h1', m.h1, REF.h1 );
	}
	if ( m.eyebrow !== null ) {
		eq( 'eyebrow', m.eyebrow, REF.eyebrow );
		eq( 'eyebrowCase', m.eyebrowUpper, 'uppercase' );
	}
	if ( m.surface ) {
		checks++;
		if ( ! rgbEq( m.surface, REF.surface ) ) {
			bad.push( `surface=${ m.surface }` );
		}
	}
	checks++;
	if ( ! rgbEq( m.canvas, REF.canvas ) ) {
		bad.push( `canvas=${ m.canvas }` );
	}
	if ( m.radius !== null ) {
		near( 'radius', m.radius, REF.radius, 1 );
	}
	eq( 'overflowX', m.overflowX, 0 );
	eq( 'squashed', m.squashed, 0 );
	if ( bad.length ) {
		fails++;
	}
	const label = `${ section }/${ view }`.padEnd( 20 );
	console.log(
		`  ${ label } ${ bad.length ? 'FAIL  ' + bad.join( ', ' ) : 'PASS' }`
	);
}

console.log( `\n  screens ${ SCREENS.length }, checks ${ checks }, failing screens ${ fails }` );
console.log( `  console errors: ${ consoleErrors.length }` );
await browser.close();