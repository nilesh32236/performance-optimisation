import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-unresolved, import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import FeatureCard from '../FeatureCard';

describe( 'FeatureCard Component', () => {
	it( 'renders the children content correctly', () => {
		render(
			<FeatureCard>
				<p>Card Body Content</p>
			</FeatureCard>
		);

		expect( screen.getByText( 'Card Body Content' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Card Body Content' ).parentElement
		).toHaveClass( 'wppo-feature-card__body' );
	} );

	it( 'renders the title and icon correctly when provided', () => {
		const TestIcon = <span data-testid="test-icon">Icon</span>;
		render(
			<FeatureCard title="Card Title" icon={ TestIcon }>
				<p>Body</p>
			</FeatureCard>
		);

		const heading = screen.getByRole( 'heading', {
			level: 3,
			name: /Card Title/i,
		} );
		expect( heading ).toBeInTheDocument();
		expect( screen.getByTestId( 'test-icon' ) ).toBeInTheDocument();
	} );

	it( 'renders the actions in the header correctly', () => {
		const Actions = <button>Header Action</button>;
		render(
			<FeatureCard actions={ Actions }>
				<p>Body</p>
			</FeatureCard>
		);

		const actionBtn = screen.getByRole( 'button', {
			name: /Header Action/i,
		} );
		expect( actionBtn ).toBeInTheDocument();
		expect( actionBtn.parentElement ).toHaveClass(
			'wppo-feature-card__header-actions'
		);
	} );

	it( 'renders the footer content correctly', () => {
		const Footer = <button>Footer Button</button>;
		render(
			<FeatureCard footer={ Footer }>
				<p>Body</p>
			</FeatureCard>
		);

		const footerBtn = screen.getByRole( 'button', {
			name: /Footer Button/i,
		} );
		expect( footerBtn ).toBeInTheDocument();
		expect( footerBtn.parentElement ).toHaveClass(
			'wppo-feature-card__footer'
		);
	} );

	it( 'appends additional className correctly', () => {
		const { container } = render(
			<FeatureCard className="custom-class-name">
				<p>Body</p>
			</FeatureCard>
		);

		expect( container.firstChild ).toHaveClass( 'wppo-feature-card' );
		expect( container.firstChild ).toHaveClass( 'custom-class-name' );
	} );
} );
