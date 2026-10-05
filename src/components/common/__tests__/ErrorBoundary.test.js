import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
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
