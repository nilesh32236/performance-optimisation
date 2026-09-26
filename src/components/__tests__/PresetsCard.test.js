import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import PresetsCard from '../file-optimization/PresetsCard';

describe( 'PresetsCard', () => {
	beforeEach( () => {
		global.wppoSettings = { translations: {} };
		jest.clearAllMocks();
	} );

	const baseProps = {
		presetNotice: null,
		onDismissPreset: jest.fn(),
		onSafe: jest.fn(),
		onAggressive: jest.fn(),
		onConfirmAggressive: jest.fn(),
		onCancelAggressive: jest.fn(),
		onRevert: jest.fn(),
		isApplyingPreset: false,
		isRestoring: false,
		isSaving: false,
		optimizerDisabled: false,
		showAggressiveConfirm: false,
		isAggressiveActive: false,
		isSafeActive: false,
	};

	it( 'renders title and description', () => {
		render( <PresetsCard { ...baseProps } /> );
		expect(
			screen.getByText( 'Optimisation Presets' )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Safe enables minify \+ defer \+ delay/ )
		).toBeInTheDocument();
	} );

	it( 'forwards safe, aggressive and revert clicks', () => {
		const props = {
			...baseProps,
			onSafe: jest.fn(),
			onAggressive: jest.fn(),
			onRevert: jest.fn(),
		};
		render( <PresetsCard { ...props } /> );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Apply Safe Preset/i } )
		);
		expect( props.onSafe ).toHaveBeenCalledTimes( 1 );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Enable Aggressive Mode/i } )
		);
		expect( props.onAggressive ).toHaveBeenCalledTimes( 1 );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Revert to Previous/i } )
		);
		expect( props.onRevert ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'disables safe and aggressive buttons while applying, saving or optimizer-disabled', () => {
		const { rerender } = render(
			<PresetsCard { ...baseProps } isApplyingPreset={ true } />
		);
		expect(
			screen.getByRole( 'button', { name: /Applying/i } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: /Enable Aggressive Mode/i } )
		).toBeDisabled();

		rerender( <PresetsCard { ...baseProps } isSaving={ true } /> );
		expect(
			screen.getByRole( 'button', { name: /Apply Safe Preset/i } )
		).toBeDisabled();

		rerender( <PresetsCard { ...baseProps } optimizerDisabled={ true } /> );
		expect(
			screen.getByRole( 'button', { name: /Apply Safe Preset/i } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: /Enable Aggressive Mode/i } )
		).toBeDisabled();
	} );

	it( 'disables revert while restoring', () => {
		render( <PresetsCard { ...baseProps } isRestoring={ true } /> );
		expect(
			screen.getByRole( 'button', { name: /Reverting/i } )
		).toBeDisabled();
	} );

	it( 'shows and dismisses the preset notice', () => {
		const onDismissPreset = jest.fn();
		render(
			<PresetsCard
				{ ...baseProps }
				presetNotice={ { type: 'success', message: 'Preset applied.' } }
				onDismissPreset={ onDismissPreset }
			/>
		);
		expect( screen.getByText( 'Preset applied.' ) ).toBeInTheDocument();
		const dismissButton = screen.getByRole( 'button', {
			name: /dismiss/i,
		} );
		fireEvent.click( dismissButton );
		expect( onDismissPreset ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'hides the preset notice when null', () => {
		render( <PresetsCard { ...baseProps } presetNotice={ null } /> );
		expect(
			screen.queryByText( 'Preset applied.' )
		).not.toBeInTheDocument();
	} );

	it( 'wires the aggressive confirm dialog open/confirm/cancel', () => {
		const props = {
			...baseProps,
			showAggressiveConfirm: true,
			onConfirmAggressive: jest.fn(),
			onCancelAggressive: jest.fn(),
		};
		render( <PresetsCard { ...props } /> );
		expect(
			screen.getByText( 'Enable Aggressive Mode?' )
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: /Enable Anyway/i } )
		);
		expect( props.onConfirmAggressive ).toHaveBeenCalledTimes( 1 );
		fireEvent.click( screen.getByRole( 'button', { name: /Cancel/i } ) );
		expect( props.onCancelAggressive ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'hides the confirm dialog when closed', () => {
		render(
			<PresetsCard { ...baseProps } showAggressiveConfirm={ false } />
		);
		expect(
			screen.queryByText( 'Enable Aggressive Mode?' )
		).not.toBeInTheDocument();
	} );

	it( 'shows aggressive and safe status banners conditionally', () => {
		const { rerender } = render(
			<PresetsCard
				{ ...baseProps }
				isAggressiveActive={ true }
				isSafeActive={ false }
			/>
		);
		expect(
			screen.getByText( /Aggressive mode is on/ )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /Safe preset is active/ )
		).not.toBeInTheDocument();

		rerender(
			<PresetsCard
				{ ...baseProps }
				isAggressiveActive={ false }
				isSafeActive={ true }
			/>
		);
		expect(
			screen.queryByText( /Aggressive mode is on/ )
		).not.toBeInTheDocument();
		expect(
			screen.getByText( /Safe preset is active/ )
		).toBeInTheDocument();
	} );
} );
