import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import SuggestionsPanel from '../SuggestionsPanel';

describe( 'SuggestionsPanel Component', () => {
	const onNavigate = jest.fn();

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'shows empty state when suggestions is null', () => {
		render(
			<SuggestionsPanel suggestions={ null } onNavigate={ onNavigate } />
		);
		expect( screen.getByText( /No suggestions/i ) ).toBeInTheDocument();
	} );

	it( 'shows empty state when suggestions array is empty', () => {
		render(
			<SuggestionsPanel suggestions={ [] } onNavigate={ onNavigate } />
		);
		expect( screen.getByText( /No suggestions/i ) ).toBeInTheDocument();
	} );

	it( 'renders suggestion cards for issues', () => {
		const suggestions = [
			{
				metric: 'enable_gzip',
				value: 'none',
				unit: 'encoding',
				status: 'poor',
				description: 'Enable Gzip compression',
				fix_action: 'enable_server_rules',
			},
			{
				metric: 'use_cache',
				value: 'none',
				unit: 'header',
				status: 'needs_improvement',
				description: 'Enable browser caching',
				fix_action: 'enable_server_rules',
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ onNavigate }
			/>
		);
		expect(
			screen.getByText( 'Enable Gzip compression' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Enable browser caching' )
		).toBeInTheDocument();
	} );

	it( 'renders passing suggestions separately', () => {
		const suggestions = [
			{
				metric: 'enable_gzip',
				value: 'pass',
				unit: 'boolean',
				status: 'good',
				description: 'Gzip compression is enabled',
				fix_action: 'no_action_required',
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ onNavigate }
			/>
		);
		expect(
			screen.getByText( 'Gzip compression is enabled' )
		).toBeInTheDocument();
	} );

	it( 'shows Fix It button for poor suggestions with valid fix_action', () => {
		const suggestions = [
			{
				metric: 'enable_gzip',
				value: 'none',
				unit: 'encoding',
				status: 'poor',
				description: 'Enable Gzip compression',
				fix_action: 'enable_server_rules',
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ onNavigate }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Fix It/i } )
		).toBeInTheDocument();
	} );

	it( 'calls onNavigate with correct tab when Fix It is clicked', () => {
		const suggestions = [
			{
				metric: 'enable_gzip',
				value: 'none',
				unit: 'encoding',
				status: 'poor',
				description: 'Enable Gzip compression',
				fix_action: 'enable_server_rules',
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ onNavigate }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: /Fix It/i } ) );
		expect( onNavigate ).toHaveBeenCalledWith( 'fileOptimization' );
	} );

	it( 'shows Passing indicator for good status with no fix_action', () => {
		const suggestions = [
			{
				metric: 'enable_gzip',
				value: 'pass',
				unit: 'boolean',
				status: 'good',
				description: 'Gzip compression is enabled',
				fix_action: 'no_action_required',
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ onNavigate }
			/>
		);
		const passingElements = screen.getAllByText( 'Passing' );
		expect( passingElements.length ).toBeGreaterThanOrEqual( 1 );
	} );

	it( 'renders suggestion icons with correct status classes', () => {
		const suggestions = [
			{
				metric: 'a',
				value: 'test',
				unit: 's',
				status: 'poor',
				description: 'Poor test',
				fix_action: null,
			},
		];

		render(
			<SuggestionsPanel
				suggestions={ suggestions }
				onNavigate={ onNavigate }
			/>
		);

		const icon = document.querySelector( '.wppo-suggestion-icon--poor' );
		expect( icon ).toBeInTheDocument();
	} );
} );

describe( 'formatValue', () => {
	const formatValue = ( value, unit ) => {
		if ( unit === 'boolean' ) {
			return value === 'pass' ? 'Passing' : 'Failing';
		}
		if ( unit === 'header' ) {
			if ( value === 'none' ) {
				return 'None';
			}
			return value;
		}
		if ( unit === 'encoding' ) {
			if ( value === 'none' ) {
				return 'None';
			}
			const encodings = {
				br: 'Brotli',
				gzip: 'Gzip',
				deflate: 'Deflate',
				zstd: 'Zstd',
			};
			return encodings[ String( value ).toLowerCase() ] || value;
		}
		if ( unit === 'score' ) {
			return `${ Math.round( parseFloat( value ) * 100 ) } / 100`;
		}
		if ( unit === '%' ) {
			return `${ Number( value ).toFixed( 1 ) }%`;
		}
		if ( unit === 's' ) {
			return `${ Number( value ).toFixed( 2 ) }s`;
		}
		if ( unit === 'ms' ) {
			return `${ Math.round( value ) }ms`;
		}
		return `${ value } ${ unit }`;
	};

	it( 'formats boolean pass', () => {
		expect( formatValue( 'pass', 'boolean' ) ).toBe( 'Passing' );
	} );

	it( 'formats boolean fail', () => {
		expect( formatValue( 'fail', 'boolean' ) ).toBe( 'Failing' );
	} );

	it( 'formats header none', () => {
		expect( formatValue( 'none', 'header' ) ).toBe( 'None' );
	} );

	it( 'formats header value', () => {
		expect( formatValue( 'max-age=3600', 'header' ) ).toBe(
			'max-age=3600'
		);
	} );

	it( 'formats encoding', () => {
		expect( formatValue( 'gzip', 'encoding' ) ).toBe( 'Gzip' );
		expect( formatValue( 'br', 'encoding' ) ).toBe( 'Brotli' );
		expect( formatValue( 'none', 'encoding' ) ).toBe( 'None' );
	} );

	it( 'formats score', () => {
		expect( formatValue( '0.95', 'score' ) ).toBe( '95 / 100' );
	} );

	it( 'formats percentage', () => {
		expect( formatValue( '85.3', '%' ) ).toBe( '85.3%' );
	} );

	it( 'formats seconds', () => {
		expect( formatValue( '1.234', 's' ) ).toBe( '1.23s' );
	} );

	it( 'formats milliseconds', () => {
		expect( formatValue( '500', 'ms' ) ).toBe( '500ms' );
	} );

	it( 'formats default unit', () => {
		expect( formatValue( '5', 'kb' ) ).toBe( '5 kb' );
	} );
} );
