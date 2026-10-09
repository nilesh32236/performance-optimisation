import { render, screen, fireEvent } from '@testing-library/react';
import SwitchField from '../SwitchField';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'SwitchField', () => {
	const defaultProps = {
		label: 'Toggle Feature',
		name: 'feature_toggle',
		checked: false,
		onChange: jest.fn(),
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders label and toggle correctly', () => {
		render( <SwitchField { ...defaultProps } /> );
		expect(
			screen.getAllByText( 'Toggle Feature' ).length
		).toBeGreaterThan( 0 );
		const checkbox = screen.getByRole( 'checkbox', {
			name: /Toggle Feature/i,
		} );
		expect( checkbox ).toBeInTheDocument();
		expect( checkbox ).not.toBeChecked();
	} );

	it( 'calls onChange with synthesized event when toggled', () => {
		render( <SwitchField { ...defaultProps } /> );
		const checkbox = screen.getByRole( 'checkbox', {
			name: /Toggle Feature/i,
		} );
		fireEvent.click( checkbox );
		expect( defaultProps.onChange ).toHaveBeenCalledTimes( 1 );
		expect( defaultProps.onChange ).toHaveBeenCalledWith( {
			target: {
				name: 'feature_toggle',
				type: 'checkbox',
				checked: true,
			},
		} );
	} );

	it( 'renders description if provided', () => {
		render(
			<SwitchField
				{ ...defaultProps }
				description="Feature description here."
			/>
		);
		expect(
			screen.getByText( 'Feature description here.' )
		).toBeInTheDocument();
	} );

	it( 'does not render label in wrapper if showLabel is false and no description is present', () => {
		const { container } = render(
			<SwitchField { ...defaultProps } showLabel={ false } />
		);
		// The visual label <strong> should not be present in the info wrapper.
		const wrapper = container.querySelector( '.wppo-switch-field__info' );
		expect( wrapper ).not.toBeInTheDocument();

		// The toggle control label should still be there for screen readers.
		const checkbox = screen.getByRole( 'checkbox', {
			name: /Toggle Feature/i,
		} );
		expect( checkbox ).toBeInTheDocument();
	} );
} );
