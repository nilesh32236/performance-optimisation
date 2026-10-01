/**
 * Prove the inspector loads the right subject, with all three blocks, for each
 * control type. The redesign's completion criteria name switch / number /
 * textarea / select explicitly; the earlier harness reported 0 controls because
 * it ran on Overview, which has no form.
 *
 * Usage: node scripts/probe-inspector.mjs [area]
 */
import { chromium } from 'playwright';

const SITE = 'https://nileshportfolio.duckdns.org';
const AREA = process.argv[ 2 ] || 'speed';
const TARGETS = [
	[ 'switch', '.wppo-switch, [role=switch], input[type=checkbox]' ],
	[ 'number', 'input[type=number]' ],
	[ 'textarea', 'textarea' ],
	[ 'select', 'select' ],
];

// The check asserts on BLOCK ELEMENTS, not on phrases. A previous version
// matched /what it does/i against the panel's innerText, which the EMPTY STATE
// satisfies by construction — its placeholder reads "explains what it does,
// what it costs you, and where you stand now". That made Manage report three
// blocks while rendering none.
const read = () => {
	const insp = document.querySelector( '.wppo-inspector' );
	const blocks = [ ...( insp?.querySelectorAll( '.wppo-inspector__block' ) || [] ) ];
	const empty = !! insp?.querySelector( '.wppo-inspector__empty' );
	const kickers = blocks.map( ( b ) =>
		( b.querySelector( 'h3, .wppo-eyebrow' )?.textContent || '' ).trim()
	);
	const active = document.activeElement;
	return {
		present: !! insp,
		empty,
		blockCount: blocks.length,
		kickers,
		len: ( insp?.innerText || '' ).trim().length,
		focused:
			active?.getAttribute( 'name' ) ||
			active?.getAttribute( 'id' ) ||
			active?.tagName ||
			null,
	};
};

// Only controls that are real settings: the inspector's own controls (its HIDE
// toggle, its clear button) and the sidebar chrome are not settings, and
// focusing one must not count as an inspector pass.
const SETTINGS = 'input:not([type=hidden]), select, textarea, [role=switch]';
const isSetting = ( el ) =>
	! el.closest( '.wppo-inspector' ) && ! el.closest( '.wppo-sidebar' );

const b = await chromium.launch( {
	args: [ '--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu' ],
} );
const ctx = await b.newContext( { viewport: { width: 1440, height: 1000 } } );
const page = await ctx.newPage();
page.setDefaultTimeout( 60000 );
page.setDefaultNavigationTimeout( 60000 );

await page.goto( `${ SITE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', 'admin' );
await page.fill( '#user_pass', 'tempPass123!' );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );
await page.goto(
	`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=${ AREA }`,
	{ waitUntil: 'domcontentloaded' }
);
await page.waitForSelector( '.wppo-section', { timeout: 45000 } );
await page.waitForTimeout( 1500 );

console.log( `area=${ AREA }` );
let allPassed = true;
for ( const [ kind, sel ] of TARGETS ) {
	const loc = page.locator( sel );
	const count = await loc.count();
	let checked = 0;
	let passed = 0;
	const failures = [];
	for ( let i = 0; i < count && checked < 40; i++ ) {
		const el = loc.nth( i );
		if ( ! ( await el.evaluate( isSetting ) ) ) {
			continue;
		}
		checked++;
		await el.scrollIntoViewIfNeeded().catch( () => {} );
		await el.focus().catch( () => {} );
		await page.waitForTimeout( 300 );
		const r = await page.evaluate( read );
		// A pass needs real blocks, no empty state, and all three headings.
		const ok =
			r.present &&
			! r.empty &&
			r.blockCount >= 3 &&
			/does/i.test( r.kickers.join( '|' ) ) &&
			/cost/i.test( r.kickers.join( '|' ) ) &&
			/stand/i.test( r.kickers.join( '|' ) );
		ok ? passed++ : failures.push( `${ r.blockCount }blocks empty=${ r.empty }` );
	}
	if ( checked === 0 ) {
		console.log( `  ${ kind.padEnd( 9 ) } (none on this screen)` );
		continue;
	}
	const ok = passed === checked;
	allPassed = allPassed && ok;
	console.log(
		`  ${ kind.padEnd( 9 ) } settings=${ checked }  3blocks=${ passed }/${ checked }  ${
			ok ? 'PASS' : 'FAIL'
		}`
	);
	if ( ! ok ) {
		for ( const f of failures.slice( 0, 3 ) ) {
			console.log( `             ${ f }` );
		}
	}
}
console.log( allPassed ? '  AREA: PASS' : '  AREA: FAIL' );
await b.close();
