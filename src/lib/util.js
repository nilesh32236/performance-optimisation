/**
 * Default idle timeout (ms) applied when the delayJSIdleTimeout field is
 * empty, non-numeric, non-finite or non-positive.
 *
 * @since NEXT
 */
export const DELAY_JITTER_DEFAULT = 3000;
/**
 * Minimum clamped idle timeout (ms) for delayJSIdleTimeout.
 *
 * @since NEXT
 */
export const DELAY_JITTER_MIN = 500;
/**
 * Maximum clamped idle timeout (ms) for delayJSIdleTimeout.
 *
 * @since NEXT
 */
export const DELAY_JITTER_MAX = 20000;

/**
 * Generic form change handler for settings tabs.
 *
 * Updates the named field in the settings state. Checkbox inputs store
 * `checked`; number inputs store a parsed number (empty string preserved
 * except for delayJSIdleTimeout, which falls back to DELAY_JITTER_DEFAULT).
 * The `delayJSIdleTimeout` field is additionally clamped to
 * [DELAY_JITTER_MIN, DELAY_JITTER_MAX] so an idle-callback delay can neither
 * fire too eagerly nor stall the delayed scripts indefinitely.
 *
 * @since NEXT
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
				'delayJSIdleTimeout' === name ? DELAY_JITTER_DEFAULT : '';
		} else {
			const parsed = Number( value );
			if ( Number.isNaN( parsed ) ) {
				nextValue =
					'delayJSIdleTimeout' === name
						? DELAY_JITTER_DEFAULT
						: value;
			} else {
				nextValue = parsed;
				if ( 'delayJSIdleTimeout' === name ) {
					if ( ! Number.isFinite( nextValue ) || nextValue <= 0 ) {
						nextValue = DELAY_JITTER_DEFAULT;
					} else {
						nextValue = Math.min(
							DELAY_JITTER_MAX,
							Math.max( DELAY_JITTER_MIN, nextValue )
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
