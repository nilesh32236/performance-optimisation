import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBrain } from '@fortawesome/free-solid-svg-icons';
import {
	apiCall,
	getErrorLogMessage,
	patchSettingsCache,
} from '../lib/apiRequest';
import { suggestionKey, formatValue } from './SuggestionsPanel';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import StatusBadge from './common/StatusBadge';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';
import LoadingSubmitButton from './common/LoadingSubmitButton';

/**
 * AI Adaptive panel (N1).
 *
 * Toggle + Learn + suggestions with one-click Apply (never auto-enables).
 *
 * @since 2.0.0
 */
const AiPanel = () => {
	const initial =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.ai_adaptive || {}
			: {};

	const [ enabled, setEnabled ] = useState( !! initial.enabled );
	const [ useWpAiClient, setUseWpAiClient ] = useState(
		!! initial.use_wp_ai_client
	);
	const [ cssRefreshOnLcpRegression, setCssRefreshOnLcpRegression ] =
		useState( !! initial.css_refresh_on_lcp_regression );
	const [ saving, setSaving ] = useState( false );
	const suggestRunRef = useRef( null );
	const [ learning, setLearning ] = useState( false );
	const [ model, setModel ] = useState( null );
	const [ suggestions, setSuggestions ] = useState( [] );
	const { notice, notify, dismiss } = useNotice();

	const fetchModel = useCallback(
		async ( signal ) => {
			try {
				const res = await apiCall( 'ai_model', {}, 'GET', signal );
				if ( res.success && ! signal?.aborted ) {
					setModel( res.data );
				}
			} catch ( err ) {
				if ( err?.name === 'AbortError' || signal?.aborted ) {
					return;
				}
				console.error(
					'Failed to load AI model.',
					getErrorLogMessage( err )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to load AI model.',
						'performance-optimisation'
					),
				} );
			}
		},
		[ notify ]
	);

	const fetchSuggestions = useCallback(
		async ( signal ) => {
			try {
				const res = await apiCall(
					'ai_suggestions',
					{},
					'GET',
					signal
				);
				if (
					res.success &&
					res.data?.suggestions &&
					! signal?.aborted
				) {
					setSuggestions( res.data.suggestions );
				}
			} catch ( err ) {
				if ( err?.name === 'AbortError' || signal?.aborted ) {
					return;
				}
				console.error(
					'Failed to load AI suggestions.',
					getErrorLogMessage( err )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to load AI suggestions.',
						'performance-optimisation'
					),
				} );
			}
		},
		[ notify ]
	);

	useEffect( () => {
		const controller = new AbortController();
		fetchModel( controller.signal );
		fetchSuggestions( controller.signal );
		return () => {
			controller.abort();
			if ( suggestRunRef.current ) {
				suggestRunRef.current.abort();
				suggestRunRef.current = null;
			}
		};
	}, [ fetchModel, fetchSuggestions ] );

	const handleSave = async () => {
		setSaving( true );
		dismiss();
		try {
			const dismissed =
				typeof wppoSettings !== 'undefined' &&
				Array.isArray(
					wppoSettings.settings?.ai_adaptive?.dismissed_suggestions
				)
					? wppoSettings.settings.ai_adaptive.dismissed_suggestions
					: [];
			const response = await apiCall( 'update_settings', {
				tab: 'ai_adaptive',
				settings: {
					enabled,
					use_wp_ai_client: useWpAiClient,
					css_refresh_on_lcp_regression: cssRefreshOnLcpRegression,
					dismissed_suggestions: dismissed,
				},
			} );
			if ( response.success ) {
				patchSettingsCache( 'ai_adaptive', {
					enabled,
					use_wp_ai_client: useWpAiClient,
					css_refresh_on_lcp_regression: cssRefreshOnLcpRegression,
					dismissed_suggestions: dismissed,
				} );
				notify( {
					type: 'success',
					message: __(
						'AI Adaptive settings saved.',
						'performance-optimisation'
					),
					durationMs: 3000,
				} );
				// Audit #1420: abort the previous post-save refresh so a
				// stale response cannot overwrite newer state after rapid saves.
				if ( suggestRunRef.current ) {
					suggestRunRef.current.abort();
				}
				suggestRunRef.current = new AbortController();
				fetchSuggestions( suggestRunRef.current.signal ).catch(
					() => {}
				);
			} else {
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'Failed to save AI settings.',
							'performance-optimisation'
						),
				} );
			}
		} catch ( saveError ) {
			console.error(
				'Save AI settings failed:',
				getErrorLogMessage( saveError )
			);
			notify( {
				type: 'error',
				message: __(
					'Failed to save AI settings.',
					'performance-optimisation'
				),
			} );
		} finally {
			setSaving( false );
		}
	};

	const handleLearn = async () => {
		setLearning( true );
		dismiss();
		try {
			const res = await apiCall( 'ai_learn', {}, 'POST' );
			if ( res.success ) {
				setModel( res.data );
				notify( {
					type: 'success',
					message: __(
						'AI model updated.',
						'performance-optimisation'
					),
					durationMs: 3000,
				} );
				fetchSuggestions();
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__( 'Failed to learn.', 'performance-optimisation' ),
				} );
			}
		} catch ( learnError ) {
			console.error( 'Learn failed:', getErrorLogMessage( learnError ) );
			notify( {
				type: 'error',
				message: __( 'Failed to learn.', 'performance-optimisation' ),
			} );
		} finally {
			setLearning( false );
		}
	};

	const [ applyingMetrics, setApplyingMetrics ] = useState( [] );
	const handleApply = async ( suggestion ) => {
		const payload = suggestion.ai_payload;
		if ( ! payload ) {
			return;
		}
		// Audit #1354: disable while in flight so double-clicks cannot
		// fire duplicate update_settings requests.
		if ( applyingMetrics.includes( suggestion.metric ) ) {
			return;
		}
		setApplyingMetrics( ( prev ) => [ ...prev, suggestion.metric ] );
		try {
			const currentTabSettings =
				typeof wppoSettings !== 'undefined'
					? wppoSettings.settings?.[ payload.tab ] || {}
					: {};
			const merged = { ...currentTabSettings, ...payload.settings };
			const res = await apiCall( 'update_settings', {
				tab: payload.tab,
				settings: merged,
			} );
			if ( res.success ) {
				patchSettingsCache( payload.tab, merged );
				notify( {
					type: 'success',
					message: __(
						'Suggestion applied.',
						'performance-optimisation'
					),
					durationMs: 3000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to apply suggestion.',
							'performance-optimisation'
						),
				} );
			}
		} catch {
			notify( {
				type: 'error',
				message: __(
					'Failed to apply suggestion.',
					'performance-optimisation'
				),
			} );
		} finally {
			setApplyingMetrics( ( prev ) =>
				prev.filter( ( metric ) => metric !== suggestion.metric )
			);
		}
	};

	const handleDismiss = async ( suggestion ) => {
		const metric = suggestion?.metric;
		if ( ! metric ) {
			return;
		}
		const current =
			typeof wppoSettings !== 'undefined' &&
			Array.isArray(
				wppoSettings.settings?.ai_adaptive?.dismissed_suggestions
			)
				? [ ...wppoSettings.settings.ai_adaptive.dismissed_suggestions ]
				: [];
		if ( ! current.includes( metric ) ) {
			current.push( metric );
		}
		// Optimistic hide so dismissal feels instant; refetch on failure.
		setSuggestions( ( prev ) =>
			prev.filter( ( s ) => s.metric !== metric )
		);
		try {
			const res = await apiCall( 'update_settings', {
				tab: 'ai_adaptive',
				settings: {
					enabled,
					use_wp_ai_client: useWpAiClient,
					css_refresh_on_lcp_regression: cssRefreshOnLcpRegression,
					dismissed_suggestions: current,
				},
			} );
			if ( res.success ) {
				patchSettingsCache( 'ai_adaptive', {
					enabled,
					use_wp_ai_client: useWpAiClient,
					css_refresh_on_lcp_regression: cssRefreshOnLcpRegression,
					dismissed_suggestions: [ ...current ],
				} );
				notify( {
					type: 'success',
					message: __(
						'Suggestion dismissed.',
						'performance-optimisation'
					),
					durationMs: 3000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to dismiss suggestion.',
							'performance-optimisation'
						),
				} );
				fetchSuggestions();
			}
		} catch {
			notify( {
				type: 'error',
				message: __(
					'Failed to dismiss suggestion.',
					'performance-optimisation'
				),
			} );
			fetchSuggestions();
		}
	};

	const [ regeneratingMetrics, setRegeneratingMetrics ] = useState( [] );
	const regenerateKey = ( suggestion ) =>
		suggestion?.ai_payload?.css_refresh?.post_id ||
		suggestion?.ai_payload?.css_refresh?.url ||
		suggestion.metric;
	const handleRegenerateCss = async ( suggestion ) => {
		const postId = suggestion?.ai_payload?.css_refresh?.post_id;
		if ( ! postId ) {
			return;
		}
		const key = regenerateKey( suggestion );
		if ( regeneratingMetrics.includes( key ) ) {
			return;
		}
		setRegeneratingMetrics( ( prev ) => [ ...prev, key ] );
		try {
			const res = await apiCall( 'used_css_regenerate', {
				post_id: postId,
			} );
			notify( {
				type: res.success ? 'success' : 'error',
				message:
					res.message ||
					( res.success
						? __(
								'Used CSS regeneration queued.',
								'performance-optimisation'
						  )
						: __(
								'Failed to regenerate used CSS.',
								'performance-optimisation'
						  ) ),
				durationMs: 3000,
			} );
		} catch ( err ) {
			console.error(
				'Failed to regenerate used CSS.',
				getErrorLogMessage( err )
			);
			notify( {
				type: 'error',
				message: __(
					'Failed to regenerate used CSS.',
					'performance-optimisation'
				),
			} );
		} finally {
			setRegeneratingMetrics( ( prev ) =>
				prev.filter( ( metric ) => metric !== key )
			);
		}
	};

	return (
		<FeatureCard
			title={ __( 'AI Adaptive', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faBrain } aria-hidden="true" /> }
		>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<SwitchField
				label={ __( 'Enable AI Adaptive', 'performance-optimisation' ) }
				description={ __(
					'Learn from RUM and trends to suggest script excludes and speculation prefetch. Never auto-enables — suggestions require confirmation.',
					'performance-optimisation'
				) }
				name="aiAdaptiveEnabled"
				checked={ enabled }
				onChange={ ( e ) => setEnabled( e.target.checked ) }
			/>
			<SwitchField
				label={ __(
					'Use WordPress AI client when available',
					'performance-optimisation'
				) }
				description={ __(
					'Off by default, falls back to local heuristic. No remote calls unless explicitly enabled.',
					'performance-optimisation'
				) }
				name="aiAdaptiveUseWpAiClient"
				checked={ useWpAiClient }
				onChange={ ( e ) => setUseWpAiClient( e.target.checked ) }
			/>
			<SwitchField
				label={ __(
					'Auto-refresh CSS on LCP regression',
					'performance-optimisation'
				) }
				description={ __(
					'Off by default (suggest-only). When on, a field LCP regression queues at most one used-CSS regeneration per URL per cooldown window.',
					'performance-optimisation'
				) }
				name="aiAdaptiveCssRefreshOnLcpRegression"
				checked={ cssRefreshOnLcpRegression }
				onChange={ ( e ) =>
					setCssRefreshOnLcpRegression( e.target.checked )
				}
			/>
			<p className="wppo-text-muted wppo-text-small">
				{ __(
					'Toggle is gated by wppo_ai_adaptive_enabled filter.',
					'performance-optimisation'
				) }
			</p>
			<div className="wppo-feature-card__footer">
				<LoadingSubmitButton
					className="wppo-button wppo-button--primary"
					onClick={ handleSave }
					isLoading={ saving }
					label={ __(
						'Save AI Settings',
						'performance-optimisation'
					) }
					loadingLabel={ __( 'Saving…', 'performance-optimisation' ) }
				/>
				<LoadingSubmitButton
					className="wppo-button wppo-button--secondary wppo-ml-8"
					onClick={ handleLearn }
					isLoading={ learning }
					label={ __( 'Learn Now', 'performance-optimisation' ) }
					loadingLabel={ __(
						'Learning…',
						'performance-optimisation'
					) }
				/>
			</div>
			{ model && model.updated_at && (
				<p className="wppo-text-muted wppo-text-small wppo-mt-12">
					{ __( 'Model updated:', 'performance-optimisation' ) }{ ' ' }
					{ new Date( model.updated_at * 1000 ).toLocaleString() }{ ' ' }
					{ model.source ? `(${ model.source })` : '' }
				</p>
			) }
			{ suggestions.length > 0 && (
				<div className="wppo-stacked-cards wppo-mt-16" role="list">
					<h4>
						{ __( 'AI Suggestions', 'performance-optimisation' ) }
					</h4>
					{ suggestions.map( ( s, index ) => (
						<div
							key={ suggestionKey( s, `ai-${ index }` ) }
							className="wppo-suggestion-card wppo-suggestion-card--needs_improvement"
							role="listitem"
						>
							<div className="wppo-suggestion-card__header">
								<span className="wppo-suggestion-card__description">
									{ s.description }
								</span>
								{ /* Audit #1354: translated badge instead of raw status. */ }
								<StatusBadge status={ s.status } />
							</div>
							<div className="wppo-suggestion-card__body">
								<span className="wppo-suggestion-card__value">
									{ formatValue( s.value, s.unit ) }
								</span>
								{ s.metric === 'ai_lcp_regression' &&
									s.ai_payload?.css_refresh && (
										<span className="wppo-text-muted wppo-text-small">
											{ sprintf(
												// translators: %1$s is the before LCP in ms, %2$s is the current LCP in ms.
												__(
													'LCP before: %1$sms, after: %2$sms',
													'performance-optimisation'
												),
												Math.round(
													s.ai_payload.css_refresh
														.before_lcp ?? 0
												),
												Math.round(
													s.ai_payload.css_refresh
														.current_lcp ?? 0
												)
											) }
											{ s.ai_payload.css_refresh.queued &&
												' ' +
													__(
														'(refresh queued)',
														'performance-optimisation'
													) }
										</span>
									) }
								{ s.metric === 'ai_lcp_regression' &&
									s.ai_payload?.css_refresh?.post_id > 0 && (
										<button
											type="button"
											className="wppo-button wppo-button--sm wppo-button--secondary wppo-ml-8"
											onClick={ () =>
												handleRegenerateCss( s )
											}
											disabled={ regeneratingMetrics.includes(
												regenerateKey( s )
											) }
											aria-label={ __(
												'Regenerate used CSS for the regressed URL',
												'performance-optimisation'
											) }
										>
											{ regeneratingMetrics.includes(
												regenerateKey( s )
											)
												? __(
														'Regenerating…',
														'performance-optimisation'
												  )
												: __(
														'Regenerate CSS',
														'performance-optimisation'
												  ) }
										</button>
									) }
								{ s.ai_payload && (
									<button
										type="button"
										className="wppo-button wppo-button--sm wppo-button--primary"
										onClick={ () => handleApply( s ) }
										disabled={ applyingMetrics.includes(
											s.metric
										) }
										aria-label={ sprintf(
											// translators: %s is the suggestion description.
											__(
												'Apply: %s',
												'performance-optimisation'
											),
											s.description
										) }
									>
										{ __(
											'Apply',
											'performance-optimisation'
										) }
									</button>
								) }
								{ s.metric && (
									<button
										type="button"
										className="wppo-button wppo-button--sm wppo-button--secondary wppo-ml-8"
										onClick={ () => handleDismiss( s ) }
										aria-label={ sprintf(
											// translators: %s is the suggestion description.
											__(
												'Dismiss: %s',
												'performance-optimisation'
											),
											s.description
										) }
									>
										{ __(
											'Dismiss',
											'performance-optimisation'
										) }
									</button>
								) }
							</div>
						</div>
					) ) }
				</div>
			) }
		</FeatureCard>
	);
};

export default AiPanel;
