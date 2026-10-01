/**
 * Tests for the CSS Optimisation inspector copy.
 *
 * These guard a failure mode that is invisible in review: a subject whose `id`
 * does not match the row it is attached to. The panel would still render, the
 * row would still highlight nothing, and the only symptom is an explanation
 * that never appears — which nobody notices until a user reports it.
 *
 * They also enforce the writing rules the redesign depends on, because the
 * copy is the substance of the design and not decoration: every subject must
 * say what the thing does, and anything claiming a cost must name it.
 *
 * @package
 */

import CSS_SUBJECTS from '../subjects';

const KEYS = Object.keys( CSS_SUBJECTS );

describe( 'CSS optimisation subjects', () => {
	it( 'covers the settings on the CSS card', () => {
		expect( KEYS ).toEqual(
			expect.arrayContaining( [
				'minifyCSS',
				'combineCSS',
				'removeUnusedCSS',
				'criticalCSS',
				'hostGoogleFontsLocally',
				'fontMetricFallback',
				'fontSubset',
				'excludeCombineCSS',
				'excludeCSS',
				'ccssMaxSize',
				'ccssSafelistExtra',
				'ccssExcludedPostTypes',
				'ccssMaxRetries',
			] )
		);
	} );

	it( 'gives every subject an id derived from its key', () => {
		// The id is what `SettingRow` matches against. Deriving it from the key
		// is what stops the two drifting apart.
		KEYS.forEach( ( key ) => {
			expect( CSS_SUBJECTS[ key ].id ).toBe( `setting:${ key }` );
		} );
	} );

	it( 'uses unique ids', () => {
		const ids = KEYS.map( ( key ) => CSS_SUBJECTS[ key ].id );
		expect( new Set( ids ).size ).toBe( ids.length );
	} );

	it( 'marks every subject as a setting', () => {
		KEYS.forEach( ( key ) => {
			expect( CSS_SUBJECTS[ key ].kind ).toBe( 'setting' );
		} );
	} );

	it( 'answers "what it does" for every subject, without naming internals', () => {
		KEYS.forEach( ( key ) => {
			const { does } = CSS_SUBJECTS[ key ];
			expect( typeof does ).toBe( 'string' );
			expect( does.length ).toBeGreaterThan( 30 );

			// The panel is read by a site owner, not a developer. A handle, a
			// hook or a PHP function name in this string is a leak.
			expect( does ).not.toMatch(
				/\bwp_[a-z_]+\(|add_action|apply_filters|\bwp-emoji\b/
			);
		} );
	} );

	it( 'gives every subject a title', () => {
		KEYS.forEach( ( key ) => {
			expect( CSS_SUBJECTS[ key ].title.length ).toBeGreaterThan( 2 );
		} );
	} );

	it( 'names a real consequence rather than a vague one', () => {
		const VAGUE =
			/may cause issues|might break things|use with caution\.?$/i;
		KEYS.forEach( ( key ) => {
			// Asserted unconditionally. A subject with no `cost` is fine, but the
			// check must not be *skipped* for the ones that have one, which is
			// exactly what a conditional expect does.
			expect( CSS_SUBJECTS[ key ].cost ?? '' ).not.toMatch( VAGUE );
		} );
	} );

	it( 'only uses tones the panel styles', () => {
		KEYS.forEach( ( key ) => {
			const tone = CSS_SUBJECTS[ key ].costTone ?? null;
			expect( tone === null || [ 'warn', 'bad' ].includes( tone ) ).toBe(
				true
			);
		} );
	} );

	it( 'marks the genuinely risky settings as warn', () => {
		// These are the ones that break a page when enabled carelessly. If a
		// refactor drops the tone, the panel stops warning before the words are
		// read — which is the whole point of having a tone.
		expect( CSS_SUBJECTS.combineCSS.costTone ).toBe( 'warn' );
		expect( CSS_SUBJECTS.removeUnusedCSS.costTone ).toBe( 'warn' );
		expect( CSS_SUBJECTS.fontSubset.costTone ).toBe( 'warn' );
		expect( CSS_SUBJECTS.ccssMaxSize.costTone ).toBe( 'warn' );
	} );

	it( 'says a safe setting is safe, in one line', () => {
		expect( CSS_SUBJECTS.minifyCSS.cost ).toMatch( /no known downside/i );
		expect( CSS_SUBJECTS.fontMetricFallback.cost ).toMatch(
			/no known downside/i
		);
	} );
} );
