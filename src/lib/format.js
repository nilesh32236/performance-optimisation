/**
 * Shared metric formatting helpers (single source for ms/percent/bytes).
 *
 * @since NEXT
 */

/**
 * Format milliseconds.
 *
 * @since NEXT
 * @param {*} value Numeric value.
 * @return {string} Formatted value or '—' fallback.
 */
export const formatMs = ( value ) => {
	const num = Number( value );
	if ( ! Number.isFinite( num ) ) {
		return '—';
	}
	return `${ Math.round( num ) } ms`;
};

/**
 * Format a 0-1 ratio or 0-100 number as percent.
 *
 * Heuristic: finite values in [0, 1] are treated as ratios (0.5 → "50%").
 * Callers holding a genuine percent in that range (e.g. 0.5%) must scale to
 * basis points or pass a consistent 0-100 scale — there is no opt-out flag
 * by design so call sites stay comparable.
 *
 * @since NEXT
 * @param {*} value Numeric value (ratio 0-1 or percent 0-100).
 * @return {string} Formatted value or '—' fallback.
 */
export const formatPercent = ( value ) => {
	const num = Number( value );
	if ( ! Number.isFinite( num ) ) {
		return '—';
	}
	const pct = num <= 1 && num >= 0 ? num * 100 : num;
	return `${ Math.round( pct * 10 ) / 10 }%`;
};

/**
 * Format bytes.
 *
 * @since NEXT
 * @param {*} value Numeric value.
 * @return {string} Formatted value or '—' fallback.
 */
export const formatBytesShared = ( value ) => {
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
