/**
 * Chooser verification harness (jsdom — no browser is available here).
 *
 * Part 1. Facts about the app the chooser claims on its face:
 *   - how many entries the ⌘K palette actually indexes
 *   - whether the smoke.js `showIf` failure is an app fault or a bad assertion
 *     (behaviour.js asserts hidden-count instead of node-count, and says so).
 *
 * Part 2. Structural checks on ../index.html (the chooser):
 *   - it parses, no uncaught errors, no external network requests
 *   - tokens.css resolved (the relative link is right)
 *   - one <main>, one <h1>, every heading level ordered, every SVG labelled
 *   - all three deep links present and pointing at ?theme=<id>
 *   - no inline <script> (no build step, no JS needed)
 *   - every internal link resolves on disk
 *
 * Run:  node /var/tmp/wp-graphql/wppo-ui/.scratch/chooser-check.js
 * Needs: python3 -m http.server 8099 running in wppo-ui/
 */
const path = require( 'path' );
const fs = require( 'fs' );
/* Resolve jsdom from the plugin's own node_modules, like the sibling harnesses.
   A vendored copy under .scratch/ is not shipped. */
const { JSDOM, VirtualConsole } = require( path.join(
	__dirname,
	'..',
	'..',
	'node_modules/jsdom'
) );

const ROOT = path.join( __dirname, '..' );
let BASE = '';
const problems = [];
const note = ( m ) => problems.push( m );

function polyfill( w ) {
	w.matchMedia =
		w.matchMedia ||
		( ( q ) => ( {
			matches: false,
			media: q,
			addEventListener() {},
			removeListener() {},
		} ) );
	w.ResizeObserver = w.ResizeObserver || class { observe() {} unobserve() {} disconnect() {} };
	w.IntersectionObserver = w.IntersectionObserver || class { observe() {} unobserve() {} disconnect() {} };
	w.scrollTo = () => {};
	w.scrollBy = () => {};
}

/* ------------------------------------------------------------------ *
 * Part 1 — app facts
 * ------------------------------------------------------------------ */
async function appFacts() {
	const dom = await JSDOM.fromURL( `${ BASE }/app/index.html?area=overview&screen=overview&theme=utility`, {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole: new VirtualConsole(),
		beforeParse: polyfill,
	} );
	const w = dom.window;
	const t0 = Date.now();
	while ( Date.now() - t0 < 8000 ) {
		if ( w.PO && w.PO.store && w.WPPO_SETTINGS_SCHEMA && w.WPPO_MOCK ) break;
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}
	await new Promise( ( r ) => setTimeout( r, 300 ) );

	const entries = w.PO.store.paletteEntries || [];
	const byKind = entries.reduce( ( a, e ) => ( a[ e.kind ] = ( a[ e.kind ] || 0 ) + 1, a ), {} );
	// 246 schema fields, but store.js skips `noUi` tabs: OD Integration,
	// Back/Forward Cache and Performance Translations, one field each.
	const SCHEMA_FIELDS = 246;
	const NO_UI_TABS = 3;
	console.log( `palette indexes ${ entries.length } entries (${ JSON.stringify( byKind ) })` );
	if ( byKind.screen !== 8 ) note( `palette: expected 8 screen entries, found ${ byKind.screen }` );
	if ( byKind.setting !== SCHEMA_FIELDS - NO_UI_TABS ) {
		note( `palette: expected ${ SCHEMA_FIELDS - NO_UI_TABS } setting entries, found ${ byKind.setting }` );
	}

	// Reproduce the smoke.js showIf assertion and show why it cannot pass.
	const dom2 = await JSDOM.fromURL( `${ BASE }/app/index.html?area=speed&screen=fileOptimization&theme=deck`, {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole: new VirtualConsole(),
		beforeParse: polyfill,
	} );
	const w2 = dom2.window;
	const t1 = Date.now();
	while ( Date.now() - t1 < 8000 ) {
		if ( w2.PO && w2.PO.store ) break;
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}
	await new Promise( ( r ) => setTimeout( r, 300 ) );
	const d = w2.document;
	const nodes = () => d.querySelectorAll( '[data-when~="criticalCSS"]' );
	const hidden = () => Array.from( nodes() ).filter( ( n ) => n.hasAttribute( 'hidden' ) ).length;
	const toggle = d.querySelector( 'input[data-setting="criticalCSS"]' );
	const before = { nodes: nodes().length, hidden: hidden() };
	toggle.checked = false;
	toggle.dispatchEvent( new w2.Event( 'change', { bubbles: true } ) );
	await new Promise( ( r ) => setTimeout( r, 300 ) );
	const after = { nodes: nodes().length, hidden: hidden() };
	console.log(
		`smoke.js showIf probe: nodes ${ before.nodes } -> ${ after.nodes } (unchanged, as expected), ` +
			`hidden ${ before.hidden } -> ${ after.hidden } (the behaviour that actually matters)`
	);
	if ( after.hidden <= before.hidden ) note( 'criticalCSS OFF did not hide its dependent node — real fault' );

	dom.window.close();
	dom2.window.close();
}

/* ------------------------------------------------------------------ *
 * Part 2 — the chooser
 * ------------------------------------------------------------------ */
async function chooser() {
	const errors = [];
	const external = [];
	const vc = new VirtualConsole();
	vc.on( 'jsdomError', ( e ) => errors.push( 'jsdomError: ' + ( e.message || e ) ) );
	vc.on( 'error', ( ...a ) => errors.push( 'console.error: ' + a.join( ' ' ) ) );

	const dom = await JSDOM.fromURL( `${ BASE }/index.html`, {
		runScripts: 'dangerously',
		resources: 'usable',
		pretendToBeVisual: true,
		virtualConsole: vc,
		beforeParse( w ) {
			polyfill( w );
			const open = w.fetch;
			w.fetch = ( u, o ) => {
				if ( ! String( u ).startsWith( BASE ) ) external.push( String( u ) );
				return open( u, o );
			};
		},
	} );
	await new Promise( ( r ) => setTimeout( r, 700 ) );
	const doc = dom.window.document;

	errors.forEach( ( e ) => note( 'chooser: ' + e ) );
	if ( external.length ) note( 'chooser made external requests: ' + external.join( ', ' ) );
	if ( errors.length ) console.log( 'chooser console output:\n  ' + errors.join( '\n  ' ) );

	// tokens.css must actually be linked and resolvable, by relative path.
	const sheets = Array.from( doc.querySelectorAll( 'link[rel=stylesheet]' ) ).map( ( l ) => l.getAttribute( 'href' ) );
	console.log( 'chooser stylesheets:', sheets.join( ', ' ) || '(none)' );
	if ( ! sheets.some( ( h ) => h.endsWith( 'app/css/tokens.css' ) ) ) {
		note( 'chooser does not link app/css/tokens.css' );
	} else if ( ! fs.existsSync( path.join( ROOT, 'app/css/tokens.css' ) ) ) {
		note( 'the linked tokens.css does not exist on disk' );
	}
	if ( sheets.some( ( h ) => /^https?:/i.test( h ) ) ) note( 'chooser links a remote stylesheet: ' + sheets.join( ', ' ) );

	// Self-contained: no JS at all.
	const scripts = Array.from( doc.querySelectorAll( 'script' ) );
	if ( scripts.length ) note( `chooser has ${ scripts.length } <script> tag(s); it should need none` );

	// Document shape.
	const h1s = doc.querySelectorAll( 'h1' );
	if ( h1s.length !== 1 ) note( `expected exactly one <h1>, found ${ h1s.length }` );
	if ( doc.querySelectorAll( 'main' ).length !== 1 ) note( 'expected exactly one <main>' );
	if ( ! doc.querySelector( 'html[lang]' ) ) note( 'no lang on <html>' );
	const title = doc.querySelector( 'title' );
	if ( ! title || ! title.textContent.trim() ) note( 'empty <title>' );

	// Heading order: never skip a level going down.
	const levels = Array.from( doc.querySelectorAll( 'h1,h2,h3,h4' ) ).map( ( h ) => Number( h.tagName[ 1 ] ) );
	let prev = 0;
	levels.forEach( ( l, i ) => {
		if ( prev && l > prev + 1 ) note( `heading jump h${ prev } -> h${ l } at heading #${ i }` );
		prev = l;
	} );

	// Every SVG must be labelled or explicitly hidden.
	doc.querySelectorAll( 'svg' ).forEach( ( svg, i ) => {
		const hidden = svg.getAttribute( 'aria-hidden' ) === 'true';
		const labelled = svg.getAttribute( 'role' ) === 'img' && svg.querySelector( 'title' );
		if ( ! hidden && ! labelled ) note( `svg #${ i + 1 } is neither aria-hidden nor role="img" with a <title>` );
	} );

	// The three deep links.
	const hrefs = Array.from( doc.querySelectorAll( 'a[href]' ) ).map( ( a ) => a.getAttribute( 'href' ) );
	[ 'native', 'deck', 'utility' ].forEach( ( id ) => {
		const want = `app/index.html?theme=${ id }`;
		if ( ! hrefs.includes( want ) ) note( `missing deep link ${ want }` );
	} );

	// Internal links must resolve on disk (query strings stripped).
	hrefs.forEach( ( h ) => {
		if ( /^(https?:|mailto:|#)/i.test( h ) ) return;
		const file = h.split( '#' )[ 0 ].split( '?' )[ 0 ];
		if ( ! file ) return;
		if ( ! fs.existsSync( path.join( ROOT, file ) ) ) note( `link target does not exist: ${ h }` );
	} );

	// Every control has an accessible name.
	doc.querySelectorAll( 'a[href]' ).forEach( ( a ) => {
		if ( ! ( a.textContent.trim() || a.getAttribute( 'aria-label' ) ) ) note( 'a link with no accessible name' );
	} );

	// ---- source-level tag balance ---------------------------------------
	// IMPORTANT: everything above inspects the PARSED dom, and the HTML parser
	// silently repairs mismatched end tags before we ever see them — so a broken
	// file can "load clean". This walks the raw source with a tag stack
	// instead, which is the only way to see the mistake. Elements whose end tag
	// is optional per spec (<li> before another <li>, <p> before a block, ...)
	// are allowed, because omitting them is legal, not a bug.
	const VOID = new Set( [ 'meta', 'link', 'br', 'hr', 'img', 'input', 'wbr', 'source', 'area', 'base', 'col', 'embed', 'param', 'track' ] );
	// Tags whose end tag is optional per the HTML spec. Omitting one is legal,
	// so the stack must not treat it as a mismatch.
	const OPTIONAL_END = new Set( [ 'li', 'p', 'dt', 'dd', 'option', 'optgroup', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot', 'rt', 'rp' ] );
	// Which arriving OPENING tag implicitly ends a still-open optional element.
	const CLOSES_ON_OPEN = {
		li: new Set( [ 'li' ] ),
		p: new Set( [ 'p', 'div', 'ul', 'ol', 'section', 'table', 'dl', 'blockquote', 'figure', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'address' ] ),
		dt: new Set( [ 'dt', 'dd' ] ),
		dd: new Set( [ 'dt', 'dd' ] ),
		option: new Set( [ 'option', 'optgroup' ] ),
		optgroup: new Set( [ 'optgroup' ] ),
		tr: new Set( [ 'tr', 'tbody', 'thead', 'tfoot' ] ),
		td: new Set( [ 'td', 'th', 'tr', 'tbody', 'thead', 'tfoot' ] ),
		th: new Set( [ 'td', 'th', 'tr', 'tbody', 'thead', 'tfoot' ] ),
		thead: new Set( [ 'tbody', 'tfoot' ] ),
		tbody: new Set( [ 'tbody', 'tfoot' ] ),
	};
	const html = fs
		.readFileSync( path.join( ROOT, 'index.html' ), 'utf8' )
		.replace( /<!--[\s\S]*?-->/g, '' )
		.replace( /<style[\s\S]*?<\/style>/gi, '<style></style>' )
		.replace( /<script[\s\S]*?<\/script>/gi, '<script></script>' )
		.replace( /<!DOCTYPE[^>]*>/gi, '' );
	const stack = [];
	let opened = 0;
	// NOTE: attrs must tolerate ">" inside a quoted value, otherwise the
	// self-closing slash gets swallowed into the attribute capture and every
	// <path/> in the diagrams is treated as an unclosed element.
	html.replace( /<(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:[^>"']|"[^"]*"|'[^']*')*)>/g, ( all, slash, name, attrs, offset ) => {
		const tag = name.toLowerCase();
		const selfClose = /\/\s*$/.test( attrs );
		const line = html.slice( 0, offset ).split( '\n' ).length;
		if ( VOID.has( tag ) || selfClose ) return all;
		if ( ! slash ) {
			// An opening tag ends any optional element it would implicitly close.
			while ( stack.length ) {
				const top = stack[ stack.length - 1 ].tag;
				if ( OPTIONAL_END.has( top ) && CLOSES_ON_OPEN[ top ] && CLOSES_ON_OPEN[ top ].has( tag ) ) stack.pop();
				else break;
			}
			stack.push( { tag, line } );
			opened++;
		} else {
			// A closing tag also implicitly ends trailing optional elements that
			// were left open — but only ones other than itself.
			while ( stack.length ) {
				const top = stack[ stack.length - 1 ].tag;
				if ( OPTIONAL_END.has( top ) && top !== tag ) stack.pop();
				else break;
			}
			const top = stack.pop();
			if ( ! top ) {
				note( `line ${ line }: stray </${ tag }> with nothing open` );
			} else if ( top.tag !== tag ) {
				note( `line ${ line }: </${ tag }> closes <${ top.tag }> opened on line ${ top.line }` );
			}
		}
		return all;
	} );
	stack.forEach( ( t ) => note( `<${ t.tag }> opened on line ${ t.line } is never closed` ) );
	console.log( `  source tag balance: ${ opened } elements opened, ${ stack.length } left open` );

	// ---- general nesting guards -----------------------------------------
	// jsdom's parser silently recovers from a mismatched end tag, so a broken
	// page still "loads clean". These two structural rules catch the DOM-level
	// half of the same class: a direct <li> inside an <li>, or a block element
	// inside a <p>.
	doc.querySelectorAll( 'li' ).forEach( ( li ) => {
		if ( li.parentElement && li.parentElement.tagName === 'LI' ) {
			note( `<li class="${ li.className }"> is a direct child of an <li>` );
		}
	} );
	const BLOCKS = [ 'DIV', 'SECTION', 'UL', 'OL', 'FIGURE', 'TABLE', 'H1', 'H2', 'H3', 'H4', 'P' ];
	doc.querySelectorAll( 'p' ).forEach( ( p ) => {
		Array.from( p.children ).forEach( ( c ) => {
			if ( BLOCKS.includes( c.tagName ) ) {
				note( `<${ c.tagName.toLowerCase() }> nested inside a <p class="${ p.className }">` );
			}
		} );
	} );

	// ---- list semantics ---------------------------------------------------
	// A five-step sequence and three parallel one-liners are lists; say so in
	// the markup rather than faking it with sibling divs.
	const steps = doc.querySelector( '.cx-steps' );
	if ( ! steps || steps.tagName !== 'OL' ) note( '.cx-steps should be an <ol>' );
	else if ( steps.children.length !== 5 ) note( `.cx-steps has ${ steps.children.length } children, expected 5` );

	const pick = doc.querySelector( '.cx-pick' );
	if ( ! pick || pick.tagName !== 'UL' ) note( '.cx-pick should be a <ul>' );
	else if ( pick.children.length !== 3 ) note( `.cx-pick has ${ pick.children.length } children, expected 3` );

	const dirs = doc.querySelector( '.cx-dirs' );
	if ( ! dirs || dirs.tagName !== 'OL' ) note( '.cx-dirs should be an <ol>' );
	else if ( dirs.children.length !== 3 ) note( `.cx-dirs has ${ dirs.children.length } children, expected 3` );

	const cov = doc.querySelector( '.cx-cov' );
	if ( ! cov || cov.tagName !== 'OL' ) note( '.cx-cov should be an <ol>' );
	else if ( cov.children.length !== 8 ) note( `.cx-cov has ${ cov.children.length } children, expected 8` );

	// ---- the hand-drawn diagrams -------------------------------------------
	// ~450 coordinates were written by hand. Anything outside the viewBox is
	// silently clipped and reads as a rendering bug, so bound-check each one.
	// jsdom cannot paint, so this is the closest thing to a visual check.
	doc.querySelectorAll( '.cx-diagram svg' ).forEach( ( svg ) => {
		const label = ( svg.querySelector( 'title' ) || {} ).textContent || '(untitled)';
		const vb = svg.getAttribute( 'viewBox' ).split( /\s+/ ).map( Number );
		const [ minX, minY, vw, vh ] = vb;
		const ok = ( n ) => n >= minX - 0.01 && n <= minX + vw + 0.01;
		const okY = ( n ) => n >= minY - 0.01 && n <= minY + vh + 0.01;
		let count = 0;
		svg.querySelectorAll( 'rect' ).forEach( ( r ) => {
			count++;
			const x = +r.getAttribute( 'x' ) || 0;
			const y = +r.getAttribute( 'y' ) || 0;
			const w = +r.getAttribute( 'width' );
			const h = +r.getAttribute( 'height' );
			const problemsHere = [];
			if ( ! ok( x ) || ! ok( x + w ) ) problemsHere.push( `x ${ x }..${ x + w }` );
			if ( ! okY( y ) || ! okY( y + h ) ) problemsHere.push( `y ${ y }..${ y + h }` );
			if ( problemsHere.length ) {
				note( `diagram "${ label.slice( 0, 22 ) }…" rect #${ count } outside viewBox: ${ problemsHere.join( ', ' ) }` );
			}
		} );
		// <path> outlines (the rounded panel headers) too.
		//
		// Deliberately NOT a full path parser. The two paths here are built as
		// `M` + relative/absolute commands + an elliptical arc, and an arc's
		// numbers (rx ry rotation large-arc sweep dx dy) are not coordinates, so
		// naively pairing them produces phantom out-of-bounds points. This scans
		// absolute M/L points and stops at the first relative command, which is
		// the part that was hand-computed and could realistically be wrong.
		svg.querySelectorAll( 'path' ).forEach( ( p, i ) => {
			const d = p.getAttribute( 'd' );
			const abs = d.match( /[MLHV][^MLHVaz]*/gi ) || [];
			let checked = 0;
			abs.forEach( ( cmd ) => {
				const kind = cmd[ 0 ].toUpperCase();
				const nums = ( cmd.slice( 1 ).match( /-?\d+(\.\d+)?/g ) || [] ).map( Number );
				if ( kind === 'M' || kind === 'L' ) {
					for ( let j = 0; j + 1 < nums.length; j += 2 ) {
						checked++;
						if ( ! ok( nums[ j ] ) || ! okY( nums[ j + 1 ] ) ) {
							note( `diagram "${ label.slice( 0, 22 ) }…" path #${ i + 1 } absolute point (${ nums[ j ] },${ nums[ j + 1 ] }) outside viewBox` );
						}
					}
				} else if ( kind === 'H' || kind === 'V' ) {
					nums.forEach( ( n ) => {
						checked++;
						const bad = kind === 'H' ? ! ok( n ) : ! okY( n );
						if ( bad ) note( `diagram "${ label.slice( 0, 22 ) }…" path #${ i + 1 } ${ cmd[ 0 ] }${ n } outside viewBox` );
					} );
				}
			} );
			// A path with no scannable absolute command means the scanner is
			// no longer looking at anything, which is itself worth knowing.
			if ( ! checked ) {
				note( `diagram "${ label.slice( 0, 22 ) }…" path #${ i + 1 } had no absolute points to check: ${ d }` );
			}
		} );
		console.log( `  diagram ${ label.slice( 0, 34 ) }…: ${ count } rects in-bounds` );
	} );

	// ---- the equal-weight claim, asserted ---------------------------------
	// The page tells the reader the three cards are identical in weight and that
	// every trade-off gets the prominence of every benefit. That is a structural
	// claim, so check the structure rather than trusting the prose.
	const cards = Array.from( doc.querySelectorAll( '.cx-dir' ) );
	if ( cards.length !== 3 ) note( `expected 3 direction cards, found ${ cards.length }` );
	const shapes = cards.map( ( c ) => {
		const kind = ( c.className.match( /cx-dir--(\w)/ ) || [] )[ 1 ] || '?';
		return {
			kind,
			traits: c.querySelectorAll( '.cx-traits > li' ).length,
			diagrams: c.querySelectorAll( '.cx-diagram svg' ).length,
			suits: c.querySelectorAll( '.cx-suits' ).length,
			costs: c.querySelectorAll( '.cx-cost' ).length,
			buttons: c.querySelectorAll( '.cx-dir__foot .cx-btn' ).length,
		};
	} );
	console.log( '  card structure:', shapes.map( ( s ) => `${ s.kind }:${ s.traits }t/${ s.diagrams }svg/${ s.suits }suits/${ s.costs }cost/${ s.buttons }btn` ).join( '  ' ) );
	shapes.forEach( ( s ) => {
		// One of each per card; the trait ROW COUNT is compared across cards
		// separately, below, because it is meant to be a number rather than 1.
		[ 'diagrams', 'suits', 'costs', 'buttons' ].forEach( ( k ) => {
			if ( s[ k ] !== 1 ) note( `card ${ s.kind }: expected 1 ${ k }, found ${ s[ k ] }` );
		} );
		if ( s.traits < 6 ) note( `card ${ s.kind }: only ${ s.traits } trait rows` );
	} );
	const traitCounts = new Set( shapes.map( ( s ) => s.traits ) );
	if ( traitCounts.size > 1 ) note( `trait rows differ between cards: ${ [ ...traitCounts ].join( ', ' ) }` );

	// The coverage claims must match the schema, not just sound plausible.
	const S = loadSchema( path.join( ROOT, 'data/settings.js' ) );
	let fields = 0;
	S.tabs.forEach( ( t ) => ( t.groups || [] ).forEach( ( g ) => ( g.cards || [] ).forEach( ( c ) => ( c.fields || [] ).forEach( ( f ) => { fields++; } ) ) ) );
	const body = doc.body.textContent.replace( /\s+/g, ' ' );
	[
		[ '14 tabs', /14 tabs/i ],
		[ '246 settings', /246 settings/i ],
		[ '8 screens', /8 screens/i ],
	].forEach( ( [ label, re ] ) => {
		if ( ! re.test( body ) ) note( `chooser does not state "${ label }" anywhere in its text` );
	} );
	console.log( `schema for comparison: ${ S.tabs.length } tabs, ${ fields } fields, ${ S.areas.length } areas` );
	if ( S.tabs.length !== 14 ) note( `schema changed: ${ S.tabs.length } tabs, the chooser says 14` );
	if ( fields !== 246 ) note( `schema changed: ${ fields } fields, the chooser says 246` );

	// Content weight: a chooser this size should not be a stub.
	const text = body.trim();
	console.log( `chooser: ${ text.length } chars of text, ${ doc.querySelectorAll( 'section' ).length } sections` );
	if ( text.length < 4000 ) note( `chooser text is thin (${ text.length } chars) — is it a stub?` );
	if ( doc.querySelectorAll( 'section' ).length < 5 ) note( 'chooser has fewer than 5 <section> elements' );

	dom.window.close();
}

/** Load a plain script that assigns to window, without a DOM. */
function loadSchema( file ) {
	const window = {};
	eval( fs.readFileSync( file, 'utf8' ) );
	return window.WPPO_SETTINGS_SCHEMA;
}

( async () => {
	const srv = await require( './static' ).start();
	BASE = srv.base;
	console.log( 'serving wppo-ui at', BASE, '\n' );
	try {
		await appFacts();
	} catch ( e ) {
		note( 'app facts threw: ' + e.message );
	}
	try {
		await chooser();
	} catch ( e ) {
		note( 'chooser check threw: ' + e.message );
	}
	console.log( '\n' + '='.repeat( 66 ) );
	if ( problems.length ) {
		console.log( `FAIL — ${ problems.length } problem(s):\n` );
		problems.forEach( ( p, i ) => console.log( `${ i + 1 }. ${ p }` ) );
		process.exitCode = 1;
	} else {
		console.log( 'PASS — app facts confirmed and the chooser is structurally sound.' );
	}

	/* The in-process static server keeps the event loop alive; without an
	   explicit close this script never exits. */
	srv.close();
	process.exit( problems.length ? 1 : 0 );
} )();