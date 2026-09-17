import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Pure suggestion helpers shared by SuggestionsPanel and AiPanel.
 *
 * Lives in lib (not in the SuggestionsPanel component module) so the
 * AiPanel lazy chunk does not pull the whole card component module in and
 * duplicate it across chunks (audit #1354).
 *
 * @since NEXT
 */

/**
 * Format a count-based custom unit with _n() plural handling.
 *
 * Custom units not listed here must arrive pre-formatted from the server.
 * Returns null when the value is not a finite number or the unit is not a
 * known countable noun.
 *
 * @since NEXT
 * @param {*}      value Metric value.
 * @param {string} unit  Unit label.
 * @return {string|null} Formatted display string, or null.
 */
export const formatCountableUnit = ( value, unit ) => {
	const count = Number( value );
	if ( ! Number.isFinite( count ) ) {
		return null;
	}
	// _n() plural selection expects integers: round first so floats do not
	// break complex plural languages.
	const rounded = Math.round( count );
	switch ( String( unit ).toLowerCase() ) {
		case 'requests':
			return sprintf(
				/* translators: %d: number of requests. */
				_n(
					'%d request',
					'%d requests',
					rounded,
					'performance-optimisation'
				),
				rounded
			);
		case 'assets':
			return sprintf(
				/* translators: %d: number of assets. */
				_n(
					'%d asset',
					'%d assets',
					rounded,
					'performance-optimisation'
				),
				rounded
			);
		case 'items':
			return sprintf(
				/* translators: %d: number of items. */
				_n(
					'%d item',
					'%d items',
					rounded,
					'performance-optimisation'
				),
				rounded
			);
		case 'images':
			return sprintf(
				/* translators: %d: number of images. */
				_n(
					'%d image',
					'%d images',
					rounded,
					'performance-optimisation'
				),
				rounded
			);
		case 'scripts':
			return sprintf(
				/* translators: %d: number of scripts. */
				_n(
					'%d script',
					'%d scripts',
					rounded,
					'performance-optimisation'
				),
				rounded
			);
		case 'resources':
			return sprintf(
				/* translators: %d: number of resources. */
				_n(
					'%d resource',
					'%d resources',
					rounded,
					'performance-optimisation'
				),
				rounded
			);
		default:
			return null;
	}
};

/**
 * Format a suggestion value for display.
 *
 * Custom units not covered by the explicit branches above or the countable
 * units must arrive pre-formatted from the server — the fallthrough renders
 * them via a translatable sprintf so translators can reorder/adapt units.
 *
 * @since NEXT
 * @param {*}      value Metric value.
 * @param {string} unit  Unit label.
 * @return {string} Formatted display string.
 */
export const formatValue = ( value, unit ) => {
	if ( value === null || value === undefined ) {
		return '—';
	}
	if ( unit === 'list' ) {
		if ( Array.isArray( value ) ) {
			return value.join( ', ' );
		}
		return String( value );
	}
	if ( unit === 'string' ) {
		return String( value );
	}
	if ( unit === 'boolean' ) {
		return value === 'pass'
			? __( 'Passing', 'performance-optimisation' )
			: __( 'Failing', 'performance-optimisation' );
	}
	if ( unit === 'header' ) {
		if ( value === 'none' ) {
			return __( 'None', 'performance-optimisation' );
		}
		// Always show Cache-Control value as-is, never translate the header text.
		return value;
	}
	if ( unit === 'encoding' ) {
		if ( value === 'none' ) {
			return __( 'None', 'performance-optimisation' );
		}
		// Map raw content-encoding values to human-readable form.
		const encodings = {
			br: 'Brotli',
			gzip: 'Gzip',
			deflate: 'Deflate',
			zstd: 'Zstd',
		};
		return encodings[ String( value ).toLowerCase() ] || value;
	}
	if ( unit === 'score' ) {
		const n = Number( value );
		if ( ! Number.isFinite( n ) ) {
			return String( value ?? '—' );
		}
		return sprintf(
			/* translators: %s: score value, e.g. "95 / 100". */
			__( '%s / 100', 'performance-optimisation' ),
			Math.round( n * 100 )
		);
	}
	if ( unit === '%' ) {
		const n = Number( value );
		if ( ! Number.isFinite( n ) ) {
			return String( value ?? '—' );
		}
		return sprintf(
			/* translators: %s: percentage value, e.g. "85.3%". */
			__( '%s%%', 'performance-optimisation' ),
			n.toFixed( 1 )
		);
	}
	if ( unit === 's' ) {
		const n = Number( value );
		if ( ! Number.isFinite( n ) ) {
			return String( value ?? '—' );
		}
		return sprintf(
			/* translators: %s: seconds value, e.g. "1.23s". */
			__( '%ss', 'performance-optimisation' ),
			n.toFixed( 2 )
		);
	}
	if ( unit === 'ms' ) {
		const n = Number( value );
		if ( ! Number.isFinite( n ) ) {
			return String( value ?? '—' );
		}
		return sprintf(
			/* translators: %s: milliseconds value, e.g. "500ms". */
			__( '%sms', 'performance-optimisation' ),
			Math.round( n )
		);
	}
	if ( typeof unit === 'string' ) {
		const countable = formatCountableUnit( value, unit );
		if ( countable !== null ) {
			return countable;
		}
	}
	return sprintf(
		/* translators: 1: value, 2: unit. */
		__( '%1$s %2$s', 'performance-optimisation' ),
		String( value ?? '—' ),
		String( unit ?? '' )
	);
};

/**
 * Build a stable key for a suggestion card.
 *
 * Suggestion objects carry no unique id, so compose the stable short fields
 * (metric, status, fix_action) plus the list index. The description is
 * deliberately excluded: hashing the longest field every render copies it
 * per row, and a description-text tweak would otherwise remount the card and
 * lose internal state.
 *
 * @since NEXT
 * @param {Object}        suggestion Suggestion object.
 * @param {number|string} [index]    Optional list index appended to disambiguate
 *                                   duplicates sharing all three fields.
 * @return {string} Stable composite key.
 */
export const suggestionKey = ( suggestion, index = null ) => {
	if ( ! suggestion ) {
		return `empty::${ index ?? '' }`;
	}
	const base = `${ suggestion.metric ?? '' }::${ suggestion.status ?? '' }::${
		suggestion.fix_action ?? ''
	}`;
	return index === null || index === undefined
		? base
		: `${ base }::${ index }`;
};
