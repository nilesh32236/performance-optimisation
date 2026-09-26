import { render, screen } from '@testing-library/react';
import LoadingSubmitButton from '../LoadingSubmitButton';
// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';

describe( 'LoadingSubmitButton', () => {
	it( 'renders correctly with default props', () => {
		render( <LoadingSubmitButton label="Submit" /> );
		const button = screen.getByRole( 'button', { name: /Submit/i } );
		expect( button ).toBeInTheDocument();
		expect( button ).not.toBeDisabled();
		expect( button ).toHaveClass( 'wppo-button' );
		expect( button ).toHaveClass( 'wppo-button--primary' );
		expect( button ).toHaveAttribute( 'type', 'submit' );
		expect( button ).not.toHaveAttribute( 'aria-busy', 'true' );
	} );

	it( 'renders correctly in loading state', () => {
		render(
			<LoadingSubmitButton
				isLoading={ true }
				label="Submit"
				loadingLabel="Saving..."
			/>
		);
		const button = screen.getByRole( 'button', { name: /Saving\.\.\./i } );
		expect( button ).toBeInTheDocument();
		expect( button ).toBeDisabled();
		expect( button ).toHaveAttribute( 'aria-busy', 'true' );
		// Icon should be present (hidden from ARIA)
		expect( button.querySelector( 'svg' ) ).toHaveClass( 'fa-spinner' );
	} );

	it( 'renders children if label is not provided', () => {
		render( <LoadingSubmitButton>Click Me</LoadingSubmitButton> );
		expect(
			screen.getByRole( 'button', { name: /Click Me/i } )
		).toBeInTheDocument();
	} );

	it( 'is disabled when disabled prop is true', () => {
		render( <LoadingSubmitButton disabled={ true } label="Submit" /> );
		const button = screen.getByRole( 'button', { name: /Submit/i } );
		expect( button ).toBeDisabled();
	} );

	it( 'applies custom className', () => {
		render(
			<LoadingSubmitButton className="custom-class" label="Submit" />
		);
		const button = screen.getByRole( 'button', { name: /Submit/i } );
		expect( button ).toHaveClass( 'custom-class' );
	} );

	it( 'renders children when isLoading is true and loadingLabel is not provided', () => {
		global.wppoSettings = { translations: {} };
		render(
			<LoadingSubmitButton isLoading={ true }>
				Custom Loading Children
			</LoadingSubmitButton>
		);
		const button = screen.getByRole( 'button', {
			name: /Custom Loading Children/i,
		} );
		expect( button ).toBeInTheDocument();
		expect( button ).toBeDisabled();
		expect( button ).toHaveAttribute( 'aria-busy', 'true' );
	} );

	// The in-flight state is carried by `aria-busy`, the disabled attribute and
	// the button's own text changing to `loadingLabel`. Repeating that text in a
	// live region makes a screen reader announce it twice, so the region is
	// reserved for completion — which is the one thing the button cannot convey
	// once focus has moved on.
	it( 'does not duplicate the button text in a live region while loading', () => {
		render(
			<LoadingSubmitButton
				isLoading
				label="Submit"
				loadingLabel="Saving..."
			/>
		);
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		// The loading state is still announced — via the button itself.
		const button = screen.getByRole( 'button' );
		expect( button ).toHaveAttribute( 'aria-busy', 'true' );
		expect( button ).toBeDisabled();
		expect( button ).toHaveTextContent( 'Saving...' );
	} );

	it( 'leaves no stale live region behind once a save has finished', () => {
		// The regression this pins. The component used to remember the last
		// loading text in a ref and never clear it, so after a single save a
		// `role="status"` sat on the idle form reading "Saving…" for the rest of
		// the page's life. No production caller passes `doneLabel`, so every one
		// of the 16 usages reached this state.
		const { rerender } = render(
			<LoadingSubmitButton label="Submit" loadingLabel="Saving..." />
		);
		rerender(
			<LoadingSubmitButton
				isLoading
				label="Submit"
				loadingLabel="Saving..."
			/>
		);
		rerender(
			<LoadingSubmitButton
				isLoading={ false }
				label="Submit"
				loadingLabel="Saving..."
			/>
		);
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'button' ) ).toHaveTextContent( 'Submit' );
	} );

	it( 'survives repeated saves without accumulating regions', () => {
		const { rerender } = render(
			<LoadingSubmitButton label="Submit" loadingLabel="Saving..." />
		);
		[ true, false, true, false ].forEach( ( isLoading ) => {
			rerender(
				<LoadingSubmitButton
					isLoading={ isLoading }
					label="Submit"
					loadingLabel="Saving..."
				/>
			);
		} );
		expect( screen.queryAllByRole( 'status' ) ).toHaveLength( 0 );
	} );

	it( 'announces completion only when the caller asks for it', () => {
		const { rerender } = render(
			<LoadingSubmitButton
				isLoading
				label="Submit"
				loadingLabel="Saving..."
				doneLabel="Saved."
			/>
		);
		// Even with a doneLabel, there is nothing to announce *during* the save.
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();

		rerender(
			<LoadingSubmitButton
				isLoading={ false }
				label="Submit"
				loadingLabel="Saving..."
				doneLabel="Saved."
			/>
		);
		const region = screen.getByRole( 'status' );
		expect( region ).toHaveTextContent( 'Saved.' );
		// And it is never rendered empty, which would announce nothing at all.
		expect( region.textContent ).not.toBe( '' );
	} );
} );
