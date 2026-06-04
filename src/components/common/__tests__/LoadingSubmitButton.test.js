import { render, screen } from '@testing-library/react';
import LoadingSubmitButton from '../LoadingSubmitButton';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'LoadingSubmitButton', () => {
	it( 'renders correctly with default props', () => {
		render( <LoadingSubmitButton label="Submit" /> );
		const button = screen.getByRole( 'button', { name: /Submit/i } );
		expect( button ).toBeInTheDocument();
		expect( button ).not.toBeDisabled();
		expect( button ).toHaveClass( 'wppo-button' );
		expect( button ).toHaveClass( 'wppo-button--primary' );
		expect( button ).toHaveAttribute( 'type', 'submit' );
		expect( button ).not.toHaveAttribute( 'aria-busy', 'true' );
	} );

	it( 'renders correctly in loading state', () => {
		render(
			<LoadingSubmitButton
				isLoading={ true }
				label="Submit"
				loadingLabel="Saving..."
			/>
		);
		const button = screen.getByRole( 'button', { name: /Saving\.\.\./i } );
		expect( button ).toBeInTheDocument();
		expect( button ).toBeDisabled();
		expect( button ).toHaveAttribute( 'aria-busy', 'true' );
		// Icon should be present (hidden from ARIA)
		expect( button.querySelector( 'svg' ) ).toHaveClass( 'fa-spinner' );
	} );

	it( 'renders children if label is not provided', () => {
		render( <LoadingSubmitButton>Click Me</LoadingSubmitButton> );
		expect(
			screen.getByRole( 'button', { name: /Click Me/i } )
		).toBeInTheDocument();
	} );

	it( 'is disabled when disabled prop is true', () => {
		render( <LoadingSubmitButton disabled={ true } label="Submit" /> );
		const button = screen.getByRole( 'button', { name: /Submit/i } );
		expect( button ).toBeDisabled();
	} );

	it( 'applies custom className', () => {
		render(
			<LoadingSubmitButton className="custom-class" label="Submit" />
		);
		const button = screen.getByRole( 'button', { name: /Submit/i } );
		expect( button ).toHaveClass( 'custom-class' );
	} );
} );
