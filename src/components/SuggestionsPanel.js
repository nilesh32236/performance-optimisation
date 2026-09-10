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

import { __ } from '@wordpress/i18n';

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
			/>
		);
	}
	if ( status === 'needs_improvement' ) {
		return (
			<FontAwesomeIcon
				icon={ faExclamationTriangle }
				className="wppo-suggestion-icon wppo-suggestion-icon--warning"
			/>
		);
	}
	return (
		<FontAwesomeIcon
			icon={ faTimesCircle }
			className="wppo-suggestion-icon wppo-suggestion-icon--poor"
		/>
	);
};

/**
 * Format a suggestion value for display.
 *
 * @param {*}      value Metric value.
 * @param {string} unit  Unit label.
 * @return {string} Formatted display string.
 */
export const formatValue = ( value, unit ) => {
	if ( value === null || value === undefined ) {
		return '—';
	}
	if ( unit === 'list' ) {
		if ( Array.isArray( value ) ) {
			return value.join( ', ' );
		}
		return String( value );
	}
	if ( unit === 'string' ) {
		return String( value );
	}
	if ( unit === 'boolean' ) {
		return value === 'pass'
			? __( 'Passing', 'performance-optimisation' )
			: __( 'Failing', 'performance-optimisation' );
	}
	if ( unit === 'header' ) {
		if ( value === 'none' ) {
			return __( 'None', 'performance-optimisation' );
		}
		// Always show Cache-Control value as-is, never translate the header text.
		return value;
	}
	if ( unit === 'encoding' ) {
		if ( value === 'none' ) {
			return __( 'None', 'performance-optimisation' );
		}
		// Map raw content-encoding values to human-readable form.
		const encodings = {
			br: 'Brotli',
			gzip: 'Gzip',
			deflate: 'Deflate',
			zstd: 'Zstd',
		};
		return encodings[ String( value ).toLowerCase() ] || value;
	}
	if ( unit === 'score' ) {
		return `${ Math.round( parseFloat( value ) * 100 ) } / 100`;
	}
	if ( unit === '%' ) {
		return `${ Number( value ).toFixed( 1 ) }%`;
	}
	if ( unit === 's' ) {
		return `${ Number( value ).toFixed( 2 ) }s`;
	}
	if ( unit === 'ms' ) {
		return `${ Math.round( value ) }ms`;
	}
	return `${ value } ${ unit }`;
};

/**
 * Build a stable key for a suggestion card.
 *
 * Suggestion objects carry no unique id (metric, status, fix_action,
 * description — see SuggestionCard above), so compose those fields into a
 * composite key instead of using the array index (which remounts cards and
 * loses internal state on filter/reorder).
 *
 * @since NEXT
 * @param {Object}        suggestion Suggestion object.
 * @param {number|string} [index]    Optional list index appended to disambiguate
 *                                   duplicates sharing all four fields.
 * @return {string} Stable composite key.
 */
export const suggestionKey = ( suggestion, index = null ) => {
	if ( ! suggestion ) {
		return `empty::${ index ?? '' }`;
	}
	const base = `${ suggestion.metric ?? '' }::${ suggestion.status ?? '' }::${
		suggestion.fix_action ?? ''
	}::${ suggestion.description ?? '' }`;
	return index === null || index === undefined
		? base
		: `${ base }::${ index }`;
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
						aria-label={ `${ __(
							'Fix It',
							'performance-optimisation'
						) }: ${ description }` }
					>
						{ __( 'Fix It', 'performance-optimisation' ) }
						<FontAwesomeIcon
							icon={ faArrowRight }
							className="wppo-ml-6"
						/>
					</button>
				) }

				{ ! canFix && status === 'good' && (
					<span className="wppo-suggestion-card__passing">
						<FontAwesomeIcon
							icon={ faCheckCircle }
							className="wppo-mr-4"
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
				<FontAwesomeIcon icon={ faLightbulb } className="wppo-mr-8" />
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
