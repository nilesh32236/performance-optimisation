/**
 * Shared settings-response commit contract (P3-019).
 *
 * One bounded choke point for syncing server settings payloads into the
 * shared `wppoSettings.settings` global. Previously only `update_settings`
 * / `restore_settings` responses were committed (inside apiCall), while
 * `import_settings`, `sandbox_promote`, `safe_mode` (all full-map payloads)
 * and `apply_preset` (nested `{ preset, settings, diff }`) silently missed
 * the cache — leaving sibling tabs on stale snapshots until reload.
 *
 * Callers that save from request data (patchSettingsCache with the outgoing
 * slice) must prefer this contract: commit the *server* payload first and
 * only fall back to a tab patch when the response carries no committable
 * map, so server-side normalization is never clobbered by the request echo.
 *
 * Fail-safe by design: unknown actions, unsuccessful envelopes, and
 * non-object payloads are a no-op returning false. No routing, no store,
 * no polling — just the response commit plus the deferred-save/async
 * workflow helpers consumed by useSaveSettings/useAsyncWorkflow.
 *
 * @since NEXT
 */

/**
 * Actions whose `data` payload is the full merged `wppo_settings` tab-map.
 *
 * Contract verified in PHP: `update_settings` / `restore_settings`
 * (`includes/Admin/class-rest-settings.php` via `rest_send_response`),
 * `import_settings` (same file, `$response_settings`), `sandbox_promote`
 * (`$options`), and `safe_mode` (`includes/Admin/class-rest.php`,
 * `$options`) all respond with the full settings map.
 *
 * @since NEXT
 * @type {string[]}
 */
export const FULL_SETTINGS_MAP_ACTIONS = [
	'update_settings',
	'restore_settings',
	'import_settings',
	'sandbox_promote',
	'safe_mode',
];

/**
 * Actions whose `data` payload nests the full settings map under `settings`.
 *
 * Contract verified in PHP: `apply_preset`
 * (`includes/Admin/class-rest.php::apply_optimization_preset`) responds
 * with `{ preset, settings, diff }` where `settings` is the full map.
 *
 * @since NEXT
 * @type {string[]}
 */
export const NESTED_SETTINGS_ACTIONS = [ 'apply_preset' ];

/**
 * Whether a value looks like a full settings tab-map.
 *
 * Plain objects only (never arrays, strings, or null). An empty object is
 * rejected: committing `{}` would wipe every sibling tab from the shared
 * cache on a degraded/empty response.
 *
 * @since NEXT
 * @param {*} payload Candidate payload (typically `response.data`).
 * @return {boolean} True when the payload is committable as a full map.
 */
export const isFullSettingsMap = ( payload ) => {
	if ( ! payload || typeof payload !== 'object' ) {
		return false;
	}
	if ( Array.isArray( payload ) ) {
		return false;
	}
	return Object.keys( payload ).length > 0;
};

/**
 * Resolve the committable settings map for a settings response envelope.
 *
 * Returns the full-map payload for full-map actions, the nested
 * `data.settings` map for nested actions, or null when the envelope
 * carries nothing committable (fail-safe: callers fall back to a tab
 * patch or leave the cache untouched).
 *
 * @since NEXT
 * @param {string} action Response action (e.g. 'import_settings').
 * @param {*}      data   Response `data` payload (envelope `.data`, not the envelope itself).
 * @return {Object|null} Committable settings map, or null.
 */
export const resolveSettingsPayload = ( action, data ) => {
	if ( ! data || typeof data !== 'object' || Array.isArray( data ) ) {
		return null;
	}
	if ( FULL_SETTINGS_MAP_ACTIONS.includes( action ) ) {
		return isFullSettingsMap( data ) ? data : null;
	}
	if ( NESTED_SETTINGS_ACTIONS.includes( action ) ) {
		const nested = data.settings;
		return isFullSettingsMap( nested ) ? nested : null;
	}
	return null;
};

/**
 * Commit a settings response payload to the shared global cache.
 *
 * Replaces `wppoSettings.settings` (frozen, like commitSettingsCache) so
 * every component reading the live global lands on the same snapshot.
 * Returns whether a commit happened so callers can skip their
 * request-echo tab patch when the server payload won.
 *
 * @since NEXT
 * @param {string} action Response action (e.g. 'sandbox_promote').
 * @param {*}      data   Response `data` payload (envelope `.data`, not the envelope itself).
 * @return {boolean} True when the global cache was replaced.
 */
export const commitSettingsResponse = ( action, data ) => {
	const payload = resolveSettingsPayload( action, data );
	if ( ! payload ) {
		return false;
	}
	if ( typeof wppoSettings === 'undefined' || ! wppoSettings ) {
		return false;
	}
	wppoSettings.settings = Object.freeze( { ...payload } );
	return true;
};
