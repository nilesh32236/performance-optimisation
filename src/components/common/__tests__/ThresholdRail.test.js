/**
 * Tests for ThresholdRail.
 *
 * Two things matter here and both are easy to regress:
 *
 *   1. The tone is derived from the value against its thresholds, and an
 *      unmeasured metric must never be given a signal colour.
 *   2. The graphic is decorative and the *meaning* is carried by a visually
 *      hidden sentence. If that sentence disappears, the rail becomes an
 *      unlabelled box to a screen reader.
 *
 * @package
 */

import { render, screen } from '@testing-library/react';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

import ThresholdRail, { toneFor } from '../ThresholdRail';

const LCP = { good: 2500, poor: 4000 };

describe( 'toneFor', () => {
	it( 'is good at or below the good threshold', () => {
		expect( toneFor( 0, LCP ) ).toBe( 'good' );
		expect( toneFor( 2500, LCP ) ).toBe( 'good' );
	} );

	it( 'is warn between the thresholds', () => {
		expect( toneFor( 2501, LCP ) ).toBe( 'warn' );
		expect( toneFor( 4000, LCP ) ).toBe( 'warn' );
	} );

	it( 'is bad above the poor threshold', () => {
		expect( toneFor( 4001, LCP ) ).toBe( 'bad' );
	} );

	it( 'is idle for every flavour of missing measurement', () => {
		expect( toneFor( null, LCP ) ).toBe( 'idle' );
		expect( toneFor( undefined, LCP ) ).toBe( 'idle' );
		expect( toneFor( NaN, LCP ) ).toBe( 'idle' );
		expect( toneFor( 100, null ) ).toBe( 'idle' );
	} );
} );

describe( 'ThresholdRail', () => {
	it( 'describes the value and its position for a screen reader', () => {
		render(
			<ThresholdRail value={ 388 } thresholds={ LCP } display="388 ms" />
		);
		expect(
			screen.getByText( '388 ms — within the good range' )
		).toBeInTheDocument();
	} );

	it( 'names the label when one is supplied', () => {
		render(
			<ThresholdRail
				value={ 388 }
				thresholds={ LCP }
				display="388 ms"
				label="Loading"
			/>
		);
		expect(
			screen.getByText( 'Loading: 388 ms — within the good range' )
		).toBeInTheDocument();
	} );

	it( 'uses the warn wording between the thresholds', () => {
		render(
			<ThresholdRail value={ 3100 } thresholds={ LCP } display="3.1 s" />
		);
		expect(
			screen.getByText( '3.1 s — outside the target' )
		).toBeInTheDocument();
	} );

	it( 'uses the failing wording above the poor threshold', () => {
		render(
			<ThresholdRail value={ 4600 } thresholds={ LCP } display="4.6 s" />
		);
		expect( screen.getByText( '4.6 s — failing' ) ).toBeInTheDocument();
	} );

	it( 'renders three zones and a marker when measured', () => {
		const { container } = render(
			<ThresholdRail value={ 388 } thresholds={ LCP } display="388 ms" />
		);
		expect( container.querySelectorAll( '.wppo-rail__zone' ) ).toHaveLength(
			3
		);
		expect(
			container.querySelector( '.wppo-rail__marker' )
		).not.toBeNull();
	} );

	it( 'draws no marker and no zone when unmeasured', () => {
		const { container } = render(
			<ThresholdRail value={ null } thresholds={ LCP } />
		);
		expect( container.querySelector( '.wppo-rail__marker' ) ).toBeNull();
		expect( container.querySelectorAll( '.wppo-rail__zone' ) ).toHaveLength(
			0
		);
		expect( screen.getByText( 'not measured yet' ) ).toBeInTheDocument();
	} );

	it( 'marks an unmeasured rail idle rather than failing', () => {
		const { container } = render(
			<ThresholdRail value={ null } thresholds={ LCP } />
		);
		const rail = container.querySelector( '.wppo-rail' );
		expect( rail ).toHaveClass( 'wppo-rail--idle' );
		expect( rail ).not.toHaveClass( 'wppo-rail--bad' );
		expect( rail ).not.toHaveClass( 'wppo-rail--warn' );
	} );

	it( 'clamps a value beyond the domain to the end of the track', () => {
		const { container } = render(
			<ThresholdRail
				value={ 999999 }
				thresholds={ LCP }
				domain={ { min: 0, max: 6000 } }
				display="999 s"
			/>
		);
		const marker = container.querySelector( '.wppo-rail__marker' );
		expect( marker.style.left ).toBe( '100%' );
	} );

	it( 'keeps the graphic out of the accessibility tree', () => {
		const { container } = render(
			<ThresholdRail value={ 388 } thresholds={ LCP } display="388 ms" />
		);
		expect(
			container.querySelector( '.wppo-rail__graphic' )
		).toHaveAttribute( 'aria-hidden', 'true' );
	} );
} );
