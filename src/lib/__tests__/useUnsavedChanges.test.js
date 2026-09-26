import { renderHook, act } from '@testing-library/react';
import useUnsavedChanges, { stableStringify } from '../useUnsavedChanges';
import UnsavedChangesContext from '../UnsavedChangesContext';

const createWrapper = ( state ) => {
	const wrapper = ( { children } ) => (
		<UnsavedChangesContext.Provider
			value={ {
				isDirty: state.currentDirty,
				setIsDirty: ( value ) => {
					state.currentDirty = value;
					state.setIsDirty( value );
				},
			} }
		>
			{ children }
		</UnsavedChangesContext.Provider>
	);
	return wrapper;
};

const renderWithDirtyFlag = ( settings, baseline ) => {
	const state = { setIsDirty: jest.fn(), currentDirty: false };
	const utils = renderHook( () => useUnsavedChanges( settings, baseline ), {
		wrapper: createWrapper( state ),
	} );
	return { ...utils, setIsDirty: state.setIsDirty };
};

describe( 'useUnsavedChanges', () => {
	it( 'reports clean when settings match the baseline', () => {
		const { setIsDirty } = renderWithDirtyFlag(
			{ a: 1, b: 2 },
			{ a: 1, b: 2 }
		);
		expect( setIsDirty ).toHaveBeenCalledWith( false );
	} );

	it( 'reports dirty when settings differ from the baseline', () => {
		const { setIsDirty } = renderWithDirtyFlag(
			{ a: 1, b: 3 },
			{ a: 1, b: 2 }
		);
		expect( setIsDirty ).toHaveBeenCalledWith( true );
	} );

	it( 'is insensitive to key insertion order', () => {
		const settings = {};
		settings.a = 1;
		settings.b = 2;
		const baseline = {};
		baseline.b = 2;
		baseline.a = 1;
		// Sanity check: raw JSON.stringify would flag this as dirty.
		expect( JSON.stringify( settings ) ).not.toBe(
			JSON.stringify( baseline )
		);
		const { setIsDirty } = renderWithDirtyFlag( settings, baseline );
		expect( setIsDirty ).toHaveBeenCalledWith( false );
	} );

	it( 'updates the flag when settings change', () => {
		const state = { setIsDirty: jest.fn(), currentDirty: false };
		const { rerender } = renderHook(
			( { settings } ) => useUnsavedChanges( settings, { a: 1 } ),
			{
				wrapper: createWrapper( state ),
				initialProps: { settings: { a: 1 } },
			}
		);
		expect( state.setIsDirty ).toHaveBeenLastCalledWith( false );
		rerender( { settings: { a: 2 } } );
		expect( state.setIsDirty ).toHaveBeenLastCalledWith( true );
	} );

	it( 'clears the flag on unmount', () => {
		const { setIsDirty, unmount } = renderWithDirtyFlag(
			{ a: 2 },
			{ a: 1 }
		);
		expect( setIsDirty ).toHaveBeenCalledWith( true );
		act( () => {
			unmount();
		} );
		expect( setIsDirty ).toHaveBeenLastCalledWith( false );
	} );
} );

describe( 'stableStringify', () => {
	it( 'is insensitive to key order', () => {
		expect( stableStringify( { b: 2, a: 1 } ) ).toBe(
			stableStringify( { a: 1, b: 2 } )
		);
	} );

	it( 'serializes circular references instead of throwing', () => {
		const circular = { a: 1 };
		circular.self = circular;
		expect( () => stableStringify( circular ) ).not.toThrow();
		expect( stableStringify( circular ) ).toContain( '[Circular]' );

		const arr = [ 1 ];
		arr.push( arr );
		expect( stableStringify( arr ) ).toContain( '[Circular]' );
	} );

	it( 'does not false-positive on diamond references', () => {
		const shared = { x: 1 };
		expect( stableStringify( { l: shared, r: shared } ) ).not.toContain(
			'[Circular]'
		);
	} );

	it( 'maps undefined and functions to null', () => {
		expect( stableStringify( undefined ) ).toBe( 'null' );
		expect( stableStringify( { a: undefined, b: 1 } ) ).toBe(
			'{"a":null,"b":1}'
		);
	} );

	it( 'never throws on BigInt values', () => {
		expect( () => stableStringify( { n: 10n } ) ).not.toThrow();
		expect( stableStringify( { n: 10n } ) ).toContain( '10' );
	} );

	it( 'honors toJSON like JSON.stringify', () => {
		const date = new Date( '2024-01-02T03:04:05.000Z' );
		expect( stableStringify( date ) ).toBe( JSON.stringify( date ) );
		expect( stableStringify( { at: date } ) ).toBe(
			JSON.stringify( { at: date } )
		);
	} );

	it( 'serializes sparse array holes as null', () => {
		expect( stableStringify( [ 1, , 3 ] ) ).toBe( '[1,null,3]' );
	} );
} );
