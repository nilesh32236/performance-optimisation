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

// Audit #1354: built lazily per call — module-scope __() would freeze
// translations at import time.
const buildReadyConfig = () => ( {
	icon: faCheckCircle,
	className: 'wppo-badge--success',
	label: __( 'Generated', 'performance-optimisation' ),
} );

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

const getStatusConfig = () => {
	const readyConfig = buildReadyConfig();
	return {
		ready: readyConfig,
		// Copy (issue #1274 review): done === ready today, but a shared
		// reference would let a future mutation hit both entries.
		done: { ...readyConfig },
		queued: pendingConfig( __( 'Queued', 'performance-optimisation' ) ),
		pending: pendingConfig( __( 'Pending', 'performance-optimisation' ) ),
		processing: pendingConfig(
			__( 'Processing', 'performance-optimisation' )
		),
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
		// Short-lived forgery-rejection marker (HMAC mismatch): distinct
		// from a genuine generation failure so a forged job neither looks
		// like breakage nor blocks the next signed retry.
		rejected: {
			icon: faExclamationTriangle,
			className: 'wppo-badge--warning',
			label: __( 'Rejected', 'performance-optimisation' ),
		},
		none: {
			icon: faExclamationTriangle,
			className: 'wppo-badge--warning',
			label: __( 'Not Generated', 'performance-optimisation' ),
		},
	};
};

/**
 * Normalize a rollout slot into a null-safe shape.
 *
 * Anything but a plain object (null, arrays, scalars) reads as no
 * rollout information. Unknown health strings pass through as-is so
 * future server states render instead of crashing.
 *
 * @since NEXT
 * @param {*} raw Raw rollout value.
 * @return {{staged: boolean, stagedChanged: boolean, stagedBytes: number|null, health: string|null, fallback: boolean}|null} Normalized rollout.
 */
export const normalizeRollout = ( raw ) => {
	if ( ! raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return null;
	}
	return {
		staged: !! raw.staged,
		stagedChanged: !! raw.staged_changed,
		stagedBytes: Number.isFinite( raw.staged_bytes )
			? raw.staged_bytes
			: null,
		health:
			typeof raw.health === 'string' && raw.health ? raw.health : null,
		fallback: !! raw.fallback,
	};
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
 * @return {{statusKey: string, label: string, size: number|null, truncated: boolean, rollout: *}} Normalized entry.
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
			rollout: null,
		};
	}
	if ( ! entry || typeof entry !== 'object' ) {
		return {
			statusKey: 'none',
			label: fallbackLabel,
			size: null,
			truncated: false,
			rollout: null,
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
		rollout: normalizeRollout( entry.rollout ),
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
	// Audit #1354: config built per call so labels localize at render
	// time, not at module import.
	const STATUS_CONFIG = getStatusConfig();
	const hasOwn = Object.hasOwn
		? Object.hasOwn( STATUS_CONFIG, statusKey )
		: Object.prototype.hasOwnProperty.call( STATUS_CONFIG, statusKey );
	return hasOwn ? STATUS_CONFIG[ statusKey ] : STATUS_CONFIG.none;
};

const CriticalCssPanel = ( {
	status = {},
	onRegenerate,
	onRegenerateSingle,
	onPreviewTemplate,
	onPromoteTemplate,
	onRollbackTemplate,
} ) => {
	const [ isRegenerating, setIsRegenerating ] = useState( false );
	// Audit #1420: memoized Map so entries.map does not rebuild 8 objects
	// + __() lookups per row on every render.
	const configCache = useMemo( () => new Map(), [] );
	const configFor = ( statusKey ) => {
		if ( ! configCache.has( statusKey ) ) {
			configCache.set( statusKey, statusConfigFor( statusKey ) );
		}
		return configCache.get( statusKey );
	};
	const [ singleBusy, setSingleBusy ] = useState( null );
	// Safe-rollout action in flight ({ hash, action } while a
	// preview/promote/rollback request runs). Kept separate from
	// singleBusy so regenerate spinners never collide with rollout ones.
	const [ rolloutBusy, setRolloutBusy ] = useState( null );
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

	const handleRolloutAction = async ( hash, label, action, handler ) => {
		if ( typeof handler !== 'function' ) {
			return;
		}
		setRolloutBusy( { hash, action } );
		try {
			await handler( hash );
		} catch ( err ) {
			// Same single-owner feedback as single regenerate: the
			// parent notifies and owns the banner; log locally here.
			console.error(
				`Failed to ${ action } critical CSS for template`,
				getErrorLogMessage( err )
			);
		} finally {
			setRolloutBusy( null );
		}
	};

	const isRolloutBusy = ( hash, action ) =>
		!! rolloutBusy &&
		rolloutBusy.hash === hash &&
		rolloutBusy.action === action;

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
						const config = configFor( statusKey );
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
									<FontAwesomeIcon
										icon={ config.icon }
										aria-hidden="true"
									/>
									{ config.label }
								</span>
								{ normalized.rollout &&
									normalized.rollout.staged && (
										<span className="wppo-badge wppo-badge--info">
											<FontAwesomeIcon
												icon={ faClock }
												aria-hidden="true"
											/>
											{ normalized.rollout.stagedChanged
												? __(
														'Staged preview — differs from live',
														'performance-optimisation'
												  )
												: __(
														'Staged preview — matches live',
														'performance-optimisation'
												  ) }
										</span>
									) }
								{ normalized.rollout &&
									normalized.rollout.health &&
									'healthy' !== normalized.rollout.health && (
										<span
											className={ `wppo-badge ${
												'restorable' ===
												normalized.rollout.health
													? 'wppo-badge--warning'
													: 'wppo-badge--error'
											}` }
										>
											<FontAwesomeIcon
												icon={ faExclamationTriangle }
												aria-hidden="true"
											/>
											{ 'restorable' ===
											normalized.rollout.health
												? __(
														'Restorable from last-good',
														'performance-optimisation'
												  )
												: __(
														'Degraded — no fallback',
														'performance-optimisation'
												  ) }
										</span>
									) }
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
								{ onPreviewTemplate && (
									<button
										className="wppo-button wppo-button--secondary wppo-button--small"
										type="button"
										disabled={ isRolloutBusy(
											hash,
											'preview'
										) }
										aria-label={ sprintf(
											/* translators: %s: template label. */
											__(
												'Preview %s',
												'performance-optimisation'
											),
											label
										) }
										onClick={ () =>
											handleRolloutAction(
												hash,
												label,
												'preview',
												onPreviewTemplate
											)
										}
									>
										{ __(
											'Preview',
											'performance-optimisation'
										) }
									</button>
								) }
								{ onPromoteTemplate &&
									normalized.rollout &&
									normalized.rollout.staged && (
										<button
											className="wppo-button wppo-button--secondary wppo-button--small"
											type="button"
											disabled={ isRolloutBusy(
												hash,
												'promote'
											) }
											aria-label={ sprintf(
												/* translators: %s: template label. */
												__(
													'Promote staged %s',
													'performance-optimisation'
												),
												label
											) }
											onClick={ () =>
												handleRolloutAction(
													hash,
													label,
													'promote',
													onPromoteTemplate
												)
											}
										>
											{ __(
												'Promote staged',
												'performance-optimisation'
											) }
										</button>
									) }
								{ onRollbackTemplate &&
									normalized.rollout &&
									normalized.rollout.fallback && (
										<button
											className="wppo-button wppo-button--secondary wppo-button--small"
											type="button"
											disabled={ isRolloutBusy(
												hash,
												'rollback'
											) }
											aria-label={ sprintf(
												/* translators: %s: template label. */
												__(
													'Restore last-good %s',
													'performance-optimisation'
												),
												label
											) }
											onClick={ () =>
												handleRolloutAction(
													hash,
													label,
													'rollback',
													onRollbackTemplate
												)
											}
										>
											{ __(
												'Restore last-good',
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
