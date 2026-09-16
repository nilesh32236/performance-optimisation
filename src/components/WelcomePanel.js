import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	apiCall,
	fetchWooCacheSelfTest,
	getErrorLogMessage,
	getWppoSettings,
} from '../lib/apiRequest';
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
 * @since NEXT
 * @param {Object}  step     Step entry from STEPS.
 * @param {boolean} isActive Whether the step action is in flight.
 * @param {boolean} isWoo    Whether this is the Woo self-test step.
 * @return {string} Accessible button label.
 */
export const getStepAriaLabel = ( step, isActive, isWoo ) => {
	const label = step?.label ?? '';
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
			__( 'Enabling %s…', 'performance-optimisation' ),
			label
		);
	}
	if ( isWoo ) {
		return sprintf(
			/* translators: %s: feature name */
			__( 'Run %s', 'performance-optimisation' ),
			label
		);
	}
	return sprintf(
		/* translators: %s: feature name */
		__( 'Enable %s', 'performance-optimisation' ),
		label
	);
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
 * @return {void}
 */
export const scrollToWooSafeMode = () => {
	if ( typeof document === 'undefined' ) {
		return;
	}
	const anchor = document.getElementById( 'wppoWooSafeMode' );
	if ( ! anchor ) {
		return;
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
};

const STEPS = [
	{
		number: 1,
		key: 'cache',
		label: __( 'Enable Page Caching', 'performance-optimisation' ),
		description: __(
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
		label: __( 'Enable JS / CSS Minification', 'performance-optimisation' ),
		description: __(
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
		label: __( 'Enable Lazy Loading', 'performance-optimisation' ),
		description: __(
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
		label: __(
			'Verify WooCommerce Cart Bypass',
			'performance-optimisation'
		),
		description: __(
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
	// never call setWooSelfTest/notify after the panel is gone.
	useEffect( () => {
		return () => {
			if ( wooAbortRef.current ) {
				wooAbortRef.current.abort();
			}
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
	 * correct if ever mounted elsewhere) and then scroll/focus the switch.
	 *
	 * @since NEXT
	 * @return {void}
	 */
	const handleWooFailNavigate = () => {
		if ( typeof onNavigate === 'function' ) {
			onNavigate( 'dashboard' );
		}
		// Dashboard is already mounted in this path, so the anchor exists
		// synchronously; defer one tick so a tab switch (if any) commits.
		setTimeout( scrollToWooSafeMode, 0 );
	};

	/**
	 * Run the read-only WooCommerce cache self-test.
	 *
	 * Never dismisses the panel: the test proves cart/checkout bypass
	 * without writing settings, so auto-dismiss would hide onboarding
	 * before the user enables anything.
	 *
	 * @since NEXT
	 */
	const handleWooSelfTest = async () => {
		setActivatingStep( 'woo-verify' );
		dismiss();
		const controller =
			typeof AbortController !== 'undefined'
				? new AbortController()
				: null;
		wooAbortRef.current = controller;
		const timeoutId = controller
			? setTimeout( () => controller.abort(), 5000 )
			: null;
		try {
			const res = await fetchWooCacheSelfTest( controller?.signal );
			if ( wooAbortRef.current?.signal?.aborted ) {
				return;
			}
			if ( res?.success && res?.data ) {
				setWooSelfTest( res.data );
				if ( ! res.data.runnable || ! res.data.woo_active ) {
					notify( {
						type: 'info',
						message: res.data.woo_active
							? __(
									'The self-test could not run. Default exclusion paths are shown read-only; dynamic pages fail open to uncached.',
									'performance-optimisation'
							  )
							: __(
									'WooCommerce is not active — showing default exclusion paths read-only. Dynamic pages fail open to uncached.',
									'performance-optimisation'
							  ),
						durationMs: 5000,
					} );
				} else if ( res.data.all_pass ) {
					notify( {
						type: 'success',
						message: __(
							'WooCommerce self-test passed: cart, checkout and account pages bypass the cache; the guest cart survives.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				} else {
					notify( {
						type: 'warning',
						message: __(
							'WooCommerce self-test found a cacheable dynamic route. Re-enable safe mode in Dashboard → Page Cache.',
							'performance-optimisation'
						),
						durationMs: 5000,
					} );
				}
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
			if ( error?.name === 'AbortError' || controller?.signal?.aborted ) {
				console.error(
					'Woo self-test timed out:',
					getErrorLogMessage( error )
				);
				notify( {
					type: 'error',
					message: __(
						'The WooCommerce self-test timed out after 5 seconds. Please retry.',
						'performance-optimisation'
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
			if ( timeoutId ) {
				clearTimeout( timeoutId );
			}
			if ( wooAbortRef.current === controller ) {
				wooAbortRef.current = null;
			}
			setActivatingStep( null );
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
					console.error( 'Welcome dismiss failed:', dismissError );
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
			console.error( 'Welcome panel action failed:', error );
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
			console.error( 'Welcome dismiss failed:', error );
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
					const wooVerified = isWooStep && !! wooSelfTest?.all_pass;
					const enabled = isWooStep ? wooVerified : step.isEnabled();
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
									{ step.label }
								</strong>
								<p className="wppo-welcome-step__desc">
									{ step.description }
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
										{ __(
											'Excluded paths:',
											'performance-optimisation'
										) }{ ' ' }
										{ Array.isArray(
											wooSelfTest.excluded_paths
										)
											? wooSelfTest.excluded_paths.join(
													', '
											  )
											: '' }
										{ ' • ' }
										{ wooSelfTest.all_pass
											? __(
													'Cart, checkout and fragments bypass the cache (PASS).',
													'performance-optimisation'
											  )
											: __(
													'A dynamic route looks cacheable (FAIL).',
													'performance-optimisation'
											  ) }
									</p>
								) }
								{ isWooStep &&
									wooSelfTest?.runnable &&
									! wooSelfTest?.all_pass && (
										<p className="wppo-text-muted wppo-text-small">
											{ __(
												'Force-excluding dynamic routes plus cookie bypass.',
												'performance-optimisation'
											) }{ ' ' }
											{ typeof onNavigate ===
											'function' ? (
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
											) : (
												<a href="#wppoWooSafeMode">
													{ __(
														'Enable safe mode in Dashboard → Page Cache',
														'performance-optimisation'
													) }
												</a>
											) }
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
											activatingStep === step.key,
											isWooStep
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
