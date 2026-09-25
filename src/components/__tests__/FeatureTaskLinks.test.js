import { render, screen } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import FeatureTaskLinks, { FEATURE_TASK_LINKS } from '../FeatureTaskLinks';

describe( 'FeatureTaskLinks', () => {
	it( 'maps common tasks to existing feature tabs', () => {
		const onNavigate = jest.fn();
		render( <FeatureTaskLinks onNavigate={ onNavigate } /> );

		expect(
			screen.getByRole( 'navigation', { name: 'Feature shortcuts' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Find a setting' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: /Enable caching.*Dashboard.*Page Cache/,
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: /Image optimization.*Image Optimization/,
			} )
		).toBeInTheDocument();
		expect( FEATURE_TASK_LINKS ).toHaveLength( 8 );
	} );

	it( 'navigates only through the existing tab callback', () => {
		const onNavigate = jest.fn();
		render( <FeatureTaskLinks onNavigate={ onNavigate } /> );

		screen.getByRole( 'button', { name: /Enable Redis/ } ).click();
		expect( onNavigate ).toHaveBeenCalledWith( 'objectCache' );
	} );
} );
