/**
 * Tests for the bounded Dashboard image-job polling workflow (P3-020).
 */
import { act, renderHook } from '@testing-library/react';
import { apiCall } from '../apiRequest';
import useImageJobPolling from '../useImageJobPolling';

jest.mock( '../apiRequest', () => ( {
	apiCall: jest.fn(),
	getErrorLogMessage: jest.fn( ( error ) => error?.message || 'Error' ),
} ) );

const flushPromises = async () => {
	await act( async () => {
		await Promise.resolve();
		await Promise.resolve();
	} );
};

describe( 'useImageJobPolling', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		jest.clearAllMocks();
	} );

	afterEach( () => {
		jest.clearAllTimers();
		jest.useRealTimers();
	} );

	it( 'cancels and ignores a superseded in-flight response', async () => {
		const notify = jest.fn();
		const onImageInfo = jest.fn();
		let resolveFirst;
		const firstResponse = new Promise( ( resolve ) => {
			resolveFirst = resolve;
		} );
		apiCall
			.mockImplementationOnce( () => firstResponse )
			.mockResolvedValueOnce( {
				success: true,
				data: {
					queued_jobs: 0,
					completed: { webp: 2 },
					pending: { webp: 0 },
					failed: { webp: 0 },
				},
			} );

		const { result } = renderHook( () =>
			useImageJobPolling( { notify, onImageInfo } )
		);

		act( () => result.current.startPolling( 3 ) );
		await act( async () => {
			jest.advanceTimersByTime( 5000 );
		} );

		expect( apiCall ).toHaveBeenCalledTimes( 1 );
		const firstSignal = apiCall.mock.calls[ 0 ][ 3 ];
		expect( firstSignal.aborted ).toBe( false );

		act( () => result.current.startPolling( 1 ) );
		expect( firstSignal.aborted ).toBe( true );

		await act( async () => {
			resolveFirst( {
				success: true,
				data: {
					queued_jobs: 0,
					completed: { webp: 99 },
					pending: { webp: 0 },
					failed: { webp: 0 },
				},
			} );
			await firstResponse;
		} );
		await flushPromises();

		expect( onImageInfo ).not.toHaveBeenCalled();
		expect( notify ).not.toHaveBeenCalled();
		expect( result.current.bgProcessing ).toBe( true );
		expect( result.current.bgJobsQueued ).toBe( 1 );

		await act( async () => {
			jest.advanceTimersByTime( 5000 );
		} );
		await flushPromises();

		expect( onImageInfo ).toHaveBeenCalledTimes( 1 );
		expect( notify ).toHaveBeenCalledWith(
			expect.objectContaining( { type: 'success' } )
		);
		expect( result.current.bgProcessing ).toBe( false );
		expect( apiCall ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'aborts polling and clears its timer on unmount', async () => {
		const notify = jest.fn();
		const onImageInfo = jest.fn();
		let resolveStatus;
		const statusResponse = new Promise( ( resolve ) => {
			resolveStatus = resolve;
		} );
		apiCall.mockImplementationOnce( () => statusResponse );

		const { result, unmount } = renderHook( () =>
			useImageJobPolling( { notify, onImageInfo } )
		);
		act( () => result.current.startPolling( 2 ) );
		await act( async () => {
			jest.advanceTimersByTime( 5000 );
		} );

		const signal = apiCall.mock.calls[ 0 ][ 3 ];
		unmount();

		expect( signal.aborted ).toBe( true );
		expect( jest.getTimerCount() ).toBe( 0 );

		await act( async () => {
			resolveStatus( {
				success: true,
				data: {
					queued_jobs: 0,
					completed: { webp: 4 },
					pending: { webp: 0 },
					failed: { webp: 0 },
				},
			} );
			await statusResponse;
		} );
		await flushPromises();

		expect( onImageInfo ).not.toHaveBeenCalled();
		expect( notify ).not.toHaveBeenCalled();
	} );

	it( 'keeps processing state isolated between card workflows', () => {
		const first = renderHook( () =>
			useImageJobPolling( { notify: jest.fn(), onImageInfo: jest.fn() } )
		);
		const second = renderHook( () =>
			useImageJobPolling( { notify: jest.fn(), onImageInfo: jest.fn() } )
		);

		act( () => first.result.current.startPolling( 7 ) );

		expect( first.result.current.bgProcessing ).toBe( true );
		expect( first.result.current.bgJobsQueued ).toBe( 7 );
		expect( second.result.current.bgProcessing ).toBe( false );
		expect( second.result.current.bgJobsQueued ).toBe( 0 );

		act( () => first.result.current.stopPolling() );
		expect( first.result.current.bgProcessing ).toBe( false );
	} );
} );
