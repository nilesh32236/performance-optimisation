import { renderHook, act } from '@testing-library/react';
import useNotice from '../useNotice';

describe( 'useNotice', () => {
	beforeEach( () => {
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'starts with no notice', () => {
		const { result } = renderHook( () => useNotice() );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'shows a notice when notify is called', () => {
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( {
				type: 'success',
				message: 'Saved successfully.',
			} );
		} );
		expect( result.current.notice ).toEqual( {
			type: 'success',
			message: 'Saved successfully.',
		} );
	} );

	it( 'clears the notice when dismiss is called', () => {
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( { type: 'error', message: 'Oops.' } );
		} );
		expect( result.current.notice ).not.toBeNull();
		act( () => {
			result.current.dismiss();
		} );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'auto-dismisses after the provided durationMs', () => {
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( {
				type: 'warning',
				message: 'Heads up.',
				durationMs: 5000,
			} );
		} );
		expect( result.current.notice ).not.toBeNull();
		act( () => {
			jest.advanceTimersByTime( 4999 );
		} );
		expect( result.current.notice ).not.toBeNull();
		act( () => {
			jest.advanceTimersByTime( 1 );
		} );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'does not auto-dismiss when durationMs is omitted', () => {
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( { type: 'info', message: 'Sticky.' } );
		} );
		act( () => {
			jest.advanceTimersByTime( 10000 );
		} );
		expect( result.current.notice ).not.toBeNull();
	} );

	it( 'replaces the previous notice and cancels its timer', () => {
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( {
				type: 'error',
				message: 'First error.',
				durationMs: 3000,
			} );
		} );
		act( () => {
			result.current.notify( {
				type: 'success',
				message: 'Second success.',
				durationMs: 5000,
			} );
		} );
		expect( result.current.notice ).toEqual( {
			type: 'success',
			message: 'Second success.',
		} );
		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );
		expect( result.current.notice ).not.toBeNull();
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'clears any pending timer on unmount', () => {
		const { result, unmount } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( {
				type: 'error',
				message: 'Pending.',
				durationMs: 5000,
			} );
		} );
		expect( result.current.notice ).not.toBeNull();
		act( () => {
			unmount();
			jest.advanceTimersByTime( 5000 );
		} );
		expect( result.current.notice ).not.toBeNull();
	} );
} );
