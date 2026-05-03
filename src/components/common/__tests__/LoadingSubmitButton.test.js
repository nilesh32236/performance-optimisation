// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
import LoadingSubmitButton from '../LoadingSubmitButton';

describe( 'LoadingSubmitButton', () => {
	it( 'renders with default props', () => {
		render( <LoadingSubmitButton>Submit</LoadingSubmitButton> );

		const button = screen.getByRole( 'button', { name: /submit/i } );
		expect( button ).toBeInTheDocument();
		expect( button ).toHaveAttribute( 'type', 'submit' );
		expect( button ).toHaveClass( 'submit-button' );
		expect( button ).not.toBeDisabled();
		expect( button ).not.toHaveAttribute( 'aria-busy' );
	} );

	it( 'renders with custom label prop', () => {
		render( <LoadingSubmitButton label="Save Changes" /> );

		const button = screen.getByRole( 'button', { name: /save changes/i } );
		expect( button ).toBeInTheDocument();
	} );

	it( 'renders as disabled when disabled prop is true', () => {
		render(
			<LoadingSubmitButton disabled>Disabled Action</LoadingSubmitButton>
		);

		const button = screen.getByRole( 'button', {
			name: /disabled action/i,
		} );
		expect( button ).toBeDisabled();
	} );

	it( 'renders loading state correctly', () => {
		render(
			<LoadingSubmitButton isLoading loadingLabel="Saving...">
				Submit
			</LoadingSubmitButton>
		);

		const button = screen.getByRole( 'button' );
		expect( button ).toBeDisabled();
		expect( button ).toHaveAttribute( 'aria-busy', 'true' );

		const statusText = screen.getByRole( 'status' );
		expect( statusText ).toHaveTextContent( 'Saving...' );

		// The spinner icon sets aria-hidden="true"
		const spinner = document.querySelector( '.fa-spinner' );
		expect( spinner ).toBeInTheDocument();
		expect( spinner ).toHaveAttribute( 'aria-hidden', 'true' );
	} );

	it( 'renders children as loading text if loadingLabel is not provided', () => {
		render(
			<LoadingSubmitButton isLoading>Processing...</LoadingSubmitButton>
		);

		const statusText = screen.getByRole( 'status' );
		expect( statusText ).toHaveTextContent( 'Processing...' );
	} );

	it( 'passes additional props to the button element', () => {
		const onClickMock = jest.fn();
		render(
			<LoadingSubmitButton
				onClick={ onClickMock }
				data-testid="custom-button"
			>
				Click Me
			</LoadingSubmitButton>
		);

		const button = screen.getByTestId( 'custom-button' );
		expect( button ).toBeInTheDocument();

		button.click();
		expect( onClickMock ).toHaveBeenCalledTimes( 1 );
	} );
} );
