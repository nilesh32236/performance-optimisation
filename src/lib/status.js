/**
 * Shared audit status helpers (single source for score → status mapping).
 *
 * @since NEXT
 */

/**
 * Status levels used across audit panels.
 *
 * @since NEXT
 * @type {string[]}
 */
export const STATUS_LEVELS = Object.freeze( [
	'good',
	'warning',
	'poor',
	'unknown',
] );

/**
 * Map a 0-100 score to a status level.
 *
 * @since NEXT
 * @param {*} score Numeric score.
 * @return {string} good|warning|poor|unknown.
 */
export const scoreToStatus = ( score ) => {
	const num = Number( score );
	if ( ! Number.isFinite( num ) ) {
		return 'unknown';
	}
	if ( num >= 90 ) {
		return 'good';
	}
	if ( num >= 50 ) {
		return 'warning';
	}
	return 'poor';
};

/**
 * Map a boolean to a status level.
 *
 * @since NEXT
 * @param {*} value Boolean value.
 * @return {string} good|poor|unknown.
 */
export const boolToStatus = ( value ) => {
	if ( value === true ) {
		return 'good';
	}
	if ( value === false ) {
		return 'poor';
	}
	return 'unknown';
};
