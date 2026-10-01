/**
 * Tests for the Preload inspector copy.
 *
 * Enforces the writing rules in the subjects file header. Two of them are
 * specific to this screen and are the reason these tests exist:
 *
 *  - nothing may leak a resource-hint header or a plugin internal, because the
 *    whole point of the copy is to describe what the *browser* does;
 *  - a list-valued setting must say what a valid line looks like, because the
 *    convention is "one per line" and nothing on screen repeats it per field.
 *
 * @package
 */

import PRELOAD_SUBJECTS, { subjectFor } from '../subjects';

const KEYS = Object.keys( PRELOAD_SUBJECTS );

/** Settings that take a list, so the "one per line" rule must be stated. */
const LIST_KEYS = [
	'excludePreloadCache',
	'preconnectOrigins',
	'dnsPrefetchOrigins',
	'preloadFontsUrls',
	'preloadCSSUrls',
	'speculationExcludeUrls',
];

/** Settings that do nothing at all while their list is empty. */
const NEEDS_A_LIST = [ 'preconnect', 'prefetchDNS' ];

describe( 'Preload subjects', () => {
	it( 'covers every field on the screen', () => {
		expect( KEYS ).toEqual(
			expect.arrayContaining( [
				'enablePreloadCache',
				'excludePreloadCache',
				'preconnect',
				'preconnectOrigins',
				'prefetchDNS',
				'dnsPrefetchOrigins',
				'preloadFontsUrls',
				'autoDiscoverFonts',
				'autoLcpPreload',
				'preloadCSSUrls',
				'enableSpeculationRules',
				'speculationMode',
				'speculationEagerness',
				'speculationPrerenderList',
				'speculationExcludeUrls',
			] )
		);
	} );

	it( 'gives every subject an id derived from its key', () => {
		KEYS.forEach( ( key ) => {
			expect( PRELOAD_SUBJECTS[ key ].id ).toBe( `setting:${ key }` );
		} );
	} );

	it( 'marks every subject as a setting and gives it a title', () => {
		KEYS.forEach( ( key ) => {
			expect( PRELOAD_SUBJECTS[ key ].kind ).toBe( 'setting' );
			expect( PRELOAD_SUBJECTS[ key ].title ).toBeTruthy();
		} );
	} );

	it( 'answers "what it does" without naming the implementation', () => {
		// The copy describes the browser, not the plugin. A resource-hint
		// header name or a function name is exactly the leak being guarded.
		// Only an actual header or attribute name counts as a leak. The words
		// "preload", "preconnect" and "prefetch" are this product's own
		// vocabulary — the settings are labelled with them and a description
		// that avoided them would be less clear, not more.
		const INTERNALS =
			/rel=|as=|fetchpriority|crossorigin|\$wpdb|wp_cache|wp_[a-z]+_|do_action|apply_filters/i;
		KEYS.forEach( ( key ) => {
			// `does` and `detail` must not name a header. `title` is allowed
			// to, because the settings are labelled with these words in the UI.
			const { does, detail = '' } = PRELOAD_SUBJECTS[ key ];
			expect( does ).toBeTruthy();
			expect( does ).not.toMatch( INTERNALS );
			expect( detail ).not.toMatch( INTERNALS );
		} );
	} );

	it( 'names a real consequence rather than a vague one', () => {
		const VAGUE =
			/may cause issues|might break things|use with caution\.?$/i;
		KEYS.forEach( ( key ) => {
			expect( PRELOAD_SUBJECTS[ key ].cost ?? '' ).not.toMatch( VAGUE );
		} );
	} );

	it( 'only uses tones the panel styles', () => {
		KEYS.forEach( ( key ) => {
			const tone = PRELOAD_SUBJECTS[ key ].costTone ?? null;
			expect( tone === null || [ 'warn', 'bad' ].includes( tone ) ).toBe(
				true
			);
		} );
	} );

	it( 'tells the reader how to fill in a list', () => {
		// The convention is one entry per line. It is not repeated on the field
		// itself, so the explanation has to carry it.
		LIST_KEYS.forEach( ( key ) => {
			const text = `${ PRELOAD_SUBJECTS[ key ].does } ${
				PRELOAD_SUBJECTS[ key ].cost ?? ''
			}`;
			expect( text ).toMatch( /per line|one line/i );
		} );
	} );

	it( 'warns that a switch with an empty list does nothing', () => {
		// This is the most common way to set up this screen wrongly: turn the
		// switch on, never fill in the list, and conclude the feature is broken.
		NEEDS_A_LIST.forEach( ( key ) => {
			expect( PRELOAD_SUBJECTS[ key ].cost ).toMatch(
				/does nothing|with this list empty/i
			);
		} );
	} );

	it( 'groups the copy by the group the field sits in', () => {
		// The screen has five visually distinct groups, and the panel's kicker
		// is how the reader knows which one they are looking at.
		const kickers = new Set(
			KEYS.map( ( k ) => PRELOAD_SUBJECTS[ k ].kicker )
		);
		expect( kickers.size ).toBeGreaterThanOrEqual( 5 );
	} );
} );

describe( 'subjectFor — the "where you stand now" block', () => {
	it( 'reports a boolean as On or Off', () => {
		expect( subjectFor( 'preconnect', { preconnect: true } ).now ).toEqual(
			[ { label: 'Currently', value: 'On', tone: 'good' } ]
		);
	} );

	it( 'counts the entries in a list rather than echoing it', () => {
		// "Is anything in here" is the question that decides whether the switch
		// above it is doing anything. Printing the list would be unreadable and
		// would put third-party hosts all over the panel.
		const rows = subjectFor( 'preconnectOrigins', {
			preconnectOrigins: 'https://a.example\nhttps://b.example\n',
		} ).now;
		expect( rows ).toEqual( [
			{ label: 'Entries', value: '2', tone: 'idle' },
		] );
	} );

	it( 'ignores blank lines when counting', () => {
		const rows = subjectFor( 'preconnectOrigins', {
			preconnectOrigins: 'https://a.example\n\n   \nhttps://b.example',
		} ).now;
		expect( rows[ 0 ].value ).toBe( '2' );
	} );

	it( 'reports an empty list as zero, not as a blank row', () => {
		// The bug this guards: a blank value row reads as a failed panel.
		const rows = subjectFor( 'preconnectOrigins', {
			preconnectOrigins: '',
		} ).now;
		expect( rows[ 0 ].value ).toBe( '0' );
	} );

	it( 'never renders a value the reader cannot see', () => {
		KEYS.forEach( ( key ) => {
			subjectFor( key, { [ key ]: '' } ).now.forEach( ( row ) => {
				expect( String( row.value ).trim() ).not.toBe( '' );
			} );
		} );
	} );

	it( 'returns undefined for an unknown key', () => {
		expect( subjectFor( 'noSuchSetting', {} ) ).toBeUndefined();
	} );
} );
