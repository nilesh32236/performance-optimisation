/**
 * Screenshot proof for the Variant C redesign.
 *
 * Drives the real admin over HTTPS with Playwright's bundled Chromium. The
 * MCP browser is deliberately not used: it resolves a Chrome channel that is
 * not installed here, while Chromium is already in ~/.cache/ms-playwright.
 *
 * Usage: node scripts/shoot-variant-c.mjs [outDir]
 *
 * Verifies, per width:
 *   - the page renders and the app mounts
 *   - nothing overflows the viewport horizontally
 *   - the page head shows eyebrow / h1 / purpose
 *   - a Save control exists in the page head
 * Then focuses one control of each type and records what the inspector shows.
 */
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const SITE = 'https://nileshportfolio.duckdns.org';
const LOGIN = `${SITE}/wp-login.php`;
const APP = `${SITE}/wp-admin/admin.php?page=performance-optimisation`;
const USER = process.env.WPPO_USER || 'admin';
const PASS = process.env.WPPO_PASS || 'tempPass123!';
const OUT = process.argv[ 2 ] || '/var/tmp/work/shots';

const WIDTHS = [
	{ w: 1440, tag: 'desktop' },
	{ w: 1200, tag: 'xl' },
	{ w: 992, tag: 'lg' },
	{ w: 768, tag: 'md' },
	{ w: 640, tag: 'sm' },
	{ w: 390, tag: 'mobile' },
];

/** Every element wider than the viewport is an overflow bug. */
const OVERFLOW_PROBE = () => {
	const docW = document.documentElement.clientWidth;
	const scrollW = document.documentElement.scrollWidth;
	const offenders = [];
	for ( const el of document.querySelectorAll( 'body *' ) ) {
		const r = el.getBoundingClientRect();
		if ( r.width === 0 && r.height === 0 ) {
			continue;
		}
		if ( r.right > docW + 1 || r.left < -1 ) {
			offenders.push( {
				tag: el.tagName.toLowerCase(),
				cls: ( el.className || '' ).toString().slice( 0, 90 ),
				left: Math.round( r.left ),
				right: Math.round( r.right ),
			} );
		}
	}
	return { docW, scrollW, overflowing: scrollW > docW + 1, offenders: offenders.slice( 0, 8 ) };
};

/** Named controls: the brief requires 0 unnamed / undescribed controls. */
const CONTROL_PROBE = () => {
	const sel = 'input:not([type=hidden]), select, textarea, button[role=switch], [role=switch], .wppo-switch';
	const out = { total: 0, unnamed: [], described: 0, byType: {} };
	for ( const el of document.querySelectorAll( sel ) ) {
		if ( el.type === 'hidden' ) {
			continue;
		}
		out.total++;
		const type =
			el.tagName === 'SELECT'
				? 'select'
				: el.tagName === 'TEXTAREA'
					? 'textarea'
						: ( el.type || 'button' );
		out.byType[ type ] = ( out.byType[ type ] || 0 ) + 1;
		const id = el.getAttribute( 'id' );
		const name = el.getAttribute( 'name' ) || el.getAttribute( 'aria-label' ) || '';
		const labelled =
			( id && document.querySelector( `label[for="${ CSS.escape( id ) }"]` ) ) ||
			el.getAttribute( 'aria-label' ) ||
			el.getAttribute( 'aria-labelledby' ) ||
			el.closest( 'label' );
		if ( labelled ) {
			out.described++;
		} else {
			out.unnamed.push( {
				tag: el.tagName.toLowerCase(),
				type,
				name: String( name ).slice( 0, 60 ),
			} );
		}
	}
	return out;
};

const HEAD_PROBE = () => {
	const q = ( s ) => document.querySelector( s );
	return {
		eyebrow: q( '.wppo-section__eyebrow' )?.textContent?.trim() || null,
		h1: q( '.wppo-section__title, h1' )?.textContent?.trim() || null,
		purpose: q( '.wppo-section__purpose' )?.textContent?.trim() || null,
		saveInHead:
			!! q(
				'.wppo-section__header .wppo-feature-header__actions, .wppo-section__header button'
			),
		subtabs: document.querySelectorAll( '.wppo-subnav__tab' ).length,
		gradientHeroes: document.querySelectorAll(
			'.wppo-dashboard-view--home > .wppo-feature-header'
		).length,
	};
};

/** Focus a control of each type; record whether the inspector fills in. */
async function focusTypes( page ) {
	const picks = [
		[ 'switch', '.wppo-switch, [role=switch]' ],
		[ 'number', 'input[type=number]' ],
		[ 'textarea', 'textarea' ],
		[ 'select', 'select' ],
	];
	const results = [];
	for ( const [ kind, sel ] of picks ) {
		const el = page.locator( sel ).first();
		if ( ( await el.count() ) === 0 ) {
			results.push( { kind, found: false } );
			continue;
		}
		await el.scrollIntoViewIfNeeded().catch( () => {} );
		await el.focus().catch( () => {} );
		await page.waitForTimeout( 350 );
		const info = await page.evaluate( () => {
			const insp = document.querySelector( '.wppo-inspector' );
			if ( ! insp ) {
				return { inspector: false };
			}
			const txt = ( insp.innerText || '' ).trim();
			return {
				inspector: true,
				charLen: txt.length,
				hasWhatItDoes: /what it does/i.test( txt ),
				hasWhatItCosts: /what it costs/i.test( txt ),
				hasWhereYouStand: /where you stand/i.test( txt ),
				head: txt.slice( 0, 90 ).replace( /\s+/g, ' ' ),
			};
		} );
		results.push( { kind, found: true, ...info } );
		await el
			.screenshot( { path: `${ OUT }/control-${ kind }.png` } )
			.catch( () => {} );
	}
	return results;
}

const report = { when: new Date().toISOString(), app: APP, widths: {}, controls: [] };

await mkdir( OUT, { recursive: true } );
const browser = await chromium.launch( { args: [ '--no-sandbox' ] } );
const ctx = await browser.newContext( {
	viewport: { width: 1440, height: 1000 },
	deviceScaleFactor: 1,
} );
const page = await ctx.newPage();
page.on( 'console', ( m ) => {
	if ( m.type() === 'error' ) {
		( report.consoleErrors ||= [] ).push( m.text().slice( 0, 160 ) );
	}
} );

// --- log in ---
await page.goto( LOGIN, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );
report.loggedIn = page.url().includes( 'wp-admin' );

// --- the app ---
await page.goto( APP, { waitUntil: 'networkidle' } );
await page.waitForSelector( '.wppo-section, .wppo-dashboard-view', { timeout: 30000 } );
await page.waitForTimeout( 900 );

for ( const { w, tag } of WIDTHS ) {
	await page.setViewportSize( { width: w, height: 1000 } );
	await page.waitForTimeout( 450 );
	const of = await page.evaluate( OVERFLOW_PROBE );
	const ctl = await page.evaluate( CONTROL_PROBE );
	const head = await page.evaluate( HEAD_PROBE );
	await page.screenshot( { path: `${ OUT }/${ tag }-${ w }.png`, fullPage: false } );
	report.widths[ w ] = { tag, ...of, controls: ctl, head };
}

// --- inspector per control type, at desktop width ---
await page.setViewportSize( { width: 1440, height: 1000 } );
await page.waitForTimeout( 400 );
report.controls = await focusTypes( page );

await writeFile( `${ OUT }/report.json`, JSON.stringify( report, null, 2 ) );
await browser.close();

// --- terse console summary ---
for ( const [ w, r ] of Object.entries( report.widths ) ) {
	console.log(
		`${ w.padStart( 4 )}  overflow=${ r.overflowing ? 'YES' : 'no ' }` +
			`  scrollW=${ r.scrollW }/${ r.docW }` +
			`  controls=${ r.controls.total } unnamed=${ r.controls.unnamed.length }` +
			`  eyebrow=${ JSON.stringify( r.head.eyebrow ) }` +
			`  saveInHead=${ r.head.saveInHead }` +
			`  subtabs=${ r.head.subtabs }` +
			`  homeHeroes=${ r.head.gradientHeroes }`
	);
	if ( r.overflowing ) {
		for ( const o of r.offenders ) {
			console.log( `        overflow: <${ o.tag } class="${ o.cls }"> ${ o.left }..${ o.right }` );
		}
	}
}
for ( const c of report.controls ) {
	console.log(
		`control ${ String( c.kind ).padEnd( 9 ) } found=${ c.found }` +
			( c.found
				? `  inspector=${ c.inspector } 3blocks=${ c.hasWhatItDoes && c.hasWhatItCosts && c.hasWhereYouStand } chars=${ c.charLen }`
				: '' )
	);
}
console.log( `console errors: ${ ( report.consoleErrors || [] ).length }` );
console.log( `shots in ${ OUT }` );
