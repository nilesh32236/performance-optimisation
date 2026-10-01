/**
 * Tests for SettingRow.
 *
 * This is the one component every settings screen uses, so its wiring is the
 * contract: hover and focus *offer* a subject, an explicit click *pins* it, and
 * the row highlights when its subject is the one on screen.
 *
 * The inspector is mocked rather than rendered through the real provider, so a
 * failure here points at this component instead of the state machine — that
 * separation is the reason the reducer has its own test file.
 *
 * @package
 */

import { fireEvent, render, screen } from '@testing-library/react';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

import InspectorContext from '../../../lib/InspectorContext';
import SettingRow from '../SettingRow';

const SUBJECT = {
	id: 'setting:minifyCSS',
	title: 'Minify CSS',
	does: 'Removes whitespace from stylesheets.',
};

const makeInspector = ( overrides = {} ) => ( {
	subject: null,
	source: null,
	isOpen: true,
	setOpen: jest.fn(),
	show: jest.fn(),
	hide: jest.fn(),
	clear: jest.fn(),
	...overrides,
} );

const renderRow = ( inspector, props = {} ) =>
	render(
		<InspectorContext.Provider value={ inspector }>
			<SettingRow subject={ SUBJECT } { ...props }>
				<span>Minify CSS</span>
			</SettingRow>
		</InspectorContext.Provider>
	);

describe( 'SettingRow', () => {
	it( 'offers the subject on hover and releases it on leave', () => {
		const inspector = makeInspector();
		const { container } = renderRow( inspector );
		const row = container.querySelector( '.wppo-setting-row' );

		fireEvent.mouseEnter( row );
		expect( inspector.show ).toHaveBeenCalledWith( SUBJECT, 'hover' );

		fireEvent.mouseLeave( row );
		expect( inspector.hide ).toHaveBeenCalledWith( 'hover' );
	} );

	it( 'offers the subject on keyboard focus and releases on blur', () => {
		const inspector = makeInspector();
		const { container } = renderRow( inspector );
		const row = container.querySelector( '.wppo-setting-row' );

		fireEvent.focusIn( row );
		expect( inspector.show ).toHaveBeenCalledWith( SUBJECT, 'focus' );

		fireEvent.focusOut( row );
		expect( inspector.hide ).toHaveBeenCalledWith( 'focus' );
	} );

	it( 'pins the subject when the affordance is clicked', () => {
		const inspector = makeInspector();
		const { container } = renderRow( inspector );

		fireEvent.click(
			container.querySelector( '.wppo-setting-row__explain' )
		);

		expect( inspector.show ).toHaveBeenCalledWith( SUBJECT, 'pinned' );
		expect( inspector.setOpen ).toHaveBeenCalledWith( true );
	} );

	it( 'keeps the pin affordance out of the tab order and the a11y tree', () => {
		// Focus already loads the subject, so this is a mouse-only extra. An
		// accessible name here would duplicate the field's own name and add a
		// tab stop to every setting on the screen.
		const { container } = renderRow( makeInspector() );
		const pin = container.querySelector( '.wppo-setting-row__explain' );

		expect( pin ).toHaveAttribute( 'aria-hidden', 'true' );
		expect( pin ).toHaveAttribute( 'tabindex', '-1' );
	} );

	it( 'keeps the pin affordance out of the accessibility tree', () => {
		// Regression guard: an `aria-label` of "Explain Minify CSS" made every
		// substring query for "Minify CSS" ambiguous — the same duplicate name
		// a screen reader user would have heard, on every one of 246 settings.
		// The row is now the only thing exposed; the pin is mouse-only.
		renderRow( makeInspector() );
		expect( screen.queryByRole( 'button' ) ).toBeNull();
	} );

	it( 'marks the row active when its subject is the one on screen', () => {
		const inspector = makeInspector( {
			subject: SUBJECT,
			source: 'focus',
		} );
		const { container } = renderRow( inspector );
		expect( container.querySelector( '.wppo-setting-row' ) ).toHaveClass(
			'wppo-setting-row--active'
		);
	} );

	it( 'does not mark a row active for a different subject', () => {
		const inspector = makeInspector( {
			subject: { id: 'setting:other' },
			source: 'focus',
		} );
		const { container } = renderRow( inspector );
		expect(
			container.querySelector( '.wppo-setting-row' )
		).not.toHaveClass( 'wppo-setting-row--active' );
	} );

	it( 'renders a bare wrapper with no affordance when given no subject', () => {
		// A row with nothing to explain must still render, and must not offer a
		// button that would open an empty panel.
		const { container } = render(
			<InspectorContext.Provider value={ makeInspector() }>
				<SettingRow className="custom">
					<span>Plain</span>
				</SettingRow>
			</InspectorContext.Provider>
		);
		expect( screen.getByText( 'Plain' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).toBeNull();
		expect( container.querySelector( '.wppo-setting-row' ) ).toBeNull();
	} );

	it( 'works outside a provider without throwing', () => {
		// The panel is an enhancement; a settings row must never depend on it.
		const { container } = render(
			<SettingRow subject={ SUBJECT }>
				<span>Standalone</span>
			</SettingRow>
		);
		expect( screen.getByText( 'Standalone' ) ).toBeInTheDocument();
		expect(
			container.querySelector( '.wppo-setting-row__explain' )
		).not.toBeNull();
	} );
} );
