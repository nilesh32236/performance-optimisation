/**
 * StatusBadge component.
 *
 * Renders a colour-coded pill badge for a metric status value.
 * Supports 'good', 'needs_improvement', and 'poor' variants using
 * --wppo- CSS custom properties defined in the abstracts layer.
 *
 * @since 1.5.0
 */

import { __ } from '@wordpress/i18n';

// Audit #1354: accept the lib/status.js vocabulary too ('warning' from
// scoreToStatus maps to the Needs Improvement badge); anything else
// falls back to 'unknown'.
const STATUS_ALIASES = {
	warning: 'needs_improvement',
};

const KNOWN_STATUSES = [ 'good', 'needs_improvement', 'poor' ];

const StatusBadge = ( { status } ) => {
	const normalized = Object.hasOwn( STATUS_ALIASES, status )
		? STATUS_ALIASES[ status ]
		: status;
	const safeStatus = KNOWN_STATUSES.includes( normalized )
		? normalized
		: 'unknown';
	const labelMap = {
		good: __( 'Good', 'performance-optimisation' ),
		needs_improvement: __(
			'Needs Improvement',
			'performance-optimisation'
		),
		poor: __( 'Poor', 'performance-optimisation' ),
		unknown: __( 'Unknown', 'performance-optimisation' ),
	};

	const label = labelMap[ safeStatus ];

	// Audit #1354: no redundant aria-label — the visible text is already
	// the accessible name.
	// Audit #1420: async status changes announced (visible text stays
	// the accessible name; no redundant aria-label).
	return (
		<span
			className={ `wppo-status-badge wppo-status-badge--${ safeStatus }` }
			role="status"
			aria-live="polite"
		>
			{ label }
		</span>
	);
};

export default StatusBadge;
