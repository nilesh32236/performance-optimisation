/**
 * SignalChip — the four-state vocabulary for the redesigned admin.
 *
 * The existing `StatusBadge` answers "how did this score" with
 * Good / Needs Improvement / Poor / Unknown. That vocabulary does not fit a
 * setting, which is not scored — it is either on, off, or not yet configured.
 * Forcing a setting into "Poor" because it is disabled is exactly the kind of
 * thing that makes an admin screen feel accusatory.
 *
 * So this is a deliberately separate, small component with a vocabulary that
 * matches what it labels:
 *
 * | tone | default label | means |
 * |---|---|---|
 * | `good` | Working | measured, or configured and healthy |
 * | `warn` | Needs attention | configured but outside the target |
 * | `bad`  | Not working | configured and failing |
 * | `idle` | Not set up | **not a fault** — never configured, or never measured |
 *
 * `idle` is the point of the component. An unconfigured feature and an
 * unmeasured metric are both idle, and both must stay visually quiet. Rendering
 * "you have not turned this on" in red trains people to ignore red.
 *
 * @since x-release-please-version
 */

import { __ } from '@wordpress/i18n';

const DEFAULT_LABELS = {
	good: __( 'Working', 'performance-optimisation' ),
	warn: __( 'Needs attention', 'performance-optimisation' ),
	bad: __( 'Not working', 'performance-optimisation' ),
	idle: __( 'Not set up', 'performance-optimisation' ),
};

const KNOWN_TONES = Object.keys( DEFAULT_LABELS );

/**
 * A small tone-coded chip.
 *
 * @param {Object}  props       Component props.
 * @param {string}  props.tone  One of `good`, `warn`, `bad`, `idle`.
 * @param {string}  props.label Override the default word for the tone. Use this
 *                              for metric states, where `idle` reads better as
 *                              "Not measured yet".
 * @param {boolean} props.small Render at the compact size.
 * @param {boolean} props.live  Announce changes. Off by default: a grid of N
 *                              chips must not create N live regions.
 * @return {Element} The chip.
 */
export default function SignalChip( {
	tone = 'idle',
	label,
	small = false,
	live = false,
} ) {
	const safeTone = KNOWN_TONES.includes( tone ) ? tone : 'idle';
	const text = label ?? DEFAULT_LABELS[ safeTone ];

	return (
		<span
			className={ `wppo-signal-chip wppo-signal-chip--${ safeTone }${
				small ? ' wppo-signal-chip--sm' : ''
			}` }
			{ ...( live ? { role: 'status', 'aria-live': 'polite' } : {} ) }
		>
			{ text }
		</span>
	);
}
