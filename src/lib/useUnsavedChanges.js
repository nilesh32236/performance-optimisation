import { useEffect, useContext, useMemo } from '@wordpress/element';
import UnsavedChangesContext from './UnsavedChangesContext';

/**
 * Stable stringify with sorted keys for deterministic dirty comparison.
 *
 * Circular references serialize as "[Circular]"; undefined/functions fall
 * back to JSON semantics so a circular settings value cannot crash every tab.
 *
 * @since 2.0.0
 * @param {*}     value  Value to stringify.
 * @param {Array} [seen] Internal circular guard.
 * @return {string} Stable JSON string.
 */
export const stableStringify = ( value, seen = [] ) => {
	if ( value === null || typeof value !== 'object' ) {
		const result = JSON.stringify( value );
		return result === undefined ? 'null' : result;
	}
	if ( seen.includes( value ) ) {
		return '"[Circular]"';
	}
	const nextSeen = [ ...seen, value ];
	if ( Array.isArray( value ) ) {
		return (
			'[' +
			value.map( ( v ) => stableStringify( v, nextSeen ) ).join( ',' ) +
			']'
		);
	}
	const keys = Object.keys( value ).sort();
	return (
		'{' +
		keys
			.map(
				( k ) =>
					JSON.stringify( k ) +
					':' +
					stableStringify( value[ k ], nextSeen )
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
 * @since 2.0.0
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
