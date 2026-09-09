import { renderHook, act } from '@testing-library/react';
import useUnsavedChanges from '../useUnsavedChanges';
import UnsavedChangesContext from '../UnsavedChangesContext';

const renderWithDirtyFlag = ( settings, baseline ) => {
	const setIsDirty = jest.fn();
	let currentDirty = false;
	const wrapper = ( { children } ) => (
		<UnsavedChangesContext.Provider
			value={ {
				isDirty: currentDirty,
				setIsDirty: ( value ) => {
					currentDirty = value;
					setIsDirty( value );
				},
			} }
		>
			{ children }
		</UnsavedChangesContext.Provider>
	);
	const utils = renderHook( () => useUnsavedChanges( settings, baseline ), {
		wrapper,
	} );
	return { ...utils, setIsDirty };
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
		const setIsDirty = jest.fn();
		let currentDirty = false;
		const wrapper = ( { children } ) => (
			<UnsavedChangesContext.Provider
				value={ {
					isDirty: currentDirty,
					setIsDirty: ( value ) => {
						currentDirty = value;
						setIsDirty( value );
					},
				} }
			>
				{ children }
			</UnsavedChangesContext.Provider>
		);
		const { rerender } = renderHook(
			( { settings } ) => useUnsavedChanges( settings, { a: 1 } ),
			{ wrapper, initialProps: { settings: { a: 1 } } }
		);
		expect( setIsDirty ).toHaveBeenLastCalledWith( false );
		rerender( { settings: { a: 2 } } );
		expect( setIsDirty ).toHaveBeenLastCalledWith( true );
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
