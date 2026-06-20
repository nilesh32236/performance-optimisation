import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-unresolved, import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import FeatureHeader from '../FeatureHeader';

describe( 'FeatureHeader Component', () => {
	it( 'renders title and description correctly', () => {
		render(
			<FeatureHeader
				title="Header Title"
				description="Header Description"
			/>
		);

		const title = screen.getByRole( 'heading', {
			level: 2,
			name: /Header Title/i,
		} );
		expect( title ).toBeInTheDocument();
		expect( screen.getByText( 'Header Description' ) ).toBeInTheDocument();
	} );

	it( 'renders the status component correctly', () => {
		const Status = <span data-testid="status-badge">Status</span>;
		render( <FeatureHeader title="Header Title" status={ Status } /> );

		const statusElement = screen.getByTestId( 'status-badge' );
		expect( statusElement ).toBeInTheDocument();
		expect( statusElement.parentElement ).toHaveClass(
			'wppo-feature-header__status'
		);
	} );

	it( 'renders the actions component correctly', () => {
		const Actions = <button>Header Action</button>;
		render( <FeatureHeader title="Header Title" actions={ Actions } /> );

		const actionElement = screen.getByRole( 'button', {
			name: /Header Action/i,
		} );
		expect( actionElement ).toBeInTheDocument();
		expect( actionElement.parentElement ).toHaveClass(
			'wppo-feature-header__actions'
		);
	} );

	it( 'renders the children correctly in extra section', () => {
		render(
			<FeatureHeader title="Header Title">
				<div data-testid="extra-content">Extra Content</div>
			</FeatureHeader>
		);

		const extraElement = screen.getByTestId( 'extra-content' );
		expect( extraElement ).toBeInTheDocument();
		expect( extraElement.parentElement ).toHaveClass(
			'wppo-feature-header__extra'
		);
	} );
} );
