/**
 * Tests for the shared abortable-fetch helpers (audit #1401).
 */
import {
	useIsMounted,
	isAbortError,
	runAbortable,
} from '../useAbortableFetch';
import { renderHook } from '@testing-library/react';

describe( 'useAbortableFetch', () => {
	it( 'isAbortError detects abort errors only', () => {
		const abort = new Error( 'aborted' );
		abort.name = 'AbortError';
		expect( isAbortError( abort ) ).toBe( true );
		expect( isAbortError( new Error( 'x' ) ) ).toBe( false );
		expect( isAbortError( null ) ).toBe( false );
	} );

	it( 'useIsMounted is true while mounted', () => {
		const { result } = renderHook( () => useIsMounted() );
		expect( result.current.current ).toBe( true );
	} );

	it( 'runAbortable passes a working signal and cancels', async () => {
		let seenSignal = null;
		const { promise, cancel } = runAbortable( ( signal ) => {
			seenSignal = signal;
			return Promise.resolve( 'ok' );
		} );
		await expect( promise ).resolves.toBe( 'ok' );
		expect( seenSignal ).not.toBe( undefined );
		cancel();
	} );
} );
