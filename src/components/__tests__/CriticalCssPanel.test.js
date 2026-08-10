import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';

jest.mock( '@fortawesome/react-fontawesome', () => ( {
	FontAwesomeIcon: ( { icon } ) => (
		<span data-icon={ icon?.iconName || 'icon' } />
	),
} ) );

jest.mock( '@fortawesome/free-solid-svg-icons', () => ( {
	faCheckCircle: { iconName: 'check-circle' },
	faExclamationTriangle: { iconName: 'exclamation-triangle' },
	faClock: { iconName: 'clock' },
	faTimesCircle: { iconName: 'times-circle' },
} ) );

import CriticalCssPanel from '../CriticalCssPanel';

describe( 'CriticalCssPanel', () => {
	it( 'renders the empty state when no templates exist', () => {
		render( <CriticalCssPanel status={ {} } onRegenerate={ jest.fn() } /> );

		expect(
			screen.getByText(
				'No templates found. Save settings and regenerate.'
			)
		).toBeInTheDocument();
		expect( screen.getByRole( 'button' ) ).toBeInTheDocument();
	} );

	it( 'renders a ready template with its label', () => {
		render(
			<CriticalCssPanel
				status={ {
					abcdef1234567890: { status: 'ready', label: 'Single' },
				} }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Single' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Generated' ) ).toBeInTheDocument();
	} );

	it( 'falls back to a truncated hash label when no label is present', () => {
		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'pending' } }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'abcdef12…' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Pending' ) ).toBeInTheDocument();
	} );

	it( 'renders unknown statuses with the warning badge', () => {
		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'mystery' } }
				onRegenerate={ jest.fn() }
			/>
		);

		expect( screen.getByText( 'Not Generated' ) ).toBeInTheDocument();
	} );

	it( 'calls onRegenerate when the button is clicked', async () => {
		const onRegenerate = jest.fn().mockResolvedValue( undefined );
		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'ready' } }
				onRegenerate={ onRegenerate }
			/>
		);

		fireEvent.click( screen.getByRole( 'button' ) );

		await waitFor( () =>
			expect( onRegenerate ).toHaveBeenCalledTimes( 1 )
		);
	} );

	it( 'does not throw when onRegenerate rejects', async () => {
		const onRegenerate = jest.fn().mockRejectedValue( new Error( 'boom' ) );
		const errorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );

		render(
			<CriticalCssPanel
				status={ { abcdef1234567890: 'ready' } }
				onRegenerate={ onRegenerate }
			/>
		);

		fireEvent.click( screen.getByRole( 'button' ) );

		await waitFor( () =>
			expect( onRegenerate ).toHaveBeenCalledTimes( 1 )
		);

		errorSpy.mockRestore();
	} );
} );
