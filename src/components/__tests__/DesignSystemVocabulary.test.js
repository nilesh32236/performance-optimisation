/**
 * One badge vocabulary, and a rule behind every class the markup emits.
 *
 * An independent review found `ObjectCache.js` emitting `--success` and
 * `--error`, and **neither has a rule in the stylesheet** — so "Connected" and
 * "Disconnected" rendered identically apart from their glyph and word, which is
 * the opposite of what a status badge is for. The same review found
 * `.wppo-overview__stale` emitted by the campaign's own Overview with no rule
 * in the campaign's own stylesheet, and `SectionShell`'s tablist accessible name
 * built from a template literal, so no translator could ever reach it.
 *
 * The tone check reads the **built** stylesheet, not the SCSS. The rules are
 * written as `&--good` nested under `.wppo-status-badge`, so a literal search
 * for `status-badge--good` in the source finds nothing even though the rule
 * exists — which is exactly how this defect survived a grep. The compiled
 * artifact has no such ambiguity.
 *
 * @package
 */

import fs from 'fs';
import path from 'path';

const ROOT = path.join( __dirname, '../../..' );
const read = ( rel ) => fs.readFileSync( path.join( ROOT, rel ), 'utf8' );

const TONES = [ 'good', 'warning', 'poor', 'needs_improvement' ];

describe( 'status badge vocabulary', () => {
	const source = read( 'src/components/ObjectCache.js' );
	const css = read( 'build/style-index.css' );

	it.each( TONES )(
		'defines a rule for the `%s` tone in the built stylesheet',
		( tone ) => {
			expect( css ).toContain( `status-badge--${ tone }` );
		}
	);

	it( 'emits only tones the built stylesheet defines', () => {
		const emitted = [ ...source.matchAll( /level: '([a-z_]+)'/g ) ].map(
			( m ) => m[ 1 ]
		);
		expect( emitted.length ).toBeGreaterThan( 0 );
		for ( const tone of emitted ) {
			expect( css ).toContain( `status-badge--${ tone }` );
		}
	} );

	it( 'emits Connected and Disconnected as different tones', () => {
		// The defect itself: one unruled tone for both states made them
		// indistinguishable.
		expect( source ).toMatch( /level: 'good'[\s\S]*Connected/ );
		expect( source ).toMatch( /level: 'poor'[\s\S]*Disconnected/ );
	} );
} );

describe( 'a class the markup emits has a rule behind it', () => {
	it( 'styles .wppo-overview__stale', () => {
		// The **SCSS**, not the build. A mutation that deleted the rule left this
		// green, because the assertion read the compiled artifact and deleting the
		// source does not change the artifact until someone rebuilds. The author
		// edits the stylesheet, so that is what the test must read.
		expect( read( 'src/css/components/_overview.scss' ) ).toMatch(
			/\.wppo-overview__stale\s*\{/
		);
		// And the markup really does emit it, or the rule is dead in the other
		// direction.
		expect( read( 'src/components/overview/SiteStatusCard.js' ) ).toContain(
			'wppo-overview__stale'
		);
	} );

	it( 'styles .wppo-error-boundary', () => {
		// The SPA crash fallback is a full-panel condition that rendered as bare
		// unstyled markup because its only class had no rule anywhere in
		// src/css — and therefore none in the built stylesheet either.
		expect( read( 'src/css/base/_base.scss' ) ).toMatch(
			/\.wppo-error-boundary\s*\{/
		);
		expect( read( 'src/components/common/ErrorBoundary.js' ) ).toContain(
			'wppo-error-boundary'
		);
		// …and the rule actually ships, not just the source of it.
		expect( read( 'build/style-index.css' ) ).toMatch(
			/\.wppo-error-boundary\{/
		);
	} );
} );

describe( 'the area heading is not overridden by WordPress core', () => {
	it( 'scopes the title rule by the mount id, not only by class', () => {
		// A **tripwire, not a CSS test**: only a browser can observe which
		// declaration wins, and jsdom has no layout or cascade. What is asserted
		// is the shape of the fix, because the defect was that a class selector
		// loses to core's id-keyed `h1` rule.
		//
		// Measured before, on all eight screens:
		//   `.wppo-section__title` declares 600 / 1.5rem
		//   the H1 computed            400 / 23px
		// so **both** declarations were lost, not just the weight. An
		// independent review found it, and the record had listed the weight as a
		// known Phase 4 problem while claiming Phase 4 done.
		const scss = read( 'src/css/components/_section.scss' );
		expect( scss ).toMatch(
			/#performance-optimisation \.wppo-section__title\s*\{/
		);
		// Still declares what core was overriding.
		const block = scss.slice(
			scss.indexOf( '#performance-optimisation .wppo-section__title {' )
		);
		expect( block ).toMatch( /font-weight:\s*600;/ );
		expect( block ).toMatch( /font-size:\s*1\.5rem;/ );
	} );
} );

describe( 'every user-visible string can be translated', () => {
	it( 'passes the tablist accessible name through __()', () => {
		const source = read( 'src/components/SectionShell.js' );
		// Previously a bare template literal, so it never reached the `.pot`.
		expect( source ).not.toMatch(
			/aria-label=\{ `\$\{ title \} sections` \}/
		);
		expect( source ).toContain( "'%s sections'" );
	} );
} );

describe( 'a reporting screen does not shout', () => {
	// The headline change of the screen-hierarchy work had **no** test at all:
	// deleting the whole demotion rule failed nothing. The mirror of the
	// `__stale` test, and the same shape of mistake.
	it( 'demotes panel primary buttons to the existing secondary token', () => {
		const scss = read( 'src/css/components/_section.scss' );
		const rule = scss.slice(
			scss.indexOf(
				'.wppo-dashboard .wppo-panel-group .wppo-button--primary {'
			)
		);
		expect( rule ).toMatch(
			/^\.wppo-dashboard \.wppo-panel-group \.wppo-button--primary \{/
		);
		// The plugin already has one secondary button; this must not invent a
		// third appearance with blue text where the real one is `#0f172a`.
		expect( rule ).toMatch( /color:\s*var\(--wppo-text-main\)/ );
		// And it needs a hover state, or the button lifts 1px and changes
		// nothing else.
		expect( rule ).toMatch( /&:hover \{/ );
		expect( rule ).toMatch( /background:\s*var\(--wppo-switch-hover\)/ );
	} );

	it( 'and the rule ships in the built stylesheet', () => {
		expect( read( 'build/style-index.css' ) ).toMatch(
			/\.wppo-dashboard \.wppo-panel-group \.wppo-button--primary\{/
		);
	} );

	// Two independent opt-ins: the wrapper, and being inside a PanelGroup. Miss
	// either and the demotion silently does nothing, so both are pinned.
	it( 'and the screen actually opts in', () => {
		expect( read( 'src/components/Dashboard.js' ) ).toMatch(
			/className="wppo-dashboard"/
		);
	} );
} );
