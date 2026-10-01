/**
 * Screenshot the redesigned admin on the real site.
 *
 * There is no browser available to the MCP tooling in this environment (the
 * Playwright MCP looks for a `chrome` channel at /opt/google/chrome/chrome,
 * which is not installed), but Playwright's own bundled Chromium IS present in
 * ~/.cache/ms-playwright — which is how the existing .ux-shots/ captures were
 * made. This drives that instead.
 *
 * It logs in for real, so it exercises the shipped CSS and JS rather than a
 * static mockup, and it also measures the things a screenshot cannot show:
 * horizontal overflow, computed fonts, and whether the shell actually laid out
 * as a three-column grid.
 *
 * Run: node .instrument-shots.js [width] [label]
 */

const path = require( 'path' );
const fs = require( 'fs' );

const PW = path.join( __dirname, 'node_modules', 'playwright' );
const { chromium } = require( PW );

const SITE = 'https://nileshportfolio.duckdns.org';
const OUT = path.join( __dirname, '.ux-shots-instrument' );

const width = Number( process.argv[ 2 ] || 1440 );
const label = process.argv[ 3 ] || `w${ width }`;

/** Screens to capture: the routes the redesign touches. */
const SCREENS = [
	{ slug: 'overview', file: 'overview', q: 'section=overview&view=overview' },
	{ slug: 'all-diagnostics', file: 'all-diagnostics', q: 'section=overview&view=dashboard' },
	{ slug: 'assets', file: 'assets-scripts', q: 'section=speed&view=fileOptimization' },
	{ slug: 'preload', file: 'preload', q: 'section=speed&view=preload' },
	{ slug: 'images', file: 'images', q: 'section=media&view=imageOptimization' },
	{ slug: 'db', file: 'database-cleanup', q: 'section=data-system&view=databaseCleanup' },
	{ slug: 'redis', file: 'object-cache', q: 'section=data-system&view=objectCache' },
	{ slug: 'tools', file: 'tools', q: 'section=manage&view=tools' },
];

( async () => {
	fs.mkdirSync( OUT, { recursive: true } );

	const browser = await chromium.launch( {
		args: [ '--no-sandbox', '--disable-setuid-sandbox' ],
	} );
	const context = await browser.newContext( {
		viewport: { width, height: 1000 },
		deviceScaleFactor: 1,
	} );
	const page = await context.newPage();

	const consoleErrors = [];
	page.on( 'console', ( m ) => {
		if ( m.type() === 'error' ) {
			consoleErrors.push( m.text() );
		}
	} );
	page.on( 'pageerror', ( e ) => consoleErrors.push( 'pageerror: ' + e.message ) );

	// ---- log in ----
	// `commit` rather than `domcontentloaded`: the WordPress front end holds
	// subresources open and the default 30s navigation timeout was tripping on
	// a perfectly healthy page.
	const go = ( url ) =>
		page.goto( url, {
			waitUntil: 'commit',
			timeout: 90000,
		} );

	await go( `${ SITE }/wp-login.php` );
	await page.waitForSelector( '#user_login', { timeout: 60000 } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'tempPass123!' );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
		page.click( '#wp-submit' ),
	] );

	if ( page.url().includes( 'wp-login' ) ) {
		throw new Error( 'login failed — still on wp-login.php' );
	}

	const report = { label, width, screens: [], consoleErrors: [] };

	for ( const screen of SCREENS ) {
		const url = `${ SITE }/wp-admin/admin.php?page=performance-optimisation&${ screen.q }`;
		await go( url );

		// Wait for the SPA to mount, then for webfonts so the shot is not taken
		// against fallback metrics.
		await page
			.waitForSelector( '#performance-optimisation .wppo-container', {
				timeout: 20000,
			} )
			.catch( () => null );
		await page.evaluate( () => document.fonts?.ready ).catch( () => null );
		await page.waitForTimeout( 1200 );

		const file = path.join( OUT, `${ screen.file }-${ label }.png` );
		await page.screenshot( { path: file, fullPage: false } );

		const metrics = await page.evaluate( () => {
			const $ = ( s ) => document.querySelector( s );
			const cs = ( el, p ) => ( el ? getComputedStyle( el )[ p ] : null );
			const box = ( el ) =>
				el
					? {
							w: Math.round( el.getBoundingClientRect().width ),
							h: Math.round( el.getBoundingClientRect().height ),
					  }
					: null;

			const container = $( '.wppo-container' );
			const inspector = $( '.wppo-inspector' );
			const title = $( '.wppo-section__title' );
			const eyebrow = $( '.wppo-section__eyebrow' );
			const rail = $( '.wppo-rail' );
			const marker = $( '.wppo-rail__marker' );
			const navBtn = $( '.wppo-sidebar .wppo-is-active' );

			// Any element wider than the viewport is a horizontal-overflow bug —
			// EXCEPT inside a deliberately scrollable ancestor. A <code> inside a
			// `overflow-x: auto` <pre> reports a box wider than its scroller by
			// definition, which is correct behaviour, not a defect. Reporting it
			// was a false positive that hid real ones.
			const inScroller = ( el ) => {
				let n = el.parentElement;
				while ( n && n !== document.body ) {
					const ox = getComputedStyle( n ).overflowX;
					if ( ox === 'auto' || ox === 'scroll' ) {
						return true;
					}
					n = n.parentElement;
				}
				return false;
			};

			const overflowing = [];
			document
				.querySelectorAll( '.wppo-container *' )
				.forEach( ( el ) => {
					const r = el.getBoundingClientRect();
					if (
						r.right > window.innerWidth + 1 &&
						r.width > 0 &&
						! inScroller( el )
					) {
						overflowing.push(
							`${ el.tagName.toLowerCase() }.${ ( el.className || '' )
								.toString()
								.split( ' ' )
								.filter( Boolean )
								.slice( 0, 2 )
								.join( '.' ) } +${ Math.round(
								r.right - window.innerWidth
							) }px`
						);
					}
				} );

			return {
				docScrollWidth: document.documentElement.scrollWidth,
				innerWidth: window.innerWidth,
				hasHorizontalScroll:
					document.documentElement.scrollWidth > window.innerWidth + 1,
				overflowing: overflowing.slice( 0, 6 ),
				containerDisplay: cs( container, 'display' ),
				containerCols: cs( container, 'gridTemplateColumns' ),
				navW: box( $( '.wppo-sidebar' ) )?.w ?? null,
				navBg: cs( $( '.wppo-sidebar' ), 'backgroundColor' ),
				inspectorW: box( inspector )?.w ?? null,
				inspectorPos: cs( inspector, 'position' ),
				titleFont: cs( title, 'fontFamily' ),
				titleText: title?.textContent?.trim() ?? null,
				eyebrowText: eyebrow?.textContent?.trim() ?? null,
				railPresent: Boolean( rail ),
				railTone: rail?.dataset?.tone ?? null,
				markerLeft: marker?.style?.left ?? null,
				activeNav: navBtn?.textContent?.trim() ?? null,
			};
		} );

		report.screens.push( { ...screen, file: path.basename( file ), ...metrics } );
	}

	report.consoleErrors = [ ...new Set( consoleErrors ) ].slice( 0, 10 );

	fs.writeFileSync(
		path.join( OUT, `report-${ label }.json` ),
		JSON.stringify( report, null, 2 )
	);

	await browser.close();
	console.log( JSON.stringify( report, null, 2 ) );
} )().catch( ( e ) => {
	console.error( 'FAILED:', e.message );
	process.exit( 1 );
} );