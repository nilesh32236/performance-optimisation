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

const KNOWN_STATUSES = [ 'good', 'needs_improvement', 'poor' ];

const StatusBadge = ( { status } ) => {
	const safeStatus = KNOWN_STATUSES.includes( status ) ? status : 'unknown';
	const labelMap = {
		good: __( 'Good', 'performance-optimisation' ),
		needs_improvement: __(
			'Needs Improvement',
			'performance-optimisation'
		),
		poor: __( 'Poor', 'performance-optimisation' ),
		unknown: __( 'Unknown', 'performance-optimisation' ),
	};

	const label = labelMap[ safeStatus ] || safeStatus;

	return (
		<span
			className={ `wppo-status-badge wppo-status-badge--${ safeStatus }` }
			aria-label={ label }
		>
			{ label }
		</span>
	);
};

export default StatusBadge;
