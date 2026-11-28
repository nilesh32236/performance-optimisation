require( '@testing-library/jest-dom' );
jest.mock( '@wordpress/i18n', () => {
	const _n = ( singular, plural, count ) => {
		return count === 1 ? singular : plural;
	};
	const sprintf = ( format, ...args ) => {
		let i = 0;
		// Single-pass: handle %% and placeholders together, mirroring
		// sprintf-js behaviour without a sentinel.
		return format.replace( /%%|%(?:(\d+)\$)?[sd]/g, ( match, index ) => {
			if ( match === '%%' ) {
				return '%';
			}
			if ( index ) {
				const positional = Number( index ) - 1;
				i = Math.max( i, positional + 1 );
				return args[ positional ];
			}
			return args[ i++ ];
		} );
	};
	return {
		__: ( str ) => str,
		_n,
		sprintf,
	};
} );
global.wppoSettings = {};

Object.defineProperty( window, 'matchMedia', {
	writable: true,
	value: jest.fn().mockImplementation( ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addEventListener: jest.fn(),
		removeEventListener: jest.fn(),
		dispatchEvent: jest.fn(),
	} ) ),
} );

/* eslint-disable import/no-extraneous-dependencies */
jest.mock(
	'@wordpress/components',
	() => {
		const React = require( 'react' );
		return {
			// Mirror the real ToggleControl contract used by SwitchField:
			// forward the ref to the input (so aria-* set imperatively by
			// SwitchField lands on it) and render `help` so its text is
			// queryable. Audit #1839.
			ToggleControl: React.forwardRef(
				(
					{
						checked,
						onChange,
						label,
						disabled,
						help,
						__nextHasNoMarginBottom,
						...rest
					},
					ref
				) => (
					<div className="components-base-control">
						<input
							type="checkbox"
							ref={ ref }
							checked={ checked }
							onChange={ ( e ) => onChange( e.target.checked ) }
							aria-label={ label }
							disabled={ disabled }
							{ ...rest }
						/>
						{ help ? (
							<span className="components-base-control__hint">
								{ help }
							</span>
						) : null }
					</div>
				)
			),
		};
	},
	{ virtual: true }
);
/* eslint-enable import/no-extraneous-dependencies */
