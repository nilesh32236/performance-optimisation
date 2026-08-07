import { renderHook, act } from '@testing-library/react';
import useNotice from '../useNotification';

describe( 'useNotice', () => {
	beforeEach( () => {
		jest.useRealTimers();
	} );

	it( 'starts with no notice', () => {
		const { result } = renderHook( () => useNotice() );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'sets a notice via notify', () => {
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( { type: 'success', message: 'Saved.' } );
		} );
		expect( result.current.notice ).toEqual( {
			type: 'success',
			message: 'Saved.',
		} );
	} );

	it( 'surfaces error and success types unchanged', () => {
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( { type: 'error', message: 'Oops.' } );
		} );
		expect( result.current.notice.type ).toBe( 'error' );
		act( () => {
			result.current.notify( { type: 'success', message: 'Done.' } );
		} );
		expect( result.current.notice.type ).toBe( 'success' );
		expect( result.current.notice.message ).toBe( 'Done.' );
	} );

	it( 'dismisses immediately and clears any pending timer', () => {
		jest.useFakeTimers();
		const { result } = renderHook( () =>
			useNotice( { autoDismissMs: 5000 } )
		);
		act( () => {
			result.current.notify( { type: 'warning', message: 'Heads up.' } );
		} );
		expect( result.current.notice ).not.toBeNull();
		act( () => {
			result.current.dismiss();
		} );
		expect( result.current.notice ).toBeNull();
		// Advancing past the old auto-dismiss window must not re-show a notice.
		act( () => {
			jest.advanceTimersByTime( 6000 );
		} );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'auto-dismisses after the configured duration', () => {
		jest.useFakeTimers();
		const { result } = renderHook( () =>
			useNotice( { autoDismissMs: 3000 } )
		);
		act( () => {
			result.current.notify( { type: 'success', message: 'Saved.' } );
		} );
		expect( result.current.notice ).not.toBeNull();
		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'prefers a per-notification durationMs over the default', () => {
		jest.useFakeTimers();
		const { result } = renderHook( () =>
			useNotice( { autoDismissMs: 5000 } )
		);
		act( () => {
			result.current.notify( {
				type: 'success',
				message: 'Short.',
				durationMs: 1000,
			} );
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'clears the prior timer when a new notice supersedes it', () => {
		jest.useFakeTimers();
		const { result } = renderHook( () =>
			useNotice( { autoDismissMs: 5000 } )
		);
		act( () => {
			result.current.notify( { type: 'success', message: 'First.' } );
		} );
		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );
		act( () => {
			result.current.notify( { type: 'success', message: 'Second.' } );
		} );
		// If the first timer were not cleared it would fire now and hide the notice.
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( result.current.notice.message ).toBe( 'Second.' );
		// The second timer eventually dismisses it.
		act( () => {
			jest.advanceTimersByTime( 3000 );
		} );
		expect( result.current.notice ).toBeNull();
	} );

	it( 'does not auto-dismiss when no auto-dismiss duration is configured', () => {
		jest.useFakeTimers();
		const { result } = renderHook( () => useNotice() );
		act( () => {
			result.current.notify( { type: 'error', message: 'Sticky.' } );
		} );
		act( () => {
			jest.advanceTimersByTime( 100000 );
		} );
		expect( result.current.notice ).not.toBeNull();
	} );

	it( 'cleans up its timer on unmount without scheduling a setState', () => {
		jest.useFakeTimers();
		const consoleError = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		const { result, unmount } = renderHook( () =>
			useNotice( { autoDismissMs: 5000 } )
		);
		act( () => {
			result.current.notify( { type: 'info', message: 'Scheduled.' } );
		} );
		unmount();
		// The pending auto-dismiss timer must be cleared on unmount, so
		// advancing time fires no post-unmount setState (which would surface
		// as an "update not wrapped in act" console.error in tests).
		act( () => {
			jest.advanceTimersByTime( 6000 );
		} );
		expect( consoleError ).not.toHaveBeenCalled();
		consoleError.mockRestore();
	} );
} );
