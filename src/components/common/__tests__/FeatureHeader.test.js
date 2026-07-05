import { render, screen } from '@testing-library/react';
import FeatureHeader from '../FeatureHeader';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'FeatureHeader', () => {
	it( 'renders title correctly', () => {
		render( <FeatureHeader title="My Title" /> );
		expect(
			screen.getByRole( 'heading', { name: 'My Title' } )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'My Description' )
		).not.toBeInTheDocument();
	} );

	it( 'renders description when provided', () => {
		render(
			<FeatureHeader title="My Title" description="My Description" />
		);
		expect( screen.getByText( 'My Description' ) ).toBeInTheDocument();
	} );

	it( 'renders status when provided', () => {
		render(
			<FeatureHeader
				title="My Title"
				status={ <span data-testid="status">Status</span> }
			/>
		);
		expect( screen.getByTestId( 'status' ) ).toBeInTheDocument();
	} );

	it( 'renders actions when provided', () => {
		render(
			<FeatureHeader
				title="My Title"
				actions={ <button>Action Button</button> }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: 'Action Button' } )
		).toBeInTheDocument();
	} );

	it( 'renders children in extra section', () => {
		const { container } = render(
			<FeatureHeader title="My Title">
				<div>Extra Content</div>
			</FeatureHeader>
		);
		expect( screen.getByText( 'Extra Content' ) ).toBeInTheDocument();
		expect(
			container.querySelector( '.wppo-feature-header__extra' )
		).toBeInTheDocument();
	} );

	it( 'does not render extra section if no children are provided', () => {
		const { container } = render( <FeatureHeader title="My Title" /> );
		expect(
			container.querySelector( '.wppo-feature-header__extra' )
		).not.toBeInTheDocument();
	} );
} );
