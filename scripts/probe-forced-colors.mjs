/**
 * Forced-colors probe: focus indicator + contrast under an OS-forced palette.
 *
 * Contrast alone was already verified (0 failures across five screens with
 * forcedColors: active). This checks the other half: whether the focus ring
 * is still VISIBLE once the UA takes the palette away.
 *
 * WHY A SEPARATE CHECK. In forced-colors mode the browser replaces author
 * colours with system ones. Two probes written earlier in this campaign both
 * failed here and the reasons are worth keeping:
 *
 *   1. Flagging any non-grey text as "an author colour escaped the palette"
 *      was wrong: rgb(96,0,0) is `darkred`, a SYSTEM colour that forced mode
 *      legitimately assigns to a secondary button.
 *   2. Counting author backgrounds was worse: it reported MORE elements under
 *      forced mode (452 vs 117) because getComputedStyle returns the UA's
 *      forced Canvas colour for most elements, not the authored one.
 *
 * Both counted a proxy. The question is not "how many colours look authored"
 * but "can a keyboard user see the element they have focused".
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

const browser = await chromium.launch( {
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );
const ctx = await browser.newContext( {
	viewport: { width: 1440, height: 1000 },
	forcedColors: 'active',
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

let grandStops = 0;
let grandNoRing = 0;

for ( const [ section, view ] of SCREENS ) {
	await page.goto(
		`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=${ section }&view=${ view }`,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForSelector( '.wppo-section', { timeout: 45000 } );
	await page.evaluate( () => document.fonts?.ready ?? null );
	await page.waitForTimeout( 800 );

	// Walk with real Tab presses, inside the plugin's own container, and read
	// what the UA actually painted for each focused element.
	const stops = await page.evaluate( () => {
		const scope =
			document.querySelector( '#performance-optimisation' ) ||
			document.querySelector( '.wppo-container' );
		if ( ! scope ) {
			return [];
		}
		const first = scope.querySelector(
			'a[href],button:not([disabled]),input:not([type=hidden]):not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
		);
		first?.focus();

		const out = [];
		for ( let i = 0; i < 220; i++ ) {
			const fv = document.querySelectorAll( ':focus-visible' );
			const el = fv[ fv.length - 1 ];
			if ( ! el || ! scope.contains( el ) ) {
				break;
			}
			const g = getComputedStyle( el );
			const rc = el.getBoundingClientRect();
			if ( rc.width >= 1 && rc.height >= 1 ) {
				out.push( {
					cls: el.className.toString().slice( 0, 34 ) || el.tagName,
					outlineWidth: parseFloat( g.outlineWidth ) || 0,
					outlineStyle: g.outlineStyle,
					outlineColor: g.outlineColor,
					shadow: g.boxShadow || 'none',
					forcedAdjust: g.forcedColorAdjust,
				} );
			}
			// Native tabbing, done by the browser, not by guessing.
			const before = el;
			( function next() {
				if ( before !== el ) {
					return;
				}
			} )();
			break;
		}
		return out;
	} );

	// The synthetic loop above only reaches the first stop; drive the rest with
	// real key presses.
	const collected = [];
	await page.evaluate( () => {
		const scope =
			document.querySelector( '#performance-optimisation' ) ||
			document.querySelector( '.wppo-container' );
		scope
			?.querySelector(
				'a[href],button:not([disabled]),input:not([type=hidden]):not([disabled]),[tabindex]:not([tabindex="-1"])'
			)
			?.focus();
	} );

	for ( let i = 0; i < 160; i++ ) {
		await page.keyboard.press( 'Tab' );
		const r = await page.evaluate( () => {
			const scope =
				document.querySelector( '#performance-optimisation' ) ||
				document.querySelector( '.wppo-container' );
			const fv = document.querySelectorAll( ':focus-visible' );
			const el = fv[ fv.length - 1 ];
			if ( ! el || ! scope?.contains( el ) ) {
				return null;
			}
			const g = getComputedStyle( el );
			const rc = el.getBoundingClientRect();
			if ( rc.width < 1 || rc.height < 1 ) {
				return { skip: true };
			}
			return {
				cls: el.className.toString().slice( 0, 34 ) || el.tagName,
				outlineWidth: parseFloat( g.outlineWidth ) || 0,
				outlineStyle: g.outlineStyle,
				outlineColor: g.outlineColor,
				shadow: ( g.boxShadow || 'none' ).slice( 0, 90 ),
			};
		} );
		if ( r === null ) {
			break;
		}
		if ( ! r.skip ) {
			collected.push( r );
		}
	}

	grandStops += collected.length;
	const noRing = collected.filter(
		( s ) =>
			s.outlineWidth < 1 &&
			! / Highlight | ButtonText |[1-9]px |[1-9]em /.test( s.shadow )
	);
	grandNoRing += noRing.length;

	console.log(
		`  ${ `${ section }/${ view }`.padEnd( 20 ) } stops=${ String(
			collected.length
		).padStart( 4 ) }  no visible ring=${ String( noRing.length ).padStart(
			3
		) }`
	);
	for ( const n of noRing.slice( 0, 4 ) ) {
		console.log(
			`      no ring: ${ n.cls }  outline=${ n.outlineWidth }/${ n.outlineStyle }  shadow=${ n.shadow }`
		);
	}
}

console.log(
	`\n  TOTAL tab stops ${ grandStops }, no visible focus ring ${ grandNoRing }`
);
console.log( '  Measured with forcedColors: active, so the UA has replaced the' );
console.log( '  palette and only system-colour rings should survive.' );

await browser.close();