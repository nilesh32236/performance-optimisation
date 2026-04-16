import { render, screen, fireEvent } from '@testing-library/react';
import ConfirmDialog from '../ConfirmDialog';

describe( 'ConfirmDialog', () => {
	const defaultProps = {
		isOpen: true,
		onConfirm: jest.fn(),
		onCancel: jest.fn(),
		title: 'Test Title',
		message: 'Test Message',
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders correctly when isOpen is true', () => {
		render( <ConfirmDialog { ...defaultProps } /> );

		expect( screen.getByRole( 'dialog' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Test Title' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Test Message' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Confirm' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeInTheDocument();
	} );

	it( 'does not render when isOpen is false', () => {
		render( <ConfirmDialog { ...defaultProps } isOpen={ false } /> );
		expect( screen.queryByRole( 'dialog' ) ).not.toBeInTheDocument();
	} );

	it( 'calls onConfirm when confirm button is clicked', () => {
		render( <ConfirmDialog { ...defaultProps } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Confirm' } ) );
		expect( defaultProps.onConfirm ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'calls onCancel when cancel button is clicked', () => {
		render( <ConfirmDialog { ...defaultProps } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( defaultProps.onCancel ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'calls onCancel when overlay is clicked', () => {
		render( <ConfirmDialog { ...defaultProps } /> );
		// The overlay is the div with role="presentation"
		fireEvent.click( screen.getByRole( 'presentation' ) );
		expect( defaultProps.onCancel ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'calls onCancel when Escape key is pressed', () => {
		render( <ConfirmDialog { ...defaultProps } /> );
		fireEvent.keyDown( document, { key: 'Escape', code: 'Escape' } );
		expect( defaultProps.onCancel ).toHaveBeenCalledTimes( 1 );
	} );
} );
