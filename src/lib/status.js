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
/**
 * Reserved status vocabulary (audit #1401): currently no in-repo importer.
 * Kept (not deleted) as the documented contract for future panels and
 * external consumers — import this instead of inventing new level strings.
 *
 * @since NEXT
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
/**
 * Map a metric value to a status level with custom thresholds.
 *
 * Parameterised sibling of scoreToStatus() for panels whose good/poor
 * cutoffs differ per metric (audit #1401): single home so threshold
 * changes cannot silently skip a panel.
 *
 * @since NEXT
 * @param {*}      value The metric value.
 * @param {number} good  Upper bound for 'good'.
 * @param {number} poor  Lower bound for 'poor'.
 * @return {string} good|needs_improvement|poor|unknown.
 */
export const numericStatus = ( value, good, poor ) => {
	const num = Number( value );
	if ( ! Number.isFinite( num ) ) {
		return 'unknown';
	}
	if ( num <= good ) {
		return 'good';
	}
	if ( num <= poor ) {
		return 'needs_improvement';
	}
	return 'poor';
};

export const boolToStatus = ( value ) => {
	if ( value === true ) {
		return 'good';
	}
	if ( value === false ) {
		return 'poor';
	}
	return 'unknown';
};
