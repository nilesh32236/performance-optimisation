/**
 * Screenshot proof for the Variant C redesign — multi-screen.
 *
 * Visits every area the sidebar exposes, because the Overview screen carries no
 * form controls and cannot demonstrate the inspector or the named-control rule.
 * For each area: shot at 1440 and 390, an overflow probe at every breakpoint,
 * and a control/inspector audit on the screen that actually has controls.
 *
 * Usage: node scripts/shoot-areas.mjs [outDir]
 */
import { chromium } from 'playwright';
import { mkdir, writeFile } from 'node:fs/promises';

const SITE = 'https://nileshportfolio.duckdns.org';
const APP = `${SITE}/wp-admin/admin.php?page=performance-optimisation`;
const USER = process.env.WPPO_USER || 'admin';
const PASS = process.env.WPPO_PASS || 'tempPass123!';
const OUT = process.argv[ 2 ] || '/var/tmp/work/shots/areas';
const WIDTHS = [ 1440, 1200, 992, 768, 640, 390 ];

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
				cls: ( el.className || '' ).toString().slice( 0, 70 ),
				right: Math.round( r.right ),
			} );
		}
	}
	// A SQUASHED element is narrow, not wide, so the overflow probe above cannot
	// see it. An H1 laid out one character per line is ~10px wide and 200px tall:
	// no horizontal overflow anywhere, and a completely unusable heading. Any
	// text-bearing block element under 90px wide and over 40px tall is reported.
	const squashed = [];
	for ( const el of document.querySelectorAll(
		'h1, h2, h3, p, .wppo-section__title, .wppo-section__eyebrow, .wppo-section__purpose'
	) ) {
		const r = el.getBoundingClientRect();
		const txt = ( el.textContent || '' ).trim();
		if ( txt.length > 3 && r.width < 90 && r.height > 40 ) {
			squashed.push( {
				tag: el.tagName.toLowerCase(),
				cls: ( el.className || '' ).toString().slice( 0, 60 ),
				w: Math.round( r.width ),
				h: Math.round( r.height ),
				text: txt.slice( 0, 40 ),
			} );
		}
	}
	return {
		docW,
		scrollW,
		overflowing: scrollW > docW + 1,
		offenders: offenders.slice( 0, 6 ),
		squashed: squashed.slice( 0, 6 ),
	};
};

const CONTROL_PROBE = () => {
	const sel = [
		'input:not([type=hidden])',
		'select',
		'textarea',
		'[role=switch]',
		'.wppo-switch',
		'.wppo-toggle',
	].join( ',' );
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
					: el.getAttribute( 'role' ) === 'switch'
						? 'switch'
						: ( el.type || 'button' );
		out.byType[ type ] = ( out.byType[ type ] || 0 ) + 1;
		const id = el.getAttribute( 'id' );
		const labelled =
			( id && document.querySelector( `label[for="${ CSS.escape( id ) }"]` ) ) ||
			el.getAttribute( 'aria-label' ) ||
			el.getAttribute( 'aria-labelledby' ) ||
			el.closest( 'label' );
		labelled ? out.described++ : out.unnamed.push( { type, tag: el.tagName.toLowerCase() } );
	}
	return out;
};

const HEAD_PROBE = () => {
	const q = ( s ) => document.querySelector( s );
	return {
		eyebrow: q( '.wppo-section__eyebrow' )?.textContent?.trim() || null,
		h1: q( '.wppo-section__title' )?.textContent?.trim() || null,
		saveInHead: !! q( '.wppo-section__header button' ),
		subtabs: [ ...document.querySelectorAll( '.wppo-subnav__tab' ) ].map( ( t ) =>
			t.textContent.trim()
		),
		innerSubtabs: document.querySelectorAll( '.wppo-sub-tabs .wppo-sub-tab' ).length,
	};
};

const report = { when: new Date().toISOString(), areas: {} };
await mkdir( OUT, { recursive: true } );

const browser = await chromium.launch( { args: [ '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu' ] } );
const ctx = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
const page = await ctx.newPage();
page.setDefaultTimeout( 60000 );
page.setDefaultNavigationTimeout( 60000 );
page.on( 'console', ( m ) => {
	if ( m.type() === 'error' ) {
		( report.consoleErrors ||= [] ).push( m.text().slice( 0, 160 ) );
	}
} );

await page.goto( `${SITE}/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );
await page.goto( APP, { waitUntil: 'domcontentloaded' } );
await page.waitForSelector( '.wppo-section', { timeout: 30000 } );
await page.waitForTimeout( 800 );

// The sidebar owns the areas; click each one rather than guessing URLs.
const areas = await page.$$eval( '.wppo-sidebar a, .wppo-sidebar button, [href*="section="]', ( els ) =>
	els
		.map( ( e ) => ( { text: e.textContent.trim(), href: e.getAttribute( 'href' ) } ) )
		.filter( ( e ) => e.text )
);

for ( const area of areas ) {
	const slug =
		( area.href && new URL( area.href, SITE ).searchParams.get( 'section' ) ) ||
		area.text.toLowerCase().replace( /[^a-z]+/g, '-' );
	if ( ! slug || report.areas[ slug ] ) {
		continue;
	}
	await page.goto( `${ APP }${ APP.includes( '?' ) ? '&' : '?' }section=${ slug }`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.waitForSelector( '.wppo-section', { timeout: 45000 } ); await page.waitForTimeout( 1200 );
	await page.waitForTimeout( 700 );

	const head = await page.evaluate( HEAD_PROBE );
	const ctl = await page.evaluate( CONTROL_PROBE );
	const widths = {};
	for ( const w of WIDTHS ) {
		await page.setViewportSize( { width: w, height: 1000 } );
		await page.waitForTimeout( 380 );
		widths[ w ] = await page.evaluate( OVERFLOW_PROBE );
		if ( w === 1440 || w === 390 ) {
			await page.screenshot( { path: `${ OUT }/${ slug }-${ w }.png` } );
		}
	}
	await page.setViewportSize( { width: 1440, height: 1000 } );
	report.areas[ slug ] = { label: area.text, head, controls: ctl, widths };
}

await writeFile( `${ OUT }/report.json`, JSON.stringify( report, null, 2 ) );
await browser.close();

for ( const [ slug, r ] of Object.entries( report.areas ) ) {
	const bad = Object.entries( r.widths )
		.filter( ( [ , v ] ) => v.overflowing )
		.map( ( [ k ] ) => k );
	console.log(
		`${ slug.padEnd( 16 )} "${ r.label }"  eyebrow=${ JSON.stringify( r.head.eyebrow ) }` +
			`  saveInHead=${ r.head.saveInHead }  subtabs=${ r.head.subtabs.length }` +
			`  inner=${ r.head.innerSubtabs }  controls=${ r.controls.total }` +
			`  unnamed=${ r.controls.unnamed.length }` +
			( bad.length ? `  OVERFLOW@${ bad.join( ',' ) }` : '  overflow=none' ) +
			( r.widths[ 390 ].squashed.length ||
			  Object.values( r.widths ).some( ( v ) => v.squashed.length )
				? `  SQUASHED=${ Object.values( r.widths ).reduce( ( n, v ) => n + v.squashed.length, 0 ) }`
				: '  squash=none' )
	);
	for ( const [ w, v ] of Object.entries( r.widths ) ) {
		for ( const q of v.squashed ) {
			console.log( `      @${ w } SQUASHED <${ q.tag } class="${ q.cls }"> ${ q.w }x${ q.h } "${ q.text }"` );
		}
	}
	if ( bad.length ) {
		for ( const [ w ] of Object.entries( r.widths ).filter( ( [ , v ] ) => v.overflowing ) ) {
			for ( const o of r.widths[ w ].offenders ) {
				console.log( `      @${ w } <${ o.tag } class="${ o.cls }"> right=${ o.right }` );
			}
		}
	}
}
console.log( `console errors: ${ ( report.consoleErrors || [] ).length }` );
console.log( `shots in ${ OUT }` );
