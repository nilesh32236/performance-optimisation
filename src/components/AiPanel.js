import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBrain } from '@fortawesome/free-solid-svg-icons';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';
import LoadingSubmitButton from './common/LoadingSubmitButton';

/**
 * AI Adaptive panel (N1).
 *
 * Toggle + Learn + suggestions with one-click Apply (never auto-enables).
 *
 * @since NEXT
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
	const [ saving, setSaving ] = useState( false );
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
				console.error( 'Failed to load AI model.', err );
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
				console.error( 'Failed to load AI suggestions.', err );
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
		return () => controller.abort();
	}, [ fetchModel, fetchSuggestions ] );

	const handleSave = async () => {
		setSaving( true );
		dismiss();
		try {
			const response = await apiCall( 'update_settings', {
				tab: 'ai_adaptive',
				settings: { enabled, use_wp_ai_client: useWpAiClient },
			} );
			if ( response.success ) {
				if (
					typeof wppoSettings !== 'undefined' &&
					wppoSettings.settings
				) {
					wppoSettings.settings = Object.freeze( {
						...wppoSettings.settings,
						ai_adaptive: Object.freeze( {
							enabled,
							use_wp_ai_client: useWpAiClient,
						} ),
					} );
				}
				notify( {
					type: 'success',
					message: __(
						'AI Adaptive settings saved.',
						'performance-optimisation'
					),
					durationMs: 3000,
				} );
				fetchSuggestions();
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
		} catch {
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
		} catch {
			notify( {
				type: 'error',
				message: __( 'Failed to learn.', 'performance-optimisation' ),
			} );
		} finally {
			setLearning( false );
		}
	};

	const handleApply = async ( suggestion ) => {
		const payload = suggestion.ai_payload;
		if ( ! payload ) {
			return;
		}
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
				if (
					typeof wppoSettings !== 'undefined' &&
					wppoSettings.settings
				) {
					wppoSettings.settings = Object.freeze( {
						...wppoSettings.settings,
						[ payload.tab ]: Object.freeze( merged ),
					} );
				}
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
				<div className="wppo-stacked-cards wppo-mt-16">
					<h4>
						{ __( 'AI Suggestions', 'performance-optimisation' ) }
					</h4>
					{ suggestions.map( ( s ) => (
						<div
							key={ `${ s.metric }::${ s.status }::${
								s.fix_action || ''
							}::${ s.description || '' }` }
							className="wppo-suggestion-card wppo-suggestion-card--needs_improvement"
						>
							<div className="wppo-suggestion-card__header">
								<span className="wppo-suggestion-card__description">
									{ s.description }
								</span>
								<span className="wppo-status-badge wppo-status-badge--warning">
									{ s.status }
								</span>
							</div>
							<div className="wppo-suggestion-card__body">
								<span className="wppo-suggestion-card__value">
									{ String( s.value ) }
								</span>
								{ s.ai_payload && (
									<button
										type="button"
										className="wppo-button wppo-button--sm wppo-button--primary"
										onClick={ () => handleApply( s ) }
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
							</div>
						</div>
					) ) }
				</div>
			) }
		</FeatureCard>
	);
};

export default AiPanel;
