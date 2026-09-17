/**
 * SuggestionsPanel component.
 *
 * Renders one card per suggestion returned by the Suggestion_Engine.
 * Cards with 'poor' or 'needs_improvement' status show a "Fix It" button
 * that navigates the user directly to the relevant WPPO tab.
 * Cards with 'good' status show a passing indicator instead.
 *
 * Sits inside the Dashboard tab, directly below <PerformanceAudit />,
 * so the user sees diagnosis and remedy on the same screen.
 *
 * @since 1.6.0
 */

import { memo } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCheckCircle,
	faExclamationTriangle,
	faTimesCircle,
	faArrowRight,
	faLightbulb,
} from '@fortawesome/free-solid-svg-icons';
import StatusBadge from './common/StatusBadge';

import { __, sprintf } from '@wordpress/i18n';
import {
	formatCountableUnit,
	formatValue,
	suggestionKey,
} from '../lib/suggestions';

// Re-exported for backward compatibility (tests and AiPanel historically
// imported these from this module); the canonical home is ../lib/suggestions.
export { formatCountableUnit, formatValue, suggestionKey };

/**
 * Maps fix_action values to WPPO sidebar tab names.
 * Must stay in sync with App.js sidebarItems names.
 *
 * @type {Object.<string, string>}
 */
const FIX_ACTION_TAB_MAP = {
	open_object_cache_tab: 'objectCache',
	open_image_optimization_tab: 'imageOptimization',
	open_file_optimization_tab: 'fileOptimization',
	open_ccss_settings: 'fileOptimization',
	enable_server_rules: 'fileOptimization',
	open_preload_tab: 'preload',
	no_action_required: null,
};

/**
 * Status icon for a suggestion card.
 *
 * @param {Object} props
 * @param {string} props.status 'good' | 'needs_improvement' | 'poor'
 */
const SuggestionIcon = ( { status } ) => {
	if ( status === 'good' ) {
		return (
			<FontAwesomeIcon
				icon={ faCheckCircle }
				className="wppo-suggestion-icon wppo-suggestion-icon--good"
				aria-hidden="true"
			/>
		);
	}
	if ( status === 'needs_improvement' ) {
		return (
			<FontAwesomeIcon
				icon={ faExclamationTriangle }
				className="wppo-suggestion-icon wppo-suggestion-icon--warning"
				aria-hidden="true"
			/>
		);
	}
	return (
		<FontAwesomeIcon
			icon={ faTimesCircle }
			className="wppo-suggestion-icon wppo-suggestion-icon--poor"
			aria-hidden="true"
		/>
	);
};

/**
 * A single suggestion card.
 *
 * @param {Object}   props
 * @param {Object}   props.suggestion Suggestion object from Suggestion_Engine.
 * @param {Function} props.onNavigate Callback to switch the active WPPO tab.
 */
const SuggestionCard = ( { suggestion, onNavigate } ) => {
	const {
		value,
		unit,
		status,
		description,
		fix_action: fixAction,
	} = suggestion;
	const targetTab = FIX_ACTION_TAB_MAP[ fixAction ] ?? null;
	const canFix = status !== 'good' && targetTab !== null;

	return (
		<div
			className={ `wppo-suggestion-card wppo-suggestion-card--${ status }` }
			role="listitem"
		>
			<div className="wppo-suggestion-card__header">
				<SuggestionIcon status={ status } />
				<span className="wppo-suggestion-card__description">
					{ description }
				</span>
				<StatusBadge status={ status } />
			</div>

			<div className="wppo-suggestion-card__body">
				<span className="wppo-suggestion-card__value">
					{ formatValue( value, unit ) }
				</span>

				{ canFix && (
					<button
						type="button"
						className="wppo-button wppo-button--sm wppo-button--primary"
						onClick={ () => onNavigate( targetTab ) }
						aria-label={ sprintf(
							/* translators: %s: suggestion description. */
							__( 'Fix It: %s', 'performance-optimisation' ),
							description
						) }
					>
						{ __( 'Fix It', 'performance-optimisation' ) }
						<FontAwesomeIcon
							icon={ faArrowRight }
							className="wppo-ml-6"
							aria-hidden="true"
						/>
					</button>
				) }

				{ ! canFix && status === 'good' && (
					<span className="wppo-suggestion-card__passing">
						<FontAwesomeIcon
							icon={ faCheckCircle }
							className="wppo-mr-4"
							aria-hidden="true"
						/>
						{ __( 'Passing', 'performance-optimisation' ) }
					</span>
				) }
			</div>
		</div>
	);
};

/**
 * SuggestionsPanel
 *
 * Renders the full suggestions list. Shown in the Dashboard tab directly
 * below <PerformanceAudit /> after a scan completes.
 *
 * @param {Object}   props
 * @param {Array}    props.suggestions Array of suggestion objects.
 * @param {Function} props.onNavigate  Callback to switch the active WPPO tab.
 */
const SuggestionsPanel = ( { suggestions, onNavigate } ) => {
	if ( ! suggestions || suggestions.length === 0 ) {
		return (
			<div className="wppo-suggestions-panel wppo-suggestions-panel--empty">
				<FontAwesomeIcon
					icon={ faCheckCircle }
					className="wppo-suggestions-panel__empty-icon"
					aria-hidden="true"
				/>
				<p>
					{ __(
						'No suggestions — your site looks great!',
						'performance-optimisation'
					) }
				</p>
			</div>
		);
	}

	const issues = suggestions.filter( ( s ) => s.status !== 'good' );
	const passing = suggestions.filter( ( s ) => s.status === 'good' );

	return (
		<div className="wppo-suggestions-panel">
			<div className="wppo-suggestions-panel__header">
				<FontAwesomeIcon
					icon={ faLightbulb }
					className="wppo-mr-8"
					aria-hidden="true"
				/>
				<h3 className="wppo-suggestions-panel__title">
					{ __( 'Suggestions', 'performance-optimisation' ) }
				</h3>
				{ issues.length > 0 && (
					<span className="wppo-suggestions-panel__badge">
						{ issues.length }
					</span>
				) }
			</div>

			<p className="wppo-suggestions-panel__desc">
				{ __(
					'Based on your scan results, here are the recommended actions to improve performance.',
					'performance-optimisation'
				) }
			</p>

			<div
				className="wppo-suggestions-panel__list"
				role="list"
				aria-label={ __( 'Suggestions', 'performance-optimisation' ) }
			>
				{ /* Issues first */ }
				{ issues.map( ( suggestion, index ) => (
					<SuggestionCard
						key={ suggestionKey( suggestion, `issue-${ index }` ) }
						suggestion={ suggestion }
						onNavigate={ onNavigate }
					/>
				) ) }

				{ /* Passing items below */ }
				{ passing.map( ( suggestion, index ) => (
					<SuggestionCard
						key={ suggestionKey(
							suggestion,
							`passing-${ index }`
						) }
						suggestion={ suggestion }
						onNavigate={ onNavigate }
					/>
				) ) }
			</div>
		</div>
	);
};

export default memo( SuggestionsPanel );
