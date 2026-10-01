/**
 * Focused behaviour test for the WPPO reference UI.
 *
 * Selectors below were read off the real DOM, not guessed:
 *   - the editable control itself carries `data-setting` (it is NOT a wrapper);
 *   - conditional blocks are always in the DOM and carry `data-when`, with the
 *     HTML `hidden` attribute toggled, so node COUNT must not be asserted;
 *   - the CDN mapping repeater uses `.po-repeater` / `data-action="repeater-add"`
 *     and lives on the Network sub-tab (the default sub-tab is Assets).
 */
const path = require( 'path' );
const { JSDOM, VirtualConsole } = require( path.join(
	'/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation/node_modules/jsdom'
) );

let APP = '';
const problems = [];

async function boot( qs ) {
	const dom = await JSDOM.fromURL( APP + qs, {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole: new VirtualConsole(),
		beforeParse( w ) {
			w.matchMedia =
				w.matchMedia ||
				( ( q ) => ( {
					matches: false,
					media: q,
					addEventListener() {},
					removeEventListener() {},
					addListener() {},
					removeListener() {},
				} ) );
			w.ResizeObserver = w.ResizeObserver || class { observe() {} unobserve() {} disconnect() {} };
			w.IntersectionObserver = w.IntersectionObserver || class { observe() {} unobserve() {} disconnect() {} };
			w.scrollTo = () => {};
			w.scrollBy = () => {};
		},
	} );
	const deadline = Date.now() + 8000;
	while ( Date.now() < deadline ) {
		if ( dom.window.PO && dom.window.PO.store && dom.window.WPPO_MOCK ) break;
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}
	await new Promise( ( r ) => setTimeout( r, 350 ) );
	return dom;
}

const wait = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/** Fire the events a real control would, so delegated listeners react. */
function poke( w, el, value ) {
	if ( 'checkbox' === el.type ) {
		el.checked = typeof value === 'boolean' ? value : ! el.checked;
	} else if ( null !== value ) {
		el.value = value;
	}
	el.dispatchEvent( new w.Event( 'input', { bubbles: true } ) );
	el.dispatchEvent( new w.Event( 'change', { bubbles: true } ) );
}

( async () => {
	const srv = await require( './static' ).start();
	APP = `${ srv.base }/app/index.html`;
	console.log( 'serving wppo-ui at', srv.base, '\n' );
	// ---- 1. conditional visibility ----
	{
		const dom = await boot( '?area=speed&screen=fileOptimization&theme=deck' );
		const w = dom.window;
		const doc = w.document;
		const gated = ( re ) =>
			Array.from( doc.querySelectorAll( '[data-when]' ) ).filter( ( n ) => re.test( n.getAttribute( 'data-when' ) ) );
		const hiddenCount = ( list ) => list.filter( ( n ) => n.hasAttribute( 'hidden' ) ).length;

		// The mock enables criticalCSS, so its dependents start visible.
		const toggle = doc.querySelector( 'input[data-setting="criticalCSS"]' );
		if ( ! toggle ) {
			problems.push( 'no input[data-setting="criticalCSS"] on the Assets sub-tab' );
		} else {
			const rows = () => gated( /criticalCSS/ );
			const startHidden = hiddenCount( rows() );
			console.log( `criticalCSS=${ toggle.checked }, dependent rows=${ rows().length }, hidden=${ startHidden }` );

			poke( w, toggle, false );
			await wait( 400 );
			const offHidden = hiddenCount( rows() );
			console.log( `  after turning criticalCSS OFF: hidden ${ startHidden } -> ${ offHidden }` );
			if ( offHidden <= startHidden ) {
				problems.push( 'turning criticalCSS OFF did not hide its dependent fields' );
			}

			poke( w, toggle, true );
			await wait( 400 );
			const onHidden = hiddenCount( rows() );
			console.log( `  after turning it back ON:        hidden ${ offHidden } -> ${ onHidden }` );
			if ( onHidden !== startHidden ) {
				problems.push( 're-enabling criticalCSS did not restore the original visibility' );
			}
		}

		// A default-off toggle must start with its dependents hidden.
		const combine = doc.querySelector( 'input[data-setting="combineCSS"]' );
		if ( combine ) {
			const comboRows = hiddenCount( gated( /combineCSS/ ) );
			console.log( `  combineCSS=${ combine.checked }, its rows hidden=${ comboRows }` );
			if ( ! combine.checked && comboRows < 1 ) {
				problems.push( 'combineCSS is off but its dependent field is visible' );
			}
			if ( ! combine.checked ) {
				poke( w, combine, true );
				await wait( 400 );
				const nowHidden = hiddenCount( gated( /combineCSS/ ) );
				console.log( `  after turning combineCSS ON:      hidden ${ comboRows } -> ${ nowHidden }` );
				if ( nowHidden >= comboRows ) problems.push( 'turning combineCSS ON did not reveal its dependent field' );
			}
		}
		dom.window.close();
	}

	// ---- 2. CDN mapping repeater (Network sub-tab) ----
	{
		const dom = await boot( '?area=speed&screen=fileOptimization&subtab=network&theme=deck' );
		const doc = dom.window.document;
		const add = doc.querySelector( '[data-action="repeater-add"]' );
		const list = () => doc.querySelectorAll( '.po-repeater__row' );
		const before = list().length;
		console.log( `\nrepeater rows=${ before }, add control=${ add ? 'found' : 'MISSING' }` );
		if ( ! add ) {
			problems.push( 'CDN mapping repeater has no add control on the Network sub-tab' );
		} else {
			add.click();
			await wait( 400 );
			const after = list().length;
			console.log( `  after add: ${ after }` );
			if ( after <= before ) problems.push( 'repeater add did not add a row' );

			const remove = doc.querySelector( '[data-action="repeater-remove"]' );
			if ( ! remove ) {
				problems.push( 'repeater has no remove control' );
			} else {
				remove.click();
				await wait( 400 );
				const back = list().length;
				console.log( `  after remove: ${ back }` );
				if ( back >= after ) problems.push( 'repeater remove did not drop a row' );
			}
			// Sub-fields of a row must be editable.
			const sub = doc.querySelector( '.po-repeater__row [data-setting], .po-repeater__row input, .po-repeater__row select' );
			console.log( `  row sub-field: ${ sub ? sub.getAttribute( 'data-setting' ) || sub.name || sub.type : 'none' }` );
			if ( ! sub ) problems.push( 'repeater row renders no sub-fields' );
		}
		dom.window.close();
	}

	// ---- 3. dirty tracking + save cycle ----
	{
		const dom = await boot( '?area=speed&screen=preload&theme=deck' );
		const w = dom.window;
		const doc = w.document;
		const bar = doc.getElementById( 'po-dirtybar' );
		const dirty = () => bar && ! bar.hasAttribute( 'hidden' );
		const before = dirty();
		const input = doc.querySelector( 'input[data-setting], select[data-setting], textarea[data-setting]' );
		if ( ! input ) {
			problems.push( 'no editable control on the Preload screen' );
		} else {
			poke( w, input, 'checkbox' === input.type ? undefined : 'https://fonts.example.com' );
			await wait( 400 );
			const after = dirty();
			console.log( `\ndirty bar visible: ${ before } -> ${ after } after editing "${ input.getAttribute( 'data-setting' ) }"` );
			if ( ! after ) problems.push( 'editing a field did not raise the dirty state' );

			// Save should clear it.
			const save = doc.querySelector( '[data-action="save-settings"]' );
			if ( save ) {
				save.click();
				await wait( 900 );
				const postSave = dirty();
				console.log( `  after clicking Save Settings: dirty=${ postSave }` );
				if ( postSave ) problems.push( 'saving did not clear the dirty state' );
			} else {
				problems.push( 'no save control on the Preload screen' );
			}
		}
		dom.window.close();
	}

	// ---- 4. command palette indexes the settings schema ----
	{
		const dom = await boot( '?area=overview&screen=overview&theme=utility' );
		const w = dom.window;
		const doc = w.document;
		doc.dispatchEvent( new w.KeyboardEvent( 'keydown', { key: 'k', ctrlKey: true, bubbles: true } ) );
		await wait( 350 );
		const dialog = doc.querySelector( '[role="dialog"]' );
		if ( ! dialog ) {
			problems.push( 'Ctrl+K did not open the command palette' );
		} else {
			const input = dialog.querySelector( 'input' );
			if ( input ) {
				input.value = 'critical css';
				input.dispatchEvent( new w.Event( 'input', { bubbles: true } ) );
				await wait( 350 );
				const items = dialog.querySelectorAll( 'li, [role="option"], .po-cmdk__item' );
				const text = ( dialog.textContent || '' ).toLowerCase();
				console.log( `\ncmdk filtered items=${ items.length }, mentions "critical": ${ text.includes( 'critical' ) }` );
				if ( ! text.includes( 'critical' ) ) {
					problems.push( 'command palette does not match a setting label from the schema' );
				}
			} else {
				problems.push( 'command palette has no filter input' );
			}
		}
		dom.window.close();
	}

	console.log( '\n' + '='.repeat( 66 ) );
	if ( problems.length ) {
		console.log( `FAIL — ${ problems.length } problem(s):\n` );
		problems.forEach( ( p, i ) => console.log( `${ i + 1 }. ${ p }` ) );
		process.exitCode = 1;
	} else {
		console.log( 'PASS — conditional visibility, repeater, dirty/save and ⌘K all behave.' );
	}

	/* The in-process static server keeps the event loop alive; without an
	   explicit close this script never exits. */
	srv.close();
	process.exit( problems.length ? 1 : 0 );
} )();