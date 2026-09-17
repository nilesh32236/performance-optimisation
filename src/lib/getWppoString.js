import { __ } from '@wordpress/i18n';

/**
 * Resolve a first-paint SPA string with i18n priority (issue #1333).
 *
 * The `wppoSettings.translations` map is server-translated via MO and always
 * available; `wp_set_script_translations()` JSON is best-effort (async load
 * can fail or lag first paint). Prefer the map, then `__()`, then English.
 *
 * @since NEXT
 * @param {string} key      Translation key in wppoSettings.translations.
 * @param {string} fallback English fallback string.
 * @return {string} Localized string.
 */
const getWppoString = ( key, fallback ) => {
	if (
		typeof wppoSettings !== 'undefined' &&
		wppoSettings &&
		wppoSettings.translations &&
		'string' === typeof wppoSettings.translations[ key ] &&
		wppoSettings.translations[ key ]
	) {
		return wppoSettings.translations[ key ];
	}
	// Static extraction is covered by the PHP-side __() calls that build
	// wppoSettings.translations; the variable fallback here only exercises
	// the runtime wp_set_script_translations JSON (same pattern as main.js).
	// eslint-disable-next-line @wordpress/i18n-no-variables
	return __( fallback, 'performance-optimisation' );
};

export default getWppoString;
