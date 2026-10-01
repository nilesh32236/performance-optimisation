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

	it( 'pins the subject on an explicit click and opens the panel', () => {
		const inspector = makeInspector();
		renderRow( inspector );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Explain Minify CSS' } )
		);

		expect( inspector.show ).toHaveBeenCalledWith( SUBJECT, 'pinned' );
		expect( inspector.setOpen ).toHaveBeenCalledWith( true );
	} );

	it( 'gives the explain affordance a name that includes the setting', () => {
		renderRow( makeInspector() );
		expect(
			screen.getByRole( 'button', { name: 'Explain Minify CSS' } )
		).toBeInTheDocument();
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
		render(
			<SettingRow subject={ SUBJECT }>
				<span>Standalone</span>
			</SettingRow>
		);
		expect( screen.getByText( 'Standalone' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Explain Minify CSS' } )
		).toBeInTheDocument();
	} );
} );
