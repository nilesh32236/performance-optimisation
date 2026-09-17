/**
 * Shared audit status helpers (single source for score → status mapping).
 *
 * @since NEXT
 */

/**
 * Status levels used across audit panels.
 *
 * Public API (pinned by status.test.js): import this constant when wiring
 * new UI to status values instead of hard-coding strings.
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
 * Map a lower-is-better metric to a status level via good/poor thresholds.
 *
 * Parameterised counterpart to scoreToStatus() for metrics such as LCP/CLS
 * where smaller values are better (previously duplicated as `numericStatus`
 * in PerformanceAudit.js).
 *
 * @since NEXT
 * @param {*}      value Raw metric value.
 * @param {number} good  Upper bound for 'good'.
 * @param {number} poor  Upper bound for 'needs_improvement' ('poor' above).
 * @return {string} good|needs_improvement|poor|unknown.
 */
export const thresholdStatus = ( value, good, poor ) => {
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
