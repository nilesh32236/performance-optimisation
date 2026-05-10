// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
import CheckboxOption from '../CheckboxOption';

describe( 'CheckboxOption', () => {
	it( 'renders correctly', () => {
		const onChange = jest.fn();
		render(
			<CheckboxOption
				label="Enable Feature"
				checked={ false }
				onChange={ onChange }
				name="feature"
			/>
		);
		const checkbox = screen.getByRole( 'checkbox' );
		expect( checkbox ).toBeInTheDocument();
		expect( checkbox ).not.toBeChecked();
		expect( screen.getByText( 'Enable Feature' ) ).toBeInTheDocument();
	} );

	it( 'handles onChange', () => {
		const onChange = jest.fn();
		render(
			<CheckboxOption
				label="Enable Feature"
				checked={ false }
				onChange={ onChange }
				name="feature"
			/>
		);
		const checkbox = screen.getByRole( 'checkbox' );
		fireEvent.click( checkbox );
		expect( onChange ).toHaveBeenCalled();
	} );

	it( 'renders description and sets aria-describedby', () => {
		const onChange = jest.fn();
		render(
			<CheckboxOption
				label="Enable Feature"
				checked={ false }
				onChange={ onChange }
				name="feature"
				description="This is a feature."
				id="my-checkbox"
			/>
		);
		const checkbox = screen.getByRole( 'checkbox' );
		const description = screen.getByText( 'This is a feature.' );
		expect( description ).toBeInTheDocument();
		expect( description ).toHaveAttribute( 'id', 'desc-my-checkbox' );
		expect( checkbox ).toHaveAttribute(
			'aria-describedby',
			'desc-my-checkbox'
		);
	} );

	it( 'renders nested content when checked', () => {
		const onChange = jest.fn();
		const onTextareaChange = jest.fn();
		render(
			<CheckboxOption
				label="Enable Feature"
				checked={ true }
				onChange={ onChange }
				name="feature"
				textareaName="feature_options"
				textareaPlaceholder="Enter options"
				textareaValue="option1"
				onTextareaChange={ onTextareaChange }
			>
				<div>Nested child</div>
			</CheckboxOption>
		);
		const textarea = screen.getByRole( 'textbox' );
		expect( textarea ).toBeInTheDocument();
		expect( textarea ).toHaveValue( 'option1' );
		expect( screen.getByText( 'Nested child' ) ).toBeInTheDocument();

		fireEvent.change( textarea, { target: { value: 'option2' } } );
		expect( onTextareaChange ).toHaveBeenCalled();
	} );

	it( 'does not render nested content when not checked', () => {
		const onChange = jest.fn();
		render(
			<CheckboxOption
				label="Enable Feature"
				checked={ false }
				onChange={ onChange }
				name="feature"
				textareaName="feature_options"
			>
				<div>Nested child</div>
			</CheckboxOption>
		);
		expect( screen.queryByRole( 'textbox' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Nested child' ) ).not.toBeInTheDocument();
	} );
} );
