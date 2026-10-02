/**
 * Prove the page-head Save submits the CURRENT value, not the one captured when
 * the node was published. This is the bug the unit suite cannot see: every
 * assertion passes with a stale closure in place.
 */
import { chromium } from 'playwright';
const SITE = 'https://nileshportfolio.duckdns.org';
const b = await chromium.launch( { args: [ '--no-sandbox', '--disable-dev-shm-usage' ] } );
const ctx = await b.newContext( { viewport: { width: 1440, height: 1000 } } );
const p = await ctx.newPage();
p.setDefaultTimeout( 60000 );
p.setDefaultNavigationTimeout( 60000 );
const payloads = [];
let watching = false;
const errs = [];
p.on( 'console', ( m ) => { if ( m.type() === 'error' ) errs.push( m.text().slice( 0, 140 ) ); } );
p.on( 'request', ( r ) => {
	if ( ! watching ) return;
	const u = r.url();
	if ( u.includes( 'admin-ajax' ) || r.method() === 'POST' || u.includes( 'rest' ) ) {
		payloads.push( `${ r.method() } ${ u.slice( 0, 90 ) } :: ${ r.postData() ?? '' }` );  // FULL body: the containment checks read this
	}
} );
await p.goto( `${ SITE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await p.fill( '#user_login', 'admin' );
await p.fill( '#user_pass', (process.env.WPPO_ADMIN_PASS' );
await Promise.all( [
	p.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	p.click( '#wp-submit' ),
] );
await p.goto(
	`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=speed`,
	{ waitUntil: 'domcontentloaded' }
);
await p.waitForSelector( '.wppo-section', { timeout: 45000 } );
await p.waitForTimeout( 2000 );

// A number input we can change: critical-CSS max size.
const field = p.locator( 'input[type=number]' ).first();
const id = await field.getAttribute( 'id' );
const before = await field.inputValue();
const after = String( Number( before ) + 137 );
await field.fill( after );
await p.waitForTimeout( 700 );

const save = p.locator( '.wppo-section__header-actions button' ).first();
const inHead = ( await save.count() ) > 0;
console.log( `  button text: "${ ( await save.textContent() ).trim() }" disabled=${ await save.isDisabled() }` );
watching = true;
await save.click();
await p.waitForTimeout( 2500 );

payloads.forEach( ( x ) => console.log( '  REQ len=' + x.length + ' containsNew=' + x.includes( after ) + ' containsOld=' + x.includes( `"${ before }"` ) ) );
console.log( '  console errors: ' + errs.length );
errs.slice( 0, 3 ).forEach( ( e ) => console.log( '    ' + e ) );
const withNew = payloads.some( ( x ) => x.includes( after ) );
const withOld = payloads.some( ( x ) => x.includes( `"${ before }"` ) );
console.log( `  field #${ id }: ${ before } -> ${ after }` );
console.log( `  save button in page head: ${ inHead }` );
console.log( `  POST payloads captured: ${ payloads.length }` );
console.log( `  payload contains NEW value ${ after }: ${ withNew }` );
console.log( `  payload contains OLD value ${ before }: ${ withOld }` );
console.log(
	`  VERDICT: ${ inHead && withNew && ! withOld ? 'PASS - saved the current value' : 'FAIL' }`
);
if ( payloads.length ) {
	const i = payloads[0]?.indexOf('unusedCSSRegressionThreshold');
console.log( '  payload around the changed key: ' + (i>=0 ? payloads[0].slice(i, i+60) : 'KEY NOT IN PAYLOAD') );
}
await b.close();
