/**
 * GuidedNextStep component.
 *
 * Shows a single RUM-driven next action from GET /suggestions
 * (`next_action`, picked server-side from real-user Core Web Vitals) plus a
 * server-type note (Apache/Nginx/LiteSpeed detection), so users see exactly
 * one thing to do next instead of a wall of suggestions. The full list
 * stays in SuggestionsPanel below; this card is additive.
 *
 * Fail-open: a failed fetch hides the card body behind a NoticeBanner error
 * and never blocks the rest of the Dashboard.
 *
 * @since 2.3.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCompass,
	faArrowRight,
	faServer,
	faSpinner,
} from '@fortawesome/free-solid-svg-icons';
import { apiCall, getErrorLogMessage } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import NoticeBanner from './common/NoticeBanner';
import FeatureCard from './common/FeatureCard';
import StatusBadge from './common/StatusBadge';
import {
	FIX_ACTION_TAB_MAP,
	formatValue,
	getFixActionLabel,
} from './SuggestionsPanel';

/**
 * Server-type note copy keyed by the detected server software.
 *
 * @param {string} serverType 'apache' | 'nginx' | 'litespeed' | 'other'.
 * @return {string} Localized note.
 */
export const serverTypeNote = ( serverType ) => {
	switch ( serverType ) {
		case 'apache':
			return __(
				'Server: Apache — .htaccess rules apply automatically when enabled.',
				'performance-optimisation'
			);
		case 'nginx':
			return __(
				'Server: Nginx — .htaccess is ignored here; copy the generated Nginx rules into your server block.',
				'performance-optimisation'
			);
		case 'litespeed':
			return __(
				'Server: LiteSpeed — Apache-compatible, so .htaccess rules apply. Check the LiteSpeed banner above for the coexistence mode.',
				'performance-optimisation'
			);
		default:
			return __(
				'Server: unknown — file-based optimizations still apply; server rules may need manual setup.',
				'performance-optimisation'
			);
	}
};

/**
 * @param {Object}   props
 * @param {Function} [props.onNavigate] Callback to switch the active WPPO tab.
 * @return {Element} The guided next-step card.
 */
const GuidedNextStep = ( { onNavigate } ) => {
	const [ nextAction, setNextAction ] = useState( null );
	const [ serverType, setServerType ] = useState( 'other' );
	const [ loaded, setLoaded ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	const load = useCallback(
		async ( signal ) => {
			dismiss();
			try {
				const response = await apiCall(
					'suggestions',
					{},
					'GET',
					signal
				);
				if ( signal?.aborted ) {
					return;
				}
				if ( response?.success && response?.data ) {
					setNextAction( response.data.next_action ?? null );
					setServerType(
						typeof response.data.server_type === 'string'
							? response.data.server_type
							: 'other'
					);
				} else {
					notify( {
						type: 'error',
						message:
							response?.message ||
							__(
								'Failed to load the guided next step.',
								'performance-optimisation'
							),
					} );
				}
			} catch ( loadError ) {
				if ( signal?.aborted || loadError?.name === 'AbortError' ) {
					return;
				}
				console.error(
					'Error fetching guided next step:',
					getErrorLogMessage( loadError )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to load the guided next step.',
						'performance-optimisation'
					),
				} );
			} finally {
				if ( ! signal?.aborted ) {
					setLoaded( true );
				}
			}
		},
		[ dismiss, notify ]
	);

	useEffect( () => {
		const controller =
			typeof AbortController !== 'undefined'
				? new AbortController()
				: null;
		load( controller?.signal );
		return () => controller?.abort();
	}, [ load ] );

	const targetTab = nextAction
		? FIX_ACTION_TAB_MAP[ nextAction.fix_action ] ?? null
		: null;
	const canFix =
		!! nextAction && nextAction.status !== 'good' && targetTab !== null;

	return (
		<FeatureCard
			title={ __( 'Guided Next Step', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faCompass } aria-hidden="true" /> }
		>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			{ ! loaded && ! notice && (
				<p className="wppo-text-muted">
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						aria-hidden="true"
					/>{ ' ' }
					{ __(
						'Checking real-user metrics…',
						'performance-optimisation'
					) }
				</p>
			) }
			{ loaded && ! nextAction && ! notice && (
				<p className="wppo-text-muted">
					{ __(
						'No urgent next step — your real-user metrics look good. Enable RUM in Performance Audit to keep watching.',
						'performance-optimisation'
					) }
				</p>
			) }
			{ nextAction && (
				<div
					className={ `wppo-suggestion-card wppo-suggestion-card--${ nextAction.status }` }
					role="listitem"
				>
					<div className="wppo-suggestion-card__header">
						<span className="wppo-suggestion-card__description">
							{ nextAction.description }
						</span>
						<StatusBadge status={ nextAction.status } />
					</div>
					<div className="wppo-suggestion-card__body">
						<span className="wppo-suggestion-card__value">
							{ formatValue( nextAction.value, nextAction.unit ) }
						</span>
						{ canFix && (
							<button
								type="button"
								className="wppo-button wppo-button--sm wppo-button--primary"
								onClick={ () => onNavigate?.( targetTab ) }
								aria-label={ sprintf(
									/* translators: 1: existing feature destination, 2: suggestion description. */
									__(
										'%1$s: %2$s',
										'performance-optimisation'
									),
									getFixActionLabel( nextAction.fix_action ),
									nextAction.description
								) }
							>
								{ getFixActionLabel( nextAction.fix_action ) }
								<FontAwesomeIcon
									icon={ faArrowRight }
									className="wppo-ml-6"
								/>
							</button>
						) }
					</div>
				</div>
			) }
			{ loaded && (
				<p
					className="wppo-text-muted wppo-text-small wppo-mt-8"
					role="status"
					aria-live="polite"
				>
					<FontAwesomeIcon icon={ faServer } aria-hidden="true" />{ ' ' }
					{ serverTypeNote( serverType ) }
				</p>
			) }
		</FeatureCard>
	);
};

export default GuidedNextStep;
