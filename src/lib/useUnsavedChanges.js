import { useEffect, useContext, useMemo } from '@wordpress/element';
import UnsavedChangesContext from './UnsavedChangesContext';

/**
 * Stable stringify with sorted keys for deterministic dirty comparison.
 *
 * @since NEXT
 * @param {*} value Value to stringify.
 * @return {string} Stable JSON string.
 */
const stableStringify = ( value ) => {
	if ( value === null || typeof value !== 'object' ) {
		return JSON.stringify( value );
	}
	if ( Array.isArray( value ) ) {
		return '[' + value.map( stableStringify ).join( ',' ) + ']';
	}
	const keys = Object.keys( value ).sort();
	return (
		'{' +
		keys
			.map(
				( k ) =>
					JSON.stringify( k ) + ':' + stableStringify( value[ k ] )
			)
			.join( ',' ) +
		'}'
	);
};

/**
 * Hook to report dirty state to the global unsaved-changes context.
 *
 * Compares the current form `settings` against a `baseline` (typically
 * derived from props `options`). When they differ the global `isDirty`
 * flag is set, which the App shell uses for tab-switch and beforeunload
 * guards.
 *
 * @since NEXT
 * @param {Object} settings Current form state.
 * @param {Object} baseline Baseline derived from props / defaults.
 */
const useUnsavedChanges = ( settings, baseline ) => {
	const { setIsDirty } = useContext( UnsavedChangesContext );

	const baselineKey = useMemo(
		() => stableStringify( baseline ),
		[ baseline ]
	);
	const settingsKey = useMemo(
		() => stableStringify( settings ),
		[ settings ]
	);

	useEffect( () => {
		const dirty = baselineKey !== settingsKey;
		setIsDirty( dirty );
	}, [ baselineKey, settingsKey, setIsDirty ] );

	useEffect( () => {
		return () => {
			// On unmount the form is no longer visible; if we were the
			// dirty owner, clear the flag so a newly mounted tab starts clean.
			// The App guard captures pending navigation synchronously before
			// unmount, so clearing here does not race with the confirm dialog.
			setIsDirty( false );
		};
	}, [ setIsDirty ] );
};

export default useUnsavedChanges;
