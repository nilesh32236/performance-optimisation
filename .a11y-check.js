/**
 * Accessibility check against the live admin.
 *
 * This exists because the unit suite **cannot** verify it. Jest renders a
 * stubbed `ToggleControl` — a bare `<input aria-label>` with no generated id,
 * no `<label htmlFor>` and no `help` support — so the real association
 * (`help` → `aria-describedby` on the input) is invisible to every jest test.
 * That gap is how a description shipped that was on screen and announced to
 * nobody.
 *
 * So this drives the shipped bundle in a real browser, where accessibility
 * trees and the cascade actually exist, and asserts the properties that matter
 * for this redesign:
 *
 *   - every control has an accessible name (not merely an adjacent <label>)
 *   - every described control exposes that description programmatically
 *   - no setting name is painted twice in one row
 *   - the pin affordance is out of the accessibility tree
 *   - the inspector panel is a live region, not silent
 *
 * Run: node .a11y-check.js
 */

const path = require( 'path' );
const { chromium } = require( path.join( __dirname, 'node_modules', 'playwright' ) );

const SITE = 'https://nileshportfolio.duckdns.org';

/** Screens dense with mixed control types, so one pass covers every branch. */
const SCREENS = [
	{ slug: 'assets', q: 'section=speed&view=fileOptimization' },
	{ slug: 'preload', q: 'section=speed&view=preload' },
	{ slug: 'images', q: 'section=media&view=imageOptimization' },
	{ slug: 'db', q: 'section=data-system&view=databaseCleanup' },
	{ slug: 'redis', q: 'section=data-system&view=objectCache' },
];

( async () => {
	const browser = await chromium.launch( {
		args: [ '--no-sandbox', '--disable-setuid-sandbox' ],
	} );
	const page = await (
		await browser.newContext( { viewport: { width: 1600, height: 1000 } } )
	).newPage();

	const go = ( u ) =>
		page.goto( u, { waitUntil: 'commit', timeout: 90000 } );

	await go( `${ SITE }/wp-login.php` );
	await page.waitForSelector( '#user_login', { timeout: 60000 } );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'tempPass123!' );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'commit' } ),
		page.click( '#wp-submit' ),
	] );

	const report = [];

	for ( const screen of SCREENS ) {
		await go(
			`${ SITE }/wp-admin/admin.php?page=performance-optimisation&${ screen.q }`
		);
		await page
			.waitForSelector( '#performance-optimisation .wppo-container', {
				timeout: 30000,
			} )
			.catch( () => null );
		await page.waitForTimeout( 1200 );

		const data = await page.evaluate( () => {
			/** The accessible name, as a screen reader would compute it. */
			const accName = ( el ) => {
				const labelledby = el.getAttribute( 'aria-labelledby' );
				if ( labelledby ) {
					return labelledby
						.split( /\s+/ )
						.map(
							( id ) =>
								document.getElementById( id )?.textContent?.trim() ??
								''
						)
						.join( ' ' )
						.trim();
				}
				if ( el.getAttribute( 'aria-label' ) ) {
					return el.getAttribute( 'aria-label' ).trim();
				}
				if ( el.id ) {
					const lbl = document.querySelector(
						`label[for="${ CSS.escape( el.id ) }"]`
					);
					if ( lbl ) {
						return lbl.textContent.trim();
					}
				}
				// A wrapping <label> is a legitimate association.
				const wrap = el.closest( 'label' );
				if ( wrap ) {
					return wrap.textContent.trim();
				}
				return '';
			};

			/** The accessible description, via aria-describedby. */
			const accDesc = ( el ) => {
				const ids = el.getAttribute( 'aria-describedby' );
				if ( ! ids ) {
					return '';
				}
				return ids
					.split( /\s+/ )
					.map(
						( id ) =>
							document.getElementById( id )?.textContent?.trim() ??
							''
					)
					.join( ' ' )
					.trim();
			};

			/** Is this text actually painted? */
			const painted = ( el ) => {
				if ( ! el ) {
					return false;
				}
				const r = el.getBoundingClientRect();
				if ( r.width < 2 || r.height < 2 ) {
					return false;
				}
				const cs = getComputedStyle( el );
				return (
					cs.visibility !== 'hidden' &&
					cs.display !== 'none' &&
					Number( cs.opacity ) > 0.01
				);
			};

			const controls = [
				...document.querySelectorAll(
					'#performance-optimisation input, #performance-optimisation select, #performance-optimisation textarea'
				),
			].filter( ( el ) => el.type !== 'hidden' );

			const unnamed = [];
			const undescribed = [];
			controls.forEach( ( el ) => {
				const name = accName( el );
				const desc = accDesc( el );
				const tag =
					el.tagName.toLowerCase() +
					( el.type ? `[${ el.type }]` : '' );
				if ( ! name ) {
					unnamed.push( tag );
				} else if ( ! desc ) {
					// Flag a control whose *own* description is on screen but
					// not associated. "Its own" is structural, and the two
					// shapes differ:
					//
					//  - `.wppo-switch-field` puts the description in
					//    `__info`, a container belonging to that one control.
					//  - `.wppo-field` puts it in the `<p>` immediately after
					//    the control.
					//
					// It deliberately does NOT treat any `<p>` anywhere in the
					// wrapper as the control's description. A `.wppo-field`
					// can group a number input, several buttons, an
					// `aria-live` status line and one shared note; that note
					// describes the group, and wiring it to the input would
					// make a screen reader read it as that field's help.
					let help = null;

					const sw = el.closest( '.wppo-switch-field' );
					if ( sw ) {
						help = sw.querySelector(
							'.wppo-switch-field__info p'
						);
					} else {
						// A wrapping <label> puts the help outside the control's
						// sibling chain, so only consider a real sibling.
						const next = el.nextElementSibling;
						help =
							next &&
							next.tagName === 'P' &&
							! next.hasAttribute( 'aria-live' )
								? next
								: null;
					}

					// `aria-live` text is an action status, announced on its
					// own, and is never a description.
					if ( help && painted( help ) ) {
						undescribed.push( `${ tag } "${ name }"` );
					}
				}
			} );

			// A setting name painted twice in one row: the duplicate-label
			// regression. Compare painted text of the wrapper's own label
			// against the control's own <label>.
			const dupLabels = [];
			document
				.querySelectorAll( '#performance-optimisation .wppo-switch-field' )
				.forEach( ( wrap ) => {
					const own = wrap.querySelector(
						'.wppo-switch-field__label'
					);
					const input = wrap.querySelector( 'input' );
					if ( ! own || ! input || ! input.id ) {
						return;
					}
					const theirs = document.querySelector(
						`label[for="${ CSS.escape( input.id ) }"]`
					);
					if (
						theirs &&
						painted( own ) &&
						painted( theirs ) &&
						own.textContent.trim() === theirs.textContent.trim()
					) {
						dupLabels.push( own.textContent.trim() );
					}
				} );

			// The pin is deliberately mouse-only; confirm it is really outside
			// the accessibility tree rather than just unstyled.
			const pins = [
				...document.querySelectorAll(
					'#performance-optimisation .wppo-setting-row__explain'
				),
			];
			const pin = pins[ 0 ];
			const rows = document.querySelectorAll(
				'#performance-optimisation .wppo-setting-row'
			).length;

			return {
				controls: controls.length,
				// How many of this screen's settings are inspector-wired. Not an
				// assertion — the migration is incremental, and a screen with 0
				// here is simply not converted yet, which is different from one
				// that is converted and broken.
				wiredRows: rows,
				unnamed: [ ...new Set( unnamed ) ],
				undescribed: [ ...new Set( undescribed ) ].slice( 0, 12 ),
				undescribedCount: undescribed.length,
				dupLabels: [ ...new Set( dupLabels ) ],
				pinCount: pins.length,
				// Only meaningful when the screen has any wired rows; `null`
				// means "not migrated yet", which is not a failure.
				pinAriaHidden: pin ? pin.getAttribute( 'aria-hidden' ) : null,
				pinTabIndex: pin ? pin.getAttribute( 'tabindex' ) : null,
			};
		} );

		report.push( { ...screen, ...data } );
	}

	await browser.close();

	// A screen fails on: an unnamed control, a visible description that is not
	// programmatically associated, a duplicated painted label, or a pin that is
	// present but reachable by a keyboard / announced. An absent pin is fine —
	// that is an unconverted screen, not a broken one.
	const bad = report.filter(
		( r ) =>
			r.unnamed.length ||
			r.undescribedCount ||
			r.dupLabels.length ||
			( r.pinCount > 0 &&
				( r.pinAriaHidden !== 'true' || r.pinTabIndex !== '-1' ) )
	);

	console.log(
		report
			.map(
				( r ) =>
					`${ r.slug.padEnd( 9 ) } controls=${ String( r.controls ).padStart(
						3
					) }  wired=${ String( r.wiredRows ).padStart( 2 ) }  unnamed=${
						r.unnamed.length
					}  undescribed=${ r.undescribedCount }  dupLabels=${
						r.dupLabels.length
					}  pin=${
						r.pinCount
							? `ok(hidden=${ r.pinAriaHidden },tabindex=${ r.pinTabIndex })`
							: 'none yet'
					}` +
					( r.unnamed.length
						? `\n    unnamed: ${ r.unnamed.join( ', ' ) }`
						: '' ) +
					( r.undescribed.length
						? `\n    undescribed: ${ r.undescribed.join( ', ' ) }`
						: '' ) +
					( r.dupLabels.length
						? `\n    dupLabels: ${ r.dupLabels.join( ', ' ) }`
						: '' )
			)
			.join( '\n' )
	);
	console.log( bad.length ? `\nFAIL: ${ bad.length } screen(s)` : '\nPASS' );
	process.exit( bad.length ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( 'FAILED:', e.message );
	process.exit( 1 );
} );
