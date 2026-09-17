import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import {
	apiCall,
	getErrorLogMessage,
	getWppoSettings,
} from '../lib/apiRequest';
import {
	WOO_SELF_TEST_TIMEOUT_MS,
	getWooSelfTestNotice,
	runWooSelfTest,
	shouldShowWooFixCta,
} from '../lib/wooSelfTest';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import NoticeBanner from './common/NoticeBanner';

/**
 * Dismiss the welcome panel server-side.
 *
 * Single choke point for the `dismiss_welcome` call shared by
 * handleStepAction() and handleDismiss() below.
 *
 * @since NEXT
 * @return {Promise<Object>} Resolved dismiss response.
 */
export const dismissWelcome = () => apiCall( 'dismiss_welcome' );

/**
 * Accessible label for a welcome step action button.
 *
 * Split out so the render path avoids nested ternaries (no-nested-ternary)
 * while keeping each sprintf() call on a literal format string.
 *
 * Returns the step label alone when idle (STEPS labels already contain the
 * verb, so prefixing another verb would read as "Enable Enable Page
 * Caching" to screen readers) and appends an ellipsis while in flight.
 * When visibleLabel is provided the accessible name contains the visible
 * button text first (WCAG 2.5.3 Label in Name), followed by the step title.
 *
 * @since NEXT
 * @param {Object}  step         Step entry from STEPS.
 * @param {boolean} isActive     Whether the step action is in flight.
 * @param {boolean} isWoo        Whether this is the Woo self-test step.
 * @param {string}  visibleLabel Visible button text (e.g. 'Run test').
 * @return {string} Accessible button label.
 */
export const getStepAriaLabel = ( step, isActive, isWoo, visibleLabel ) => {
	// Audit #1354: STEPS labels are lazy getters (module-scope __()
	// would freeze translations at import time).
	const label =
		typeof step?.getLabel === 'function'
			? step.getLabel()
			: step?.label ?? '';
	const visible = typeof visibleLabel === 'string' ? visibleLabel : '';
	if ( visible ) {
		if ( isActive ) {
			return sprintf(
				/* translators: 1: visible button text, 2: feature name */
				__( '%1$s – %2$s…', 'performance-optimisation' ),
				visible,
				label
			);
		}
		return sprintf(
			/* translators: 1: visible button text, 2: feature name */
			__( '%1$s – %2$s', 'performance-optimisation' ),
			visible,
			label
		);
	}
	if ( isActive ) {
		if ( isWoo ) {
			return sprintf(
				/* translators: %s: feature name */
				__( 'Running %s…', 'performance-optimisation' ),
				label
			);
		}
		return sprintf(
			/* translators: %s: feature name */
			__( '%s…', 'performance-optimisation' ),
			label
		);
	}
	return label;
};

/**
 * Scroll to and focus the WooCommerce safe-mode switch.
 *
 * Shared pattern with Dashboard's handleReenableWooSafeMode: the switch is
 * wrapped in `#wppoWooSafeMode`, so both the WelcomePanel FAIL path (already
 * on the dashboard tab) and the Dashboard FAIL block land keyboard and
 * screen-reader users on the fix.
 *
 * @since NEXT
 * @return {boolean} True when the switch was found and scrolled to.
 */
export const scrollToWooSafeMode = () => {
	if ( typeof document === 'undefined' ) {
		return false;
	}
	const anchor = document.getElementById( 'wppoWooSafeMode' );
	if ( ! anchor ) {
		return false;
	}
	if (
		anchor.scrollIntoView &&
		typeof anchor.scrollIntoView === 'function'
	) {
		try {
			anchor.scrollIntoView( { block: 'nearest' } );
		} catch {
			// scrollIntoView options unsupported — ignore.
		}
	}
	const input = anchor.querySelector( 'input, button' );
	if ( input && typeof input.focus === 'function' ) {
		input.focus( { preventScroll: true } );
	}
	return true;
};

const STEPS = [
	{
		number: 1,
		key: 'cache',
		getLabel: () => __( 'Enable Page Caching', 'performance-optimisation' ),
		getDescription: () =>
			__(
				'Speed up your site with static HTML page caching — the single biggest performance win.',
				'performance-optimisation'
			),

		settings: {
			tab: 'cache_settings',
			payload: { enableCache: true },
		},
		isEnabled: () =>
			getWppoSettings()?.settings?.cache_settings?.enableCache ?? false,
	},
	{
		number: 2,
		key: 'minify',
		getLabel: () =>
			__( 'Enable JS / CSS Minification', 'performance-optimisation' ),
		getDescription: () =>
			__(
				'Reduce file sizes by removing whitespace and comments from your CSS and JavaScript.',
				'performance-optimisation'
			),

		settings: {
			tab: 'file_optimisation',
			payload: { minifyJS: true, minifyCSS: true },
		},
		isEnabled: () =>
			( getWppoSettings()?.settings?.file_optimisation?.minifyJS ??
				false ) &&
			( getWppoSettings()?.settings?.file_optimisation?.minifyCSS ??
				false ),
	},
	{
		number: 3,
		key: 'lazyload',
		getLabel: () => __( 'Enable Lazy Loading', 'performance-optimisation' ),
		getDescription: () =>
			__(
				'Defer off-screen images and videos so they only load when visitors scroll to them.',
				'performance-optimisation'
			),

		settings: {
			tab: 'image_optimisation',
			payload: { lazyLoadImages: true },
		},
		isEnabled: () =>
			getWppoSettings()?.settings?.image_optimisation?.lazyLoadImages ??
			false,
	},
	{
		number: 4,
		key: 'woo-verify',
		getLabel: () =>
			__( 'Verify WooCommerce Cart Bypass', 'performance-optimisation' ),

		getDescription: () =>
			__(
				'Prove in one click that cart, checkout and account pages bypass the page cache so the guest cart survives.',
				'performance-optimisation'
			),

		action: 'woo-self-test',
		isEnabled: () => false,
	},
];

const WelcomePanel = ( { onNavigate } = {} ) => {
	const [ visible, setVisible ] = useState(
		getWppoSettings()?.show_welcome ?? false
	);
	const [ activatingStep, setActivatingStep ] = useState( null );
	const [ dismissing, setDismissing ] = useState( false );
	const [ wooSelfTest, setWooSelfTest ] = useState( null );
	const { notice, notify, dismiss } = useNotice();
	const dismissedRef = useRef( false );
	const wooAbortRef = useRef( null );
	const wooTimedOutRef = useRef( false );
	const wooTimeoutRef = useRef( null );
	const scrollTimersRef = useRef( [] );

	// Resync when the global settings arrive late (e.g. localised data
	// injected after first paint) or change after a save elsewhere.
	const showWelcomeKey = String( getWppoSettings()?.show_welcome ?? false );
	useEffect( () => {
		if ( dismissedRef.current ) {
			return;
		}
		setVisible( getWppoSettings()?.show_welcome ?? false );
	}, [ showWelcomeKey ] );

	// Abort any in-flight Woo self-test on unmount so a slow request can
	// never call setWooSelfTest/notify after the panel is gone. Also clear
	// the shared timeout and any pending FAIL-scroll retries.
	useEffect( () => {
		return () => {
			if ( wooTimeoutRef.current ) {
				clearTimeout( wooTimeoutRef.current );
				wooTimeoutRef.current = null;
			}
			if ( wooAbortRef.current ) {
				wooAbortRef.current.abort();
			}
			scrollTimersRef.current.forEach( clearTimeout );
			scrollTimersRef.current = [];
		};
	}, [] );

	if ( ! visible ) {
		return null;
	}

	/**
	 * Navigate to the Page Cache safe-mode switch after a FAIL result.
	 *
	 * WelcomePanel only ever mounts inside Dashboard, so `onNavigate(
	 * 'dashboard' )` alone is a no-op tab re-set with no scroll — and the
	 * `#wppoWooSafeMode` anchor fallback below is unreachable in that mount
	 * path. Call onNavigate first (harmless when already on dashboard, still
	 * correct if ever mounted elsewhere) and then scroll/focus the switch,
	 * retrying briefly so a late tab-switch commit still lands. When the
	 * anchor never appears, surface a notice instead of failing silently.
	 *
	 * @return {void}
	 */
	const handleWooFailNavigate = () => {
		if ( typeof onNavigate === 'function' ) {
			onNavigate( 'dashboard' );
		}
		// Dashboard is already mounted in this path, so the anchor usually
		// exists synchronously; retry for ~500ms so a tab switch (if any)
		// that commits later still lands on the switch.
		const clearScrollTimers = () => {
			scrollTimersRef.current.forEach( clearTimeout );
			scrollTimersRef.current = [];
		};
		const attempts = [ 0, 100, 250, 500 ];
		scrollTimersRef.current.forEach( clearTimeout );
		scrollTimersRef.current = [];
		attempts.forEach( ( delay ) => {
			const id = setTimeout( () => {
				if ( scrollToWooSafeMode() ) {
					// Cancel remaining retries once the switch is found —
					// no redundant scrollIntoView/focus layout passes.
					clearScrollTimers();
					return;
				}
				if ( delay === 500 ) {
					notify( {
						type: 'info',
						message: __(
							'Open Dashboard → Page Cache and turn WooCommerce safe mode back on.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				}
			}, delay );
			scrollTimersRef.current.push( id );
		} );
	};

	/**
	 * Run the read-only WooCommerce cache self-test.
	 *
	 * Never dismisses the panel: the test proves cart/checkout bypass
	 * without writing settings, so auto-dismiss would hide onboarding
	 * before the user enables anything.
	 */
	const handleWooSelfTest = async () => {
		setActivatingStep( 'woo-verify' );
		dismiss();
		// Abort any previous in-flight test and clear its timeout first so
		// a rapid re-run can never let a stale timer misclassify the new
		// run's abort as a 5s timeout, nor let stale results win.
		if ( wooTimeoutRef.current ) {
			clearTimeout( wooTimeoutRef.current );
			wooTimeoutRef.current = null;
		}
		if ( wooAbortRef.current ) {
			wooAbortRef.current.abort();
		}
		const controller =
			typeof AbortController !== 'undefined'
				? new AbortController()
				: null;
		wooAbortRef.current = controller;
		wooTimedOutRef.current = false;
		if ( controller ) {
			wooTimeoutRef.current = setTimeout( () => {
				wooTimedOutRef.current = true;
				controller.abort();
			}, WOO_SELF_TEST_TIMEOUT_MS );
		}
		try {
			const res = await runWooSelfTest( controller?.signal );
			// Bail when this run is no longer current (a newer re-run
			// replaced it) or its signal was aborted — stale results must
			// never overwrite the latest run.
			if (
				wooAbortRef.current !== controller ||
				wooAbortRef.current?.signal?.aborted
			) {
				return;
			}
			if ( res?.success && res?.data ) {
				setWooSelfTest( res.data );
				const descriptor = getWooSelfTestNotice( res.data );
				notify( { ...descriptor, durationMs: 5000 } );
			} else {
				notify( {
					type: 'error',
					message: __(
						'Failed to run the WooCommerce self-test.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			// An abort from unmount cleanup (not the 5s timeout) must stay
			// silent: notifying or logging after unmount is spurious. The
			// timeout sets wooTimedOutRef before aborting, so an aborted
			// signal without the flag means unmount (or a superseded run).
			if ( controller?.signal?.aborted && ! wooTimedOutRef.current ) {
				return;
			}
			if ( error?.name === 'AbortError' || controller?.signal?.aborted ) {
				console.error(
					'Woo self-test timed out:',
					getErrorLogMessage( error )
				);
				notify( {
					type: 'error',
					message: sprintf(
						/* translators: %d: timeout in seconds, derived from WOO_SELF_TEST_TIMEOUT_MS. */
						_n(
							'The WooCommerce self-test timed out after %d second. Please retry.',
							'The WooCommerce self-test timed out after %d seconds. Please retry.',
							Math.round( WOO_SELF_TEST_TIMEOUT_MS / 1000 ),
							'performance-optimisation'
						),
						Math.round( WOO_SELF_TEST_TIMEOUT_MS / 1000 )
					),
					durationMs: 5000,
				} );
			} else {
				console.error(
					'Woo self-test failed:',
					getErrorLogMessage( error )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to run the WooCommerce self-test.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			}
		} finally {
			if ( wooTimeoutRef.current ) {
				clearTimeout( wooTimeoutRef.current );
				wooTimeoutRef.current = null;
			}
			// Guard the spinner clear to the current run: a superseded
			// first run must not clear the spinner while the second run
			// is still in flight.
			if ( wooAbortRef.current === controller ) {
				wooAbortRef.current = null;
				setActivatingStep( null );
			}
		}
	};

	const handleStepAction = async ( step ) => {
		if ( step?.action === 'woo-self-test' ) {
			await handleWooSelfTest();
			return;
		}
		setActivatingStep( step.key );
		dismiss();
		try {
			// Dismissal is gated on the settings update succeeding: the two
			// calls are not independent. Firing dismiss_welcome while
			// update_settings fails or rejects would hide the onboarding
			// panel server-side even though the feature was never enabled,
			// leaving the user no way to retry from the panel.
			const updateRes = await apiCall( 'update_settings', {
				tab: step.settings.tab,
				settings: step.settings.payload,
			} );

			if ( ! updateRes.success ) {
				notify( {
					type: 'error',
					message:
						updateRes.message ||
						__(
							'Failed to enable the feature.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
				return;
			}

			const dismissRes = await dismissWelcome().catch(
				( dismissError ) => {
					// The feature is enabled; a thrown dismiss request must
					// not masquerade as an enable failure. Surface the
					// dismiss-specific error and keep the panel visible.
					console.error(
						'Welcome dismiss failed:',
						getErrorLogMessage( dismissError )
					);
					return { success: false };
				}
			);
			if ( dismissRes.success ) {
				dismissedRef.current = true;
				setVisible( false );
			} else {
				// Partial success: the feature is enabled, but the panel
				// could not be dismissed server-side. Keep it visible and
				// surface the error — retrying re-issues an idempotent
				// update_settings, so recovery from the panel is safe.
				notify( {
					type: 'error',
					message:
						dismissRes.message ||
						__(
							'Failed to dismiss the welcome panel.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			console.error(
				'Welcome panel action failed:',
				getErrorLogMessage( error )
			);
			notify( {
				type: 'error',
				message: __(
					'Failed to enable the feature.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setActivatingStep( null );
		}
	};

	const handleDismiss = async () => {
		setDismissing( true );
		dismiss();
		try {
			const res = await dismissWelcome();
			if ( res.success ) {
				dismissedRef.current = true;
				setVisible( false );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to dismiss the welcome panel.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			console.error(
				'Welcome dismiss failed:',
				getErrorLogMessage( error )
			);
			notify( {
				type: 'error',
				message: __(
					'Failed to dismiss the welcome panel.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setDismissing( false );
		}
	};

	return (
		<FeatureCard
			className="wppo-welcome-panel"
			title={ __(
				'Welcome to Performance Optimisation',
				'performance-optimisation'
			) }
			footer={
				<LoadingSubmitButton
					type="button"
					className="wppo-button wppo-button--secondary"
					onClick={ handleDismiss }
					isLoading={ dismissing }
					label={ __( 'Got it', 'performance-optimisation' ) }
					loadingLabel={ __(
						'Dismissing…',
						'performance-optimisation'
					) }
				/>
			}
		>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<p className="wppo-welcome-panel__intro">
				{ __(
					'Get started in 4 quick steps. Each toggle below activates a key performance feature — no page reload needed.',
					'performance-optimisation'
				) }
			</p>
			<div className="wppo-welcome-steps">
				{ STEPS.map( ( step ) => {
					const isWooStep = step.action === 'woo-self-test';
					const isStepActive = activatingStep === step.key;
					let stepVisibleLabel;
					if ( isStepActive ) {
						stepVisibleLabel = isWooStep
							? __( 'Running…', 'performance-optimisation' )
							: __( 'Enabling…', 'performance-optimisation' );
					} else if ( isWooStep ) {
						stepVisibleLabel = __(
							'Run test',
							'performance-optimisation'
						);
					} else {
						stepVisibleLabel = __(
							'Enable',
							'performance-optimisation'
						);
					}
					const wooVerified =
						isWooStep && wooSelfTest?.all_pass === true;
					const enabled = isWooStep ? wooVerified : step.isEnabled();
					// Explicit tri-state result copy: a malformed payload
					// with all_pass missing must never render FAIL copy or
					// the fix CTA with no evidence of failure.
					let wooResultCopy = '';
					if ( isWooStep && wooSelfTest ) {
						if ( wooSelfTest.all_pass === true ) {
							wooResultCopy = __(
								'Cart, checkout and fragments bypass the cache (PASS).',
								'performance-optimisation'
							);
						} else if ( wooSelfTest.all_pass === false ) {
							wooResultCopy = __(
								'A dynamic route looks cacheable (FAIL).',
								'performance-optimisation'
							);
						} else {
							wooResultCopy = __(
								'Result inconclusive — please re-run the test.',
								'performance-optimisation'
							);
						}
					}
					return (
						<div
							key={ step.key }
							className={ `wppo-welcome-step${
								enabled ? ' wppo-welcome-step--done' : ''
							}` }
						>
							<span className="wppo-welcome-step__number">
								{ enabled ? (
									<svg
										width="16"
										height="16"
										viewBox="0 0 16 16"
										fill="none"
										aria-hidden="true"
									>
										<path
											d="M13.3 4.3L6 11.6 2.7 8.3"
											stroke="currentColor"
											strokeWidth="2"
											strokeLinecap="round"
											strokeLinejoin="round"
										/>
									</svg>
								) : (
									step.number
								) }
							</span>
							<div className="wppo-welcome-step__content">
								<strong className="wppo-welcome-step__label">
									{ step.getLabel() }
								</strong>
								<p className="wppo-welcome-step__desc">
									{ step.getDescription() }
								</p>
								{ isWooStep &&
									wooSelfTest &&
									! wooSelfTest.runnable && (
										<p className="wppo-text-muted wppo-text-small">
											{ __(
												'The self-test could not run. Default exclusion paths are shown read-only; dynamic pages fail open to uncached.',
												'performance-optimisation'
											) }
										</p>
									) }
								{ isWooStep &&
									wooSelfTest?.runnable &&
									! wooSelfTest?.woo_active && (
										<p className="wppo-text-muted wppo-text-small">
											{ __(
												'WooCommerce is not active — showing default exclusion paths read-only. Dynamic pages fail open to uncached.',
												'performance-optimisation'
											) }
										</p>
									) }
								{ isWooStep && wooSelfTest && (
									<p className="wppo-text-muted wppo-text-small">
										{ sprintf(
											/* translators: 1: safe mode state (On/Off), 2: comma-separated excluded paths, 3: self-test result */
											__(
												'Safe mode: %1$s • Excluded paths: %2$s • %3$s',
												'performance-optimisation'
											),
											wooSelfTest.safe_mode
												? __(
														'On',
														'performance-optimisation'
												  )
												: __(
														'Off',
														'performance-optimisation'
												  ),
											Array.isArray(
												wooSelfTest.excluded_paths
											)
												? wooSelfTest.excluded_paths.join(
														', '
												  )
												: '',
											wooResultCopy
										) }
									</p>
								) }
								{ isWooStep &&
									shouldShowWooFixCta( wooSelfTest ) && (
										<p className="wppo-text-muted wppo-text-small">
											{ __(
												'Force-excluding dynamic routes plus cookie bypass.',
												'performance-optimisation'
											) }{ ' ' }
											<button
												type="button"
												className="wppo-button wppo-button--secondary wppo-button--sm"
												onClick={
													handleWooFailNavigate
												}
											>
												{ __(
													'Enable safe mode in Dashboard → Page Cache',
													'performance-optimisation'
												) }
											</button>
										</p>
									) }
							</div>
							<div className="wppo-welcome-step__action">
								{ enabled ? (
									<span className="wppo-welcome-step__check">
										{ __(
											'Active',
											'performance-optimisation'
										) }
									</span>
								) : (
									<LoadingSubmitButton
										type="button"
										className="wppo-button wppo-button--primary"
										isLoading={
											activatingStep === step.key
										}
										aria-label={ getStepAriaLabel(
											step,
											isStepActive,
											isWooStep,
											stepVisibleLabel
										) }
										onClick={ () =>
											handleStepAction( step )
										}
										label={
											isWooStep
												? __(
														'Run test',
														'performance-optimisation'
												  )
												: __(
														'Enable',
														'performance-optimisation'
												  )
										}
										loadingLabel={
											isWooStep
												? __(
														'Running…',
														'performance-optimisation'
												  )
												: __(
														'Enabling…',
														'performance-optimisation'
												  )
										}
									/>
								) }
							</div>
						</div>
					);
				} ) }
			</div>
		</FeatureCard>
	);
};

export default WelcomePanel;
