/**
 * Tests for the shared abortable-fetch helpers (audit #1401).
 */
import {
	useIsMounted,
	isAbortError,
	runAbortable,
	useAsyncWorkflow,
} from '../useAbortableFetch';
import { renderHook, act } from '@testing-library/react';

describe( 'useAbortableFetch', () => {
	it( 'isAbortError detects abort errors only', () => {
		const abort = new Error( 'aborted' );
		abort.name = 'AbortError';
		expect( isAbortError( abort ) ).toBe( true );
		expect( isAbortError( new Error( 'x' ) ) ).toBe( false );
		expect( isAbortError( null ) ).toBe( false );
		const domAbort = new Error( 'aborted' );
		domAbort.code = 20;
		expect( isAbortError( domAbort ) ).toBe( true );
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

	describe( 'useAsyncWorkflow (P3-019)', () => {
		it( 'passes a signal and reports fresh runs as not stale', async () => {
			const { result } = renderHook( () => useAsyncWorkflow() );
			let seen = null;
			await act( async () => {
				await result.current.run( async ( ctx ) => {
					seen = ctx;
					return 'done';
				} );
			} );
			expect( seen.signal ).toBeInstanceOf( AbortSignal );
			expect( seen.isStale() ).toBe( false );
			expect( typeof seen.seq ).toBe( 'number' );
		} );

		it( 'aborts the previous run and marks it stale (seq-guard)', async () => {
			const { result } = renderHook( () => useAsyncWorkflow() );
			const firstStates = [];
			let resolveFirst;
			const gate = new Promise( ( resolve ) => {
				resolveFirst = resolve;
			} );
			let firstPromise;
			let secondResult;
			await act( async () => {
				firstPromise = result.current.run( async ( ctx ) => {
					await gate;
					firstStates.push( ctx.isStale() );
					return 'first';
				} );
				secondResult = await result.current.run( async ( ctx ) => {
					resolveFirst();
					return ctx.isStale() ? 'stale' : 'second';
				} );
				await firstPromise;
			} );
			expect( secondResult ).toBe( 'second' );
			// The slow first run observed its own staleness after abort.
			expect( firstStates ).toEqual( [ true ] );
		} );

		it( 'cancel() aborts the in-flight run', async () => {
			const { result } = renderHook( () => useAsyncWorkflow() );
			let seenSignal = null;
			let taskPromise;
			await act( async () => {
				taskPromise = result.current.run( async ( { signal } ) => {
					seenSignal = signal;
					await new Promise( ( resolve ) =>
						setTimeout( resolve, 50 )
					);
					return 'late';
				} );
				result.current.cancel();
				await taskPromise;
			} );
			expect( seenSignal.aborted ).toBe( true );
		} );

		it( 'unmount aborts the in-flight run (fail-safe)', async () => {
			const { result, unmount } = renderHook( () => useAsyncWorkflow() );
			let seenSignal = null;
			let taskPromise;
			await act( async () => {
				taskPromise = result.current.run( async ( ctx ) => {
					seenSignal = ctx.signal;
					await new Promise( ( resolve ) =>
						setTimeout( resolve, 20 )
					);
					return ctx.isStale();
				} );
			} );
			unmount();
			const wasStale = await taskPromise;
			expect( seenSignal.aborted ).toBe( true );
			expect( wasStale ).toBe( true );
		} );
	} );
} );
