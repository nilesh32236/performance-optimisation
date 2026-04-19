/**
 * StatusBadge component.
 *
 * Renders a colour-coded pill badge for a metric status value.
 * Supports 'good', 'needs_improvement', and 'poor' variants using
 * --wppo- CSS custom properties defined in the abstracts layer.
 *
 * @since 1.5.0
 */

const StatusBadge = ( { status } ) => {
	const t = wppoSettings.translations;

	const labelMap = {
		good: t.good || 'Good',
		needs_improvement: t.needsImprovement || 'Needs Improvement',
		poor: t.poor || 'Poor',
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
