import { useEffect, useContext, useMemo } from '@wordpress/element';
import UnsavedChangesContext from './UnsavedChangesContext';

/**
 * Stable stringify with sorted keys for deterministic dirty comparison.
 *
 * Circular references serialize as "[Circular]". Object keys holding
 * undefined/function values serialize as null (JSON.stringify would drop
 * those keys; here keys are always emitted so key sets stay comparable).
 * toJSON is honored like JSON.stringify. Values that cannot stringify
 * (BigInt, throwing toJSON) fall back to a stable sentinel so a bad settings
 * value can never throw out of useMemo and crash every tab.
 *
 * @since 2.0.0
 * @since NEXT Circular guard, toJSON support and never-throw hardening; exported for shared use.
 * @param {*} value Value to stringify.
 * @return {string} Stable JSON string.
 */
export const stableStringify = ( value ) => {
	const stringifyInner = ( val, seen ) => {
		if ( val !== null && typeof val === 'object' ) {
			if ( seen.includes( val ) ) {
				return '"[Circular]"';
			}
			const nextSeen = [ ...seen, val ];
			if ( typeof val.toJSON === 'function' ) {
				let json;
				try {
					json = val.toJSON();
				} catch {
					return '"[Unserializable]"';
				}
				return stringifyInner( json, nextSeen );
			}
			if ( Array.isArray( val ) ) {
				const parts = [];
				for ( let i = 0; i < val.length; i++ ) {
					parts.push( stringifyInner( val[ i ], nextSeen ) );
				}
				return '[' + parts.join( ',' ) + ']';
			}
			const keys = Object.keys( val ).sort();
			return (
				'{' +
				keys
					.map(
						( k ) =>
							JSON.stringify( k ) +
							':' +
							stringifyInner( val[ k ], nextSeen )
					)
					.join( ',' ) +
				'}'
			);
		}
		if ( typeof val === 'bigint' ) {
			return JSON.stringify( String( val ) );
		}
		try {
			const result = JSON.stringify( val );
			return result === undefined ? 'null' : result;
		} catch {
			try {
				return JSON.stringify( String( val ) );
			} catch {
				return '"[Unserializable]"';
			}
		}
	};
	return stringifyInner( value, [] );
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
	// Audit #1420: guard a missing provider — destructuring undefined
	// crashes every effect in every form using this hook.
	const unsavedContext = useContext( UnsavedChangesContext );
	const setIsDirty =
		unsavedContext && typeof unsavedContext.setIsDirty === 'function'
			? unsavedContext.setIsDirty
			: () => {};

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
			// On unmount the form is no longer visible; clear the flag only
			// if this form still owns the dirtiness (audit #1420: an
			// unconditional clear can wipe dirtiness owned by another
			// still-mounted form during Suspense transitions).
			if ( baselineKey !== settingsKey ) {
				setIsDirty( false );
			}
		};
	}, [ setIsDirty, baselineKey, settingsKey ] );
};

export default useUnsavedChanges;
