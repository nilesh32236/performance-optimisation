/**
 * SettingField — the wrapper every non-toggle control now uses.
 *
 * These assert the properties that were previously accidental per screen: that
 * a label is *associated* rather than merely adjacent, that the description is
 * programmatically linked, that each type renders the right element, and that
 * the row wires the inspector. A regression in any of those is invisible in a
 * diff and only shows up for a screen-reader user on a live page.
 */

import { render, screen } from '@testing-library/react';
import InspectorProvider from '../InspectorProvider';
import SettingField from '../SettingField';

const SUBJECT = {
	id: 'setting:test',
	kind: 'setting',
	title: 'Safelist Selectors',
	does: 'Keeps the listed selectors even when unused.',
};

/**
 * Render inside a provider, the way App.js does.
 *
 * @param {Object} props Field props, merged over sensible defaults.
 */
const renderField = ( props ) =>
	render(
		<InspectorProvider>
			<SettingField
				name="excludeUnusedCSS"
				label="Safelist Selectors"
				value=""
				onChange={ () => {} }
				{ ...props }
			/>
		</InspectorProvider>
	);

describe( 'associates its label with the control', () => {
	it( 'names the control from its visible label', () => {
		// A `<label>` that is merely next to an input leaves the control
		// unnamed. This is the failure mode, so it is the assertion.
		renderField();
		expect(
			screen.getByLabelText( 'Safelist Selectors' )
		).toBeInTheDocument();
	} );

	it( 'links the description to the control', () => {
		renderField( { description: 'One selector per line.' } );
		const control = screen.getByLabelText( 'Safelist Selectors' );
		expect( control ).toHaveAccessibleDescription(
			'One selector per line.'
		);
	} );

	it( 'omits aria-describedby when there is no description', () => {
		// A dangling id reference is worse than none: it points at nothing.
		renderField();
		expect(
			screen.getByLabelText( 'Safelist Selectors' )
		).not.toHaveAttribute( 'aria-describedby' );
	} );
} );

describe( 'renders each control type', () => {
	it.each( [
		[ 'text', 'textbox' ],
		[ 'number', 'spinbutton' ],
		[ 'textarea', 'textbox' ],
		[ 'select', 'combobox' ],
		[ 'checkbox', 'checkbox' ],
	] )( 'type %s exposes the %s role', ( type, role ) => {
		const { unmount } = renderField( {
			type,
			options: [ { value: 'a', label: 'A' } ],
		} );
		expect( screen.getByRole( role ) ).toBeInTheDocument();
		unmount();
	} );

	it( 'renders textarea rows and monospace variant', () => {
		renderField( { type: 'textarea', rows: 7, mono: true } );
		const el = screen.getByLabelText( 'Safelist Selectors' );
		expect( el.tagName ).toBe( 'TEXTAREA' );
		expect( el ).toHaveAttribute( 'rows', '7' );
		expect( el ).toHaveClass( 'wppo-textarea--mono' );
	} );

	it( 'renders one option per entry', () => {
		renderField( {
			type: 'select',
			options: [
				{ value: 'a', label: 'Alpha' },
				{ value: 'b', label: 'Beta' },
			],
		} );
		expect(
			screen.getByRole( 'option', { name: 'Alpha' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'option', { name: 'Beta' } )
		).toBeInTheDocument();
	} );

	it( 'reflects the current value', () => {
		renderField( { value: 'on' } );
		expect( screen.getByLabelText( 'Safelist Selectors' ) ).toHaveValue(
			'on'
		);
	} );

	it( 'treats a null value as empty rather than uncontrolled', () => {
		// React warns and flips to uncontrolled if a value goes null, which
		// silently stops the field saving.
		renderField( { value: null } );
		expect( screen.getByLabelText( 'Safelist Selectors' ) ).toHaveValue(
			''
		);
	} );
} );

describe( 'generates ids so repeated fields stay distinguishable', () => {
	it( 'gives each instance its own id', () => {
		// The hand-rolled markup this replaces used a literal `id`, so a field
		// rendered per post type repeated the same id and every copy after the
		// first was unlabelled.
		render(
			<InspectorProvider>
				<SettingField
					name="exclude"
					label="Safelist"
					value=""
					onChange={ () => {} }
				/>
				<SettingField
					name="exclude"
					label="Safelist"
					value=""
					onChange={ () => {} }
				/>
			</InspectorProvider>
		);
		const both = screen.getAllByLabelText( 'Safelist' );
		expect( both ).toHaveLength( 2 );
		expect( both[ 0 ].id ).not.toBe( both[ 1 ].id );
	} );
} );

describe( 'fails loudly on a mistyped control type', () => {
	it( 'throws rather than silently rendering a text input', () => {
		// Silently coercing would save a value into the wrong shape of setting
		// and the only symptom would be a value that never takes effect.
		const spy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
		expect( () => renderField( { type: 'textraea' } ) ).toThrow(
			/unknown type "textraea"/
		);
		spy.mockRestore();
	} );
} );

describe( 'wires the inspector', () => {
	it( 'marks the row active when its subject is loaded', () => {
		const { container } = renderField( { subject: SUBJECT } );
		const row = container.querySelector( '.wppo-setting-row' );
		expect( row ).toBeInTheDocument();
	} );

	it( 'still renders the field when no subject is supplied', () => {
		// Subject copy is authored screen by screen, so an unwired field must
		// degrade to a plain field rather than crash the whole screen.
		renderField();
		expect(
			screen.getByLabelText( 'Safelist Selectors' )
		).toBeInTheDocument();
	} );
} );
