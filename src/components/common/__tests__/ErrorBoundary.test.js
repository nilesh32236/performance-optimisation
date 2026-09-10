import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ErrorBoundary from '../ErrorBoundary';

const ProblemChild = ( { shouldThrow = false } ) => {
	if ( shouldThrow ) {
		throw new Error( 'Test error' );
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

	it( 'logs the full error and component stack when wppoSettings.debug is set', () => {
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
				expect.any( Error ),
				expect.any( Object )
			);
		} finally {
			global.wppoSettings = saved;
		}
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
