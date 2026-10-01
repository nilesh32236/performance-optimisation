/**
 * Headless smoke test for the WPPO reference UI.
 *
 * No browser binary is available in this environment, so this uses jsdom to
 * actually execute the app: boot it, walk all 8 screens in all 3 themes, and
 * report uncaught errors plus a content size per screen.
 *
 * jsdom has no layout engine, so this CANNOT check overflow, real font metrics
 * or paint. Those still need a human or a real browser.
 *
 * Run:  node /var/tmp/wp-graphql/wppo-ui/.scratch/smoke.js
 */
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( path.join(
	'/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation/node_modules/jsdom'
) );

let BASE = '';
let APP = '';

const SCREENS = [
	[ 'overview', 'overview' ],
	[ 'overview', 'dashboard' ],
	[ 'speed', 'fileOptimization' ],
	[ 'speed', 'preload' ],
	[ 'media', 'imageOptimization' ],
	[ 'data-system', 'databaseCleanup' ],
	[ 'data-system', 'objectCache' ],
	[ 'manage', 'tools' ],
];
const THEMES = [ 'native', 'deck', 'utility' ];

const problems = [];
let checks = 0;

function note( msg ) {
	problems.push( msg );
}

/** Boot the app in a fresh jsdom and hand the window to `fn`. */
async function boot( area, screen, theme ) {
	const errors = [];
	const vc = new VirtualConsole();
	vc.on( 'jsdomError', ( e ) => errors.push( 'jsdomError: ' + ( e.message || e ) ) );
	vc.on( 'error', ( ...a ) => errors.push( 'console.error: ' + a.join( ' ' ) ) );

	const url = `${ APP }?area=${ encodeURIComponent( area ) }&screen=${ encodeURIComponent( screen ) }&theme=${ theme }`;

	// beforeParse runs BEFORE any page script executes, so the app never sees a
	// jsdom gap. Installing these after load is too late and masks real bugs.
	const dom = await JSDOM.fromURL( url, {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole: vc,
		beforeParse( window ) {
			window.matchMedia =
				window.matchMedia ||
				( ( q ) => ( {
					matches: false,
					media: q,
					onchange: null,
					addListener() {},
					removeListener() {},
					addEventListener() {},
					removeEventListener() {},
					dispatchEvent() {
						return false;
					},
				} ) );
			window.ResizeObserver =
				window.ResizeObserver ||
				class {
					observe() {}
					unobserve() {}
					disconnect() {}
				};
			window.IntersectionObserver =
				window.IntersectionObserver ||
				class {
					observe() {}
					unobserve() {}
					disconnect() {}
					takeRecords() {
						return [];
					}
				};
			// jsdom throws "Not implemented" for these and logs an error that
			// would otherwise be misreported as an app fault.
			window.scrollTo = () => {};
			window.scrollBy = () => {};
			window.requestAnimationFrame = ( cb ) => window.setTimeout( () => cb( Date.now() ), 16 );
			window.cancelAnimationFrame = ( id ) => window.clearTimeout( id );
		},
	} );

	const { window } = dom;

	// jsdom fetches external scripts in parallel, so DOMContentLoaded can fire
	// before app.js has run. Poll for readiness rather than guessing a delay.
	const deadline = Date.now() + 8000;
	while ( Date.now() < deadline ) {
		if ( window.PO && window.PO.store && window.WPPO_MOCK && window.WPPO_SETTINGS_SCHEMA ) {
			break;
		}
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}
	// Let the post-boot render settle.
	await new Promise( ( r ) => setTimeout( r, 300 ) );

	return { window, errors, dom };
}

function mainContent( window ) {
	const app = window.document.getElementById( 'app' ) || window.document.body;
	// Exclude the fixed preview bar so its text cannot mask an empty screen.
	const clone = app.cloneNode( true );
	clone.querySelectorAll( '.po-previewbar, [data-preview-bar], .preview-bar' ).forEach( ( n ) => n.remove() );
	return ( clone.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}

( async () => {
	const srv = await require( './static' ).start();
	BASE = srv.base;
	APP = `${ BASE }/app/index.html`;
	console.log( 'serving wppo-ui at', BASE, '\n' );
	for ( const theme of THEMES ) {
		for ( const [ area, screen ] of SCREENS ) {
			checks++;
			const label = `${ theme }/${ area }/${ screen }`;
			let ctx;
			try {
				ctx = await boot( area, screen, theme );
			} catch ( e ) {
				note( `${ label }: FAILED TO BOOT — ${ e.message }` );
				continue;
			}

			const { window, errors, dom } = ctx;
			for ( const e of errors ) {
				note( `${ label }: ${ e }` );
			}

			const text = mainContent( window );
			if ( text.length < 120 ) {
				note( `${ label }: content too thin (${ text.length } chars) — "${ text.slice( 0, 80 ) }"` );
			}

			// Stub text means a screen is still a placeholder.
			if ( /next pass|coming soon|being built|not yet implemented/i.test( text ) ) {
				note( `${ label }: still a stub` );
			}

			// Theme must actually be applied to the document.
			const applied = window.document.body.dataset.theme;
			if ( applied !== theme ) {
				note( `${ label }: body[data-theme] is "${ applied }", expected "${ theme }"` );
			}

			// Global primitives phase 1/2 promised must exist.
			const nForms = window.document.querySelectorAll( 'input, select, textarea' ).length;
			const nButtons = window.document.querySelectorAll( 'button' ).length;
			if ( nButtons < 3 ) {
				note( `${ label }: only ${ nButtons } buttons — controls likely missing` );
			}

			process.stdout.write(
				`${ label.padEnd( 34 ) } chars=${ String( text.length ).padStart( 5 ) }  controls=${ String(
					nForms + nButtons
				).padStart( 4 ) }  ${ errors.length ? 'ERRORS:' + errors.length : 'clean' }\n`
			);

			dom.window.close();
		}
	}

	// ---- conditional-visibility behaviour, on the CSS settings screen ----
	checks++;
	try {
		const { window, errors, dom } = await boot( 'speed', 'fileOptimization', 'deck' );
		errors.forEach( ( e ) => note( `showIf check: ${ e }` ) );
		const doc = window.document;

		const critical = doc.querySelector( 'input[data-setting="criticalCSS"]' );
		if ( ! critical ) {
			note( 'showIf check: could not find the criticalCSS toggle' );
		} else {
			/* Conditional blocks are ALWAYS in the DOM; the app toggles the HTML
			   `hidden` attribute on a [data-when] wrapper. Asserting on node
			   COUNT cannot detect anything -- that was a bad assertion. */
			const gated = () =>
				Array.from( doc.querySelectorAll( '[data-when]' ) ).filter( ( n ) =>
					/criticalCSS/.test( n.getAttribute( 'data-when' ) )
				);
			const hiddenOf = ( list ) => list.filter( ( n ) => n.hasAttribute( 'hidden' ) ).length;

			const startHidden = hiddenOf( gated() );
			critical.checked = ! critical.checked;
			critical.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			await new Promise( ( r ) => setTimeout( r, 400 ) );
			const flippedHidden = hiddenOf( gated() );

			process.stdout.write(
				`\nshowIf check: criticalCSS=${ critical.checked }, dependent hidden ${ startHidden } -> ${ flippedHidden }\n`
			);
			if ( flippedHidden === startHidden ) {
				note( 'showIf: toggling criticalCSS did not change its dependents\' hidden state' );
			}

			critical.checked = ! critical.checked;
			critical.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
			await new Promise( ( r ) => setTimeout( r, 400 ) );
			if ( hiddenOf( gated() ) !== startHidden ) {
				note( 'showIf: toggling criticalCSS back did not restore the original visibility' );
			}
		}
		dom.window.close();
	} catch ( e ) {
		note( 'showIf check threw: ' + e.message );
	}

	// ---- CDN repeater ----
	checks++;
	try {
		const { window, dom } = await boot( 'speed', 'fileOptimization', 'deck' );
		const doc = window.document;
		const before = doc.querySelectorAll( '[data-repeater-row], [data-setting-row]' ).length;
		const add = doc.querySelector( '[data-repeater-add], .po-repeater__add' );
		if ( add ) {
			add.click();
			await new Promise( ( r ) => setTimeout( r, 250 ) );
			const after = doc.querySelectorAll(
				'[data-repeater-row], [data-setting-row]'
			).length;
			process.stdout.write( `repeater check: rows ${ before } -> ${ after } after add\n` );
			if ( after <= before ) {
				note( 'repeater: add button did not add a CDN mapping row' );
			}
		} else {
			process.stdout.write( 'repeater check: no add control found on default sub-tab (Network sub-tab may be needed)\n' );
		}
		dom.window.close();
	} catch ( e ) {
		note( 'repeater check threw: ' + e.message );
	}

	// ---- command palette (theme C) ----
	checks++;
	try {
		const { window, dom } = await boot( 'overview', 'overview', 'utility' );
		doc = window.document;
		window.document.dispatchEvent(
			new window.KeyboardEvent( 'keydown', { key: 'k', ctrlKey: true, bubbles: true } )
		);
		await new Promise( ( r ) => setTimeout( r, 300 ) );
		const open = window.document.querySelector(
			'[role="dialog"], .po-cmdk, [data-cmdk], .cmdk'
		);
		process.stdout.write( `cmdk check: dialog ${ open ? 'OPEN' : 'not found' } after Ctrl+K\n` );
		if ( ! open ) {
			note( '⌘K: no command-palette dialog appeared on Ctrl+K in theme C' );
		}
		dom.window.close();
	} catch ( e ) {
		note( 'cmdk check threw: ' + e.message );
	}

	// ---- schema wiring ----
	checks++;
	try {
		const { window, dom } = await boot( 'overview', 'overview', 'native' );
		const S = window.WPPO_SETTINGS_SCHEMA;
		const M = window.WPPO_MOCK;
		if ( ! S ) {
			note( 'settings schema did not load (window.WPPO_SETTINGS_SCHEMA undefined)' );
		} else {
			const tabs = S.tabs || [];
			let fields = 0;
			tabs.forEach( ( t ) =>
				( t.groups || [] ).forEach( ( g ) =>
					( g.cards || [] ).forEach( ( c ) => ( c.fields || [] ).forEach( ( f ) => fields++ ) )
				)
			);
			process.stdout.write( `schema check: ${ tabs.length } tabs, ${ fields } fields\n` );
			if ( tabs.length < 10 ) {
				note( `schema: only ${ tabs.length } tabs, expected >= 10` );
			}
		}
		if ( ! M ) {
			note( 'mock data did not load (window.WPPO_MOCK undefined)' );
		}
		dom.window.close();
	} catch ( e ) {
		note( 'schema check threw: ' + e.message );
	}

	console.log( '\n' + '='.repeat( 66 ) );
	if ( problems.length ) {
		console.log( `FAIL — ${ problems.length } problem(s) across ${ checks } checks:\n` );
		problems.forEach( ( p, i ) => console.log( `${ i + 1 }. ${ p }` ) );
		process.exitCode = 1;
	} else {
		console.log( `PASS — ${ checks } checks, no errors.` );
	}

	/* The in-process static server keeps the event loop alive; without an
	   explicit close this script never exits. */
	srv.close();
	process.exit( problems.length ? 1 : 0 );
} )();
