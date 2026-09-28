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
