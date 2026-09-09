import { renderHook, act } from '@testing-library/react';
import useUnsavedChanges from '../useUnsavedChanges';
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
