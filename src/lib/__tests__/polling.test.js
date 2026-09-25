import {
	POLL_INTERVAL_MS,
	MAX_POLL_ATTEMPTS,
	MAX_POLL_DELAY_MS,
	getPollDelay,
} from '../polling';

describe( 'polling backoff', () => {
	it( 'exposes the shared constants', () => {
		expect( POLL_INTERVAL_MS ).toBe( 5000 );
		expect( MAX_POLL_ATTEMPTS ).toBe( 60 );
		expect( MAX_POLL_DELAY_MS ).toBe( 15000 );
	} );

	it( 'backs off with the attempt count and caps at the max', () => {
		expect( getPollDelay( 1 ) ).toBe( 5000 );
		expect( getPollDelay( 10 ) ).toBe( 5000 );
		expect( getPollDelay( 11 ) ).toBe( 10000 );
		expect( getPollDelay( 60 ) ).toBe( 15000 );
		expect( getPollDelay( 1000 ) ).toBe( 15000 );
	} );

	it( 'fails open to the base interval for non-finite attempts', () => {
		expect( getPollDelay( NaN ) ).toBe( POLL_INTERVAL_MS );
		expect( getPollDelay( undefined ) ).toBe( POLL_INTERVAL_MS );
	} );
} );
