/**
 * Shared metric formatting helpers (single source for ms/percent/bytes).
 *
 * Unit suffixes compose through sprintf() with translatable patterns
 * (audit #1354) so UI-facing values localize like lib/util.js formatBytes.
 *
 * @since NEXT
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Format milliseconds.
 *
 * @since NEXT
 * @param {*} value Numeric value.
 * @return {string} Formatted value or '—' fallback.
 */
/**
 * True when a metric value counts as missing.
 *
 * Null, undefined, booleans, arrays, and blank strings render the '—'
 * fallback instead of a coerced number (e.g. true → 1, [] → 0).
 *
 * @since NEXT
 * @param {*} value Raw value.
 * @return {boolean} Whether to render the fallback.
 */
const isMissingMetric = ( value ) => {
	if ( value === null || value === undefined ) {
		return true;
	}
	if ( typeof value === 'boolean' ) {
		return true;
	}
	if ( Array.isArray( value ) ) {
		return true;
	}
	if ( typeof value === 'string' && '' === value.trim() ) {
		return true;
	}
	return false;
};

export const formatMs = ( value ) => {
	if ( isMissingMetric( value ) ) {
		return '—';
	}
	const num = Number( value );
	if ( ! Number.isFinite( num ) ) {
		return '—';
	}
	return sprintf(
		/* translators: %d: milliseconds value. */
		__( '%d ms', 'performance-optimisation' ),
		Math.round( num )
	);
};

/**
 * Format a 0-1 ratio or 0-100 number as percent.
 *
 * By default finite values in [0, 1] are treated as ratios (0.5 → "50%").
 * Pass an explicit `{ ratio: true|false }` to opt out of range-sniffing when
 * the caller holds a genuine small percent (e.g. 0.5%): `{ ratio: false }`
 * formats the value as-is, `{ ratio: true }` always scales by 100. Omitting
 * the option keeps the legacy heuristic for backward compatibility.
 *
 * @since NEXT
 * @param {*}       value           Numeric value (ratio 0-1 or percent 0-100).
 * @param {Object}  [options]       Formatting options.
 * @param {boolean} [options.ratio] Explicit ratio flag; omit for heuristic.
 * @return {string} Formatted value or '—' fallback.
 */
export const formatPercent = ( value, options = {} ) => {
	if ( isMissingMetric( value ) ) {
		return '—';
	}
	const num = Number( value );
	if ( ! Number.isFinite( num ) ) {
		return '—';
	}
	let pct;
	if ( typeof options.ratio === 'boolean' ) {
		pct = options.ratio ? num * 100 : num;
	} else {
		pct = num <= 1 && num >= 0 ? num * 100 : num;
	}
	return sprintf(
		/* translators: %s: percent value. */
		__( '%s%%', 'performance-optimisation' ),
		Math.round( pct * 10 ) / 10
	);
};

/**
 * Format bytes.
 *
 * Non-i18n background/metric counterpart to the localised `formatBytes`
 * in lib/util.js. UI code must use `formatBytes` (lib/util); background and
 * metric-only contexts (dashboards, logs, non-DOM summaries where
 * translation is handled elsewhere) use this helper. The two intentionally
 * differ in rounding/caps/fallback — do not mix them in one view.
 *
 * @since NEXT
 * @param {*} value Numeric value.
 * @return {string} Formatted value or '—' fallback.
 */
export const formatBytesShared = ( value ) => {
	if ( isMissingMetric( value ) ) {
		return '—';
	}
	const num = Number( value );
	if ( ! Number.isFinite( num ) || num < 0 ) {
		return '—';
	}
	if ( num < 1024 ) {
		return `${ Math.round( num ) } B`;
	}
	const units = [ 'KB', 'MB', 'GB', 'TB', 'PB' ];
	let size = num / 1024;
	let unit = 0;
	while ( size >= 1024 && unit < units.length - 1 ) {
		size /= 1024;
		unit++;
	}
	return `${ Math.round( size * 10 ) / 10 } ${ units[ unit ] }`;
};

/**
 * Compute a savings percent with guards (no NaN% on partial payloads).
 *
 * @since NEXT
 * @param {*} original  Original size.
 * @param {*} optimized Optimized size.
 * @return {number|null} Percent saved, or null when not computable.
 */
export const savingsPercent = ( original, optimized ) => {
	// Reject booleans/arrays before Number(): bare Number() coerces true → 1
	// and [5] → 5, which would return a phantom 100%/0% on partial payloads.
	for ( const raw of [ original, optimized ] ) {
		if ( typeof raw === 'boolean' || Array.isArray( raw ) ) {
			return null;
		}
	}
	const before = Number( original );
	const after = Number( optimized );
	if (
		! Number.isFinite( before ) ||
		! Number.isFinite( after ) ||
		before <= 0 ||
		after < 0 ||
		after > before
	) {
		return null;
	}
	return Math.round( ( ( before - after ) / before ) * 1000 ) / 10;
};
