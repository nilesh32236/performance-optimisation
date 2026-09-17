import { useEffect, useContext, useMemo, useRef } from '@wordpress/element';
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
 * Safe to render outside `UnsavedChangesContext.Provider`: a missing
 * provider (or a value without `setIsDirty`) degrades to a no-op.
 *
 * @since 2.0.0
 * @since NEXT No longer throws outside the provider; falls back to a no-op.
 * @param {Object} settings Current form state.
 * @param {Object} baseline Baseline derived from props / defaults.
 */
const useUnsavedChanges = ( settings, baseline ) => {
	// Safe outside UnsavedChangesContext.Provider (e.g. isolated Jest
	// renders or future embeds): fall back to a no-op instead of throwing
	// so consumers never need a provider wrapper just to mount.
	const unsavedCtx = useContext( UnsavedChangesContext );
	const setIsDirty = useMemo( () => {
		const setter = unsavedCtx?.setIsDirty;
		return typeof setter === 'function' ? setter : () => {};
	}, [ unsavedCtx ] );
	const ownedRef = useRef( false );

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
		ownedRef.current = dirty;
		setIsDirty( dirty );
	}, [ baselineKey, settingsKey, setIsDirty ] );

	useEffect( () => {
		return () => {
			// On unmount the form is no longer visible; if we were the
			// dirty owner, clear the flag so a newly mounted tab starts clean.
			// The App guard captures pending navigation synchronously before
			// unmount, so clearing here does not race with the confirm dialog.
			if ( ownedRef.current ) {
				setIsDirty( false );
			}
		};
	}, [ setIsDirty ] );
};

export default useUnsavedChanges;
