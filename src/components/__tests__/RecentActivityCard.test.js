import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import RecentActivityCard from '../RecentActivityCard';

describe( 'RecentActivityCard Component', () => {
	const onNavigate = jest.fn();

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the card title', () => {
		render(
			<RecentActivityCard activities={ [] } onNavigate={ onNavigate } />
		);
		expect(
			screen.getByText( /Recent Optimisation Activity/i )
		).toBeInTheDocument();
	} );

	it( 'shows empty state when activities array is empty', () => {
		render(
			<RecentActivityCard activities={ [] } onNavigate={ onNavigate } />
		);
		expect(
			screen.getByText( /No optimisation activity recorded yet/i )
		).toBeInTheDocument();
	} );

	it( 'renders up to 5 activity items', () => {
		const activities = [
			{ id: 1, activity: 'Cache cleared' },
			{ id: 2, activity: 'Images optimized' },
			{ id: 3, activity: 'Database cleaned' },
			{ id: 4, activity: 'Settings updated' },
			{ id: 5, activity: 'PageSpeed scan completed' },
		];

		render(
			<RecentActivityCard
				activities={ activities }
				onNavigate={ onNavigate }
			/>
		);
		expect( screen.getByText( 'Cache cleared' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'PageSpeed scan completed' )
		).toBeInTheDocument();
	} );

	it( 'renders only 5 items when more than 5 are provided', () => {
		const activities = Array.from( { length: 10 }, ( _, i ) => ( {
			id: i + 1,
			activity: `Activity ${ i + 1 }`,
		} ) );

		render(
			<RecentActivityCard
				activities={ activities }
				onNavigate={ onNavigate }
			/>
		);
		expect( screen.getByText( 'Activity 1' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Activity 5' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Activity 6' ) ).not.toBeInTheDocument();
	} );

	it( 'renders View Full Log button', () => {
		render(
			<RecentActivityCard activities={ [] } onNavigate={ onNavigate } />
		);
		expect(
			screen.getByRole( 'button', { name: /View Full/i } )
		).toBeInTheDocument();
	} );

	it( 'calls onNavigate with tools when View Full Log is clicked', () => {
		render(
			<RecentActivityCard activities={ [] } onNavigate={ onNavigate } />
		);
		fireEvent.click( screen.getByRole( 'button', { name: /View Full/i } ) );
		expect( onNavigate ).toHaveBeenCalledWith( 'tools' );
	} );

	it( 'renders inside FeatureCard wrapper', () => {
		const { container } = render(
			<RecentActivityCard activities={ [] } onNavigate={ onNavigate } />
		);
		expect(
			container.querySelector( '.wppo-feature-card' )
		).toBeInTheDocument();
	} );
} );
