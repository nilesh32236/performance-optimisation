import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import SuggestionsPanel from '../SuggestionsPanel';

describe( 'SuggestionsPanel Component', () => {
	const mockOnNavigate = jest.fn();

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders empty state when no suggestions are provided', () => {
		render(
			<SuggestionsPanel
				suggestions={ [] }
				onNavigate={ mockOnNavigate }
			/>
		);

		expect(
			screen.getByText( /No suggestions — your site looks great!/i )
		).toBeInTheDocument();
	} );

	it( 'renders empty state when suggestions is undefined', () => {
		render( <SuggestionsPanel onNavigate={ mockOnNavigate } /> );

		expect(
			screen.getByText( /No suggestions — your site looks great!/i )
		).toBeInTheDocument();
	} );

	it( 'renders issues and passing suggestions correctly', () => {
		const suggestions = [
			{
				metric: 'metric1',
				value: 0.5,
				unit: 'score',
				status: 'poor',
				description: 'A poor metric',
				fix_action: 'open_file_optimization_tab',
			},
			{
				metric: 'metric2',
				value: 0.95,
				unit: 'score',
				status: 'good',
				description: 'A good metric',
				fix_action: 'no_action_required',
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ mockOnNavigate }
			/>
		);

		// Header
		expect( screen.getByText( 'Suggestions' ) ).toBeInTheDocument();
		expect( screen.getByText( '1' ) ).toBeInTheDocument(); // Badge for 1 issue

		// Poor metric
		expect( screen.getByText( 'A poor metric' ) ).toBeInTheDocument();
		expect( screen.getByText( '50 / 100' ) ).toBeInTheDocument();
		const fixButton = screen.getByRole( 'button', {
			name: /Fix It: A poor metric/i,
		} );
		expect( fixButton ).toBeInTheDocument();

		// Good metric
		expect( screen.getByText( 'A good metric' ) ).toBeInTheDocument();
		expect( screen.getByText( '95 / 100' ) ).toBeInTheDocument();
		// Expect the word 'Passing' near the good metric
		const passingIndicators = screen.getAllByText( /Passing/i );
		expect( passingIndicators.length ).toBeGreaterThan( 0 );

		// Click fix button
		fireEvent.click( fixButton );
		expect( mockOnNavigate ).toHaveBeenCalledWith( 'fileOptimization' );
	} );

	it( 'formats values correctly based on unit', () => {
		const suggestions = [
			{
				metric: 'm1',
				value: 'pass',
				unit: 'boolean',
				status: 'good',
				description: 'Boolean pass',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm2',
				value: 'fail',
				unit: 'boolean',
				status: 'poor',
				description: 'Boolean fail',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm3',
				value: 'none',
				unit: 'header',
				status: 'poor',
				description: 'Header none',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm4',
				value: 'max-age=3600',
				unit: 'header',
				status: 'good',
				description: 'Header value',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm5',
				value: 'gzip',
				unit: 'encoding',
				status: 'good',
				description: 'Encoding value',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm6',
				value: 0.85,
				unit: 'score',
				status: 'needs_improvement',
				description: 'Score value',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm7',
				value: 12.34,
				unit: '%',
				status: 'needs_improvement',
				description: 'Percent value',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm8',
				value: 1.234,
				unit: 's',
				status: 'needs_improvement',
				description: 'Seconds value',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm9',
				value: 150.5,
				unit: 'ms',
				status: 'good',
				description: 'Milliseconds value',
				fix_action: 'no_action_required',
			},
			{
				metric: 'm10',
				value: 10,
				unit: 'KB',
				status: 'good',
				description: 'Custom unit value',
				fix_action: 'no_action_required',
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ mockOnNavigate }
			/>
		);

		// Checking all formatted strings
		expect( screen.getAllByText( 'Passing' )[ 0 ] ).toBeInTheDocument();
		expect( screen.getByText( 'Failing' ) ).toBeInTheDocument();
		expect( screen.getByText( 'None' ) ).toBeInTheDocument();
		expect( screen.getByText( 'max-age=3600' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Gzip' ) ).toBeInTheDocument();
		expect( screen.getByText( '85 / 100' ) ).toBeInTheDocument();
		expect( screen.getByText( '12.3%' ) ).toBeInTheDocument();
		expect( screen.getByText( '1.23s' ) ).toBeInTheDocument();
		expect( screen.getByText( '151ms' ) ).toBeInTheDocument();
		expect( screen.getByText( '10 KB' ) ).toBeInTheDocument();
	} );

	it( 'does not render Fix It button if targetTab is null', () => {
		const suggestions = [
			{
				metric: 'metric1',
				value: 'fail',
				unit: 'boolean',
				status: 'poor',
				description: 'A poor metric with no fix action',
				fix_action: 'unknown_action', // Not mapped in FIX_ACTION_TAB_MAP
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ mockOnNavigate }
			/>
		);

		expect(
			screen.getByText( 'A poor metric with no fix action' )
		).toBeInTheDocument();
		const fixButton = screen.queryByRole( 'button', {
			name: /Fix It/i,
		} );
		expect( fixButton ).not.toBeInTheDocument();
	} );
} );
