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

import CSS_SUBJECTS, { subjectFor } from '../subjects';

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
				// Sub-fields of Remove Unused CSS. These are the escape
				// hatches for the specific ways aggressive trimming goes
				// wrong, so they are explained as part of that decision.
				'excludeUnusedCSS',
				'unusedCSSSafelistExtra',
				'unusedCSSRegressionGuard',
				'unusedCSSRegressionThreshold',
				'usedCSSExcludeUrls',
				'usedCSSDeliveryMode',
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

describe( 'subjectFor — the "where you stand now" block', () => {
	// This function had no tests at all, and the only thing that caught its
	// one real bug was looking at a screenshot: an empty safelist rendered the
	// value as a blank row, which reads as a broken panel rather than as
	// "you have not filled this in".
	it( 'reports a boolean as On or Off', () => {
		expect( subjectFor( 'minifyCSS', { minifyCSS: true } ).now ).toEqual( [
			{ label: 'Currently', value: 'On', tone: 'good' },
		] );
		expect( subjectFor( 'minifyCSS', { minifyCSS: false } ).now ).toEqual( [
			{ label: 'Currently', value: 'Off', tone: 'idle' },
		] );
	} );

	it( 'reports an empty text value as Empty, not as a blank row', () => {
		[ '', '   ' ].forEach( ( value ) => {
			expect(
				subjectFor( 'excludeUnusedCSS', { excludeUnusedCSS: value } )
					.now[ 0 ].value
			).toBe( 'Empty' );
		} );
	} );

	it( 'reports a number as a string, so the panel never renders a bare node', () => {
		expect(
			subjectFor( 'ccssMaxSize', { ccssMaxSize: 20480 } ).now[ 0 ].value
		).toBe( '20480' );
	} );

	it( 'reports a missing setting as Not set', () => {
		expect( subjectFor( 'ccssMaxSize', {} ).now[ 0 ].value ).toBe(
			'Not set'
		);
	} );

	it( 'preserves the static copy', () => {
		const s = subjectFor( 'removeUnusedCSS', { removeUnusedCSS: true } );
		expect( s.id ).toBe( 'setting:removeUnusedCSS' );
		expect( s.does ).toBe( CSS_SUBJECTS.removeUnusedCSS.does );
		expect( s.costTone ).toBe( 'warn' );
	} );

	it( 'returns undefined for an unknown key, so the row degrades to plain', () => {
		// SettingRow renders children unchanged when there is no subject, which
		// is how an unconverted screen keeps working.
		expect( subjectFor( 'noSuchSetting', {} ) ).toBeUndefined();
	} );

	it( 'gives every CSS-card subject a non-empty now block', () => {
		KEYS.forEach( ( key ) => {
			const rows = subjectFor( key, { [ key ]: '' } ).now;
			expect( Array.isArray( rows ) ).toBe( true );
			expect( rows.length ).toBeGreaterThan( 0 );
			// The bug this guards: a row whose value renders as nothing.
			rows.forEach( ( r ) => {
				expect( String( r.value ).trim() ).not.toBe( '' );
			} );
		} );
	} );
} );
