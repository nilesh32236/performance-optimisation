/**
 * SetupWizard — 6-step onboarding modal.
 *
 * Steps: Welcome → Environment → Analyze → Recommendations → Review → Apply → Verify
 *
 * @since NEXT
 */
/* eslint-disable no-nested-ternary, jsx-a11y/label-has-associated-control */
import {
	useState,
	useEffect,
	useCallback,
	useMemo,
	useRef,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	apiCall,
	runPerformanceScan,
	fetchSuggestions,
} from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import NoticeBanner from './common/NoticeBanner';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faTimes,
	faCheckCircle,
	faRocket,
	faServer,
	faDatabase,
	faImages,
	faShieldAlt,
	faLeaf,
	faMagic,
	faSearch,
} from '@fortawesome/free-solid-svg-icons';

const STORAGE_KEY = 'wppo_wizard_dismissed';

const SAFE_SET = {
	cache_settings: { enableCache: true },
	file_optimisation: { minifyCSS: true, minifyJS: true, deferJS: true },
	image_optimisation: { lazyLoadNative: true },
};

const STEP_LABELS = [
	__( 'Welcome', 'performance-optimisation' ),
	__( 'Environment', 'performance-optimisation' ),
	__( 'Analyze', 'performance-optimisation' ),
	__( 'Recommendations', 'performance-optimisation' ),
	__( 'Review & Apply', 'performance-optimisation' ),
	__( 'Verify', 'performance-optimisation' ),
];

const tierForSuggestion = ( s ) => {
	const m = s.metric || '';
	const safe = [
		'cache_control',
		'compression',
		'modern_images',
		'alt_text',
		'uses_https',
	];
	const review = [ 'page_size', 'assets', 'unminified', 'dom_size' ];
	if ( safe.includes( m ) ) {
		return 'safe';
	}
	if ( review.includes( m ) ) {
		return 'review';
	}
	return 'advanced';
};

const tierMeta = {
	safe: {
		label: __( 'Safe', 'performance-optimisation' ),
		color: 'var(--wppo-success)',
		desc: __(
			'Low risk, reversible. Recommended for all sites.',
			'performance-optimisation'
		),
	},
	review: {
		label: __( 'Review', 'performance-optimisation' ),
		color: 'var(--wppo-warning)',
		desc: __(
			'Check compatibility before applying.',
			'performance-optimisation'
		),
	},
	advanced: {
		label: __( 'Advanced', 'performance-optimisation' ),
		color: 'var(--wppo-info)',
		desc: __(
			'For developers. May need server access.',
			'performance-optimisation'
		),
	},
};

const SetupWizard = ( { onClose, initialOpen = false } ) => {
	const [ step, setStep ] = useState( 0 );
	const [ dismissed, setDismissed ] = useState( () => {
		try {
			return localStorage.getItem( STORAGE_KEY ) === '1' && ! initialOpen;
		} catch {
			return false;
		}
	} );
	const [ open, setOpen ] = useState( () => {
		try {
			if ( initialOpen ) {
				return true;
			}
			return localStorage.getItem( STORAGE_KEY ) !== '1';
		} catch {
			return true;
		}
	} );
	const [ env, setEnv ] = useState( {
		litespeed: null,
		redis: null,
		gd: null,
		imagick: null,
		loading: true,
	} );
	const [ analyzing, setAnalyzing ] = useState( false );
	const [ suggestions, setSuggestions ] = useState( [] );
	const [ auditResult, setAuditResult ] = useState( null );
	const [ selectedTiers, setSelectedTiers ] = useState( {
		safe: true,
		review: false,
		advanced: false,
	} );
	const [ applying, setApplying ] = useState( false );
	const [ verifying, setVerifying ] = useState( false );
	const [ verifyResult, setVerifyResult ] = useState( null );
	const { notice, notify, dismiss } = useNotice();
	const abortRef = useRef( null );

	const homeUrl =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.performance_audit?.homeUrl ?? ''
			: '';

	// Listen for re-open event from Manage tab.
	useEffect( () => {
		const handler = () => {
			try {
				localStorage.removeItem( STORAGE_KEY );
			} catch {}
			setDismissed( false );
			setOpen( true );
			setStep( 0 );
		};
		window.addEventListener( 'wppo:open-wizard', handler );
		return () => window.removeEventListener( 'wppo:open-wizard', handler );
	}, [] );

	// Environment detection on step 1 entry
	useEffect( () => {
		if ( step !== 1 || ! open ) {
			return;
		}
		let cancelled = false;
		const litespeedInfo =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.litespeed
				: null;
		const gdImagickHint =
			typeof wppoSettings !== 'undefined'
				? wppoSettings?.image_libraries
				: null;

		const fetchEnv = async () => {
			let redisStatus = null;
			let gd = gdImagickHint?.gd ?? null;
			const imagick = gdImagickHint?.imagick ?? null;
			try {
				const res = await apiCall( 'object_cache', { action: 'ping' } );
				redisStatus = res.success ? 'PONG' : 'Unreachable';
			} catch {
				redisStatus = 'Unreachable';
			}
			try {
				const sys = await apiCall( 'system_info', {}, 'GET' );
				if ( sys.success && sys.data ) {
					// Try to infer GD/Imagick from extensions_count or php info
					// Fallback to true if info unavailable to keep UI calm.
					const php = sys.data.php || {};
					if ( typeof php.extensions_count === 'number' ) {
						// Heuristic: if count > 20, assume GD present.
						if ( gd === null ) {
							gd = php.extensions_count > 10 ? true : null;
						}
					}
				}
			} catch {}
			if ( ! cancelled ) {
				const gdLabel =
					gd === null ? 'Available' : gd ? 'Available' : 'Missing';
				const imagickLabel =
					imagick === null
						? 'Available'
						: imagick
						? 'Available'
						: 'Missing';
				setEnv( {
					litespeed: litespeedInfo,
					redis: redisStatus,
					gd: gdLabel,
					imagick: imagickLabel,
					loading: false,
				} );
			}
		};
		fetchEnv();
		return () => {
			cancelled = true;
		};
	}, [ step, open ] );

	// Auto analyze on step 2 entry
	useEffect( () => {
		if ( step !== 2 || ! open ) {
			return;
		}
		if ( analyzing || auditResult ) {
			return;
		}
		if ( ! homeUrl ) {
			return;
		}
		let cancelled = false;
		const run = async () => {
			setAnalyzing( true );
			// Debug log for troubleshooting (visible when WP_DEBUG_LOG enabled, browser console)
			// eslint-disable-next-line no-console
			console.log( '[WPPO Wizard] Analyze start', { homeUrl, step } );
			try {
				const res = await runPerformanceScan(
					homeUrl,
					false,
					abortRef.current?.signal
				);
				// eslint-disable-next-line no-console
				console.log( '[WPPO Wizard] performance_scan result', res );
				if ( ! cancelled && res.success && res.data ) {
					setAuditResult( res.data );
					try {
						const sug = await fetchSuggestions(
							homeUrl,
							abortRef.current?.signal
						);
						// eslint-disable-next-line no-console
						console.log( '[WPPO Wizard] suggestions', sug );
						if (
							! cancelled &&
							sug.success &&
							Array.isArray( sug.data?.suggestions )
						) {
							setSuggestions( sug.data.suggestions );
						}
					} catch ( sugErr ) {
						console.warn(
							'[WPPO Wizard] suggestions failed',
							sugErr
						);
					}
				} else if ( ! cancelled ) {
					console.warn( '[WPPO Wizard] scan no data', res );
				}
			} catch ( scanErr ) {
				console.error( '[WPPO Wizard] scan failed', scanErr );
			}
			if ( ! cancelled ) {
				setAnalyzing( false );
				// eslint-disable-next-line no-console
				console.log( '[WPPO Wizard] Analyze done', {
					auditResult: !! auditResult,
				} );
			}
		};
		abortRef.current = new AbortController();
		run();
		return () => {
			cancelled = true;
			abortRef.current?.abort();
		};
	}, [ step, open, homeUrl ] );

	const grouped = useMemo( () => {
		const g = { safe: [], review: [], advanced: [] };
		for ( const s of suggestions ) {
			const t = tierForSuggestion( s );
			g[ t ].push( s );
		}
		return g;
	}, [ suggestions ] );

	const handleDismiss = useCallback( () => {
		try {
			localStorage.setItem( STORAGE_KEY, '1' );
		} catch {}
		setDismissed( true );
		setOpen( false );
		if ( onClose ) {
			onClose();
		}
	}, [ onClose ] );

	const handleClose = useCallback( () => {
		setOpen( false );
		if ( onClose ) {
			onClose();
		}
	}, [ onClose ] );

	const handleApply = useCallback( async () => {
		if ( applying ) {
			return;
		}
		setApplying( true );
		dismiss();
		try {
			// Build payload from selected tiers — for now safe set is authoritative.
			// Review/advanced tiers in wizard just gate the safe set until vetted.
			const cacheCurrent =
				typeof wppoSettings !== 'undefined'
					? wppoSettings.settings?.cache_settings ?? {}
					: {};
			const fileCurrent =
				typeof wppoSettings !== 'undefined'
					? wppoSettings.settings?.file_optimisation ?? {}
					: {};
			const imgCurrent =
				typeof wppoSettings !== 'undefined'
					? wppoSettings.settings?.image_optimisation ?? {}
					: {};

			if ( selectedTiers.safe ) {
				await apiCall( 'update_settings', {
					tab: 'cache_settings',
					settings: { ...cacheCurrent, ...SAFE_SET.cache_settings },
				} );
				await apiCall( 'update_settings', {
					tab: 'file_optimisation',
					settings: { ...fileCurrent, ...SAFE_SET.file_optimisation },
				} );
				await apiCall( 'update_settings', {
					tab: 'image_optimisation',
					settings: { ...imgCurrent, ...SAFE_SET.image_optimisation },
				} );
			}
			notify( {
				type: 'success',
				message: __(
					'Recommended settings applied.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
			setStep( 5 );
			// Trigger verify re-scan
			setVerifying( true );
			try {
				const vRes = await runPerformanceScan( homeUrl, true );
				if ( vRes.success ) {
					setVerifyResult( vRes.data );
				}
			} catch {}
			setVerifying( false );
		} catch {
			notify( {
				type: 'error',
				message: __(
					'Failed to apply settings.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setApplying( false );
		}
	}, [ applying, selectedTiers, homeUrl, notify, dismiss ] );

	const handleVerify = useCallback( async () => {
		setVerifying( true );
		try {
			const vRes = await runPerformanceScan( homeUrl, true );
			if ( vRes.success ) {
				setVerifyResult( vRes.data );
				notify( {
					type: 'success',
					message: __(
						'Verified with fresh scan.',
						'performance-optimisation'
					),
				} );
			}
		} catch {
			notify( {
				type: 'error',
				message: __( 'Verify failed.', 'performance-optimisation' ),
			} );
		} finally {
			setVerifying( false );
		}
	}, [ homeUrl, notify ] );

	if ( dismissed || ! open ) {
		return null;
	}

	const totalSteps = STEP_LABELS.length;

	return (
		<div
			className="tw-fixed tw-inset-0 tw-bg-[rgba(15,23,42,0.52)] tw-backdrop-blur-[3px] tw-z-[9998] tw-flex tw-items-center tw-justify-center tw-p-4 sm:tw-p-5"
			role="dialog"
			aria-modal="true"
			aria-label={ __( 'Setup Wizard', 'performance-optimisation' ) }
		>
			<div
				className="tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[16px] tw-shadow-[var(--wppo-shadow-lg)] tw-w-full tw-max-w-[760px] tw-max-h-[88vh] tw-flex tw-flex-col tw-overflow-hidden"
				role="document"
			>
				<div className="tw-flex tw-items-start tw-justify-between tw-gap-3 tw-p-4 tw-border-b tw-border-[var(--wppo-border)] tw-bg-[var(--wppo-bg-card-surface)]">
					<div className="tw-flex tw-flex-nowrap tw-gap-1.5 sm:tw-gap-2 tw-flex-1 tw-overflow-x-auto tw-scrollbar-none tw-pb-1 sm:tw-pb-0">
						{ STEP_LABELS.map( ( label, idx ) => (
							<span
								key={ label }
								className={ `tw-inline-flex tw-items-center tw-gap-1.5 tw-text-[11px] tw-font-semibold tw-px-2 tw-py-1 tw-rounded-full tw-border tw-border-transparent ${
									idx === step
										? 'tw-text-[var(--wppo-primary)] tw-bg-[var(--wppo-primary-soft)] tw-border-[var(--wppo-primary-medium)]'
										: idx < step
										? 'tw-text-[var(--wppo-success)] tw-bg-[var(--wppo-success-bg)] tw-border-[var(--wppo-success-border)]'
										: ''
								}` }
							>
								<span className="tw-w-[18px] tw-h-[18px] tw-rounded-full tw-bg-current tw-text-white tw-inline-flex tw-items-center tw-justify-center tw-text-[10px] tw-flex-shrink-0">
									{ idx < step ? (
										<FontAwesomeIcon
											icon={ faCheckCircle }
										/>
									) : (
										idx + 1
									) }
								</span>
								<span className="tw-whitespace-nowrap tw-hidden sm:tw-inline">
									{ label }
								</span>
							</span>
						) ) }
					</div>
					<button
						type="button"
						className="tw-bg-transparent tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-w-8 tw-h-8 tw-inline-flex tw-items-center tw-justify-center tw-text-[var(--wppo-text-muted)] hover:tw-bg-[var(--wppo-switch-hover)] tw-flex-shrink-0"
						onClick={ handleClose }
						aria-label={ __(
							'Close wizard',
							'performance-optimisation'
						) }
					>
						<FontAwesomeIcon icon={ faTimes } />
					</button>
				</div>

				{ notice && (
					<NoticeBanner
						type={ notice.type }
						message={ notice.message }
						onDismiss={ dismiss }
					/>
				) }

				<div className="tw-p-5 sm:tw-p-6 tw-overflow-auto tw-flex-1">
					{ step === 0 && (
						<div className="tw-space-y-3">
							<h2>
								{ __(
									'Welcome to Performance Optimisation',
									'performance-optimisation'
								) }
							</h2>
							<p className="tw-text-[var(--wppo-text-muted)]">
								{ __(
									'This quick 6-step setup will detect your environment, run a health check, and apply safe recommendations in one click. You can dismiss anytime and re-open from Manage → Re-open setup.',
									'performance-optimisation'
								) }
							</p>
							<div className="tw-flex tw-gap-4 tw-my-4 tw-text-[var(--wppo-primary)]">
								<FontAwesomeIcon icon={ faRocket } size="2x" />
								<FontAwesomeIcon
									icon={ faShieldAlt }
									size="2x"
								/>
								<FontAwesomeIcon icon={ faLeaf } size="2x" />
							</div>
							<ul className="tw-my-3 tw-pl-5 tw-list-disc tw-text-[13px] tw-leading-6 tw-text-[var(--wppo-text-muted)] tw-space-y-1">
								<li>
									{ __(
										'Detects LiteSpeed, Redis, and image libraries',
										'performance-optimisation'
									) }
								</li>
								<li>
									{ __(
										'Analyzes your homepage (cached telemetry)',
										'performance-optimisation'
									) }
								</li>
								<li>
									{ __(
										'Applies Safe settings with one click',
										'performance-optimisation'
									) }
								</li>
							</ul>
						</div>
					) }
					{ step === 1 && (
						<div className="tw-space-y-3">
							<h3>
								{ __(
									'Environment',
									'performance-optimisation'
								) }
							</h3>
							{ env.loading ? (
								<p>
									{ __(
										'Detecting…',
										'performance-optimisation'
									) }
								</p>
							) : (
								<div className="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-3 tw-my-3">
									<div className="tw-border tw-border-[var(--wppo-border)] tw-rounded-[10px] tw-p-3.5 tw-bg-[var(--wppo-bg-card-surface)] tw-flex tw-flex-col tw-gap-1.5 tw-text-[13px]">
										<FontAwesomeIcon icon={ faServer } />
										<strong>
											{ __(
												'LiteSpeed',
												'performance-optimisation'
											) }
										</strong>
										<span>
											{ env.litespeed?.detected
												? sprintf(
														/* translators: %s: LiteSpeed mode */
														__(
															'Detected (%s)',
															'performance-optimisation'
														),
														env.litespeed
															.effective_mode
												  )
												: __(
														'Not detected',
														'performance-optimisation'
												  ) }
										</span>
									</div>
									<div className="tw-border tw-border-[var(--wppo-border)] tw-rounded-[10px] tw-p-3.5 tw-bg-[var(--wppo-bg-card-surface)] tw-flex tw-flex-col tw-gap-1.5 tw-text-[13px]">
										<FontAwesomeIcon icon={ faDatabase } />
										<strong>
											{ __(
												'Redis',
												'performance-optimisation'
											) }
										</strong>
										<span>
											{ env.redis === 'PONG'
												? 'PONG ✓'
												: env.redis ||
												  __(
														'Not connected',
														'performance-optimisation'
												  ) }
										</span>
									</div>
									<div className="tw-border tw-border-[var(--wppo-border)] tw-rounded-[10px] tw-p-3.5 tw-bg-[var(--wppo-bg-card-surface)] tw-flex tw-flex-col tw-gap-1.5 tw-text-[13px]">
										<FontAwesomeIcon icon={ faImages } />
										<strong>
											{ __(
												'GD / Imagick',
												'performance-optimisation'
											) }
										</strong>
										<span>
											{ env.gd } / { env.imagick }
										</span>
									</div>
								</div>
							) }
							<p className="tw-text-[12px] tw-text-[var(--wppo-text-muted)]">
								{ __(
									'We use wppoSettings.litespeed + Redis PONG + GD/Imagick hints. No extra server calls except ping & system_info.',
									'performance-optimisation'
								) }
							</p>
						</div>
					) }
					{ step === 2 && (
						<div className="tw-space-y-3">
							<h3>
								{ __( 'Analyze', 'performance-optimisation' ) }
							</h3>
							<p className="tw-text-[var(--wppo-text-muted)]">
								{ sprintf(
									/* translators: %s: URL being scanned */
									__(
										'Scanning %s…',
										'performance-optimisation'
									),
									homeUrl ||
										__(
											'homepage',
											'performance-optimisation'
										)
								) }
							</p>
							{ analyzing && (
								<p>
									<FontAwesomeIcon icon={ faSearch } spin />{ ' ' }
									{ __(
										'Running performance scan (cached telemetry)…',
										'performance-optimisation'
									) }
								</p>
							) }
							{ ! analyzing && auditResult && (
								<div className="tw-bg-[var(--wppo-info-bg)] tw-border tw-border-[var(--wppo-info-border)] tw-rounded-[10px] tw-p-3 tw-mt-2.5">
									<p>
										<strong>
											{ __(
												'Load time:',
												'performance-optimisation'
											) }
										</strong>{ ' ' }
										{ auditResult.load_time }s •{ ' ' }
										<strong>TTFB</strong>{ ' ' }
										{ auditResult.ttfb }ms •{ ' ' }
										<strong>
											{ __(
												'Page size',
												'performance-optimisation'
											) }
										</strong>{ ' ' }
										{ Math.round(
											( auditResult.total_size || 0 ) /
												1024
										) }{ ' ' }
										KB
									</p>
									<p className="tw-text-[12px] tw-text-[var(--wppo-text-muted)]">
										{ __(
											'Suggestions will be grouped into Safe / Review / Advanced in the next step (merged telemetry + PageSpeed).',
											'performance-optimisation'
										) }
									</p>
								</div>
							) }
							{ ! analyzing && ! auditResult && (
								<p className="tw-text-[var(--wppo-text-muted)]">
									{ __(
										'No scan data yet. Try again or skip.',
										'performance-optimisation'
									) }
								</p>
							) }
						</div>
					) }
					{ step === 3 && (
						<div className="tw-space-y-3">
							<h3>
								{ __(
									'Recommendations',
									'performance-optimisation'
								) }
							</h3>
							<p className="tw-text-[var(--wppo-text-muted)]">
								{ __(
									'Grouped into 3 tiers. Select which to apply.',
									'performance-optimisation'
								) }
							</p>
							{ [ 'safe', 'review', 'advanced' ].map(
								( tier ) => (
									<div
										key={ tier }
										className="tw-border tw-border-[var(--wppo-border)] tw-rounded-[10px] tw-p-3 tw-mb-3 tw-bg-white"
									>
										<label className="tw-flex tw-items-center tw-gap-2 tw-text-[13px] tw-cursor-pointer">
											<input
												type="checkbox"
												checked={
													!! selectedTiers[ tier ]
												}
												onChange={ ( e ) =>
													setSelectedTiers(
														( prev ) => ( {
															...prev,
															[ tier ]:
																e.target
																	.checked,
														} )
													)
												}
											/>
											<span
												className="tw-text-white tw-px-2 tw-py-0.5 tw-rounded-full tw-text-[11px] tw-font-bold"
												style={ {
													background:
														tierMeta[ tier ].color,
												} }
											>
												{ tierMeta[ tier ].label }
											</span>
											<span className="tw-text-[var(--wppo-text-muted)] tw-text-[12px] tw-flex-1">
												{ tierMeta[ tier ].desc }
											</span>
											<span className="tw-bg-[var(--wppo-bg-card-surface)] tw-border tw-border-[var(--wppo-border)] tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[11px]">
												{ grouped[ tier ].length }
											</span>
										</label>
										<ul className="tw-mt-2.5 tw-p-0 tw-list-none tw-flex tw-flex-col tw-gap-1.5">
											{ grouped[ tier ].length === 0 && (
												<li className="tw-text-[12px] tw-text-[var(--wppo-text-muted)]">
													{ __(
														'No items in this tier.',
														'performance-optimisation'
													) }
												</li>
											) }
											{ grouped[ tier ].map( ( s ) => (
												<li
													key={ s.metric }
													className="tw-flex tw-items-center tw-gap-2 tw-text-[12.5px] tw-bg-[var(--wppo-bg-card-surface)] tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-p-2 tw-px-2.5"
												>
													<span
														className={ `wppo-status-badge wppo-status-badge--${ s.status }` }
													>
														{ s.status }
													</span>
													<span>
														{ s.description }
													</span>
													<span className="tw-text-[12px] tw-text-[var(--wppo-text-muted)]">
														{ s.metric }
													</span>
												</li>
											) ) }
										</ul>
									</div>
								)
							) }
							{ suggestions.length === 0 && (
								<p className="tw-text-[var(--wppo-text-muted)]">
									{ __(
										'No suggestions yet — run Analyze first or proceed with Safe defaults (cache + minify + defer + native lazy).',
										'performance-optimisation'
									) }
								</p>
							) }
						</div>
					) }
					{ step === 4 && (
						<div className="tw-space-y-3">
							<h3>
								{ __( 'Review', 'performance-optimisation' ) }
							</h3>
							<p className="tw-text-[var(--wppo-text-muted)]">
								{ __(
									'Benefit / risk / badge for each selected tier.',
									'performance-optimisation'
								) }
							</p>
							<table className="tw-w-full tw-border-collapse tw-text-[13px] tw-my-3">
								<thead>
									<tr>
										<th>
											{ __(
												'Tier',
												'performance-optimisation'
											) }
										</th>
										<th>
											{ __(
												'Benefit',
												'performance-optimisation'
											) }
										</th>
										<th>
											{ __(
												'Risk',
												'performance-optimisation'
											) }
										</th>
										<th>
											{ __(
												'Badge',
												'performance-optimisation'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td>
											<span className="wppo-status-badge wppo-status-badge--good">
												{ __(
													'Safe',
													'performance-optimisation'
												) }
											</span>
										</td>
										<td>
											{ __(
												'Faster TTFB, smaller files, native lazy-load',
												'performance-optimisation'
											) }
										</td>
										<td>
											{ __(
												'Low',
												'performance-optimisation'
											) }
										</td>
										<td>
											<span className="wppo-status-badge wppo-status-badge--good">
												{ __(
													'Recommended',
													'performance-optimisation'
												) }
											</span>
										</td>
									</tr>
									<tr>
										<td>
											<span className="wppo-status-badge wppo-status-badge--warning">
												{ __(
													'Review',
													'performance-optimisation'
												) }
											</span>
										</td>
										<td>
											{ __(
												'Fewer assets, better caching headers',
												'performance-optimisation'
											) }
										</td>
										<td>
											{ __(
												'Medium — test staging',
												'performance-optimisation'
											) }
										</td>
										<td>
											<span className="wppo-status-badge wppo-status-badge--warning">
												{ __(
													'Check',
													'performance-optimisation'
												) }
											</span>
										</td>
									</tr>
									<tr>
										<td>
											<span className="wppo-status-badge wppo-status-badge--poor">
												{ __(
													'Advanced',
													'performance-optimisation'
												) }
											</span>
										</td>
										<td>
											{ __(
												'Edge purge, preconnect tweaks',
												'performance-optimisation'
											) }
										</td>
										<td>
											{ __(
												'High — needs server access',
												'performance-optimisation'
											) }
										</td>
										<td>
											<span className="wppo-status-badge wppo-status-badge--poor">
												{ __(
													'Expert',
													'performance-optimisation'
												) }
											</span>
										</td>
									</tr>
								</tbody>
							</table>
							<div className="tw-flex tw-items-center tw-gap-3 tw-flex-wrap tw-mt-3">
								<LoadingSubmitButton
									className="tw-inline-flex tw-items-center tw-justify-center tw-px-4 tw-py-2 tw-bg-[var(--wppo-primary)] tw-text-white tw-rounded-[8px] tw-text-[13px] tw-font-semibold"
									onClick={ handleApply }
									isLoading={ applying }
									label={
										<>
											{ ' ' }
											<FontAwesomeIcon
												icon={ faMagic }
												style={ { marginRight: '6px' } }
											/>{ ' ' }
											{ __(
												'Apply Selected',
												'performance-optimisation'
											) }
										</>
									}
									loadingLabel={ __(
										'Applying…',
										'performance-optimisation'
									) }
								/>
								<span className="tw-text-[12px] tw-text-[var(--wppo-text-muted)]">
									{ __(
										'Single apiCall batch: enableCache, minifyCSS/JS, deferJS, lazyLoadNative',
										'performance-optimisation'
									) }
								</span>
							</div>
						</div>
					) }
					{ step === 5 && (
						<div className="tw-space-y-3">
							<h3>
								{ __( 'Verify', 'performance-optimisation' ) }
							</h3>
							{ verifying && (
								<p>
									<FontAwesomeIcon icon={ faSearch } spin />{ ' ' }
									{ __(
										'Re-scanning…',
										'performance-optimisation'
									) }
								</p>
							) }
							{ ! verifying && verifyResult && (
								<div>
									<p>
										<strong>
											{ __(
												'Verified load time:',
												'performance-optimisation'
											) }
										</strong>{ ' ' }
										{ verifyResult.load_time }s • TTFB{ ' ' }
										{ verifyResult.ttfb }ms
									</p>
									<p className="tw-bg-[var(--wppo-success-bg)] tw-border tw-border-[var(--wppo-success-border)] tw-rounded-[8px] tw-p-3 tw-text-[13px]">
										{ __(
											'Verification complete — compare before/after in Dashboard → Performance Audit.',
											'performance-optimisation'
										) }
									</p>
								</div>
							) }
							{ ! verifying && ! verifyResult && (
								<p className="tw-text-[var(--wppo-text-muted)]">
									{ __(
										'Run a fresh scan to verify improvements.',
										'performance-optimisation'
									) }
								</p>
							) }
							<div className="tw-flex tw-items-center tw-gap-3 tw-flex-wrap tw-mt-3">
								<LoadingSubmitButton
									className="tw-inline-flex tw-items-center tw-justify-center tw-px-4 tw-py-2 tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-text-[13px] tw-font-semibold"
									onClick={ handleVerify }
									isLoading={ verifying }
									label={ __(
										'Re-scan now',
										'performance-optimisation'
									) }
									loadingLabel={ __(
										'Scanning…',
										'performance-optimisation'
									) }
								/>
								<button
									type="button"
									className="tw-inline-flex tw-items-center tw-justify-center tw-px-4 tw-py-2 tw-bg-[var(--wppo-primary)] tw-text-white tw-rounded-[8px] tw-text-[13px] tw-font-semibold"
									onClick={ handleDismiss }
								>
									{ __(
										'Finish',
										'performance-optimisation'
									) }
								</button>
							</div>
						</div>
					) }
				</div>

				<div className="tw-flex tw-flex-col sm:tw-flex-row tw-items-center tw-justify-between tw-gap-3 tw-p-3.5 sm:tw-p-4 tw-border-t tw-border-[var(--wppo-border)] tw-bg-[rgba(248,250,252,0.6)] tw-backdrop-blur-[6px]">
					<div className="tw-flex tw-items-center tw-gap-2.5 tw-flex-1">
						<button
							type="button"
							className="tw-inline-flex tw-items-center tw-justify-center tw-px-4 tw-py-2 tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-text-[13px] tw-font-semibold"
							onClick={ handleDismiss }
						>
							{ __( 'Dismiss', 'performance-optimisation' ) }
						</button>
						<span className="tw-text-[12px] tw-text-[var(--wppo-text-muted)]">
							{ sprintf(
								/* translators: %1$d: current step, %2$d: total steps */
								__(
									'Step %1$d of %2$d',
									'performance-optimisation'
								),
								step + 1,
								totalSteps
							) }
						</span>
					</div>
					<div className="tw-flex tw-items-center tw-gap-2.5 tw-flex-shrink-0">
						{ step > 0 && (
							<button
								type="button"
								className="tw-inline-flex tw-items-center tw-justify-center tw-px-4 tw-py-2 tw-bg-white tw-border tw-border-[var(--wppo-border)] tw-rounded-[8px] tw-text-[13px] tw-font-semibold"
								onClick={ () =>
									setStep( ( s ) => Math.max( 0, s - 1 ) )
								}
							>
								{ __( 'Back', 'performance-optimisation' ) }
							</button>
						) }
						{ step < totalSteps - 1 && step !== 4 && (
							<button
								type="button"
								className="tw-inline-flex tw-items-center tw-justify-center tw-px-4 tw-py-2 tw-bg-[var(--wppo-primary)] tw-text-white tw-rounded-[8px] tw-text-[13px] tw-font-semibold"
								onClick={ () =>
									setStep( ( s ) =>
										Math.min( totalSteps - 1, s + 1 )
									)
								}
							>
								{ __( 'Next', 'performance-optimisation' ) }
							</button>
						) }
					</div>
				</div>
			</div>
		</div>
	);
};

export default SetupWizard;
