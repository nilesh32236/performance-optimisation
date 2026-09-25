import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import TaskMap from '../TaskMap';
import { CORE_TASKS } from '../../lib/taskMap';

describe( 'TaskMap', () => {
	it( 'renders all seven core tasks', () => {
		expect( CORE_TASKS ).toHaveLength( 7 );
		render( <TaskMap onNavigate={ jest.fn() } /> );
		for ( const entry of CORE_TASKS ) {
			expect( screen.getByText( entry.getTask() ) ).toBeInTheDocument();
		}
	} );

	it( 'covers caching, LCP, images, CSS/JS, database, Redis, and CDN', () => {
		const keys = CORE_TASKS.map( ( entry ) => entry.key );
		expect( keys ).toEqual( [
			'caching',
			'lcp',
			'images',
			'css-js',
			'database',
			'redis',
			'cdn',
		] );
	} );

	it( 'navigates to the existing tab for each task', () => {
		const onNavigate = jest.fn();
		render( <TaskMap onNavigate={ onNavigate } /> );
		for ( const entry of CORE_TASKS ) {
			const button = screen.getByRole( 'button', {
				name: entry.getAction(),
			} );
			fireEvent.click( button );
			expect( onNavigate ).toHaveBeenCalledWith( entry.tab );
		}
		expect( onNavigate ).toHaveBeenCalledTimes( CORE_TASKS.length );
	} );

	it( 'only targets existing App.js tabs', () => {
		const knownTabs = new Set( [
			'dashboard',
			'fileOptimization',
			'preload',
			'imageOptimization',
			'databaseCleanup',
			'objectCache',
			'tools',
		] );
		for ( const entry of CORE_TASKS ) {
			expect( knownTabs.has( entry.tab ) ).toBe( true );
		}
	} );
} );
