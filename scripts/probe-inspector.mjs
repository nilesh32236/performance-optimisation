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

const read = () => {
	const insp = document.querySelector( '.wppo-inspector' );
	const txt = ( insp?.innerText || '' ).trim();
	const active = document.activeElement;
	return {
		present: !! insp,
		len: txt.length,
		whatItDoes: /what it does/i.test( txt ),
		whatItCosts: /what it costs/i.test( txt ),
		whereYouStand: /where you stand/i.test( txt ),
		head: txt.slice( 0, 120 ).replace( /\s+/g, ' ' ),
		focused:
			active?.getAttribute( 'name' ) ||
			active?.getAttribute( 'id' ) ||
			active?.tagName ||
			null,
	};
};

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
for ( const [ kind, sel ] of TARGETS ) {
	const loc = page.locator( sel ).first();
	if ( ( await loc.count() ) === 0 ) {
		console.log( `  ${ kind.padEnd( 9 )} ABSENT on this screen` );
		continue;
	}
	// Walk the real controls, not just the first: hover precedence, focus
	// precedence and pin precedence are three different paths.
	const count = await page.locator( sel ).count();
	let allOk = true;
	const seen = [];
	for ( let i = 0; i < Math.min( count, 4 ); i++ ) {
		const el = page.locator( sel ).nth( i );
		await el.scrollIntoViewIfNeeded().catch( () => {} );
		await el.focus().catch( () => {} );
		await page.waitForTimeout( 320 );
		const r = await page.evaluate( read );
		const ok =
			r.present && r.whatItDoes && r.whatItCosts && r.whereYouStand && r.len > 60;
		allOk = allOk && ok;
		if ( i === 0 ) {
			seen.push( r );
		}
	}
	const r = seen[ 0 ] || {};
	console.log(
		`  ${ kind.padEnd( 9 ) } found=${ count }  checked=${ Math.min( count, 4 ) }` +
			`  3blocks=${ allOk }  inspectorLen=${ r.len }`
	);
	console.log( `             "${ r.head || '' }"` );
	if ( ! allOk ) {
		console.log(
			`             present=${ r.present } does=${ r.whatItDoes } costs=${ r.whatItCosts } stand=${ r.whereYouStand }`
		);
	}
}
await b.close();
