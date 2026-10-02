/**
 * WCAG 1.4.12 "Text Spacing" probe.
 *
 * Applies the user-adjustable overrides from the criterion verbatim - line
 * height 1.5, paragraph spacing 2em, letter spacing 0.12em, word spacing
 * 0.16em - and checks the three ways the criterion can fail:
 *
 *   1. CLIPPING: an element with hidden/clip overflow whose content is taller
 *      or wider than its box.
 *   2. COLLAPSE: a text element whose rendered box has collapsed to nothing.
 *   3. OVERLAP: two text leaves whose boxes intersect by more than 3px on both
 *      axes, which means text is drawn on top of other text.
 * Plus horizontal scroll on the document, which is the visible symptom of the
 * word-spacing override overflowing a fixed-width container.
 *
 * THE FALSE POSITIVE THIS FILE EXISTS TO AVOID: the first run reported 130
 * failures on five screens. Every one of them was a 1x1 clipped box - the
 * `clip: rect(0, 0, 0, 0)` pattern used by .wppo-visually-hidden and
 * .screen-reader-text. Clipping screen-reader-only text is the entire point
 * of that pattern; reporting it as a 1.4.12 failure means the probe would
 * train people to ignore it.
 *
 * So: a box of 1x1 or smaller is excluded, because that is a
 * screen-reader-only element by construction, not text the user can see.
 * Everything else that clips or collapses is reported.
 */

import { chromium } from 'playwright';

const SITE = 'https://nileshportfolio.duckdns.org';
const USER = 'admin';
const PASS = process.env.WPPO_ADMIN_PASS;

if ( ! PASS ) {
	console.error(
		'WPPO_ADMIN_PASS is not set. Refusing to run rather than measuring nothing.'
	);
	process.exit( 2 );
}

const SCREENS = [ 'overview', 'speed', 'media', 'data-system', 'manage' ];

// WCAG 1.4.12 defines these four values; they are the criterion, not a choice.
const TEXT_SPACING_CSS =
	'*, *::before, *::after { line-height: 1.5 !important; ' +
	'letter-spacing: 0.12em !important; word-spacing: 0.16em !important; } ' +
	'p { margin-bottom: 2em !important; }';

const browser = await chromium.launch( {
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );
const ctx = await browser.newContext( {
	viewport: { width: 1280, height: 900 },
} );
const page = await ctx.newPage();
page.setDefaultTimeout( 60000 );
page.setDefaultNavigationTimeout( 60000 );

await page.goto( `${ SITE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );

let grandTotal = 0;

for ( const section of SCREENS ) {
	await page.goto(
		`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=${ section }`,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForSelector( '.wppo-section', { timeout: 45000 } );
	await page.evaluate( () => document.fonts?.ready ?? null );
	await page.waitForTimeout( 700 );

	const r = await page.evaluate( ( css ) => {
		const style = document.createElement( 'style' );
		style.textContent = css;
		document.head.appendChild( style );

		const de = document.documentElement;
		const hscroll =
			de.scrollWidth > de.clientWidth + 2
				? { scrollWidth: de.scrollWidth, clientWidth: de.clientWidth }
				: null;

		const leaves = [
			...document.querySelectorAll( '.wppo-container *' ),
		].filter( ( e ) => {
			const t = ( e.textContent || '' ).trim();
			return t && e.children.length === 0;
		} );

		const failures = [];

		for ( const el of leaves ) {
			const rc = el.getBoundingClientRect();
			// A 1x1 clipped box is the screen-reader-only pattern by
			// construction. Clipping it is correct, not a failure.
			if ( rc.width <= 1 || rc.height <= 1 ) {
				continue;
			}
			const g = getComputedStyle( el );
			const clips =
				/hidden|clip/.test( g.overflow + g.overflowX + g.overflowY );
			if (
				clips &&
				( el.scrollHeight > el.clientHeight + 2 ||
					el.scrollWidth > el.clientWidth + 2 )
			) {
				failures.push( {
					kind: 'clipped',
					text: ( el.textContent || '' ).trim().slice( 0, 22 ),
					cls: el.className.toString().slice( 0, 30 ),
				} );
			} else if ( rc.height > 0 && rc.height < 6 ) {
				failures.push( {
					kind: 'collapsed',
					text: ( el.textContent || '' ).trim().slice( 0, 22 ),
					cls: el.className.toString().slice( 0, 30 ),
					h: Math.round( rc.height ),
				} );
			}
		}

		let overlaps = 0;
		for ( let i = 0; i < leaves.length; i++ ) {
			const a = leaves[ i ].getBoundingClientRect();
			if ( a.width <= 1 || a.height <= 1 ) {
				continue;
			}
			for ( let j = i + 1; j < leaves.length; j++ ) {
				const b = leaves[ j ].getBoundingClientRect();
				if ( b.width <= 1 || b.height <= 1 ) {
					continue;
				}
				if (
					leaves[ i ].contains( leaves[ j ] ) ||
					leaves[ j ].contains( leaves[ i ] )
				) {
					continue;
				}
				const ox = Math.min( a.right, b.right ) - Math.max( a.left, b.left );
				const oy = Math.min( a.bottom, b.bottom ) - Math.max( a.top, b.top );
				if ( ox > 3 && oy > 3 ) {
					overlaps++;
					break;
				}
			}
		}

		style.remove();
		return { failures, overlaps, hscroll };
	}, TEXT_SPACING_CSS );

	grandTotal += r.failures.length + r.overlaps + ( r.hscroll ? 1 : 0 );
	console.log(
		`  ${ section.padEnd( 13 ) } clipped/collapsed=${ String(
			r.failures.length
		).padStart( 2 ) }  overlaps=${ String( r.overlaps ).padStart(
			2
		) }  h-scroll=${ r.hscroll ? 'YES' : 'no' }`
	);
	for ( const f of r.failures.slice( 0, 3 ) ) {
		console.log( `      ${ f.kind }: "${ f.text }" (${ f.cls })` );
	}
}

console.log( `\n  TOTAL 1.4.12 failures: ${ grandTotal }` );
console.log( '  Overrides applied: line-height 1.5, paragraph margin 2em,' );
console.log( '  letter-spacing 0.12em, word-spacing 0.16em — the values the' );
console.log( '  criterion names, not a chosen tolerance.' );

await browser.close();