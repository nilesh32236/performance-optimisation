/**
 * Live-page can-fail proof for the contrast fix.
 *
 * `scripts/a11y-axe-selfcheck.js` proves the detector fires on a synthetic
 * fixture. This proves something stronger and more specific: that on the REAL
 * admin SPA, after the contrast fix, the same axe run that now reports zero
 * `color-contrast` violations will report one the instant a deliberately
 * low-contrast pair is introduced -- and stops reporting it when that pair is
 * removed.
 *
 * Without this, "0 serious violations" is indistinguishable from a run that
 * stopped measuring. A check that cannot fail is the same defect as the bug it
 * guards.
 *
 * The injected pair is #9494a8 on #d2d2dc, roughly 1.9:1, far past the 4.5:1
 * that 14px normal text needs, so the assertion cannot hinge on antialiasing.
 * It is injected at runtime via the DOM and is never committed to the source.
 *
 * Run: node scripts/a11y-contrast-canfail.js
 */

const { chromium } = require( 'playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

const SITE = process.env.WPPO_SITE || 'https://nileshportfolio.duckdns.org';
const USER = process.env.WPPO_USER || 'admin';
const PASS = process.env.WPPO_PASS || 'tempPass123!';
const MOUNT = '#performance-optimisation';

/** Screen that carries all three of the selectors this fix touched. */
const SCREEN = 'section=speed&view=fileOptimization';

/**
 * The deliberate defect: a ~1.9:1 contrast pair injected into the live SPA.
 *
 * @return {string} HTML for the injected node.
 */
const BAD_PAIR = `
<div id="wppo-canfail-probe" style="
	background:#d2d2dc;
	color:#9494a8;
	font-size:14px;
	padding:8px 12px;
	margin-top:8px;
">
	Deliberate low-contrast probe for the can-fail check.
</div>`;

/**
 * Run axe over the plugin mount point, exactly as the real run does.
 *
 * @param {import('playwright').Page} page Page under test.
 * @return {Promise<Array>} color-contrast node targets.
 */
const contrastNodes = async ( page ) => {
	const results = await new AxeBuilder( { page } ).include( MOUNT ).analyze();
	const violation = results.violations.find( ( v ) => v.id === 'color-contrast' );
	return ( violation?.nodes ?? [] ).map( ( n ) => n.target.join( ' ' ) );
};

( async () => {
	const browser = await chromium.launch( {
		args: [ '--no-sandbox', '--disable-setuid-sandbox' ],
	} );
	const page = await ( await browser.newContext() ).newPage();
	const say = ( line ) => process.stdout.write( line + '\n' );

	await page.goto( `${ SITE }/wp-login.php`, {
		waitUntil: 'commit',
		timeout: 90000,
	} );
	await page.waitForSelector( '#user_login', { timeout: 60000 } );
	await page.fill( '#user_login', USER );
	await page.fill( '#user_pass', PASS );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'commit', timeout: 90000 } ),
		page.click( '#wp-submit' ),
	] );

	await page.goto(
		`${ SITE }/wp-admin/admin.php?page=performance-optimisation&${ SCREEN }`,
		{ waitUntil: 'commit', timeout: 90000 }
	);
	await page.waitForSelector( `${ MOUNT } .wppo-container`, { timeout: 30000 } );
	await page.waitForTimeout( 3000 );

	const failures = [];

	// ---- GREEN: the fixed page, as shipped --------------------------------
	const before = await contrastNodes( page );
	say( '=== BASELINE: the fixed SPA, no probe injected ===' );
	say( `color-contrast nodes: ${ before.length } ${ JSON.stringify( before ) }` );
	if ( before.length !== 0 ) {
		failures.push(
			`expected 0 color-contrast nodes on the fixed page, got ${ before.length }: ${ before.join(
				', '
			) }`
		);
	}

	// ---- RED: inject the deliberate ~1.9:1 pair ---------------------------
	await page.evaluate( ( html ) => {
		document.querySelector( '#performance-optimisation' ).insertAdjacentHTML(
			'beforeend',
			html
		);
	}, BAD_PAIR );
	await page.waitForTimeout( 400 );

	const during = await contrastNodes( page );
	say( '' );
	say( '=== RED: deliberate ~1.9:1 pair injected ===' );
	say( `color-contrast nodes: ${ during.length } ${ JSON.stringify( during ) }` );
	if ( ! during.some( ( t ) => t.includes( 'wppo-canfail-probe' ) ) ) {
		failures.push(
			'axe did NOT report the injected low-contrast pair; the check cannot be trusted'
		);
	} else {
		say( '  DETECTED  wppo-canfail-probe  ~1.9:1 vs the 4.5:1 requirement' );
	}

	// ---- GREEN: remove it again -------------------------------------------
	await page
		.locator( '#wppo-canfail-probe' )
		.evaluate( ( el ) => el.remove() );
	await page.waitForTimeout( 400 );

	const after = await contrastNodes( page );
	say( '' );
	say( '=== GREEN: probe removed ===' );
	say( `color-contrast nodes: ${ after.length } ${ JSON.stringify( after ) }` );
	if ( after.some( ( t ) => t.includes( 'wppo-canfail-probe' ) ) ) {
		failures.push( 'the injected probe persisted after removal' );
	} else {
		say( '  CLEARED   wppo-canfail-probe' );
	}

	await browser.close();

	say( '' );
	if ( failures.length ) {
		say( 'FAIL: the accessibility check did not behave correctly.' );
		failures.forEach( ( f ) => say( `  - ${ f }` ) );
		process.exit( 1 );
	}
	say(
		'PASS: the same axe run that reports 0 color-contrast violations on the\n' +
			'fixed SPA reports the probe the moment a ~1.9:1 pair is injected, and\n' +
			'stops reporting it once removed. The zero is a measurement.'
	);
	process.exit( 0 );
} )().catch( ( error ) => {
	process.stderr.write(
		'FAILED -- the can-fail proof could not run, so nothing was proven: ' +
			error.stack +
			'\n'
	);
	process.exit( 2 );
} );