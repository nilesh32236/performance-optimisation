/**
 * The Object Cache screen must not report itself dirty before the user has
 * touched anything.
 *
 * The defaults and the dirty baseline were two *independent* hard-coded lists.
 * The plugin schema declares 13 keys; both lists carried 10, so a server value
 * such as `outage_bypassed` reached `settings` but could never reach the
 * baseline. The comparison then differed on first render, and **every**
 * navigation attempt raised a false "You have unsaved changes" dialog whose
 * modal covered the sidebar — the screen was a trap until the user found
 * Discard.
 *
 * Every existing ObjectCache test passed `options={ {} }`, which is exactly why
 * the drift never showed up. These use the real payload.
 *
 * @package
 */

import { act, render, screen, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';

import ObjectCache from '../ObjectCache';
import UnsavedChangesContext from '../../lib/UnsavedChangesContext';
import { apiCall } from '../../lib/apiRequest';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn( async () => ( { success: true, data: {} } ) ),
	getErrorLogMessage: ( error ) =>
		error instanceof Error ? error.message : String( error ),
} ) );

/**
 * Render the screen and report every `setIsDirty(true)` it makes unprompted.
 *
 * @param {Object} options The server slice, exactly as it arrives.
 * @return {Promise<Array>} The values passed to setIsDirty.
 */
const renderAndObserve = async ( options ) => {
	const dirtyCalls = [];
	await act( async () => {
		render(
			<UnsavedChangesContext.Provider
				value={ {
					isDirty: false,
					setIsDirty: ( value ) => dirtyCalls.push( value ),
					registerGuard: () => {},
					unregisterGuard: () => {},
					clearDirty: () => {},
				} }
			>
				<ObjectCache options={ options } />
			</UnsavedChangesContext.Provider>
		);
	} );
	return dirtyCalls;
};

// Only the truthy calls matter: `setIsDirty( false )` is a reset, not a change.
const dirtied = ( calls ) => calls.filter( Boolean );

describe( 'ObjectCache dirty state', () => {
	beforeEach( () => {
		apiCall.mockClear();
	} );

	it( 'is not dirty on arrival with the real server payload', async () => {
		// The exact slice this site sends, per an independent review.
		expect(
			dirtied( await renderAndObserve( { outage_bypassed: false } ) )
		).toEqual( [] );
	} );

	it( 'is not dirty when the server sends keys the old list omitted', async () => {
		// `timeout` and `prefix` were missing from both hard-coded lists too.
		const calls = await renderAndObserve( {
			timeout: 5,
			prefix: 'wp:',
			outage_bypassed: true,
		} );
		expect( dirtied( calls ) ).toEqual( [] );
	} );

	it( 'is not dirty with the empty slice the old tests used', async () => {
		expect( dirtied( await renderAndObserve( {} ) ) ).toEqual( [] );
	} );

	it( 'does not treat an explicitly undefined option as a change', async () => {
		// `{ ...defaults, ...options }` would let an explicit `undefined`
		// clobber a real default, which is the other half of the same trap.
		const calls = await renderAndObserve( {
			host: undefined,
			port: undefined,
		} );
		expect( dirtied( calls ) ).toEqual( [] );
	} );

	it( 'does not persist invented values for keys the plugin never read', async () => {
		// `object_cache.timeout` and `.prefix` are declared by the schema but
		// have no consumer — the connect timeout is hardcoded 0.5 and the
		// drop-in builds its prefix from $table_prefix. Shipping defaults for
		// them would have started persisting two keys the plugin never wrote.
		const api = require( '../../lib/apiRequest' );
		api.apiCall.mockClear();
		api.apiCall.mockResolvedValue( { success: true } );
		await act( async () => {
			render( <ObjectCache options={ { password: 'x' } } /> );
		} );
		const save = screen
			.getAllByRole( 'button', { name: /Save/i } )
			.find( ( b ) => ! b.disabled );
		// If the control is not there, the test fails loudly rather than
		// passing by skipping its own assertion.
		expect( save ).toBeDefined();

		await act( async () => {
			save.click();
		} );
		await waitFor( () => expect( api.apiCall ).toHaveBeenCalled() );
		const payload = api.apiCall.mock.calls
			.map( ( c ) => c[ 1 ] && c[ 1 ].settings )
			.find( Boolean );
		expect( payload ).toBeDefined();
		expect( payload ).not.toHaveProperty( 'timeout' );
		expect( payload ).not.toHaveProperty( 'prefix' );
	} );

	it( 'still renders its actions with a full payload, and stays clean', async () => {
		const calls = await renderAndObserve( {
			mode: 'standalone',
			host: '10.0.0.5',
			port: 6380,
			password: '',
			database: 2,
			timeout: 5,
			prefix: 'wp:',
			nodes: '',
			master_name: 'mymaster',
			use_tls: true,
			persistent: true,
			compression: 'lz4',
			outage_bypassed: false,
		} );
		await screen.findByRole( 'button', {
			name: /Ping|Flush Cache|Test Connection/i,
		} );
		expect( dirtied( calls ) ).toEqual( [] );
	} );
} );
