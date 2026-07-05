import { render, screen } from '@testing-library/react';
import FeatureCard from '../FeatureCard';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'FeatureCard', () => {
	it( 'renders children only if no other props are provided', () => {
		const { container } = render( <FeatureCard>Body Content</FeatureCard> );
		expect( screen.getByText( 'Body Content' ) ).toBeInTheDocument();
		expect(
			container.querySelector( '.wppo-feature-card__header' )
		).not.toBeInTheDocument();
		expect(
			container.querySelector( '.wppo-feature-card__footer' )
		).not.toBeInTheDocument();
	} );

	it( 'renders title and header when title is provided', () => {
		render( <FeatureCard title="My Title">Body Content</FeatureCard> );
		expect( screen.getByText( 'My Title' ) ).toBeInTheDocument();
		expect(
			document.querySelector( '.wppo-feature-card__header' )
		).toBeInTheDocument();
	} );

	it( 'renders icon with title', () => {
		render(
			<FeatureCard title="My Title" icon={ <span data-testid="icon" /> }>
				Body Content
			</FeatureCard>
		);
		expect( screen.getByTestId( 'icon' ) ).toBeInTheDocument();
	} );

	it( 'renders actions in header', () => {
		render(
			<FeatureCard actions={ <button>Action</button> }>
				Body Content
			</FeatureCard>
		);
		expect(
			screen.getByRole( 'button', { name: 'Action' } )
		).toBeInTheDocument();
		expect(
			document.querySelector( '.wppo-feature-card__header' )
		).toBeInTheDocument();
	} );

	it( 'renders footer', () => {
		render(
			<FeatureCard footer={ <div>Footer Content</div> }>
				Body Content
			</FeatureCard>
		);
		expect( screen.getByText( 'Footer Content' ) ).toBeInTheDocument();
		expect(
			document.querySelector( '.wppo-feature-card__footer' )
		).toBeInTheDocument();
	} );

	it( 'applies custom className', () => {
		const { container } = render(
			<FeatureCard className="custom-class">Body Content</FeatureCard>
		);
		expect( container.firstChild ).toHaveClass( 'custom-class' );
	} );
} );
