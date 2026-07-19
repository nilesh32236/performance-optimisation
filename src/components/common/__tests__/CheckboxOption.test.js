import { render, screen, fireEvent } from '@testing-library/react';
import { CheckboxOption } from '../CheckboxOption';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'CheckboxOption', () => {
	const defaultProps = {
		label: 'Enable Feature',
		checked: false,
		onChange: jest.fn(),
		name: 'feature_enabled',
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders label and checkbox correctly', () => {
		render( <CheckboxOption { ...defaultProps } /> );
		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		expect( checkbox ).toBeInTheDocument();
		expect( checkbox ).not.toBeChecked();
		expect( checkbox ).toHaveAttribute( 'name', 'feature_enabled' );
	} );

	it( 'calls onChange when checkbox is clicked', () => {
		render( <CheckboxOption { ...defaultProps } /> );
		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		fireEvent.click( checkbox );
		expect( defaultProps.onChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'renders description if provided and links via aria-describedby', () => {
		render(
			<CheckboxOption
				{ ...defaultProps }
				description="This is a feature description."
			/>
		);
		const descriptionEl = screen.getByText(
			'This is a feature description.'
		);
		expect( descriptionEl ).toBeInTheDocument();
		expect( descriptionEl ).toHaveClass( 'wppo-option-description' );

		const checkbox = screen.getByRole( 'checkbox', {
			name: /Enable Feature/i,
		} );
		const descId = descriptionEl.getAttribute( 'id' );
		expect( checkbox ).toHaveAttribute( 'aria-describedby', descId );
	} );

	it( 'renders nested content and textarea when checked', () => {
		const props = {
			...defaultProps,
			checked: true,
			textareaName: 'feature_details',
			textareaPlaceholder: 'Enter details',
			textareaValue: 'Some details',
			onTextareaChange: jest.fn(),
		};
		render(
			<CheckboxOption { ...props }>
				<div data-testid="nested-child">Child Element</div>
			</CheckboxOption>
		);

		const textarea = screen.getByRole( 'textbox', {
			name: /Enter details/i,
		} );
		expect( textarea ).toBeInTheDocument();
		expect( textarea ).toHaveValue( 'Some details' );
		expect( screen.getByTestId( 'nested-child' ) ).toBeInTheDocument();

		fireEvent.change( textarea, { target: { value: 'New details' } } );
		expect( props.onTextareaChange ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not render nested content or textarea when unchecked', () => {
		const props = {
			...defaultProps,
			checked: false,
			textareaName: 'feature_details',
			textareaPlaceholder: 'Enter details',
		};
		render(
			<CheckboxOption { ...props }>
				<div data-testid="nested-child">Child Element</div>
			</CheckboxOption>
		);

		expect( screen.queryByRole( 'textbox' ) ).not.toBeInTheDocument();
		expect(
			screen.queryByTestId( 'nested-child' )
		).not.toBeInTheDocument();
	} );
} );
