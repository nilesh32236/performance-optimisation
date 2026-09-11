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
 * Format a byte count as a localised human-readable size string.
 *
 * Single shared implementation (replaces the per-component copies in
 * AutoloadedOptions, PerformanceAudit and ImageOptimizationCard). Units are
 * passed through __() and composed via sprintf() so translators can reorder
 * words and localise the unit.
 *
 * @since 2.0.0
 * @param {number} bytes Byte count.
 * @return {string} Formatted size (e.g. "1.5 KB").
 */
export const formatBytes = ( bytes ) => {
	const num = Number( bytes );
	if ( ! Number.isFinite( num ) || num <= 0 ) {
		return sprintf(
			/* translators: 1: size value, 2: unit. */
			__( '%1$s %2$s', 'performance-optimisation' ),
			'0',
			__( 'B', 'performance-optimisation' )
		);
	}
	const units = [
		__( 'B', 'performance-optimisation' ),
		__( 'KB', 'performance-optimisation' ),
		__( 'MB', 'performance-optimisation' ),
		__( 'GB', 'performance-optimisation' ),
	];
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
	return sprintf(
		/* translators: 1: size value, 2: unit. */
		__( '%1$s %2$s', 'performance-optimisation' ),
		value,
		units[ index ]
	);
};
