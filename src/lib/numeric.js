/**
 * Shared PHP-parity numeric sanitizer (audit #1628).
 *
 * Single home for the whitespace/hex-guard + trunc/clamp logic previously
 * inlined in FileOptimization.js (normalizeRetries, parseGuardedNumber) and
 * ImageOptimization.js (coerceLongestEdge). A PHP is_numeric() behaviour
 * change now needs exactly one edit here instead of three coordinated ones.
 *
 * PHP parity rules mirrored from the original call sites:
 * - Booleans and arrays fail open (true must not coerce to 1 via Number()).
 * - Empty / whitespace-only strings fail open (Number('   ') === 0 would
 *   otherwise coerce to 0 instead of the fallback).
 * - Hex/binary/octal literals ('0x3', '0b101', '0o17') fail open: PHP
 *   is_numeric() rejects them while Number() parses them.
 * - Otherwise Number() (mirroring is_numeric() whole-value check),
 *   Math.trunc() (mirroring the (int) cast), then min/max clamp.
 *
 * @since NEXT
 * @param {*}      value              Raw input value.
 * @param {Object} [options]          Clamp/fallback options.
 * @param {number} [options.min]      Minimum accepted value (inclusive).
 * @param {number} [options.max]      Maximum accepted value (inclusive).
 * @param {*}      [options.fallback] Value returned when unparseable.
 * @return {*} Truncated + clamped integer, or fallback when unparseable.
 */
export const parseGuardedInt = ( value, options = {} ) => {
	const { min, max, fallback = 0 } = options ?? {};
	if ( typeof value === 'boolean' || Array.isArray( value ) ) {
		return fallback;
	}
	if ( typeof value === 'number' ) {
		if ( ! Number.isFinite( value ) ) {
			return fallback;
		}
		const truncated = Math.trunc( value );
		if ( typeof min === 'number' && truncated < min ) {
			return min;
		}
		if ( typeof max === 'number' && truncated > max ) {
			return max;
		}
		return truncated;
	}
	const s = String( value ?? '' ).trim();
	if ( '' === s ) {
		return fallback;
	}
	// PHP is_numeric() rejects hex/binary/octal while Number() parses
	// them, so guard explicitly to keep UI/server parity.
	if ( /^0[xXoObB]/.test( s ) ) {
		return fallback;
	}
	const n = Number( s );
	if ( ! Number.isFinite( n ) ) {
		return fallback;
	}
	const truncated = Math.trunc( n );
	if ( typeof min === 'number' && truncated < min ) {
		return min;
	}
	if ( typeof max === 'number' && truncated > max ) {
		return max;
	}
	return truncated;
};

export default parseGuardedInt;
