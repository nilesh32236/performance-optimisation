/**
 * HealthHeader — Overview health summary (Tailwind).
 * Mobile-first: stacks rings on mobile, 3-col on md+.
 * Truthful status only when scores are null (no fake 92).
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
import Button from './ui/Button';
import Badge from './ui/Badge';

const STATUS_COLOR = {
	good: 'var(--wppo-success, #059669)',
	needs_improvement: 'var(--wppo-warning, #d97706)',
	poor: 'var(--wppo-error, #dc2626)',
	needs_attention: 'var(--wppo-warning, #d97706)',
};

const STATUS_LABEL = {
	good: 'Good',
	needs_improvement: 'Needs attention',
	poor: 'Needs review',
	needs_attention: 'Needs attention',
};

const HealthRing = ( { label, icon, status, score, description } ) => {
	const hasScore = typeof score === 'number';
	const clampedScore = hasScore
		? Math.max( 0, Math.min( 100, score ) )
		: null;
	const color = STATUS_COLOR[ status ] || STATUS_COLOR.good;
	const circ = 2 * Math.PI * 36;
	const offset = hasScore ? circ - ( clampedScore / 100 ) * circ : 0;
	let tone = 'warning';
	if ( status === 'good' ) {
		tone = 'good';
	} else if ( status === 'poor' ) {
		tone = 'error';
	}

	return (
		<div
			className="tw-flex tw-flex-col tw-items-center tw-text-center tw-p-4 sm:tw-p-5 tw-bg-[var(--wppo-bg-card-surface)] tw-border tw-border-[var(--wppo-border)] tw-rounded-[14px] tw-transition hover:tw-border-[var(--wppo-border-hover)] hover:tw-shadow-sm"
			role="status"
			aria-label={ `${ label }: ${ STATUS_LABEL[ status ] || status }${
				hasScore ? ` ${ clampedScore }%` : ''
			}` }
		>
			<div
				className="tw-relative tw-w-[72px] tw-h-[72px] sm:tw-w-[88px] sm:tw-h-[88px] tw-mb-2.5 sm:tw-mb-3"
				aria-hidden="true"
			>
				<svg
					width="88"
					height="88"
					viewBox="0 0 88 88"
					className="tw-block"
				>
					<circle
						cx="44"
						cy="44"
						r="36"
						fill="none"
						stroke="var(--wppo-border, #e2e8f0)"
						strokeWidth="7"
					/>
					{ hasScore && (
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
							className="tw-transition-[stroke-dashoffset] tw-duration-600 tw-ease-out"
						/>
					) }
					{ ! hasScore && (
						<circle
							cx="44"
							cy="44"
							r="36"
							fill="none"
							stroke={ color }
							strokeWidth="7"
							strokeLinecap="round"
							opacity="0.18"
						/>
					) }
				</svg>
				<span className="tw-absolute tw-inset-0 tw-flex tw-items-center tw-justify-center tw-text-[18px] tw-text-[var(--wppo-text-muted)]">
					<FontAwesomeIcon icon={ icon } aria-hidden="true" />
				</span>
			</div>
			<div className="tw-flex tw-flex-col tw-items-center tw-gap-1.5 tw-w-full tw-min-w-0">
				<span className="tw-text-[11px] tw-font-bold tw-tracking-[0.08em] tw-uppercase tw-text-[var(--wppo-text-muted)]">
					{ label }
				</span>
				{ hasScore ? (
					<span
						className="tw-text-[22px] tw-font-extrabold tw-tracking-tight tw-leading-none"
						style={ { color } }
					>
						{ clampedScore }%
					</span>
				) : (
					<span
						className="tw-text-[15px] tw-font-bold tw-leading-none tw-mt-0.5"
						style={ { color } }
					>
						{ STATUS_LABEL[ status ] || status }
					</span>
				) }
				{ hasScore && (
					<Badge tone={ tone } className="tw-mt-1">
						{ STATUS_LABEL[ status ] || status }
					</Badge>
				) }
				<span className="tw-text-[12px] sm:tw-text-[13px] tw-leading-[1.5] tw-text-[var(--wppo-text-muted)] tw-break-words tw-mt-1">
					{ description }
				</span>
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
	const conflicts =
		typeof wppoSettings !== 'undefined' ? wppoSettings?.conflicts : null;
	const hasConflict = !! conflicts?.has_conflict;
	const conflictMessage =
		conflicts?.message ||
		__(
			'Another plugin handles this optimization. We recommend leaving it off.',
			'performance-optimisation'
		);
	const activeList = Array.isArray( conflicts?.active )
		? conflicts.active.join( ', ' )
		: '';

	return (
		<div
			className="tw-w-full tw-max-w-full tw-min-w-0 tw-bg-[var(--wppo-bg-card)] tw-border tw-border-[var(--wppo-border)] tw-rounded-[16px] tw-shadow-[var(--wppo-shadow-card)] tw-p-5 sm:tw-p-6 tw-mb-5 sm:tw-mb-6 "
			aria-live="polite"
		>
			{ hasConflict && (
				<div
					className="tw-flex tw-flex-wrap tw-gap-3 tw-items-start tw-p-3.5 tw-rounded-[10px] tw-border tw-bg-[var(--wppo-warning-bg)] tw-border-[var(--wppo-warning-border)] tw-mb-5"
					role="alert"
					aria-live="polite"
				>
					<FontAwesomeIcon
						icon={ faExclamationTriangle }
						className="tw-mt-0.5 tw-text-[var(--wppo-warning)] tw-flex-shrink-0"
						aria-hidden="true"
					/>
					<div className="tw-flex-1 tw-min-w-0 tw-text-[13.5px] tw-leading-5">
						<span className="tw-font-semibold">
							{ conflictMessage }
						</span>
						{ activeList ? (
							<span className="tw-text-[var(--wppo-text-muted)]">
								{ ' ' }
								({ activeList })
							</span>
						) : null }
						<a
							href="#advanced"
							className="tw-ml-2 tw-font-semibold tw-text-[var(--wppo-primary)] hover:tw-underline tw-text-[13px]"
						>
							{ __(
								'Details → Advanced',
								'performance-optimisation'
							) }
						</a>
					</div>
				</div>
			) }
			<div className="tw-flex tw-flex-col tw-gap-1.5 tw-mb-5">
				<h2 className="tw-text-[18px] sm:tw-text-[20px] tw-font-bold tw-tracking-tight tw-text-[var(--wppo-text-main)] tw-leading-tight">
					{ __( 'Site Health', 'performance-optimisation' ) }
				</h2>
				<p className="tw-text-[13.5px] sm:tw-text-[14px] tw-leading-6 tw-text-[var(--wppo-text-muted)] tw-max-w-[65ch] tw-break-words tw-min-w-0">
					{ __(
						'How your site is performing and what to improve next.',
						'performance-optimisation'
					) }
				</p>
			</div>
			<div className="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-4 sm:tw-gap-4 tw-mb-5">
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
				<div className="tw-flex tw-flex-col sm:tw-flex-row tw-gap-2.5 tw-pt-4 tw-border-t tw-border-[var(--wppo-border)]">
					{ onApplyRecommended && (
						<Button
							variant="primary"
							onClick={ onApplyRecommended }
							isLoading={ isApplying }
							loadingLabel={ __(
								'Applying…',
								'performance-optimisation'
							) }
						>
							<FontAwesomeIcon
								icon={ faMagic }
								aria-hidden="true"
							/>
							{ __(
								'Apply Recommended',
								'performance-optimisation'
							) }
						</Button>
					) }
					{ onRunScan && (
						<Button
							variant="secondary"
							onClick={ onRunScan }
							isLoading={ isScanning }
							loadingLabel={ __(
								'Scanning…',
								'performance-optimisation'
							) }
						>
							<FontAwesomeIcon
								icon={ faSearch }
								aria-hidden="true"
							/>
							{ __(
								'Run Health Check',
								'performance-optimisation'
							) }
						</Button>
					) }
					{ onPurgeAll && (
						<Button
							variant="secondary"
							onClick={ onPurgeAll }
							isLoading={ isPurging }
							loadingLabel={ __(
								'Purging…',
								'performance-optimisation'
							) }
						>
							<FontAwesomeIcon
								icon={ faBroom }
								aria-hidden="true"
							/>
							{ __(
								'Purge All Cache',
								'performance-optimisation'
							) }
						</Button>
					) }
				</div>
			) }
		</div>
	);
};

export default HealthHeader;
