/**
 * Tests for FeatureCard and FeatureHeader components.
 */

import { render, screen } from '@testing-library/react';
import FeatureCard from '../FeatureCard';
import FeatureHeader from '../FeatureHeader';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'FeatureCard', () => {
	it( 'renders children in the body', () => {
		render( <FeatureCard>Body content</FeatureCard> );
		expect( screen.getByText( 'Body content' ) ).toHaveClass(
			'wppo-feature-card__body'
		);
	} );

	it( 'renders a title and icon in the header', () => {
		const { container } = render(
			<FeatureCard title="Minify" icon="★">
				Content
			</FeatureCard>
		);
		expect(
			container.querySelector( '.wppo-feature-card__header' )
		).toBeInTheDocument();
		expect( screen.getByText( /Minify/ ) ).toBeInTheDocument();
		expect( screen.getByText( /★/ ) ).toBeInTheDocument();
	} );

	it( 'renders header actions and footer', () => {
		render(
			<FeatureCard
				actions={ <button>Action</button> }
				footer="Footer text"
			>
				Content
			</FeatureCard>
		);
		expect(
			screen
				.getByText( 'Action' )
				.closest( '.wppo-feature-card__header-actions' )
		).toBeInTheDocument();
		expect(
			screen
				.getByText( 'Footer text' )
				.closest( '.wppo-feature-card__footer' )
		).toBeInTheDocument();
	} );

	it( 'does not render a header when title and actions are absent', () => {
		const { container } = render( <FeatureCard>Content</FeatureCard> );
		expect(
			container.querySelector( '.wppo-feature-card__header' )
		).toBeNull();
	} );

	it( 'appends an extra className', () => {
		const { container } = render(
			<FeatureCard className="custom-class">Content</FeatureCard>
		);
		expect( container.querySelector( '.wppo-feature-card' ) ).toHaveClass(
			'custom-class'
		);
	} );
} );

describe( 'FeatureHeader', () => {
	it( 'renders title and description', () => {
		render(
			<FeatureHeader title="Dashboard" description="Overview text" />
		);
		expect( screen.getByText( 'Dashboard' ).tagName.toLowerCase() ).toBe(
			'h2'
		);
		expect( screen.getByText( 'Overview text' ) ).toBeInTheDocument();
	} );

	it( 'renders status and actions', () => {
		render(
			<FeatureHeader
				status={ <span>Active</span> }
				actions={ <button>Save</button> }
			/>
		);
		expect(
			screen
				.getByText( 'Active' )
				.closest( '.wppo-feature-header__status' )
		).toBeInTheDocument();
		expect(
			screen
				.getByText( 'Save' )
				.closest( '.wppo-feature-header__actions' )
		).toBeInTheDocument();
	} );

	it( 'renders extra children', () => {
		render( <FeatureHeader>Extra content</FeatureHeader> );
		expect(
			screen
				.getByText( 'Extra content' )
				.closest( '.wppo-feature-header__extra' )
		).toBeInTheDocument();
	} );

	it( 'omits the actions container when actions are absent', () => {
		const { container } = render( <FeatureHeader title="Title" /> );
		expect(
			container.querySelector( '.wppo-feature-header__actions' )
		).toBeNull();
	} );
} );
