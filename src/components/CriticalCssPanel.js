import { __, sprintf } from '@wordpress/i18n';
import { memo, useMemo, useState } from '@wordpress/element';
import {
	faCheckCircle,
	faExclamationTriangle,
	faClock,
	faTimesCircle,
} from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { formatBytes } from '../lib/util';
import { getErrorLogMessage } from '../lib/apiRequest';

const READY_CONFIG = {
	icon: faCheckCircle,
	className: 'wppo-badge--success',
	label: __( 'Generated', 'performance-optimisation' ),
};

// Length of the hash prefix shown when an entry has no label.
const HASH_PREFIX_LEN = 8;

// Factory for the pending-style badges (queued/pending/processing share one
// icon + className; only the label differs). Single source so a future
// icon/class change needs one edit.
const pendingConfig = ( label ) => ( {
	icon: faClock,
	className: 'wppo-badge--info',
	label,
} );

const STATUS_CONFIG = {
	ready: READY_CONFIG,
	// Copy (issue #1274 review): done === ready today, but a shared
	// reference would let a future mutation hit both entries.
	done: { ...READY_CONFIG },
	queued: pendingConfig( __( 'Queued', 'performance-optimisation' ) ),
	pending: pendingConfig( __( 'Pending', 'performance-optimisation' ) ),
	processing: pendingConfig( __( 'Processing', 'performance-optimisation' ) ),
	skipped: {
		icon: faExclamationTriangle,
		className: 'wppo-badge--warning',
		label: __( 'Skipped', 'performance-optimisation' ),
	},
	failed: {
		icon: faTimesCircle,
		className: 'wppo-badge--error',
		label: __( 'Failed', 'performance-optimisation' ),
	},
	none: {
		icon: faExclamationTriangle,
		className: 'wppo-badge--warning',
		label: __( 'Not Generated', 'performance-optimisation' ),
	},
};

/**
 * Normalize a status entry into a null-safe shape.
 *
 * Malformed payloads (null, numbers, missing status) fall back to 'none'
 * with a hash-derived label instead of throwing.
 *
 * @since NEXT
 * @param {*} hash  Status key.
 * @param {*} entry Raw entry value.
 * @return {{statusKey: string, label: string, size: number|null, truncated: boolean}} Normalized entry.
 */
export const normalizeCcssEntry = ( hash, entry ) => {
	const safeHash = typeof hash === 'string' ? hash : String( hash ?? '' );
	const fallbackLabel =
		( safeHash ? safeHash.substring( 0, HASH_PREFIX_LEN ) : '?' ) + '…';
	if ( typeof entry === 'string' ) {
		return {
			statusKey: entry || 'none',
			label: fallbackLabel,
			size: null,
			truncated: false,
		};
	}
	if ( ! entry || typeof entry !== 'object' ) {
		return {
			statusKey: 'none',
			label: fallbackLabel,
			size: null,
			truncated: false,
		};
	}
	return {
		statusKey:
			typeof entry.status === 'string' && entry.status
				? entry.status
				: 'none',
		label:
			typeof entry.label === 'string' && entry.label
				? entry.label
				: fallbackLabel,
		size: Number.isFinite( entry.size ) ? entry.size : null,
		truncated: !! entry.truncated,
	};
};

/**
 * Resolve the badge config for a status key with an own-property check.
 *
 * Plain property access would resolve inherited keys like '__proto__' to
 * Object.prototype (truthy) instead of the intended `none` fallback.
 *
 * @since NEXT
 * @param {*} statusKey Raw status key.
 * @return {{icon: *, className: string, label: string}} Badge config.
 */
export const statusConfigFor = ( statusKey ) => {
	const hasOwn = Object.hasOwn
		? Object.hasOwn( STATUS_CONFIG, statusKey )
		: Object.prototype.hasOwnProperty.call( STATUS_CONFIG, statusKey );
	return hasOwn ? STATUS_CONFIG[ statusKey ] : STATUS_CONFIG.none;
};

const CriticalCssPanel = ( {
	status = {},
	onRegenerate,
	onRegenerateSingle,
} ) => {
	const [ isRegenerating, setIsRegenerating ] = useState( false );
	const [ singleBusy, setSingleBusy ] = useState( null );
	// No local useNotice/NoticeBanner here (issue #1274 review): the
	// parent (FileOptimization via withNotification) is the single
	// feedback owner; this panel logs locally and rethrows.

	const handleRegenerate = async () => {
		setIsRegenerating( true );
		try {
			await onRegenerate();
		} catch ( err ) {
			// Single-owner feedback (mirrors handleRegenerateSingle): the
			// parent handleRegenerateCss via withNotification owns the
			// banner, so log locally and rethrow instead of notifying a
			// second time for the same click.
			console.error(
				'Failed to regenerate CCSS',
				getErrorLogMessage( err )
			);
			throw err;
		} finally {
			setIsRegenerating( false );
		}
	};

	const handleRegenerateSingle = async ( hash ) => {
		if ( ! onRegenerateSingle ) {
			return;
		}
		setSingleBusy( hash );
		try {
			await onRegenerateSingle( hash );
		} catch ( err ) {
			// No rethrow: the parent (FileOptimization
			// handleRegenerateSingleCcss) notifies internally and owns the
			// banner, and the click site here has no catch — rethrowing
			// would only risk an unhandled rejection with no UI benefit.
			console.error(
				'Failed to regenerate CCSS for template',
				getErrorLogMessage( err )
			);
		} finally {
			setSingleBusy( null );
		}
	};

	const entries = useMemo( () => {
		if (
			! status ||
			typeof status !== 'object' ||
			Array.isArray( status )
		) {
			return [];
		}
		return Object.entries( status );
	}, [ status ] );

	return (
		<div className="wppo-ccss-panel wppo-mt-20">
			<div className="wppo-field-label">
				{ __( 'Critical CSS Status', 'performance-optimisation' ) }
			</div>
			<p className="wppo-text-muted wppo-mb-12">
				{ __(
					'Critical CSS is generated per template. Regenerate after theme changes.',
					'performance-optimisation'
				) }
			</p>
			{ entries.length > 0 ? (
				<div
					className="wppo-ccss-status-list wppo-mb-16"
					aria-live="polite"
				>
					{ entries.map( ( [ hash, entry ] ) => {
						const normalized = normalizeCcssEntry( hash, entry );
						const { statusKey, label, size, truncated } =
							normalized;
						const config = statusConfigFor( statusKey );
						return (
							<div key={ hash } className="wppo-ccss-status-item">
								<span className="wppo-ccss-status-hash">
									{ label }
									{ null !== size && size > 0 && (
										<span className="wppo-text-muted">
											{ truncated
												? sprintf(
														/* translators: 1: file size, 2: "capped" label. */
														__(
															'— %1$s (%2$s)',
															'performance-optimisation'
														),
														formatBytes( size ),
														__(
															'capped',
															'performance-optimisation'
														)
												  )
												: sprintf(
														/* translators: %s: file size. */
														__(
															'— %s',
															'performance-optimisation'
														),
														formatBytes( size )
												  ) }
										</span>
									) }
								</span>
								<span
									className={ `wppo-badge ${ config.className }` }
								>
									<FontAwesomeIcon icon={ config.icon } />
									{ config.label }
								</span>
								{ onRegenerateSingle && (
									<button
										className="wppo-button wppo-button--secondary wppo-button--small"
										type="button"
										disabled={ singleBusy === hash }
										aria-label={ sprintf(
											/* translators: %s: template label. */
											__(
												'Regenerate %s',
												'performance-optimisation'
											),
											label
										) }
										onClick={ () =>
											handleRegenerateSingle( hash )
										}
									>
										{ __(
											'Regenerate',
											'performance-optimisation'
										) }
									</button>
								) }
							</div>
						);
					} ) }
				</div>
			) : (
				<div className="wppo-text-muted wppo-mb-16">
					{ __(
						'No templates found. Save settings and regenerate.',
						'performance-optimisation'
					) }
				</div>
			) }
			<LoadingSubmitButton
				className="wppo-button wppo-button--secondary"
				isLoading={ isRegenerating }
				onClick={ handleRegenerate }
				label={ __( 'Regenerate All', 'performance-optimisation' ) }
			/>
		</div>
	);
};

export default memo( CriticalCssPanel );
