/**
 * TrendLine — zero-dependency SVG sparkline component.
 *
 * Renders a <polyline> scaled to the data's min/max range inside a fixed
 * viewBox so the chart always fills its container regardless of data values.
 *
 * @since 1.7.0
 */

const TrendLine = ( {
	data = [],
	color = 'var(--wppo-color-primary, #2563eb)',
	height = 60,
	strokeWidth = 2,
} ) => {
	const translations = wppoSettings.translations;

	// Need at least 2 points to draw a line.
	if ( ! Array.isArray( data ) || data.length < 2 ) {
		return (
			<p className="wppo-trendline-empty">
				{ translations.notEnoughData ||
					'Not enough data points to show a trend.' }
			</p>
		);
	}

	const WIDTH = 100;
	const HEIGHT = 100;
	const PADDING = 5;

	const min = Math.min( ...data );
	const max = Math.max( ...data );
	const range = max - min || 1; // Avoid division by zero when all values equal.

	const points = data
		.map( ( value, index ) => {
			const x =
				PADDING +
				( index / ( data.length - 1 ) ) * ( WIDTH - PADDING * 2 );
			// Invert Y so higher values appear at the top.
			const y =
				PADDING +
				( 1 - ( value - min ) / range ) * ( HEIGHT - PADDING * 2 );
			return `${ x },${ y }`;
		} )
		.join( ' ' );

	return (
		<svg
			className="wppo-trendline"
			viewBox="-5 -5 110 110"
			preserveAspectRatio="none"
			style={ { width: '100%', height } }
			aria-hidden="true"
		>
			<polyline
				points={ points }
				fill="none"
				stroke={ color }
				strokeWidth={ strokeWidth }
				strokeLinejoin="round"
				strokeLinecap="round"
			/>
		</svg>
	);
};

export default TrendLine;
