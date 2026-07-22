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
		const { container } = render(
			<Tooltip content="Tooltip Content">
				<button>Hover Me</button>
			</Tooltip>
		);
		const tooltipContainer = container.querySelector(
			'.wppo-tooltip-container'
		);
		expect( tooltipContainer ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Hover Me/i } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Tooltip Content' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Tooltip Content' ) ).toHaveClass(
			'wppo-tooltip-content'
		);
	} );

	it( 'renders default icon if children are not provided', () => {
		const { container } = render( <Tooltip content="Info tooltip" /> );
		const tooltipContainer = container.querySelector(
			'.wppo-tooltip-container'
		);
		expect( tooltipContainer ).toBeInTheDocument();
		const icon = tooltipContainer.querySelector( '.wppo-tooltip-icon' );
		expect( icon ).toBeInTheDocument();
		expect( icon.tagName.toLowerCase() ).toBe( 'svg' );
		expect( screen.getByText( 'Info tooltip' ) ).toBeInTheDocument();
	} );

	it( 'toggles visible class on hover', () => {
		const { container } = render(
			<Tooltip content="Hover content">Hover Me</Tooltip>
		);
		const tooltipContainer = container.querySelector(
			'.wppo-tooltip-container'
		);

		expect( tooltipContainer ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);

		fireEvent.mouseEnter( tooltipContainer );
		expect( tooltipContainer ).toHaveClass(
			'wppo-tooltip-container--visible'
		);

		fireEvent.mouseLeave( tooltipContainer );
		expect( tooltipContainer ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);
	} );

	it( 'toggles visible class on focus and blur', () => {
		const { container } = render(
			<Tooltip content="Focus content">Focus Me</Tooltip>
		);
		const tooltipContainer = container.querySelector(
			'.wppo-tooltip-container'
		);

		expect( tooltipContainer ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);

		fireEvent.focus( tooltipContainer );
		expect( tooltipContainer ).toHaveClass(
			'wppo-tooltip-container--visible'
		);

		fireEvent.blur( tooltipContainer );
		expect( tooltipContainer ).not.toHaveClass(
			'wppo-tooltip-container--visible'
		);
	} );
} );
