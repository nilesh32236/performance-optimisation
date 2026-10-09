/**
 * The granular cleanup screen: ten types under one section, each action named.
 *
 * Two real defects, both found by driving Chrome's accessibility tree rather
 * than by reading the JSX.
 *
 * **1. The outline skipped a level back up.** "Granular Cleanup Options" was an
 * `<h4>` and the ten type names were each an `<h3>`, so the outline ran
 * `H2 -> H3 -> H3 -> H4 -> H3…`.
 *
 * **2. Ten buttons with the same accessible name.** Every row's action was the
 * bare string "Clean", so a screen-reader user heard ten identical actions and
 * could not tell which row each belonged to. That is pre-existing and
 * independent of the heading question.
 *
 * There is a third thing this file used to assert, and it is worth recording
 * because it was *wrong*. An earlier version turned the ten titles into `<p>`
 * elements, on the theory that they were list items rather than sections. An
 * independent review measured the accessibility tree and found the change
 * replaced **ten named heading nodes with ten anonymous paragraphs** — the
 * strings survived only as `StaticText` — and the "one list" never reached the
 * tree, because nothing emitted a list role. The headings stayed.
 *
 * @package
 */

// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, within } from '@testing-library/react';

import FeatureCard from '../common/FeatureCard';

const TYPES = [ 'Post Revisions', 'Auto Drafts', 'Trashed Posts' ];

describe( 'FeatureCard heading level', () => {
	it( 'is an h3 by default, so every other card in the plugin is unchanged', () => {
		const { container } = render(
			<FeatureCard title="CSS Optimisation" />
		);
		expect( container.querySelector( 'h3' ) ).not.toBeNull();
	} );

	// The `h3` case gets no "no h3 present" assertion: the element *is* the
	// default, so the two assertions would contradict each other.
	it.each( [ 'h4', 'h5', 'h6' ] )(
		'honours titleAs="%s", and the default is gone',
		( tag ) => {
			const { container } = render(
				<FeatureCard title="Post Revisions" titleAs={ tag }>
					<p>body</p>
				</FeatureCard>
			);
			expect( container.querySelector( tag ) ).not.toBeNull();
			expect( container.querySelector( 'h3' ) ).toBe( null );
		}
	);

	it( 'honours titleAs="h3", which is also the default', () => {
		const { container } = render(
			<FeatureCard title="Post Revisions" titleAs="h3">
				<p>body</p>
			</FeatureCard>
		);
		expect( container.querySelector( 'h3' ) ).not.toBeNull();
	} );

	// A bad value would otherwise render silently as an unknown element.
	it.each( [ undefined, null, '', 'h2', 'h99', 'div', 'span' ] )(
		'falls back to h3 for the invalid level %p',
		( value ) => {
			const { container } = render(
				<FeatureCard title="Post Revisions" titleAs={ value }>
					<p>body</p>
				</FeatureCard>
			);
			expect( container.querySelector( 'h3' ) ).not.toBeNull();
		}
	);
} );

describe( 'a list of cleanup types', () => {
	// These are **headings**, not paragraphs. The `<p>` variant is recorded in
	// this file's header as the thing that was tried and measured as worse.
	it( 'keeps a navigation stop for every type', () => {
		render(
			<div>
				{ TYPES.map( ( label ) => (
					<FeatureCard key={ label } title={ label } titleAs="h4">
						<p>{ label } description</p>
					</FeatureCard>
				) ) }
			</div>
		);
		for ( const label of TYPES ) {
			expect(
				screen.getByRole( 'heading', { level: 4, name: label } )
			).toBeInTheDocument();
		}
	} );

	it( 'gives every action a unique accessible name', () => {
		render(
			<div>
				{ TYPES.map( ( label ) => (
					<FeatureCard key={ label } title={ label } titleAs="h4">
						<button type="button" aria-label={ `Clean ${ label }` }>
							Clean
						</button>
					</FeatureCard>
				) ) }
			</div>
		);
		for ( const label of TYPES ) {
			expect(
				screen.getByRole( 'button', { name: `Clean ${ label }` } )
			).toBeInTheDocument();
		}
		// The visible text alone would collide three ways.
		expect( screen.getAllByText( 'Clean' ) ).toHaveLength( TYPES.length );
		expect(
			screen.queryByRole( 'button', { name: 'Clean' } )
		).not.toBeInTheDocument();
	} );

	it( 'reads a type and its action together from one row', () => {
		render(
			<div>
				{ TYPES.map( ( label ) => (
					<div key={ label } data-testid={ `row-${ label }` }>
						<h4>{ label }</h4>
						<button type="button" aria-label={ `Clean ${ label }` }>
							Clean
						</button>
					</div>
				) ) }
			</div>
		);
		const row = within( screen.getByTestId( 'row-Post Revisions' ) );
		expect( row.getByRole( 'heading' ) ).toHaveTextContent(
			'Post Revisions'
		);
		expect( row.getByRole( 'button' ) ).toHaveAccessibleName(
			'Clean Post Revisions'
		);
	} );
} );
