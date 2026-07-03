import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
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

	it( 'uses correct default labels when cancelLabel and confirmLabel are not provided', () => {
		// Temporary override of wppoSettings for translations fallback test
		const originalWppoSettings = global.wppoSettings;
		global.wppoSettings = undefined;
		render(
			<ConfirmDialog
				isOpen={ true }
				onConfirm={ jest.fn() }
				onCancel={ jest.fn() }
				title="Confirm Action"
				message="Are you sure?"
			/>
		);

		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Confirm' } )
		).toBeInTheDocument();

		global.wppoSettings = originalWppoSettings;
	} );

	it( 'handles focus trapping correctly on mount/unmount', async () => {
		const button = document.createElement( 'button' );
		button.id = 'external-button';
		document.body.appendChild( button );
		button.focus();

		expect( document.activeElement ).toBe( button );

		const { unmount } = render(
			<ConfirmDialog
				isOpen={ true }
				onConfirm={ jest.fn() }
				onCancel={ jest.fn() }
				title="Confirm Action"
				message="Are you sure?"
			/>
		);

		await waitFor( () => {
			expect( document.activeElement ).not.toBe( button );
		} );

		unmount();
		document.body.removeChild( button );
	} );

	it( 'handles tabbing correctly to keep focus inside dialog', async () => {
		const onCancel = jest.fn();
		render(
			<ConfirmDialog
				isOpen={ true }
				onConfirm={ jest.fn() }
				onCancel={ onCancel }
				title="Confirm Action"
				message="Are you sure?"
			/>
		);

		const dialog = screen.getByRole( 'dialog' );
		const focusableElements = dialog.querySelectorAll(
			'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
		);
		const first = focusableElements[ 0 ];
		const last = focusableElements[ focusableElements.length - 1 ];

		// Spies for focus
		const firstFocusSpy = jest.spyOn( first, 'focus' );
		const lastFocusSpy = jest.spyOn( last, 'focus' );

		// Mock activeElement on document
		Object.defineProperty( document, 'activeElement', {
			value: last,
			writable: true,
		} );

		// Simulate Tab keypress on dialog when activeElement is last
		fireEvent.keyDown( dialog, { key: 'Tab', code: 'Tab' } );

		expect( firstFocusSpy ).toHaveBeenCalled();

		// Simulate Shift+Tab on dialog when activeElement is first
		Object.defineProperty( document, 'activeElement', {
			value: first,
			writable: true,
		} );
		fireEvent.keyDown( dialog, {
			key: 'Tab',
			code: 'Tab',
			shiftKey: true,
		} );

		expect( lastFocusSpy ).toHaveBeenCalled();
	} );

	it( 'handles tab key logic with no shift correctly', async () => {
		const onCancel = jest.fn();
		render(
			<ConfirmDialog
				isOpen={ true }
				onConfirm={ jest.fn() }
				onCancel={ onCancel }
				title="Confirm Action"
				message="Are you sure?"
			/>
		);

		const dialog = screen.getByRole( 'dialog' );

		fireEvent.keyDown( dialog, {
			key: 'Tab',
			code: 'Tab',
			shiftKey: false,
		} );
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
