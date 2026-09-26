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
 * @param {Object}   props         Component props.
 * @param {Array}    props.rows    Status rows from the status model.
 * @param {string}   props.overall The overall verdict.
 * @param {boolean}  props.loading Whether the data is still arriving.
 * @param {boolean}  props.failed  Whether the data could not be loaded.
 * @param {Function} props.onRetry Re-request the data.
 * @return {Object} The card.
 */
export default function SiteStatusCard( {
	rows = [],
	overall = 'unknown',
	loading = false,
	failed = false,
	onRetry,
} ) {
	// One live region for the whole card, announcing only the verdict, so a
	// screen reader hears the outcome once rather than once per badge.
	const titleId = 'wppo-site-status-title';

	if ( loading ) {
		return (
			<section className="wppo-card wppo-overview__card">
				<h2 className="wppo-card__title" id={ titleId }>
					{ __( 'Site status', 'performance-optimisation' ) }
				</h2>
				<p className="wppo-overview__placeholder" role="status">
					{ __( 'Checking your site…', 'performance-optimisation' ) }
				</p>
			</section>
		);
	}

	if ( failed ) {
		return (
			<section className="wppo-card wppo-overview__card">
				<h2 className="wppo-card__title" id={ titleId }>
					{ __( 'Site status', 'performance-optimisation' ) }
				</h2>
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
						onClick={ onRetry }
					>
						{ __( 'Try again', 'performance-optimisation' ) }
					</button>
				) : null }
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
								{ row.label ? (
									<strong className="wppo-overview__status-name">
										{ row.label }
									</strong>
								) : null }
								<span className="wppo-overview__status-detail">
									{ row.detail }
								</span>
							</span>
						</li>
					);
				} ) }
			</ul>
		</section>
	);
}
