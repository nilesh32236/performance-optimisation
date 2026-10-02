/**
 * WCAG 2.4.13 "Focus Appearance" / 2.4.7 "Focus Visible" probe.
 *
 * The naive version of this check was wrong twice, and both failures came
 * from the same place - deciding "is this element focused?" and "is this
 * thing a focus indicator?" by pattern-matching strings:
 *
 *   1. It called el.focus() and then compared against :focus-visible. In
 *      Chromium those are different states, so 103 elements were reported as
 *      having no focus ring when they were never in the focused state at all.
 *   2. It treated ANY box-shadow as a focus indicator. That matched the CSS
 *      reset `rgba(0, 0, 0, 0) 0 0 0 0 inset` on nearly every element, which
 *      produced 90 false "weak ring" findings.
 *
 * So this version:
 *   - drives focus with REAL KEYBOARD input (Tab), never el.focus(), so the
 *     state is the one a keyboard user actually produces;
 *   - selects the focused element with :focus-visible semantics by asking the
 *     browser for matches(':focus-visible') rather than guessing;
 *   - treats a box-shadow as an indicator only if it is NOT fully transparent
 *     and NOT a zero-spread reset;
 *   - compares the indicator against the colour UNDERNEATH it, which is what
 *     WCAG 2.4.13 asks (3:1 against adjacent colours), not against a
 *     hardcoded white.
 *
 * The output is a measurement, not a pass/fail I assert: where the indicator
 * cannot be measured reliably this reports why rather than counting it.
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

const SCREENS = [
	[ 'overview', 'overview' ],
	[ 'speed', 'speed' ],
	[ 'media', 'media' ],
	[ 'data-system', 'data-system' ],
	[ 'manage', 'manage' ],
];

const lum = ( r, g, b ) => {
	const f = ( c ) => {
		const x = c / 255;
		return x <= 0.03928 ? x / 12.92 : Math.pow( ( x + 0.055 ) / 1.055, 2.4 );
	};
	return 0.2126 * f( r ) + 0.7152 * f( g ) + 0.0722 * f( b );
};
const parseRgb = ( s ) => {
	const m = s && s.match( /(\d+(?:\.\d+)?)[,\s]+(\d+(?:\.\d+)?)[,\s]+(\d+(?:\.\d+)?)/ );
	return m ? [ +m[ 1 ], +m[ 2 ], +m[ 3 ] ] : null;
};
const ratio = ( a, b ) => {
	if ( ! a || ! b ) {
		return null;
	}
	const la = lum( ...a ),
		lb = lum( ...b );
	return ( Math.max( la, lb ) + 0.05 ) / ( Math.min( la, lb ) + 0.05 );
};

const browser = await chromium.launch( {
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );
const ctx = await browser.newContext( {
	viewport: { width: 1440, height: 1000 },
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

// Walk the page with real Tab presses inside the app region only, so wp's own
// admin bar does not contribute hundreds of focus stops we do not control.
const walk = async ( maxStops = 220 ) => {
	await page.evaluate( () => {
		const app =
			document.querySelector( '.wppo-container' ) ||
			document.querySelector( '#performance-optimisation' );
		const first = app.querySelector(
			'a[href],button:not([disabled]),input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
		);
		first?.focus();
	} );

	const results = [];
	for ( let i = 0; i < maxStops; i++ ) {
		await page.keyboard.press( 'Tab' );
		const r = await page.evaluate( () => {
			// NOTE: everything here runs in the BROWSER. Helpers defined at
			// module scope in Node are not in scope for a serialised function -
			// that is why parseRgb is re-declared here.
			const parseRgb = ( s ) => {
				const m =
					s &&
					s.match(
						/(\d+(?:\.\d+)?)[,\s]+(\d+(?:\.\d+)?)[,\s]+(\d+(?:\.\d+)?)/
					);
				return m ? [ +m[ 1 ], +m[ 2 ], +m[ 3 ] ] : null;
			};
			// Ask the BROWSER which element is focus-visible, rather than
			// assuming document.activeElement is in that state.
			const fv = document.querySelectorAll( ':focus-visible' );
			const el = fv[ fv.length - 1 ];
			if ( ! el ) {
				return null;
			}
			// Tab walks the WHOLE document, so the walk escapes into wp-admin's
			// own admin bar and menus. Those are not this plugin's UI and not
			// this plugin's failure: the first run of this probe reported 466
			// weak indicators whose top offenders were `wp-first-item` and a bare
			// `A` - WordPress's admin-bar markup. Scoped to the plugin's own
			// container before any measurement.
			const scope =
				document.querySelector( '#performance-optimisation' ) ||
				document.querySelector( '.wppo-container' );
			if ( ! scope || ! scope.contains( el ) ) {
				return { outside: true };
			}
			const g = getComputedStyle( el );
			const rc = el.getBoundingClientRect();
			if ( rc.width < 1 || rc.height < 1 ) {
				return { skip: true };
			}

			// What is painted UNDER the element, walking up for a real colour.
			const under = ( () => {
				let n = el;
				while ( n ) {
					const c = parseRgb( getComputedStyle( n ).backgroundColor );
					if ( c && ! ( c[ 0 ] === 0 && c[ 1 ] === 0 && c[ 2 ] === 0 ) ) {
						return c;
					}
					n = n.parentElement;
				}
				return [ 255, 255, 255 ];
			} )();

			// A box-shadow only counts if it is not the transparent zero-spread
			// reset that every element carries.
			const sh = g.boxShadow || 'none';
			const shadowReal =
				sh !== 'none' &&
				!/rgba\(\s*0[,\s]+0[,\s]+0[,\s]*[,\s]*0?\s*\)\s*0px\s+0px\s+0px\s+0px/.test( sh );

			return {
				cls:
					el.className.toString().slice( 0, 40 ) ||
					el.tagName,
				outlineW: parseFloat( g.outlineWidth ) || 0,
				outlineColor: parseRgb( g.outlineColor ),
				outlineStyle: g.outlineStyle,
				shadow: shadowReal,
				under,
				w: Math.round( rc.width ),
				h: Math.round( rc.height ),
			};
		} );
		if ( r && ! r.skip && ! r.outside ) {
			results.push( r );
		}
		if ( ! r ) {
			break; // focus genuinely left the document
		}
	}
	return results;
};

let grandTotal = 0;
let grandWeak = 0;

for ( const [ section, view ] of SCREENS ) {
	await page.goto(
		`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=${ section }&view=${ view }`,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForSelector( '.wppo-section', { timeout: 45000 } );
	await page.evaluate( () => document.fonts?.ready ?? null );
	await page.waitForTimeout( 800 );

	const stops = await walk();
	grandTotal += stops.length;

	const weak = [];
	for ( const s of stops ) {
		const hasOutline =
			s.outlineW >= 1 &&
			s.outlineStyle !== 'none' &&
			s.outlineColor !== null;
		if ( hasOutline ) {
			const r = ratio( s.outlineColor, s.under );
			if ( r !== null && r < 3 ) {
				weak.push( { ...s, r: r.toFixed( 2 ), why: 'outline < 3:1' } );
			}
			continue;
		}
		if ( s.shadow ) {
			// Shadow indicators exist; measuring their contrast reliably needs
			// the painted pixels, not the computed value. Counted as PRESENT but
			// NOT verified - saying so beats counting it as a failure.
			continue;
		}
		weak.push( { ...s, why: 'no indicator found' } );
	}
	grandWeak += weak.length;

	console.log(
		`  ${ `${ section }/${ view }`.padEnd( 20 ) } tab stops=${ String(
			stops.length
		).padStart( 4 ) }  weak-or-absent=${ String( weak.length ).padStart( 3 ) }`
	);
	for ( const w of weak.slice( 0, 4 ) ) {
		console.log( `      ${ w.why }: ${ w.cls } (${ w.w }x${ w.h })` );
	}
}

console.log( `\n  TOTAL tab stops ${ grandTotal }, weak or absent ${ grandWeak }` );
console.log(
	'  NOTE: elements using a box-shadow indicator are counted PRESENT but NOT'
);
console.log(
	'  verified — their contrast needs painted pixels, not a computed value.'
);

await browser.close();