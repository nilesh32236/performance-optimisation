/**
 * HealthHeader — 3-ring Overview health summary.
 *
 * Replaces the 4-card stats strip on Dashboard Overview L1.
 * Shows Speed / Stability / Efficiency rings using calm WP-native tokens.
 *
 * @since NEXT
 */
import { __ } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faRocket,
	faShieldAlt,
	faLeaf,
	faBroom,
	faSearch,
	faMagic,
	faExclamationTriangle,
} from '@fortawesome/free-solid-svg-icons';
import LoadingSubmitButton from './common/LoadingSubmitButton';

const STATUS_COLOR = {
	good: 'var(--wppo-success, #059669)',
	needs_improvement: 'var(--wppo-warning, #d97706)',
	poor: 'var(--wppo-error, #dc2626)',
	needs_attention: 'var(--wppo-warning, #d97706)',
};

const STATUS_SCORE = {
	good: 90,
	needs_improvement: 65,
	poor: 35,
	needs_attention: 65,
};

/**
 * Circular ring with SVG progress.
 * @param {Object} root0
 * @param {string} root0.label
 * @param {Object} root0.icon
 * @param {string} root0.status
 * @param {number} root0.score
 * @param {string} root0.description
 */
const HealthRing = ( { label, icon, status, score, description } ) => {
	const numericScore =
		typeof score === 'number' ? score : STATUS_SCORE[ status ] ?? 0;
	const clampedScore = Math.max( 0, Math.min( 100, numericScore ) );
	const color = STATUS_COLOR[ status ] || STATUS_COLOR.good;
	// SVG circle: r=36, circ=2πr≈226
	const circ = 2 * Math.PI * 36;
	const offset = circ - ( clampedScore / 100 ) * circ;

	return (
		<div
			className={ `wppo-health-ring wppo-health-ring--${ status }` }
			role="status"
			aria-label={ `${ label }: ${ status } ${ clampedScore }` }
		>
			<div className="wppo-health-ring__visual" aria-hidden="true">
				<svg
					width="88"
					height="88"
					viewBox="0 0 88 88"
					className="wppo-health-ring__svg"
				>
					<circle
						cx="44"
						cy="44"
						r="36"
						fill="none"
						stroke="var(--wppo-border, #e2e8f0)"
						strokeWidth="7"
					/>
					<circle
						cx="44"
						cy="44"
						r="36"
						fill="none"
						stroke={ color }
						strokeWidth="7"
						strokeLinecap="round"
						strokeDasharray={ `${ circ }` }
						strokeDashoffset={ `${ offset }` }
						transform="rotate(-90 44 44)"
						className="wppo-health-ring__progress"
					/>
				</svg>
				<span className="wppo-health-ring__icon">
					<FontAwesomeIcon icon={ icon } aria-hidden="true" />
				</span>
			</div>
			<div className="wppo-health-ring__content">
				<span className="wppo-health-ring__label">{ label }</span>
				<span className="wppo-health-ring__value" style={ { color } }>
					{ clampedScore }%
				</span>
				<span className="wppo-health-ring__desc">{ description }</span>
			</div>
		</div>
	);
};

const HealthHeader = ( {
	speedStatus = 'good',
	stabilityStatus = 'good',
	efficiencyStatus = 'good',
	speedScore = null,
	stabilityScore = null,
	efficiencyScore = null,
	onPurgeAll,
	onRunScan,
	onApplyRecommended,
	isPurging = false,
	isScanning = false,
	isApplying = false,
} ) => {
	const hasActions = onPurgeAll || onRunScan || onApplyRecommended;
	// Conflict detection from wppoSettings.conflicts (System_Info::get_conflicts) — WP Rocket, FlyingPress, SG Optimizer, Autoptimize.
	const conflicts =
		typeof wppoSettings !== 'undefined' ? wppoSettings?.conflicts : null;
	const hasConflict = !! conflicts?.has_conflict;
	const conflictMessage =
		conflicts?.message ||
		__(
			'Another plugin handles minify — leave off. Why? Details → Advanced',
			'performance-optimisation'
		);
	const activeList = Array.isArray( conflicts?.active )
		? conflicts.active.join( ', ' )
		: '';

	return (
		<div className="wppo-health-header" aria-live="polite">
			{ hasConflict && (
				<div
					className="wppo-notice wppo-notice--warning wppo-mb-16"
					role="alert"
					aria-live="polite"
				>
					<FontAwesomeIcon
						icon={ faExclamationTriangle }
						aria-hidden="true"
					/>
					<span>
						{ conflictMessage }
						{ activeList ? ` (${ activeList })` : '' }
					</span>
					<a href="#advanced" className="wppo-link">
						{ __(
							'Details → Advanced',
							'performance-optimisation'
						) }
					</a>
				</div>
			) }
			<div className="wppo-health-header__intro">
				<h2 className="wppo-health-header__title">
					{ __( 'Site Health', 'performance-optimisation' ) }
				</h2>
				<p className="wppo-text-muted wppo-health-header__subtitle">
					{ __(
						'How your site is performing and what to improve next.',
						'performance-optimisation'
					) }
				</p>
			</div>
			<div className="wppo-health-header__rings">
				<HealthRing
					label={ __( 'Speed', 'performance-optimisation' ) }
					icon={ faRocket }
					status={ speedStatus }
					score={ speedScore }
					description={ __(
						'Page load and server response',
						'performance-optimisation'
					) }
				/>
				<HealthRing
					label={ __( 'Stability', 'performance-optimisation' ) }
					icon={ faShieldAlt }
					status={ stabilityStatus }
					score={ stabilityScore }
					description={ __(
						'Visual stability and interactivity',
						'performance-optimisation'
					) }
				/>
				<HealthRing
					label={ __( 'Efficiency', 'performance-optimisation' ) }
					icon={ faLeaf }
					status={ efficiencyStatus }
					score={ efficiencyScore }
					description={ __(
						'Efficient assets and caching',
						'performance-optimisation'
					) }
				/>
			</div>
			{ hasActions && (
				<div className="wppo-health-header__actions">
					{ onApplyRecommended && (
						<LoadingSubmitButton
							type="button"
							className="wppo-button wppo-button--primary"
							onClick={ onApplyRecommended }
							isLoading={ isApplying }
							label={
								<>
									<FontAwesomeIcon
										icon={ faMagic }
										aria-hidden="true"
										style={ { marginRight: '6px' } }
									/>
									{ __(
										'Apply Recommended',
										'performance-optimisation'
									) }
								</>
							}
							loadingLabel={ __(
								'Applying…',
								'performance-optimisation'
							) }
						/>
					) }
					{ onRunScan && (
						<LoadingSubmitButton
							type="button"
							className="wppo-button wppo-button--secondary"
							onClick={ onRunScan }
							isLoading={ isScanning }
							label={
								<>
									<FontAwesomeIcon
										icon={ faSearch }
										aria-hidden="true"
										style={ { marginRight: '6px' } }
									/>
									{ __(
										'Run Health Check',
										'performance-optimisation'
									) }
								</>
							}
							loadingLabel={ __(
								'Scanning…',
								'performance-optimisation'
							) }
						/>
					) }
					{ onPurgeAll && (
						<LoadingSubmitButton
							type="button"
							className="wppo-button wppo-button--secondary"
							onClick={ onPurgeAll }
							isLoading={ isPurging }
							label={
								<>
									<FontAwesomeIcon
										icon={ faBroom }
										aria-hidden="true"
										style={ { marginRight: '6px' } }
									/>
									{ __(
										'Purge All Cache',
										'performance-optimisation'
									) }
								</>
							}
							loadingLabel={ __(
								'Purging…',
								'performance-optimisation'
							) }
						/>
					) }
				</div>
			) }
		</div>
	);
};

export default HealthHeader;
