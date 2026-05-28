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

const StatusBadge = ( { status } ) => {
	const labelMap = {
		good: __( 'Good', 'performance-optimisation' ),
		needs_improvement: __(
			'Needs Improvement',
			'performance-optimisation'
		),
		poor: __( 'Poor', 'performance-optimisation' ),
	};

	const label = labelMap[ status ] || status;

	return (
		<span
			className={ `wppo-status-badge wppo-status-badge--${ status }` }
			aria-label={ label }
		>
			{ label }
		</span>
	);
};

export default StatusBadge;
