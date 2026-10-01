/**
 * SiteStatusCard — the Overview's "what state is my site in" panel.
 *
 * Renders the rows from `overviewStatus.js` with the exact wording that model
 * produced, so the card cannot editorialise beyond what the backend actually
 * reported. It adds no number of its own.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

import ThresholdRail from '../common/ThresholdRail';
import { renderDetail, renderLabel } from './detailCopy';

import { ALL_STATUSES, needsAttention } from '../../lib/overviewStatus';

/**
 * Human label and tone for a status.
 *
 * @param {string} status A STATUS value.
 * @return {{label: string, tone: string}} Badge presentation.
 */
const badgeFor = ( status ) => {
	switch ( status ) {
		// The tones are the design system's own vocabulary
		// (`--good`/`--poor`/`--unknown`/`--warning` in
		// `_performance-audit.scss`). The first version invented
		// `success`/`error`/`neutral`, none of which exist, so "Working",
		// "Needs attention" and "Not set up" all rendered with no background,
		// no border and no colour — and the most important state on the page
		// was the least prominent.
		case 'healthy':
			return {
				label: __( 'Working', 'performance-optimisation' ),
				tone: 'good',
			};
		case 'attention':
			return {
				label: __( 'Needs attention', 'performance-optimisation' ),
				tone: 'poor',
			};
		case 'not-configured':
			return {
				label: __( 'Not set up', 'performance-optimisation' ),
				tone: 'unknown',
			};
		case 'unavailable':
			return {
				label: __( 'Unavailable', 'performance-optimisation' ),
				tone: 'warning',
			};
		default:
			return {
				label: __( 'Unknown', 'performance-optimisation' ),
				tone: 'warning',
			};
	}
};

/**
 * The site status card.
 *
 * @param {Object}   props                Component props.
 * @param {Array}    props.rows           Status rows from the status model.
 * @param {string}   props.overall        The overall verdict.
 * @param {boolean}  props.loading        Whether the data is still arriving.
 * @param {boolean}  props.failed         Whether the data could not be loaded.
 * @param {Function} props.onRetry        Re-request the data.
 * @param {boolean}  props.partialFailure Whether some, but not all,
 *                                        sources produced nothing.
 * @param {Function} [props.onNavigate]   Called with `{ area, view }` to navigate.
 * @return {Object} The card.
 */
export default function SiteStatusCard( {
	rows = [],
	overall = 'unknown',
	loading = false,
	failed = false,
	partialFailure = false,
	onRetry,
	onNavigate,
} ) {
	// One live region for the whole card, announcing only the verdict, so a
	// screen reader hears the outcome once rather than once per badge.
	const titleId = 'wppo-site-status-title';

	// One shared line, not one per row: two INP rows each repeating the same
	// advice is noise, and the rows already say what is missing.
	//
	// Scoped to **INP specifically**, because the sentence is about one metric.
	// An independent review reproduced a state this site can produce — an
	// unmeasured LCP and no INP row at all — where a predicate keyed on `vital-`
	// rendered "Responsiveness to taps and clicks…" under a card with no
	// responsiveness row, next to an LCP row ending `(PageSpeed lab scan)`,
	// which is the opposite of what that sentence asserts.
	//
	// `startsWith( 'vital-inp' )` covers both id shapes: the per-device
	// `vital-inp--desktop` and the flat `vital-inp` from the back-compat path.
	const hasUnmeasuredInp = rows.some(
		( row ) =>
			typeof row.id === 'string' &&
			row.id.startsWith( 'vital-inp' ) &&
			row.status === 'unknown'
	);

	if ( loading ) {
		return (
			<section className="wppo-card wppo-overview__card">
				<h2 className="wppo-card__title" id={ titleId }>
					{ __( 'Site status', 'performance-optimisation' ) }
				</h2>
				<div className="wppo-card__body">
					<p className="wppo-overview__placeholder" role="status">
						{ __(
							'Checking your site…',
							'performance-optimisation'
						) }
					</p>
				</div>
			</section>
		);
	}

	if ( failed ) {
		return (
			<section className="wppo-card wppo-overview__card">
				<h2 className="wppo-card__title" id={ titleId }>
					{ __( 'Site status', 'performance-optimisation' ) }
				</h2>
				<div className="wppo-card__body">
					<p className="wppo-overview__placeholder" role="status">
						{ __(
							'This information could not be loaded.',
							'performance-optimisation'
						) }
					</p>
					{ onRetry ? (
						<button
							type="button"
							className="wppo-button wppo-button--secondary"
							onClick={ () => onRetry?.( { force: true } ) }
						>
							{ __( 'Try again', 'performance-optimisation' ) }
						</button>
					) : null }
				</div>
			</section>
		);
	}

	const overallBadge = badgeFor( overall );

	return (
		<section
			className="wppo-card wppo-overview__card"
			aria-labelledby={ titleId }
		>
			<div className="wppo-overview__card-header">
				<h2 className="wppo-card__title" id={ titleId }>
					{ __( 'Site status', 'performance-optimisation' ) }
				</h2>
				<span
					className={ `wppo-status-badge wppo-status-badge--${ overallBadge.tone }` }
				>
					{ overallBadge.label }
				</span>
			</div>

			{ /* Announce the verdict once, not once per badge. */ }
			<p className="wppo-visually-hidden" role="status">
				{ `${ __( 'Overall status:', 'performance-optimisation' ) } ${
					overallBadge.label
				}` }
			</p>

			{ partialFailure && onRetry ? (
				<p className="wppo-overview__stale">
					{ __(
						'Some information could not be loaded.',
						'performance-optimisation'
					) }{ ' ' }
					<button
						type="button"
						className="wppo-button wppo-button--link"
						onClick={ () => onRetry?.( { force: true } ) }
					>
						{ __( 'Try again', 'performance-optimisation' ) }
					</button>
				</p>
			) : null }

			<div className="wppo-card__body">
				<ul className="wppo-overview__status-list">
					{ rows.map( ( row ) => {
						const badge = badgeFor(
							ALL_STATUSES.includes( row.status )
								? row.status
								: 'unknown'
						);
						return (
							<li
								key={ row.id }
								className={ `wppo-overview__status${
									needsAttention( row.status )
										? ' wppo-overview__status--attention'
										: ''
								}` }
							>
								<span
									className={ `wppo-status-badge wppo-status-badge--${ badge.tone }` }
								>
									{ badge.label }
								</span>
								<span className="wppo-overview__status-body">
									{ renderLabel( row ) ? (
										<strong className="wppo-overview__status-name">
											{ renderLabel( row ) }
										</strong>
									) : null }
									{ /* Only measurements get a rail. A cache being on or
									     a version pair being readable is a state, not a
									     position on a scale, and drawing a scale for it
									     would be decoration. */ }
									{ row.good !== undefined &&
									row.poor !== undefined ? (
										<ThresholdRail
											className="wppo-overview__status-rail"
											value={ row.raw }
											thresholds={ {
												good: row.good,
												poor: row.poor,
											} }
											display={ row.detailArgs?.value }
										/>
									) : null }
									<span className="wppo-overview__status-detail">
										{ renderDetail( row ) }
									</span>
								</span>
							</li>
						);
					} ) }
				</ul>
			</div>
			{ hasUnmeasuredInp ? (
				<p className="wppo-overview__action-hint">
					{ __(
						'Responsiveness to taps and clicks comes from real visitors, not a PageSpeed scan.',
						'performance-optimisation'
					) }{ ' ' }
					<button
						type="button"
						className="wppo-button wppo-button--link"
						onClick={ () => {
							if ( typeof onNavigate === 'function' ) {
								// A bare view id, which `resolveDestination`
								// maps to the area that owns it. Passing
								// `{ area, view }` here is a **silent
								// no-op**: the resolver does
								// `String( target )`, so an object becomes
								// `"[object Object]"`, matches no section, and
								// the click appears to do nothing.
								onNavigate( 'dashboard' );
							}
						} }
					>
						{ __(
							'See Real-user Web Vitals',
							'performance-optimisation'
						) }
					</button>
				</p>
			) : null }
		</section>
	);
}
