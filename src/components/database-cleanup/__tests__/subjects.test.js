/**
 * Tests for the Database Cleanup inspector copy.
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

import DATABASE_CLEANUP_SUBJECTS, { subjectFor } from '../subjects';

const KEYS = Object.keys( DATABASE_CLEANUP_SUBJECTS );

describe( 'database cleanup subjects', () => {
	it( 'covers the settings on the Automated Database Cleanup card', () => {
		expect( KEYS ).toEqual(
			expect.arrayContaining( [
				'dbSchedule',
				'dbRevMaxAge',
				'dbRevKeepLatest',
				'dbOptimize',
			] )
		);
	} );

	it( 'covers exactly the settings the card saves', () => {
		// The completeness check, from first principles: a subject with no
		// matching field is an explanation nobody can reach, and a field with
		// no subject is a control the pilot did not convert.
		expect( KEYS.slice().sort() ).toEqual( [
			'dbOptimize',
			'dbRevKeepLatest',
			'dbRevMaxAge',
			'dbSchedule',
		] );
	} );

	it( 'gives every subject an id derived from its key', () => {
		// The id is what `SettingRow` matches against. Deriving it from the key
		// is what stops the two drifting apart.
		KEYS.forEach( ( key ) => {
			expect( DATABASE_CLEANUP_SUBJECTS[ key ].id ).toBe(
				`setting:${ key }`
			);
		} );
	} );

	it( 'uses unique ids', () => {
		const ids = KEYS.map( ( key ) => DATABASE_CLEANUP_SUBJECTS[ key ].id );
		expect( new Set( ids ).size ).toBe( ids.length );
	} );

	it( 'marks every subject as a setting', () => {
		KEYS.forEach( ( key ) => {
			expect( DATABASE_CLEANUP_SUBJECTS[ key ].kind ).toBe( 'setting' );
		} );
	} );

	it( 'answers "what it does" for every subject, without naming internals', () => {
		KEYS.forEach( ( key ) => {
			const { does } = DATABASE_CLEANUP_SUBJECTS[ key ];
			expect( typeof does ).toBe( 'string' );
			expect( does.length ).toBeGreaterThan( 30 );

			// The panel is read by a site owner, not a developer. A method
			// name, a hook or a SQL verb in this string is a leak.
			expect( does ).not.toMatch(
				/\bwp_[a-z_]+\(|add_action|apply_filters|OPTIMIZE TABLE|DELETE FROM/i
			);
		} );
	} );

	it( 'gives every subject a title', () => {
		KEYS.forEach( ( key ) => {
			expect(
				DATABASE_CLEANUP_SUBJECTS[ key ].title.length
			).toBeGreaterThan( 2 );
		} );
	} );

	it( 'gives every subject a kicker', () => {
		KEYS.forEach( ( key ) => {
			expect( DATABASE_CLEANUP_SUBJECTS[ key ].kicker ).toEqual(
				expect.any( String )
			);
		} );
	} );

	it( 'names a real consequence rather than a vague one', () => {
		const VAGUE =
			/may cause issues|might break things|use with caution\.?$/i;
		KEYS.forEach( ( key ) => {
			// Asserted unconditionally. A subject with no `cost` is fine, but
			// the check must not be *skipped* for the ones that have one, which
			// is exactly what a conditional expect does.
			expect( DATABASE_CLEANUP_SUBJECTS[ key ].cost ?? '' ).not.toMatch(
				VAGUE
			);
		} );
	} );

	it( 'only uses tones the panel styles', () => {
		KEYS.forEach( ( key ) => {
			const tone = DATABASE_CLEANUP_SUBJECTS[ key ].costTone ?? null;
			expect( tone === null || [ 'warn', 'bad' ].includes( tone ) ).toBe(
				true
			);
		} );
	} );

	it( 'marks the genuinely risky settings as warn', () => {
		// These two destroy data (a scheduled run deletes trash and spam
		// without a prompt) or hold a lock on a live table. If a refactor drops
		// the tone, the panel stops warning before the words are read — which
		// is the whole point of having a tone.
		expect( DATABASE_CLEANUP_SUBJECTS.dbSchedule.costTone ).toBe( 'warn' );
		expect( DATABASE_CLEANUP_SUBJECTS.dbOptimize.costTone ).toBe( 'warn' );
	} );

	it( 'leaves the two revision limits un-warned', () => {
		// They are ordinary limits with a stated bound, not hazards. Marking
		// them warn would train people to stop reading the tone.
		expect(
			DATABASE_CLEANUP_SUBJECTS.dbRevMaxAge.costTone ?? null
		).toBeNull();
		expect(
			DATABASE_CLEANUP_SUBJECTS.dbRevKeepLatest.costTone ?? null
		).toBeNull();
	} );

	it( 'states the real revision age bound, not "no limit"', () => {
		// `get_revision_defaults()` clamps the age to 1-365, so the
		// pre-existing on-screen "(0 for no age limit)" is not true. The
		// inspector must not repeat a claim the code contradicts.
		expect( DATABASE_CLEANUP_SUBJECTS.dbRevMaxAge.cost ).toMatch(
			/between 1 and 365/
		);
	} );
} );

describe( 'subjectFor', () => {
	it( 'returns undefined for a key with no subject', () => {
		// A typo must degrade to an unwired field, not crash the screen.
		expect( subjectFor( 'notASetting', {} ) ).toBeUndefined();
	} );

	it( 'reads a boolean as on or off', () => {
		expect( subjectFor( 'dbOptimize', { dbOptimize: true } ).now ).toEqual(
			[ { label: 'Currently', value: 'On', tone: 'good' } ]
		);
		expect( subjectFor( 'dbOptimize', { dbOptimize: false } ).now ).toEqual(
			[ { label: 'Currently', value: 'Off', tone: 'idle' } ]
		);
	} );

	it( 'reads a number and a string as a current value', () => {
		expect( subjectFor( 'dbRevMaxAge', { dbRevMaxAge: 30 } ).now ).toEqual(
			[ { label: 'Current value', value: '30', tone: 'idle' } ]
		);
		expect(
			subjectFor( 'dbSchedule', { dbSchedule: 'weekly' } ).now
		).toEqual( [
			{ label: 'Current value', value: 'weekly', tone: 'idle' },
		] );
	} );

	it( 'reads a missing value as not set', () => {
		expect( subjectFor( 'dbRevKeepLatest', {} ).now ).toEqual( [
			{ label: 'Currently', value: 'Not set', tone: 'idle' },
		] );
	} );

	it( 'defaults its settings argument', () => {
		// Called from JSX, where a partially-loaded settings object can arrive.
		expect( () => subjectFor( 'dbSchedule' ) ).not.toThrow();
	} );

	it( 'keeps the prose and only adds the state', () => {
		const subject = subjectFor( 'dbSchedule', { dbSchedule: 'daily' } );
		expect( subject.id ).toBe( 'setting:dbSchedule' );
		expect( subject.does ).toBe(
			DATABASE_CLEANUP_SUBJECTS.dbSchedule.does
		);
		expect( subject.now ).toHaveLength( 1 );
	} );
} );
