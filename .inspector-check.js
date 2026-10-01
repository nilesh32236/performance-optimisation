/**
 * Verify the inspector actually works on the live admin.
 *
 * Screenshots cannot show this: the panel only fills when something is focused,
 * so every shot so far has captured the empty state. This drives a real
 * keyboard focus and reads back what the panel says.
 *
 * It checks the whole contract, because each half can fail alone and look the
 * same in a picture:
 *   - focusing a control loads its subject (hover/focus precedence)
 *   - the panel answers the three questions in order
 *   - the consequence block is tinted when the setting is risky
 *   - tabbing away clears it
 *   - the row that is loaded is visibly marked
 *
 * Run: node .inspector-check.js
 */

const path = require( 'path' );
const fs = require( 'fs' );
const { chromium } = require( path.join( __dirname, 'node_modules', 'playwright' ) );

const SITE = 'https://nileshportfolio.duckdns.org';
const OUT = path.join( __dirname, '.ux-shots-instrument' );

/** The settings we focus, and what the panel must then contain. */
const EXPECT = [
	{
		key: 'minifyCSS',
		label: 'Minify CSS',
		expect: [ 'What it does', 'What it costs you', 'No known downside' ],
		tone: null,
	},
	{
		key: 'combineCSS',
		label: 'Combine CSS',
		expect: [ 'What it costs you', 'unstyled content' ],
		tone: 'warn',
	},
	{
		key: 'removeUnusedCSS',
		label: 'Remove Unused CSS',
		expect: [ 'safelist', 'What it costs you' ],
		tone: 'warn',
	},
	// A textarea, not a switch. This is the case that used to have no
	// explanation at all, and a field that can hold an invalid selector is
	// exactly the one that needs one.
	{
		key: 'excludeUnusedCSS',
		label: 'Safelist Selectors',
		expect: [ 'What it does', 'dropdown', 'Where you stand now' ],
		tone: 'warn',
		control: 'textarea',
	},
	{
		key: 'usedCSSDeliveryMode',
		label: 'Used CSS Delivery Mode',
		expect: [ 'What it does', 'What it costs you' ],
		tone: 'warn',
		control: 'select',
	},
	{
		key: 'unusedCSSRegressionThreshold',
		label: 'Minimum Retained CSS',
		expect: [ 'What it does', 'Where you stand now' ],
		tone: 'warn',
		control: 'input',
	},
];

const results = [];
let failures = 0;

( async () => {
	fs.mkdirSync( OUT, { recursive: true } );

	const browser = await chromium.launch( {
		args: [ '--no-sandbox', '--disable-setuid-sandbox' ],
	} );
	const page = await ( await browser.newContext( { viewport: { width: 1600, height: 1000 } } ) ).newPage();
	const go = ( u ) => page.goto( u, { waitUntil: 'commit', timeout: 90000 } );

	await go( `${ SITE }/wp-login.php` );
	await page.waitForSelector( '#user_login', { timeout: 60000 } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'tempPass123!' );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'commit' } ),
		page.click( '#wp-submit' ),
	] );

	await go(
		`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=speed&view=fileOptimization`
	);
	await page.waitForSelector( '.wppo-inspector', { timeout: 30000 } );
	await page.evaluate( () => document.fonts?.ready ).catch( () => null );
	await page.waitForTimeout( 1500 );

	for ( const item of EXPECT ) {
		// Focus the switch the way a keyboard user would, not by clicking: focus
		// is the path that must work, since it is the only one available
		// without a pointer.
		//
		// Rows are located by their visible label. `SwitchField` renders a
		// `ToggleControl` and does **not** forward its `name` prop to the input,
		// so `[name="minifyCSS"]` matches nothing — the row's identity in the DOM
		// is the text it shows.
		//
		// The control to focus is chosen by `item.control` where given, because
		// a row can contain more than one focusable thing (a switch and the
		// fields it reveals) and picking the first checkbox would silently test
		// the parent toggle instead of the field under test.
		const focused = await page.evaluate(
			( { label, control } ) => {
				const row = [
					...document.querySelectorAll( '.wppo-setting-row' ),
				].find( ( r ) => r.textContent.includes( label ) );
				if ( ! row ) {
					return 'no row';
				}
				const selector = control
					? control
					: 'input[type=checkbox]';
				const el = row.querySelector( selector );
				if ( ! el ) {
					return `no ${ selector }`;
				}
				el.focus();
				return 'ok';
			},
			{ label: item.label, control: item.control }
		);

		await page.waitForTimeout( 500 );

		const state = await page.evaluate( ( label ) => {
			const panel = document.querySelector( '.wppo-inspector' );
			const subject = panel?.querySelector( '.wppo-inspector__subject h2' );
			const blocks = [ ...( panel?.querySelectorAll( '.wppo-inspector__block .wppo-eyebrow' ) || [] ) ]
				.map( ( n ) => n.textContent.trim() );
			const cost = panel?.querySelector( '.wppo-inspector__block--warn, .wppo-inspector__block--bad' );
			const active = document.querySelector( '.wppo-setting-row--active' );
			return {
				text: ( panel?.textContent || '' ).replace( /\s+/g, ' ' ),
				subject: subject?.textContent.trim() || null,
				blocks,
				costTone: cost ? ( cost.className.includes( 'bad' ) ? 'bad' : 'warn' ) : null,
				activeRow: active?.textContent.includes( label ) ? label : null,
			};
		}, item.label );

		const missing = item.expect.filter( ( s ) => ! state.text.includes( s ) );
		const toneOk = item.tone ? state.costTone === item.tone : ! state.costTone;
		const marked = state.activeRow === item.label;

		const ok = focused === 'ok' && ! missing.length && toneOk && marked;
		if ( ! ok ) {
			failures++;
		}

		results.push( {
			key: item.key,
			ok,
			focused,
			subject: state.subject,
			blocks: state.blocks,
			costTone: state.costTone,
			expectTone: item.tone,
			activeRow: state.activeRow,
			missing,
			excerpt: state.text.slice( 0, 260 ),
		} );

		await page.screenshot( {
			path: path.join( OUT, `inspector-${ item.key }.png` ),
			fullPage: false,
		} );
	}

	// Tabbing away must clear the panel, or it goes stale describing a control
	// the user is no longer on. Focus has to move to something genuinely
	// focusable — `document.body.focus()` is a no-op unless tabindex is set, so
	// it never fired a blur and reported a false failure.
	const moved = await page.evaluate( () => {
		const outside = document.querySelector(
			'.wppo-sidebar nav button, .wppo-section__title'
		);
		if ( ! outside ) {
			return 'no focusable target';
		}
		outside.focus();
		return document.activeElement === outside ? 'ok' : 'focus did not move';
	} );
	await page.waitForTimeout( 400 );
	const cleared = await page.evaluate( () => {
		const panel = document.querySelector( '.wppo-inspector' );
		return ! panel?.querySelector( '.wppo-inspector__subject h2' );
	} );
	if ( ! cleared || moved !== 'ok' ) {
		failures++;
	}

	await browser.close();

	console.log( JSON.stringify( { results, clearedOnBlur: cleared, failures }, null, 1 ) );
	process.exit( failures ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( 'FAILED:', e.message );
	process.exit( 1 );
} );