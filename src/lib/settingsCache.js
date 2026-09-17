/**
 * Shared `wppoSettings.settings` cache (audit maintainability).
 *
 * Split out of `lib/apiRequest.js`, which mixed transport + nonce retry,
 * settings-cache mutation, scan validation, and endpoint wrappers in one
 * 660-line module. `apiRequest.js` re-exports everything here so existing
 * imports keep working.
 *
 * @since NEXT
 */

/**
 * Safe accessor for the global wppoSettings object injected by PHP via
 * wp_localize_script. Optional chaining alone does not protect against an
 * undeclared global (ReferenceError), so every direct read must go through
 * the typeof guard centralised here.
 *
 * Note: refreshNonce() and apiCall() intentionally keep their own
 * typeof wppoSettings guards instead of routing through this helper. Those
 * paths must throw when the global is absent and mutate the live global
 * (wppoSettings.nonce / wppoSettings.settings); this helper returns a
 * fallback {} which would mask the absent-global case and break the live
 * mutation contract.
 *
 * @since 2.0.0
 * @since NEXT Accepts an optional dot-path with fallback (getWppoSettings('settings.cache.enabled', false)).
 * @param {string} [path]     Optional dot-separated path (e.g. 'settings.cache').
 * @param {*}      [fallback] Optional fallback returned when the global or path is absent.
 * @return {*} The global settings object (or path value), or fallback/{} when absent.
 */
export const getWppoSettings = ( path, fallback = {} ) => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return fallback;
	}
	if ( typeof path !== 'string' || ! path ) {
		return wppoSettings;
	}
	const hasOwn = ( obj, key ) =>
		Object.hasOwn
			? Object.hasOwn( obj, key )
			: Object.prototype.hasOwnProperty.call( obj, key );
	let current = wppoSettings;
	for ( const key of path.split( '.' ) ) {
		if (
			! current ||
			typeof current !== 'object' ||
			! hasOwn( current, key )
		) {
			return fallback;
		}
		current = current[ key ];
	}
	return current === undefined ? fallback : current;
};

/**
 * Commit a settings payload to the shared `wppoSettings.settings` cache.
 *
 * Single choke point for the frozen-global mutation previously inlined in
 * apiCall() and copied across AiPanel/EdgeCachePanel/LlmsPanel. Freezing
 * keeps every component reading the live global on the same snapshot.
 * The top-level object and each nested tab object are frozen (one level
 * deep) to match patchSettingsCache(), so no path can mutate shared
 * global state that another path assumes frozen.
 *
 * Freeze contract is intentionally one level deep: objects nested deeper
 * than a tab (e.g. settings.cache.nested) remain mutable, so callers must
 * replace rather than mutate nested state to keep snapshots consistent.
 *
 * Contract verified in includes/class-rest.php: both `update_settings` and
 * `restore_settings` respond with the full merged `wppo_settings` option
 * (via `send_response( $response_settings )` / `send_response( $merged_settings )`),
 * so a full replace here is correct. If an endpoint ever echoes only the
 * saved tab slice, callers must use patchSettingsCache() instead.
 *
 * @since NEXT
 * @param {*} payload Resolved settings payload (typically `data.data`).
 * @return {void}
 */
export const commitSettingsCache = ( payload ) => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return;
	}
	if ( payload && typeof payload === 'object' ) {
		for ( const key of Object.keys( payload ) ) {
			const tab = payload[ key ];
			if ( tab && typeof tab === 'object' ) {
				Object.freeze( tab );
			}
		}
		wppoSettings.settings = Object.freeze( payload );
	}
};

/**
 * Patch a single settings tab into the shared `wppoSettings.settings` cache.
 *
 * Components that save one tab optimistically merge the saved slice into the
 * live global so sibling panels see the new value without a reload. The
 * merged tab and the top-level object are both frozen like commitSettingsCache().
 *
 * @since NEXT
 * @param {string} tab   Settings tab key (e.g. 'ai_adaptive').
 * @param {Object} patch Tab settings to merge.
 * @return {void}
 */
export const patchSettingsCache = ( tab, patch ) => {
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return;
	}
	if ( typeof tab !== 'string' || ! tab ) {
		return;
	}
	if ( ! patch || typeof patch !== 'object' ) {
		return;
	}
	const current =
		wppoSettings.settings && typeof wppoSettings.settings === 'object'
			? wppoSettings.settings
			: {};
	const base =
		current[ tab ] && typeof current[ tab ] === 'object'
			? current[ tab ]
			: {};
	wppoSettings.settings = Object.freeze( {
		...current,
		[ tab ]: Object.freeze( { ...base, ...patch } ),
	} );
};
