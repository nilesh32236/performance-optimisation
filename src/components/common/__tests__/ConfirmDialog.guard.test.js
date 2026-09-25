import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ConfirmDialog from '../ConfirmDialog';

/**
 * Phase C UX regressions: the unsaved-change guard keeps its
 * cancel/discard safety contract — Escape and Cancel never discard,
 * focus stays trapped, and a busy confirm blocks dismissal.
 */
describe( 'ConfirmDialog guard (Phase C)', () => {
	const guardProps = {
		isOpen: true,
		onConfirm: jest.fn(),
		onCancel: jest.fn(),
		title: 'Discard unsaved changes?',
		message:
			'You have unsaved changes. Switching tabs now will lose your edits. Choose Cancel to keep editing, or Discard to leave without saving.',
		confirmLabel: 'Discard',
		cancelLabel: 'Cancel',
		variant: 'warning',
	};

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'exposes the dialog role with labelled title and message', () => {
		render( <ConfirmDialog { ...guardProps } /> );
		const dialog = screen.getByRole( 'dialog' );
		expect( dialog ).toHaveAttribute( 'aria-modal', 'true' );
		expect( dialog ).toHaveAttribute( 'aria-labelledby' );
		expect( dialog ).toHaveAttribute( 'aria-describedby' );
		expect(
			screen.getByRole( 'button', { name: 'Discard' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeInTheDocument();
	} );

	it( 'Escape cancels without discarding', () => {
		render( <ConfirmDialog { ...guardProps } /> );
		fireEvent.keyDown( document, { key: 'Escape', code: 'Escape' } );
		expect( guardProps.onCancel ).toHaveBeenCalledTimes( 1 );
		expect( guardProps.onConfirm ).not.toHaveBeenCalled();
	} );

	it( 'Cancel keeps edits while Discard leaves', () => {
		render( <ConfirmDialog { ...guardProps } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( guardProps.onCancel ).toHaveBeenCalledTimes( 1 );
		expect( guardProps.onConfirm ).not.toHaveBeenCalled();

		jest.clearAllMocks();
		fireEvent.click( screen.getByRole( 'button', { name: 'Discard' } ) );
		expect( guardProps.onConfirm ).toHaveBeenCalledTimes( 1 );
		expect( guardProps.onCancel ).not.toHaveBeenCalled();
	} );

	it( 'traps Shift+Tab from the first control to the last', () => {
		render( <ConfirmDialog { ...guardProps } /> );
		const dialog = screen.getByRole( 'dialog' );
		const buttons = dialog.querySelectorAll( 'button:not([disabled])' );
		const first = buttons[ 0 ];
		const last = buttons[ buttons.length - 1 ];

		first.focus();
		expect( document.activeElement ).toBe( first );
		fireEvent.keyDown( dialog, {
			key: 'Tab',
			code: 'Tab',
			shiftKey: true,
		} );
		expect( document.activeElement ).toBe( last );
	} );

	it( 'blocks Escape and both buttons while busy', () => {
		render( <ConfirmDialog { ...guardProps } isBusy={ true } /> );
		fireEvent.keyDown( document, { key: 'Escape', code: 'Escape' } );
		expect( guardProps.onCancel ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'button', { name: 'Discard' } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Cancel' } )
		).toBeDisabled();
	} );
} );
