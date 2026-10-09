/**
 * Shared presentational notice banner.
 *
 * Renders the `.wppo-notice` markup used across the admin SPA with the
 * correct modifier class, icon, ARIA live region semantics and an optional
 * dismiss button. Pair with the `useNotice()` hook for state and timing.
 *
 * @since 1.10.0
 * @param {Object}   props             Component props.
 * @param {string}   [props.type]      Notice type.
 * @param {string}   [props.message]   Notice text.
 * @param {Function} [props.onDismiss] Dismiss handler.
 * @param {string}   [props.className] Extra class names.
 * @param {Object}   [props.action]    Optional one-click action
 *                                     `{ label, onClick, disabled?, isBusy? }`.
 */
import { __ } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCheckCircle,
	faExclamationTriangle,
	faInfoCircle,
	faTimes,
} from '@fortawesome/free-solid-svg-icons';

/**
 * Allowed notice types. Unknown values fall back to 'info' so a typo never
 * emits an unstyled `.wppo-notice--foo` class.
 *
 * @since 2.3.0
 * @type {string[]}
 */
export const NOTICE_TYPES = Object.freeze( [
	'error',
	'warning',
	'info',
	'success',
] );

/**
 * Per-type icon map so info notices no longer reuse the warning triangle.
 *
 * @since 2.3.0
 * @type {Object<string, *>}
 */
export const NOTICE_ICONS = Object.freeze( {
	success: faCheckCircle,
	error: faExclamationTriangle,
	warning: faExclamationTriangle,
	info: faInfoCircle,
} );

const NoticeBanner = ( {
	type = 'info',
	message = '',
	onDismiss,
	className,
	action,
} ) => {
	if ( ! message ) {
		return null;
	}

	const safeType = NOTICE_TYPES.includes( type ) ? type : 'info';
	const icon = NOTICE_ICONS[ safeType ] ?? faInfoCircle;

	return (
		<div
			className={ `wppo-notice wppo-notice--${ safeType }${
				className ? ` ${ className }` : ''
			}` }
			role={ safeType === 'error' ? 'alert' : 'status' }
			aria-live={ safeType === 'error' ? 'assertive' : 'polite' }
		>
			<div className="wppo-notice__content">
				<FontAwesomeIcon icon={ icon } aria-hidden="true" />
				<span>{ message }</span>
			</div>
			{ action &&
				action.label &&
				typeof action.onClick === 'function' && (
					<button
						type="button"
						className="wppo-notice__action"
						onClick={ action.onClick }
						disabled={ action.disabled || action.isBusy }
						aria-busy={ action.isBusy || undefined }
					>
						{ action.isBusy
							? __( 'Working…', 'performance-optimisation' )
							: action.label }
					</button>
				) }
			{ onDismiss && (
				<button
					type="button"
					className="wppo-notice__dismiss"
					onClick={ onDismiss }
					aria-label={ __( 'Dismiss', 'performance-optimisation' ) }
				>
					<FontAwesomeIcon icon={ faTimes } aria-hidden="true" />
				</button>
			) }
		</div>
	);
};

export default NoticeBanner;
