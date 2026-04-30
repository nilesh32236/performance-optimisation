// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import CheckboxOption from '../CheckboxOption';

describe( 'CheckboxOption Component', () => {
	const defaultProps = {
		label: 'Enable Feature',
		checked: false,
		onChange: jest.fn(),
		name: 'feature_toggle',
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders correctly with required props', () => {
		render( <CheckboxOption { ...defaultProps } /> );

		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		expect( checkbox ).toBeInTheDocument();
		expect( checkbox ).not.toBeChecked();

		const container = checkbox.closest( '.checkbox-option' );
		expect( container ).not.toHaveClass( 'is-checked' );
	} );

	it( 'calls onChange when toggled', () => {
		render( <CheckboxOption { ...defaultProps } /> );

		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		fireEvent.click( checkbox );

		expect( defaultProps.onChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'applies is-checked class and renders nested content when checked', () => {
		render(
			<CheckboxOption { ...defaultProps } checked={ true }>
				<div data-testid="nested-child">Nested Setting</div>
			</CheckboxOption>
		);

		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		expect( checkbox ).toBeChecked();

		const container = checkbox.closest( '.checkbox-option' );
		expect( container ).toHaveClass( 'is-checked' );

		const nestedContent = screen.getByTestId( 'nested-child' );
		expect( nestedContent ).toBeInTheDocument();
	} );

	it( 'does not render nested content when unchecked', () => {
		render(
			<CheckboxOption { ...defaultProps } checked={ false }>
				<div data-testid="nested-child">Nested Setting</div>
			</CheckboxOption>
		);

		expect(
			screen.queryByTestId( 'nested-child' )
		).not.toBeInTheDocument();
	} );

	it( 'renders description and associates it with aria-describedby', () => {
		const description = 'This feature does something cool.';
		render(
			<CheckboxOption { ...defaultProps } description={ description } />
		);

		const descElement = screen.getByText( description );
		expect( descElement ).toBeInTheDocument();
		expect( descElement ).toHaveClass( 'option-description' );

		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		expect( checkbox ).toHaveAttribute(
			'aria-describedby',
			descElement.id
		);
	} );

	it( 'renders textarea when checked and textarea props are provided', () => {
		const textareaProps = {
			...defaultProps,
			checked: true,
			textareaName: 'feature_config',
			textareaPlaceholder: 'Enter config here',
			textareaValue: 'Current config',
			onTextareaChange: jest.fn(),
		};

		render( <CheckboxOption { ...textareaProps } /> );

		const textarea = screen.getByPlaceholderText( 'Enter config here' );
		expect( textarea ).toBeInTheDocument();
		expect( textarea ).toHaveValue( 'Current config' );
		expect( textarea ).toHaveAttribute( 'name', 'feature_config' );

		fireEvent.change( textarea, { target: { value: 'New config' } } );
		expect( textareaProps.onTextareaChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'uses provided id instead of generating one', () => {
		render(
			<CheckboxOption
				{ ...defaultProps }
				id="custom-id"
				description="Desc"
			/>
		);

		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		expect( checkbox ).toHaveAttribute( 'id', 'custom-id' );

		const descElement = screen.getByText( 'Desc' );
		expect( descElement ).toHaveAttribute( 'id', 'desc-custom-id' );
		expect( checkbox ).toHaveAttribute(
			'aria-describedby',
			'desc-custom-id'
		);
	} );
} );
