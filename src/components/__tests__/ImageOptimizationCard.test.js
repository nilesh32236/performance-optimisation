import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import ImageOptimizationCard from '../ImageOptimizationCard';

describe( 'ImageOptimizationCard Component', () => {
	const onOptimize = jest.fn();
	const onRemove = jest.fn();

	beforeEach( () => {
		jest.clearAllMocks();
	} );

	it( 'renders the card title', () => {
		render(
			<ImageOptimizationCard
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect( screen.getByText( /Image Optimization/i ) ).toBeInTheDocument();
	} );

	it( 'renders WebP progress bar with correct percentage', () => {
		render(
			<ImageOptimizationCard
				completed={ { webp: 30, avif: 10 } }
				pending={ { webp: 10, avif: 5 } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		const webpProgress = screen.getByRole( 'progressbar', {
			name: /WebP Conversion Progress/i,
		} );
		expect( webpProgress ).toHaveAttribute( 'aria-valuenow', '75' );
	} );

	it( 'renders AVIF progress bar with correct percentage', () => {
		render(
			<ImageOptimizationCard
				completed={ { webp: 10, avif: 25 } }
				pending={ { webp: 5, avif: 25 } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		const avifProgress = screen.getByRole( 'progressbar', {
			name: /AVIF Conversion Progress/i,
		} );
		expect( avifProgress ).toHaveAttribute( 'aria-valuenow', '50' );
	} );

	it( 'renders Optimize All button', () => {
		render(
			<ImageOptimizationCard
				pendingPathsCount={ 5 }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Optimize All/i } )
		).toBeInTheDocument();
	} );

	it( 'calls onOptimize when Optimize All is clicked', () => {
		render(
			<ImageOptimizationCard
				pendingPathsCount={ 5 }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /Optimize All/i } )
		);
		expect( onOptimize ).toHaveBeenCalled();
	} );

	it( 'renders Remove Optimized button', () => {
		render(
			<ImageOptimizationCard
				completed={ { webp: 5, avif: 3 } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Remove Optimized/i } )
		).toBeInTheDocument();
	} );

	it( 'calls onRemove when Remove Optimized is clicked', () => {
		render(
			<ImageOptimizationCard
				completed={ { webp: 5, avif: 3 } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /Remove Optimized/i } )
		);
		expect( onRemove ).toHaveBeenCalled();
	} );

	it( 'disables Remove button when no completed items', () => {
		render(
			<ImageOptimizationCard
				completed={ { webp: 0, avif: 0 } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Remove Optimized/i } )
		).toBeDisabled();
	} );

	it( 'shows loading state on Optimize All button', () => {
		render(
			<ImageOptimizationCard
				loading={ { optimize_images: true } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Optimizing/i } )
		).toBeDisabled();
	} );

	it( 'shows loading state on Remove Optimized button', () => {
		render(
			<ImageOptimizationCard
				loading={ { remove_images: true } }
				completed={ { webp: 5, avif: 3 } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Removing/i } )
		).toBeDisabled();
	} );

	it( 'disables Optimize All button when no pending paths', () => {
		render(
			<ImageOptimizationCard
				pendingPathsCount={ 0 }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Optimize All/i } )
		).toBeDisabled();
	} );

	it( 'shows background processing banner', () => {
		render(
			<ImageOptimizationCard
				bgProcessing={ true }
				bgJobsQueued={ 3 }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect(
			screen.getByText( /background optimization jobs/i )
		).toBeInTheDocument();
	} );

	it( 'renders progress section with correct counts', () => {
		render(
			<ImageOptimizationCard
				completed={ { webp: 10, avif: 5 } }
				pending={ { webp: 5, avif: 3 } }
				onOptimize={ onOptimize }
				onRemove={ onRemove }
			/>
		);
		expect( screen.getByText( '10 / 15' ) ).toBeInTheDocument();
		expect( screen.getByText( '5 / 8' ) ).toBeInTheDocument();
	} );
} );
