/**
 * SwitchField → ToggleControl description contract.
 *
 * This is a separate file from `SwitchField.test.js` because it needs to mock
 * `ToggleControl` itself, and that mock would break the sibling suite.
 *
 * ## Why this cannot be asserted in the normal way
 *
 * The obvious assertion — "the checkbox has this accessible description" —
 * **cannot** be made in jest, and that is the whole reason the defect this
 * guards against shipped. The `ToggleControl` that jest renders is a stub
 * emitting `<input type="checkbox" aria-label={label}>`: it has no generated
 * id, no `<label htmlFor>`, and no `help` support, because
 * `@wordpress/components` is mocked at the module level in this project's test
 * setup. The real association happens inside the real component:
 *
 *   `help` → `describedBy = id + '__help'` → `aria-describedby` on the input
 *
 * so this suite asserts the half jest *can* see (the prop we pass), and the
 * other half — that the rendered page really exposes the description — is
 * verified in a browser by `.a11y-check.js`, which drives the shipped bundle
 * rather than a stub. A test that can only ever pass against a mock is worse
 * than no test, because it looks like coverage.
 */

import { render } from '@testing-library/react';

// Only `ToggleControl` is stubbed, and it is the only thing `SwitchField`
// imports from the package. `jest.requireActual` is deliberately not used: it
// pulls in the real ESM build, which this project's jest config does not
// transform, so the suite would fail to parse before running a single test.
jest.mock( '@wordpress/components', () => ( {
	ToggleControl: jest.fn( () => null ),
} ) );

import { ToggleControl } from '@wordpress/components';

import SwitchField from '../SwitchField';

beforeEach( () => {
	ToggleControl.mockClear();
} );

/** The props of the single ToggleControl call the last render made. */
const lastProps = () => ToggleControl.mock.calls.at( -1 )[ 0 ];

it( 'passes the description as help, the only prop that reaches the input', () => {
	render(
		<SwitchField
			label="Minify CSS"
			description="Strips whitespace from stylesheets."
			name="minifyCSS"
			checked={ false }
			onChange={ () => {} }
		/>
	);
	expect( lastProps().help ).toBe( 'Strips whitespace from stylesheets.' );
} );

it( 'passes no help when there is no description', () => {
	// ToggleControl treats a falsy `help` as "no description", so this is what
	// keeps an `aria-describedby` pointing at a non-existent id off the input.
	render(
		<SwitchField
			label="Minify CSS"
			name="minifyCSS"
			checked={ false }
			onChange={ () => {} }
		/>
	);
	expect( lastProps().help ).toBeUndefined();
} );

it( 'still passes label, because it is the toggle accessible name', () => {
	// The label cannot be dropped: ToggleControl binds it to the input with
	// `htmlFor` against an id it generates internally, so it is the only thing
	// naming the checkbox. It is hidden with CSS, not removed.
	render(
		<SwitchField
			label="Minify CSS"
			name="minifyCSS"
			checked={ false }
			onChange={ () => {} }
		/>
	);
	expect( lastProps().label ).toBe( 'Minify CSS' );
} );

it( 'does not pass an aria-describedby of its own', () => {
	// The previous implementation passed `aria-describedby` as a prop, which
	// landed on BaseControl's wrapper <div> rather than the input. Asserting
	// its absence is what stops the fix being reintroduced alongside `help`.
	render(
		<SwitchField
			label="Minify CSS"
			description="Strips whitespace."
			name="minifyCSS"
			checked={ false }
			onChange={ () => {} }
		/>
	);
	expect( lastProps()[ 'aria-describedby' ] ).toBeUndefined();
} );
