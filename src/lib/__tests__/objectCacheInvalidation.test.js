/**
 * Which object-cache action results invalidate the Overview memo.
 *
 * The call sat on the failure branch for a full review round, directly beneath
 * a comment asserting the opposite, and deleting it entirely failed no test.
 * This pins the rule itself, which is reachable directly — the button that
 * triggers it is disabled until the settings form is valid, so the branch
 * cannot be driven through the component in jsdom.
 */

import { shouldInvalidateObjectCache } from '../objectCacheStatus';

describe( 'shouldInvalidateObjectCache', () => {
	it( 'invalidates only on an explicit success', () => {
		expect( shouldInvalidateObjectCache( { success: true } ) ).toBe( true );
		expect(
			shouldInvalidateObjectCache( { success: true, data: {} } )
		).toBe( true );
	} );

	it( 'does NOT invalidate when the action failed', () => {
		// A failed action changes nothing, so discarding a good memo would be
		// gratuitous — and it would spend one of the five calls a minute this
		// throttled endpoint allows.
		expect(
			shouldInvalidateObjectCache( { success: false, message: 'No.' } )
		).toBe( false );
	} );

	it( 'does NOT invalidate on a response that merely looks successful', () => {
		// A `WP_Error`-shaped body carries `data` but no `success`, and a
		// truthy `data` is exactly how an error once became a "the object cache
		// is off" claim. Require the explicit flag, not a truthy payload.
		expect( shouldInvalidateObjectCache( { data: { status: 200 } } ) ).toBe(
			false
		);
		expect( shouldInvalidateObjectCache( { success: 1 } ) ).toBe( false );
	} );

	it( 'does NOT invalidate on nothing at all', () => {
		[ undefined, null, {}, '' ].forEach( ( value ) =>
			expect( shouldInvalidateObjectCache( value ) ).toBe( false )
		);
	} );
} );
