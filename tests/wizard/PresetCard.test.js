/**
 * @jest-environment jsdom
 */

/**
 * External dependencies
 */
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
/**
 * Internal dependencies
 */
import PresetCard from '../../src/components/Wizard/PresetCard';

const mockPreset = {
	id: 'recommended',
	title: 'Recommended (Balanced)',
	description: 'The best balance of speed and compatibility for most websites.',
	features: [
		'Page Caching',
		'Image Lazy Loading',
		'CSS & HTML Minification',
		'Combine CSS Files',
	],
	isRecommended: true,
	hasWarning: false,
};

const mockTranslations = {
	recommended: 'Recommended',
};

describe( 'PresetCard', () => {
	test( 'renders preset information correctly', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		expect( screen.getByText( 'Recommended (Balanced)' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'The best balance of speed and compatibility for most websites.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'Page Caching' ) ).toBeInTheDocument();
		expect( screen.getByText( 'CSS & HTML Minification' ) ).toBeInTheDocument();
	} );

	test( 'shows recommended badge when isRecommended is true', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		expect( screen.getByText( 'Recommended' ) ).toBeInTheDocument();
	} );

	test( 'shows warning icon when hasWarning is true', () => {
		const mockOnSelect = jest.fn();
		const presetWithWarning = { ...mockPreset, hasWarning: true };

		render(
			<PresetCard
				preset={ presetWithWarning }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		const warningIcon = screen.getByLabelText( 'Warning: May require testing' );
		expect( warningIcon ).toBeInTheDocument();
	} );

	test( 'calls onSelect when clicked', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		const card = screen.getByRole( 'radio' );
		fireEvent.click( card );

		expect( mockOnSelect ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'calls onSelect when Enter key is pressed', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		const card = screen.getByRole( 'radio' );
		fireEvent.keyPress( card, { key: 'Enter', code: 'Enter' } );

		expect( mockOnSelect ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'calls onSelect when Space key is pressed', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		const card = screen.getByRole( 'radio' );
		fireEvent.keyPress( card, { key: ' ', code: 'Space' } );

		expect( mockOnSelect ).toHaveBeenCalledTimes( 1 );
	} );

	test( 'has correct accessibility attributes when selected', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ true }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		const card = screen.getByRole( 'radio' );
		expect( card ).toHaveAttribute( 'aria-checked', 'true' );
		expect( card ).toHaveClass( 'selected' );
	} );

	test( 'has correct accessibility attributes when not selected', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		const card = screen.getByRole( 'radio' );
		expect( card ).toHaveAttribute( 'aria-checked', 'false' );
		expect( card ).not.toHaveClass( 'selected' );
	} );

	test( 'has proper ARIA labeling', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		const card = screen.getByRole( 'radio' );
		expect( card ).toHaveAttribute( 'aria-labelledby', 'preset-title-recommended' );
		expect( card ).toHaveAttribute(
			'aria-describedby',
			'preset-desc-recommended preset-features-recommended'
		);
	} );

	test( 'renders all features in the list', () => {
		const mockOnSelect = jest.fn();

		render(
			<PresetCard
				preset={ mockPreset }
				isSelected={ false }
				onSelect={ mockOnSelect }
				translations={ mockTranslations }
			/>
		);

		mockPreset.features.forEach( ( feature ) => {
			expect( screen.getByText( feature ) ).toBeInTheDocument();
		} );
	} );
} );
