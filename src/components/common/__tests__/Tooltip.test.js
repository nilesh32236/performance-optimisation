import { render, screen, fireEvent } from '@testing-library/react';
import Tooltip from '../Tooltip';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'Tooltip', () => {
	it( 'renders children without tooltip if content is empty', () => {
		render(
			<Tooltip content="">
				<button>Hover Me</button>
			</Tooltip>
		);
		expect(
			screen.getByRole( 'button', { name: /Hover Me/i } )
		).toBeInTheDocument();
		expect(
			screen
				.queryByText( 'Hover Me' )
				?.closest( '.wppo-tooltip-container' )
		).not.toBeInTheDocument();
	} );

	it( 'renders tooltip content and children', () => {
		render(
			<Tooltip content="Tooltip Content">
				<button>Hover Me</button>
			</Tooltip>
		);
		const container = document.querySelector( '.wppo-tooltip-container' );
		expect( container ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Hover Me/i } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Tooltip Content' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Tooltip Content' ) ).toHaveClass(
			'wppo-tooltip-content'
		);
	} );

	it( 'renders default icon if children are not provided', () => {
		render( <Tooltip content="Info tooltip" /> );
		const container = document.querySelector( '.wppo-tooltip-container' );
		expect( container ).toBeInTheDocument();
		const icon = container.querySelector( '.wppo-tooltip-icon' );
		expect( icon ).toBeInTheDocument();
		expect( icon.tagName.toLowerCase() ).toBe( 'svg' );
		expect( screen.getByText( 'Info tooltip' ) ).toBeInTheDocument();
	} );

	it( 'toggles visible class on hover', () => {
		render( <Tooltip content="Hover content">Hover Me</Tooltip> );
		const container = document.querySelector( '.wppo-tooltip-container' );

		expect( container ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);

		fireEvent.mouseEnter( container );
		expect( container ).toHaveClass( 'wppo-tooltip-container--visible' );

		fireEvent.mouseLeave( container );
		expect( container ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);
	} );

	it( 'toggles visible class on focus and blur', () => {
		render( <Tooltip content="Focus content">Focus Me</Tooltip> );
		const container = document.querySelector( '.wppo-tooltip-container' );

		expect( container ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);

		fireEvent.focus( container );
		expect( container ).toHaveClass( 'wppo-tooltip-container--visible' );

		fireEvent.blur( container );
		expect( container ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);
	} );
} );
