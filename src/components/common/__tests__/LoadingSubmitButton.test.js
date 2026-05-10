// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import LoadingSubmitButton from '../LoadingSubmitButton';

describe( 'LoadingSubmitButton', () => {
	it( 'renders correctly when not loading', () => {
		render( <LoadingSubmitButton isLoading={ false } label="Save" /> );
		const button = screen.getByRole( 'button' );
		expect( button ).toBeInTheDocument();
		expect( button ).toHaveTextContent( 'Save' );
		expect( button ).not.toBeDisabled();
		expect( button ).toHaveAttribute( 'aria-busy', 'false' );
	} );

	it( 'renders correctly when loading', () => {
		render(
			<LoadingSubmitButton
				isLoading={ true }
				label="Save"
				loadingLabel="Saving..."
			/>
		);
		const button = screen.getByRole( 'button' );
		expect( button ).toBeInTheDocument();
		expect( button ).toHaveTextContent( 'Saving...' );
		expect( button ).toBeDisabled();
		expect( button ).toHaveAttribute( 'aria-busy', 'true' );
		expect(
			screen.getByRole( 'status', { hidden: true } )
		).toBeInTheDocument(); // It has aria-live="polite"
	} );

	it( 'can be explicitly disabled even when not loading', () => {
		render(
			<LoadingSubmitButton
				isLoading={ false }
				disabled={ true }
				label="Save"
			/>
		);
		const button = screen.getByRole( 'button' );
		expect( button ).toBeDisabled();
	} );
} );
