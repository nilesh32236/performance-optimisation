import { __, sprintf } from '@wordpress/i18n';

/**
 * Default idle timeout (ms) applied when the delayJSIdleTimeout field is
 * empty, non-numeric, non-finite or non-positive.
 *
 * @since 2.0.0
 */
export const DELAY_IDLE_TIMEOUT_DEFAULT = 3000;
/**
 * Minimum clamped idle timeout (ms) for delayJSIdleTimeout.
 *
 * @since 2.0.0
 */
export const DELAY_IDLE_TIMEOUT_MIN = 500;
/**
 * Maximum clamped idle timeout (ms) for delayJSIdleTimeout.
 *
 * @since 2.0.0
 */
export const DELAY_IDLE_TIMEOUT_MAX = 20000;
/**
 * Generic form change handler for settings tabs.
 *
 * Updates the named field in the settings state. Checkbox inputs store
 * `checked`; number inputs store a parsed number (empty string preserved
 * except for delayJSIdleTimeout, which falls back to DELAY_IDLE_TIMEOUT_DEFAULT).
 * The `delayJSIdleTimeout` field is additionally clamped to
 * [DELAY_IDLE_TIMEOUT_MIN, DELAY_IDLE_TIMEOUT_MAX] so an idle-callback delay can neither
 * fire too eagerly nor stall the delayed scripts indefinitely.
 *
 * @since 2.0.0
 * @param {Function} setSettings React state setter for the settings object.
 * @return {Function} Change event handler.
 */
export const handleChange = ( setSettings ) => ( e ) => {
	const { name, type, value, checked } = e.target;

	// Guard the settings key shape: the name comes from rendered inputs, but
	// rejecting empty/non-string and prototype-pollution keys keeps future
	// dynamic inputs from corrupting settings state shape.
	if (
		typeof name !== 'string' ||
		'' === name ||
		[ '__proto__', 'constructor', 'prototype' ].includes( name )
	) {
		return;
	}

	let nextValue;
	if ( 'checkbox' === type ) {
		nextValue = checked;
	} else if ( 'number' === type || 'delayJSIdleTimeout' === name ) {
		if ( '' === value ) {
			nextValue =
				'delayJSIdleTimeout' === name ? DELAY_IDLE_TIMEOUT_DEFAULT : '';
		} else {
			const parsed = Number( value );
			if ( Number.isNaN( parsed ) ) {
				nextValue =
					'delayJSIdleTimeout' === name
						? DELAY_IDLE_TIMEOUT_DEFAULT
						: value;
			} else {
				nextValue = parsed;
				if ( 'delayJSIdleTimeout' === name ) {
					if ( ! Number.isFinite( nextValue ) || nextValue <= 0 ) {
						nextValue = DELAY_IDLE_TIMEOUT_DEFAULT;
					} else {
						nextValue = Math.min(
							DELAY_IDLE_TIMEOUT_MAX,
							Math.max( DELAY_IDLE_TIMEOUT_MIN, nextValue )
						);
					}
				}
			}
		}
	} else {
		nextValue = value;
	}

	setSettings( ( prevState ) => ( {
		...prevState,
		[ name ]: nextValue,
	} ) );
};

/**
 * Normalize a newline-delimited textarea value.
 *
 * Sanitize/process_urls normalization can produce arrays, so arrays are
 * joined instead of dropped (issue #1217). Single shared implementation
 * replacing the per-component toDelayLines/toExcludeLines copies.
 *
 * @since 2.3.0
 * @param {*} value Raw value.
 * @return {string} Textarea-safe string.
 */
export const toTextLines = ( value ) => {
	if ( typeof value === 'string' ) {
		return value;
	}
	// A finite numeric scalar stringifies instead of clearing: the backend
	// contract for textarea keys is string|array, but a stray number must
	// never wipe the field to ''.
	if ( typeof value === 'number' && Number.isFinite( value ) ) {
		return String( value );
	}
	if ( Array.isArray( value ) ) {
		return value
			.filter(
				( item ) => typeof item === 'string' || typeof item === 'number'
			)
			.map( String )
			.join( '\n' );
	}
	return '';
};

/**
 * Cached translated byte units + format pattern (page-load locale).
 *
 * formatBytes() is called per template per render from status loops, so the
 * __() dictionary lookups are done once per page load instead of 4-6 times
 * per invocation. The WP admin locale is fixed for the page lifetime, so a
 * module-level cache is safe.
 *
 * @since 2.3.0
 * @type {{pattern: string|null, units: string[]|null}}
 */
const cachedByteUnits = { pattern: null, units: null };

const getByteUnits = () => {
	if ( ! cachedByteUnits.pattern || ! cachedByteUnits.units ) {
		/* translators: 1: size value, 2: unit. */
		cachedByteUnits.pattern = __( '%1$s %2$s', 'performance-optimisation' );
		cachedByteUnits.units = [
			__( 'B', 'performance-optimisation' ),
			__( 'KB', 'performance-optimisation' ),
			__( 'MB', 'performance-optimisation' ),
			__( 'GB', 'performance-optimisation' ),
		];
	}
	return cachedByteUnits;
};

/**
 * Format a byte count as a localised human-readable size string.
 *
 * Localised canonical UI formatter (units via __(), composed with
 * sprintf() so translators can reorder words). The non-i18n
 * `formatBytesShared` in lib/format.js is the background/metric
 * counterpart (TB/PB caps, '—' fallback); UI code must use this
 * function — see also formatBytesShared.
 *
 * @since 2.0.0
 * @param {number} bytes Byte count.
 * @return {string} Formatted size (e.g. "1.5 KB").
 */

export const formatBytes = ( bytes ) => {
	const { pattern, units } = getByteUnits();
	const num = Number( bytes );
	// Only an actual zero renders as "0 B": non-finite, negative, boolean,
	// array and other invalid input renders the '—' fallback (matching
	// formatBytesShared) so callers can distinguish missing data from zero.
	if (
		typeof bytes === 'boolean' ||
		Array.isArray( bytes ) ||
		( typeof bytes === 'string' && '' === bytes.trim() ) ||
		! Number.isFinite( num ) ||
		num < 0
	) {
		return '—';
	}
	if ( 0 === num ) {
		/* translators: 1: size value, 2: unit. */
		return sprintf( pattern, '0', units[ 0 ] );
	}
	const index = Math.max(
		0,
		Math.min(
			Math.floor( Math.log( num ) / Math.log( 1024 ) ),
			units.length - 1
		)
	);
	const value =
		index === 0
			? String( Math.round( num ) )
			: ( num / 1024 ** index ).toFixed( 1 );
	/* translators: 1: size value, 2: unit. */
	return sprintf( pattern, value, units[ index ] );
};
