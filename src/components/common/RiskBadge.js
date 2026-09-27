/**
 * RiskBadge component.
 *
 * A tiny "Aggressive" pill marking toggles that can break a site with one
 * click (Delay JS, Defer JS, Remove Unused CSS). Intentionally separate from
 * StatusBadge: that component owns the metric vocabulary
 * (good/needs_improvement/poor/unknown) and must not gain a risk variant.
 * This pill reuses the `.wppo-status-badge--poor` styling so no new palette
 * is introduced.
 *
 * @since NEXT
 *
 * @param {Object} [props]       Component props.
 * @param {string} [props.label] Override for the badge text. Defaults to the
 *                               translated "Aggressive" string.
 * @return {Element} Badge element.
 */
import { __ } from '@wordpress/i18n';

const RiskBadge = ( { label } ) => (
	<span className="wppo-status-badge wppo-status-badge--poor wppo-risk-badge">
		{ label || __( 'Aggressive', 'performance-optimisation' ) }
	</span>
);

export default RiskBadge;
