/**
 * ThresholdRail component.
 *
 * A performance metric is not pass/fail. It is a position on a scale with real
 * published boundaries, and the useful question is not "am I green" but "how
 * much room do I have". A status pill answers the first; this answers the
 * second.
 *
 * The rail draws three tinted zones (good / needs-work / poor) across a domain
 * and plots the current value as a solid marker. Thresholds are supplied by the
 * caller because they are metric-specific and already published: LCP
 * 2500/4000 ms, CLS 0.1/0.25, INP 200/500 ms, TTFB 200/500 ms.
 *
 * An unmeasured metric renders an empty dashed track. It is never drawn with a
 * zero marker and never given a signal colour — "not measured" is an idle
 * state, not a failure.
 *
 * Accessibility: the scale is decorative and marked `aria-hidden`. The
 * meaning is carried by a visually hidden sentence naming the value and its
 * position, so a screen reader hears "388 milliseconds, within the good range"
 * rather than being read a graphic it cannot perceive.
 *
 * @since x-release-please-version
 */

import { __ } from '@wordpress/i18n';

/**
 * Resolve a value against its thresholds. Returns the idle tone when unmeasured.
 *
 * @param {number} value           The measured value.
 * @param {Object} thresholds      `{ good, poor }` thresholds, or null.
 * @param {number} thresholds.good Value at or below which the metric is good.
 * @param {number} thresholds.poor Value above which the metric is failing.
 * @return {string} One of `good`, `warn`, `bad`, `idle`.
 */
export const toneFor = ( value, thresholds ) => {
	if (
		value === null ||
		value === undefined ||
		Number.isNaN( value ) ||
		! thresholds
	) {
		return 'idle';
	}
	if ( value <= thresholds.good ) {
		return 'good';
	}
	return value <= thresholds.poor ? 'warn' : 'bad';
};

const TONE_LABEL = {
	good: __( 'within the good range', 'performance-optimisation' ),
	warn: __( 'outside the target', 'performance-optimisation' ),
	bad: __( 'failing', 'performance-optimisation' ),
	idle: __( 'not measured yet', 'performance-optimisation' ),
};

const clamp = ( n, min, max ) => Math.min( Math.max( n, min ), max );

/**
 * A metric plotted against its published thresholds.
 *
 * @param {Object} props            Component props.
 * @param {number} props.value      The measured value. `null`/`undefined`/`NaN`
 *                                  render the idle state.
 * @param {Object} props.thresholds `{ good, poor }` — the values at which the
 *                                  metric stops being good and starts failing.
 * @param {Object} props.domain     Optional `{ min, max }` for the visible
 *                                  scale. Defaults to `0` → `poor * 1.5`, so a
 *                                  failing value still has somewhere to sit.
 * @param {string} props.display    The value as already formatted by the
 *                                  caller, e.g. "388 ms". Used for the
 *                                  accessible sentence.
 * @param {string} props.label      Optional metric name, prefixed to that
 *                                  sentence.
 * @param {string} props.className  Extra class for the rail.
 * @return {Element} The rail.
 */
const ThresholdRail = ( {
	value,
	thresholds,
	domain,
	display,
	label,
	className = '',
} ) => {
	const tone = toneFor( value, thresholds );
	const measured = tone !== 'idle';

	// The domain is the visible extent of the scale. Without one, extend a
	// little past the poor boundary so a failing value still has somewhere to
	// sit rather than pinning to the end of the track.
	const min = domain?.min ?? 0;
	const max = domain?.max ?? ( thresholds ? thresholds.poor * 1.5 : 1 );
	const span = max - min || 1;

	const pct = ( n ) => `${ clamp( ( n - min ) / span, 0, 1 ) * 100 }%`;

	const zones = thresholds
		? [
				{ key: 'good', width: pct( thresholds.good ) },
				{
					key: 'warn',
					width: pct( thresholds.poor - thresholds.good ),
				},
				{ key: 'bad', width: pct( max - thresholds.poor ) },
		  ]
		: [];

	// "388 milliseconds, within the good range." The unit is baked into the
	// caller's display string when one is supplied, so do not repeat it.
	const summary = measured
		? `${ display ?? value } — ${ TONE_LABEL[ tone ] }`
		: TONE_LABEL.idle;

	return (
		<span
			className={ `wppo-rail wppo-rail--${ tone } ${ className }`.trim() }
			data-tone={ tone }
		>
			<span className="wppo-rail__graphic" aria-hidden="true">
				<span className="wppo-rail__track">
					{ measured &&
						zones.map( ( zone ) => (
							<span
								key={ zone.key }
								className={ `wppo-rail__zone wppo-rail__zone--${ zone.key }` }
								style={ { width: zone.width } }
							/>
						) ) }
					{ measured && (
						<span
							className="wppo-rail__marker"
							style={ { left: pct( value ) } }
						/>
					) }
				</span>
			</span>
			<span className="wppo-visually-hidden">
				{ label ? `${ label }: ${ summary }` : summary }
			</span>
		</span>
	);
};

export default ThresholdRail;
