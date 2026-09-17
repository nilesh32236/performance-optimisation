/**
 * Woo self-test check list (audit maintainability).
 *
 * Dashboard carried 5 near-identical Woo check-list blocks (checks,
 * fragment, editor, preload, cart) sharing the same li/getWooCheckCopy/
 * remediation shape and differing only by kind label — a tri-state or
 * fail-closed remediation fix applied to 4/5 copies left one list
 * mislabeling FAIL as pass. The row shape lives here once; the 5 groups
 * map through it.
 *
 * @since NEXT
 */

import { __ } from '@wordpress/i18n';
import { getWooCheckState, getWooRuleRemediation } from '../../lib/wooSelfTest';

/**
 * Copy for a Woo self-test check row with explicit tri-state handling.
 *
 * A malformed entry with pass missing must never render FAIL copy with no
 * evidence — it renders an inconclusive label instead.
 *
 * @since NEXT
 * @param {*}      pass     Raw pass value from a check entry.
 * @param {string} passCopy Pass label.
 * @param {string} failCopy Fail label.
 * @return {string} Row label.
 */
export const getWooCheckCopy = ( pass, passCopy, failCopy ) => {
	const state = getWooCheckState( pass );
	if ( state === 'pass' ) {
		return passCopy;
	}
	if ( state === 'fail' ) {
		return failCopy;
	}
	return __( 'Inconclusive (re-run)', 'performance-optimisation' );
};

/**
 * One group of Woo self-test check rows.
 *
 * @since NEXT
 * @param {Object}  props                   Props.
 * @param {Array}   props.items             Check entries.
 * @param {string}  [props.labelField]      Entry field rendered as the label.
 * @param {string}  [props.keyFallback]     Key fallback when the label is absent.
 * @param {string}  props.kind              Remediation kind (route/fragment/editor/preload/cart).
 * @param {string}  props.passCopy          Pass label.
 * @param {string}  props.failCopy          Fail label.
 * @param {string}  [props.heading]         Optional group heading.
 * @param {string}  [props.ariaLabel]       aria-label for the list (defaults to heading).
 * @param {boolean} [props.requireNonEmpty] Hide when the list is empty.
 * @return {Element|null} Check list or null.
 */
const WooCheckList = ( {
	items,
	labelField = 'path',
	keyFallback = 'check',
	kind,
	passCopy,
	failCopy,
	heading,
	ariaLabel,
	requireNonEmpty = false,
} ) => {
	if ( ! Array.isArray( items ) ) {
		return null;
	}
	if ( requireNonEmpty && items.length === 0 ) {
		return null;
	}
	const list = (
		<ul
			className="wppo-woo-self-test"
			aria-label={ ariaLabel || heading || undefined }
		>
			{ items.map( ( check, index ) => (
				<li
					key={ `${
						check?.[ labelField ] ?? keyFallback
					}-${ index }` }
				>
					<span>{ check?.[ labelField ] }</span>
					{ ' — ' }
					<span>
						{ getWooCheckCopy( check?.pass, passCopy, failCopy ) }
					</span>
					{ check?.pass === false && (
						<>
							{ ' — ' }
							<span className="wppo-text-muted wppo-text-small">
								{ getWooRuleRemediation( kind, check?.pass ) }
							</span>
						</>
					) }
				</li>
			) ) }
		</ul>
	);
	if ( ! heading ) {
		return list;
	}
	return (
		<>
			<p className="wppo-text-muted wppo-text-small">{ heading }</p>
			{ list }
		</>
	);
};

export default WooCheckList;
