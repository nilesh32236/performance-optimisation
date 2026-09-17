/**
 * Shared numeric normalizer mirroring PHP sanitizers (audit maintainability).
 *
 * FileOptimization.js carried four near-identical normalizers
 * (normalizeIdleTimeout, normalizeCcssMaxSize, normalizeRegressionThreshold,
 * normalizeRetries) duplicating the same boolean/array fail-open guard,
 * trim, hex-prefix guard, and Number()+clamp shape. A PHP-parity fix to one
 * copy but not the others let the UI commit a value the server sanitizes
 * differently — so the guards live here exactly once and the four exports
 * stay thin wrappers.
 *
 * @since NEXT
 * @param {*}       value                             Raw input value.
 * @param {Object}  options                           Normalization options.
 * @param {*}       options.defaultValue              Fail-open default.
 * @param {number}  [options.min]                     Minimum clamp (inclusive, via Math.max).
 * @param {number}  [options.max]                     Maximum clamp (inclusive, via Math.min).
 * @param {boolean} [options.truncate=true]           Whether to Math.trunc() the result.
 * @param {boolean} [options.rejectNonPositive=false] Fail open on n <= 0.
 * @return {*} Normalized number or the default.
 */
export const clampNumeric = (
	value,
	{
		defaultValue,
		min = -Infinity,
		max = Infinity,
		truncate = true,
		rejectNonPositive = false,
	} = {}
) => {
	if ( typeof value === 'boolean' || Array.isArray( value ) ) {
		return defaultValue;
	}
	const s = String( value ?? '' ).trim();
	// PHP is_numeric() rejects hex/binary/octal while Number() parses them,
	// so guard explicitly to keep UI/server parity.
	if ( '' === s || /^0[xXoObB]/.test( s ) ) {
		return defaultValue;
	}
	const n = typeof value === 'number' ? value : Number( s );
	if ( ! Number.isFinite( n ) ) {
		return defaultValue;
	}
	if ( rejectNonPositive && n <= 0 ) {
		return defaultValue;
	}
	const t = truncate ? Math.trunc( n ) : n;
	if ( t < min || t > max ) {
		// Range-guard callers (regression threshold) fail open; clamped
		// callers pass min/max through Math.min/Math.max instead — see below.
		return defaultValue;
	}
	return Math.min( max, Math.max( min, t ) );
};

/**
 * Clamp a numeric input into [min, max] with fail-open default.
 *
 * Variant of clampNumeric() for callers that clamp out-of-range values
 * instead of failing open (idle timeout, retries).
 *
 * @since NEXT
 * @param {*}       value                             Raw input value.
 * @param {Object}  options                           Normalization options.
 * @param {*}       options.defaultValue              Fail-open default.
 * @param {number}  [options.min=-Infinity]           Minimum clamp.
 * @param {number}  [options.max=Infinity]            Maximum clamp.
 * @param {boolean} [options.rejectNonPositive=false] Fail open on n <= 0.
 * @return {*} Normalized number or the default.
 */
export const clampNumericRange = (
	value,
	{
		defaultValue,
		min = -Infinity,
		max = Infinity,
		rejectNonPositive = false,
	} = {}
) => {
	if ( typeof value === 'boolean' || Array.isArray( value ) ) {
		return defaultValue;
	}
	const s = String( value ?? '' ).trim();
	if ( '' === s || /^0[xXoObB]/.test( s ) ) {
		return defaultValue;
	}
	const n = typeof value === 'number' ? value : Number( s );
	if ( ! Number.isFinite( n ) ) {
		return defaultValue;
	}
	if ( rejectNonPositive && n <= 0 ) {
		return defaultValue;
	}
	return Math.min( max, Math.max( min, Math.trunc( n ) ) );
};
