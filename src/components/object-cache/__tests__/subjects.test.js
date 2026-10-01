/**
 * Tests for the Object Cache inspector copy.
 *
 * These enforce the writing rules the file header states, because the copy is
 * the substance of the redesign rather than decoration: every subject must say
 * what the setting does, anything claiming a cost must name it, and nothing
 * may leak the internals this screen is otherwise careful to hide.
 *
 * @package
 */

import OBJECT_CACHE_SUBJECTS, { subjectFor } from '../subjects';

const KEYS = Object.keys( OBJECT_CACHE_SUBJECTS );

describe( 'Object cache subjects', () => {
	it( 'covers every field on the screen', () => {
		expect( KEYS ).toEqual(
			expect.arrayContaining( [
				'mode',
				'host',
				'port',
				'nodes',
				'master_name',
				'password',
				'database',
				'compression',
				'persistent',
				'use_tls',
			] )
		);
	} );

	it( 'gives every subject an id derived from its key', () => {
		// The id is what `SettingRow` matches on. Deriving it from the key is
		// what stops the two drifting apart, which shows up as an explanation
		// that silently never appears.
		KEYS.forEach( ( key ) => {
			expect( OBJECT_CACHE_SUBJECTS[ key ].id ).toBe(
				`setting:${ key }`
			);
		} );
	} );

	it( 'uses unique ids', () => {
		expect( new Set( KEYS.map( ( k ) => k ) ).size ).toBe( KEYS.length );
	} );

	it( 'marks every subject as a setting', () => {
		KEYS.forEach( ( key ) => {
			expect( OBJECT_CACHE_SUBJECTS[ key ].kind ).toBe( 'setting' );
		} );
	} );

	it( 'gives every subject a title', () => {
		KEYS.forEach( ( key ) => {
			expect( OBJECT_CACHE_SUBJECTS[ key ].title ).toBeTruthy();
		} );
	} );

	it( 'answers "what it does" for every subject, without naming internals', () => {
		// This screen is the easiest place in the plugin to leak an internal
		// name, because it is all infrastructure.
		//
		// The ban is on the plugin's *own* plumbing, not on the product names
		// the person already chose from the dropdown. "Sentinel" and "Cluster"
		// are what the options in this very screen are called, and a
		// description that avoided them would be harder to recognise than one
		// that uses them. What must never appear is the implementation: a
		// database handle, a drop-in filename, a config path, a PHP symbol.
		const INTERNALS =
			/\$wpdb|drop-?in|wp-config|extension_loaded|function_|class-|::|wp_cache/i;
		KEYS.forEach( ( key ) => {
			const { does, detail = '' } = OBJECT_CACHE_SUBJECTS[ key ];
			expect( does ).toBeTruthy();
			expect( does ).not.toMatch( INTERNALS );
			expect( detail ).not.toMatch( INTERNALS );
		} );
	} );

	it( 'names a real consequence rather than a vague one', () => {
		const VAGUE =
			/may cause issues|might break things|use with caution\.?$/i;
		KEYS.forEach( ( key ) => {
			expect( OBJECT_CACHE_SUBJECTS[ key ].cost ?? '' ).not.toMatch(
				VAGUE
			);
		} );
	} );

	it( 'only uses tones the panel styles', () => {
		KEYS.forEach( ( key ) => {
			const tone = OBJECT_CACHE_SUBJECTS[ key ].costTone ?? null;
			expect( tone === null || [ 'warn', 'bad' ].includes( tone ) ).toBe(
				true
			);
		} );
	} );

	it( 'marks the genuinely risky settings as warn', () => {
		// These four fail in ways that are invisible from the outside: a wrong
		// host, master name or password does not error, it just quietly stops
		// caching. A person has to be told that up front.
		[ 'mode', 'nodes', 'master_name', 'persistent' ].forEach( ( key ) => {
			expect( OBJECT_CACHE_SUBJECTS[ key ].costTone ).toBe( 'warn' );
		} );
	} );

	it( 'says a safe setting is safe, in one line', () => {
		expect( OBJECT_CACHE_SUBJECTS.host.cost ).toMatch(
			/no known downside/i
		);
		expect( OBJECT_CACHE_SUBJECTS.port.cost ).toMatch(
			/no known downside/i
		);
	} );
} );

describe( 'subjectFor — the "where you stand now" block', () => {
	it( 'reports a boolean as On or Off', () => {
		expect( subjectFor( 'persistent', { persistent: true } ).now ).toEqual(
			[ { label: 'Currently', value: 'On', tone: 'good' } ]
		);
		expect( subjectFor( 'persistent', { persistent: false } ).now ).toEqual(
			[ { label: 'Currently', value: 'Off', tone: 'idle' } ]
		);
	} );

	it( 'reports an empty connection field as Empty, not a blank row', () => {
		// The most common reason an object cache is not working is a blank
		// host or password. A blank row would read as a broken panel.
		[ '', '   ' ].forEach( ( value ) => {
			expect( subjectFor( 'host', { host: value } ).now[ 0 ].value ).toBe(
				'Empty'
			);
		} );
	} );

	it( 'never renders a value the reader cannot see', () => {
		// The bug this guards: a `now` row whose value is an empty string,
		// which looks like the panel failed to load.
		KEYS.forEach( ( key ) => {
			subjectFor( key, { [ key ]: '' } ).now.forEach( ( row ) => {
				expect( String( row.value ).trim() ).not.toBe( '' );
			} );
		} );
	} );

	it( 'never echoes a credential into the panel', () => {
		// The panel is rendered on screen and captured in support screenshots.
		// It must not become a place a password is displayed back to the user.
		const rows = subjectFor( 'password', { password: 'hunter2' } ).now;
		expect( rows.map( ( r ) => r.value ) ).not.toContain( 'hunter2' );
	} );

	it( 'returns undefined for an unknown key, so the row degrades to plain', () => {
		expect( subjectFor( 'noSuchSetting', {} ) ).toBeUndefined();
	} );
} );
