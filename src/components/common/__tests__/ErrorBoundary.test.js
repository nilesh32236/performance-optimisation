import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import fs from 'fs';
import path from 'path';
import ErrorBoundary from '../ErrorBoundary';

const ProblemChild = ( { shouldThrow = false, error } ) => {
	if ( shouldThrow ) {
		throw error ?? new Error( 'Test error' );
	}
	return <div>Normal child</div>;
};

describe( 'ErrorBoundary Component', () => {
	beforeEach( () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'renders children normally when there is no error', () => {
		render(
			<ErrorBoundary>
				<div>Child content</div>
			</ErrorBoundary>
		);
		expect( screen.getByText( 'Child content' ) ).toBeInTheDocument();
	} );

	it( 'displays fallback UI when a child component throws', () => {
		render(
			<ErrorBoundary>
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		expect(
			screen.getByText( 'Something went wrong' )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'An unexpected error occurred. Please reload the page.'
			)
		).toBeInTheDocument();
	} );

	it( 'renders a Reload button in the error state', () => {
		render(
			<ErrorBoundary>
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		expect(
			screen.getByRole( 'button', { name: /Reload/i } )
		).toBeInTheDocument();
	} );

	it( 'announces the fallback to assistive tech', () => {
		// Audit #1354: `role="alert"` on the fallback container is the a11y
		// contract the component's own comment claims. jsdom exposes the role,
		// so this pins it without needing a browser.
		render(
			<ErrorBoundary>
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Something went wrong'
		);
	} );

	it( 'reloads the page when the Reload button is clicked', () => {
		// The Reload button is the fallback's only interactive branch, and the
		// only escape hatch the component offers. It was previously never
		// clicked, so the handler could be deleted or rewired and the suite
		// stayed green. jsdom's `window.location` is non-configurable and its
		// `reload` is read-only, so the handler is injected via `onReload`.
		const onReload = jest.fn();
		render(
			<ErrorBoundary onReload={ onReload }>
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		fireEvent.click( screen.getByRole( 'button', { name: /Reload/i } ) );
		expect( onReload ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'defaults the Reload handler to a full page reload', () => {
		// Pins the *default* wiring, which the click test above bypasses by
		// injecting its own handler. Source-level because jsdom cannot observe a
		// real navigation: `window.location.reload()` is a not-implemented
		// jsdom call that emits a console error the suite forbids.
		const source = fs.readFileSync(
			path.join( __dirname, '..', 'ErrorBoundary.js' ),
			'utf8'
		);
		expect( source ).toContain( 'window.location.reload()' );
		// And the button really uses the injected-or-default handler.
		expect( source ).toContain(
			'onClick={ this.props.onReload ?? reloadPage }'
		);
	} );

	it( 'recovers when resetKey changes', () => {
		// App.js renders one stable boundary instance keyed by the routed
		// area/view. Without a reset path the first throw latched the fallback
		// for the whole SPA lifetime while the sidebar kept navigating — a
		// dead-end whose only escape discarded every other tab's unsaved work.
		const { rerender } = render(
			<ErrorBoundary resetKey="tools:plugin-setting">
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		expect(
			screen.getByText( 'Something went wrong' )
		).toBeInTheDocument();

		rerender(
			<ErrorBoundary resetKey="tools:ai-adaptive">
				<div>Recovered content</div>
			</ErrorBoundary>
		);
		expect(
			screen.queryByText( 'Something went wrong' )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'Recovered content' ) ).toBeInTheDocument();
	} );

	it( 'keeps the fallback latched while resetKey is unchanged', () => {
		const { rerender } = render(
			<ErrorBoundary resetKey="tools:plugin-setting">
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		rerender(
			<ErrorBoundary resetKey="tools:plugin-setting">
				<div>Not rendered</div>
			</ErrorBoundary>
		);
		expect(
			screen.getByText( 'Something went wrong' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'Not rendered' ) ).not.toBeInTheDocument();
	} );

	it( 'logs a sentinel, and does not throw, for an uncoercible thrown value', () => {
		// A null-prototype object has no `toString`, so an unguarded
		// `String( error )` in componentDidCatch throws. React treats that as a
		// new commit-phase error and escalates to the parent boundary — the
		// crash handler would blank wp-admin while handling a crash. The
		// canonical getErrorLogMessage() helper cannot throw for any value.
		const hostile = Object.create( null );
		expect( () =>
			render(
				<ErrorBoundary>
					<ProblemChild shouldThrow={ true } error={ hostile } />
				</ErrorBoundary>
			)
		).not.toThrow();
		expect( console.error ).toHaveBeenCalledWith(
			'ErrorBoundary caught:',
			'Unknown error'
		);
	} );

	it( 'caps the logged message at 500 characters', () => {
		render(
			<ErrorBoundary>
				<ProblemChild
					shouldThrow={ true }
					error={ 'y'.repeat( 600 ) }
				/>
			</ErrorBoundary>
		);
		const logged = console.error.mock.calls
			.filter( ( call ) => call[ 0 ] === 'ErrorBoundary caught:' )
			.flatMap( ( call ) => call.slice( 1 ) );
		expect( logged ).toHaveLength( 1 );
		expect( logged[ 0 ] ).toHaveLength( 500 );
	} );

	it( 'logs only the error message by default (no component stack)', () => {
		render(
			<ErrorBoundary>
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		expect( console.error ).toHaveBeenCalledWith(
			'ErrorBoundary caught:',
			'Test error'
		);
	} );

	it( 'logs only the redacted message (never the component stack or the raw Error), whatever wppoSettings carries', () => {
		// Audit #1776: a stray `debug` key in the global must not change the
		// logged output — the dead gate was removed, so the redacted
		// message-only log is unconditional.
		const saved = global.wppoSettings;
		global.wppoSettings = { ...( saved || {} ), debug: true };
		try {
			render(
				<ErrorBoundary>
					<ProblemChild shouldThrow={ true } />
				</ErrorBoundary>
			);
			expect( console.error ).toHaveBeenCalledWith(
				'ErrorBoundary caught:',
				'Test error'
			);
			// The raw Error object and the errorInfo component stack (which
			// can carry props/state fragments) must never reach the console
			// via our own logging (React internals may log separately).
			const ownCalls = console.error.mock.calls.filter(
				( call ) => call[ 0 ] === 'ErrorBoundary caught:'
			);
			expect( ownCalls.length ).toBeGreaterThan( 0 );
			const leakedObjects = ownCalls
				.map( ( call ) => call.slice( 1 ) )
				.flat()
				.filter( ( arg ) => arg instanceof Error );
			expect( leakedObjects ).toEqual( [] );
			const leakedStacks = ownCalls
				.map( ( call ) => call.slice( 1 ) )
				.flat()
				.filter(
					( arg ) =>
						arg &&
						typeof arg === 'object' &&
						'componentStack' in arg
				);
			expect( leakedStacks ).toEqual( [] );
		} finally {
			global.wppoSettings = saved;
		}
	} );

	it( 'redacts secrets from a non-Error thrown value', () => {
		render(
			<ErrorBoundary>
				<ProblemChild
					shouldThrow={ true }
					error={
						'fetch failed for https://example.com/?_wpnonce=abc123def456'
					}
				/>
			</ErrorBoundary>
		);
		expect( console.error ).toHaveBeenCalledWith(
			'ErrorBoundary caught:',
			'fetch failed for https://example.com/?_wpnonce=[redacted]'
		);
	} );

	it( 'recovers when the ErrorBoundary is remounted with a new key', () => {
		const { rerender } = render(
			<ErrorBoundary key="1">
				<ProblemChild shouldThrow={ true } />
			</ErrorBoundary>
		);
		expect(
			screen.getByText( 'Something went wrong' )
		).toBeInTheDocument();

		rerender(
			<ErrorBoundary key="2">
				<div>Recovered content</div>
			</ErrorBoundary>
		);
		expect(
			screen.queryByText( 'Something went wrong' )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'Recovered content' ) ).toBeInTheDocument();
	} );
} );
