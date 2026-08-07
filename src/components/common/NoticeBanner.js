import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCheckCircle,
	faExclamationCircle,
	faExclamationTriangle,
	faInfoCircle,
	faTimes,
} from '@fortawesome/free-solid-svg-icons';
import { __ } from '@wordpress/i18n';

const NOTICE_VARIANTS = {
	success: { icon: faCheckCircle },
	error: { icon: faExclamationCircle },
	warning: { icon: faExclamationTriangle },
	info: { icon: faInfoCircle },
};

/**
 * A shared, presentational notice banner.
 *
 * Renders the centralised `.wppo-notice` markup using the existing
 * `_notices.scss` variants (`--success/--error/--warning/--info`). It is a
 * controlled component: consumers drive when a notice is shown/hidden (e.g.
 * via the `useNotice` hook) and may pass `onDismiss` to render a dismiss
 * button.
 *
 * @since 2.0.0
 * @param {Object}   props             Component props.
 * @param {string}   props.type        Notice variant: 'success'|'error'|'warning'|'info'.
 * @param {string}   props.message     The notice text.
 * @param {Function} [props.onDismiss] Optional callback; renders a dismiss button when set.
 * @param {string}   [props.className] Additional CSS classes appended to `.wppo-notice`.
 */
const NoticeBanner = ( {
	type = 'info',
	message,
	onDismiss,
	className = '',
} ) => {
	const variant = NOTICE_VARIANTS[ type ] || NOTICE_VARIANTS.info;
	const ariaLive = type === 'error' ? 'assertive' : 'polite';

	return (
		<div
			className={ `wppo-notice wppo-notice--${ type }${
				className ? ` ${ className }` : ''
			}` }
			role="alert"
			aria-live={ ariaLive }
		>
			<div className="wppo-notice__content">
				<FontAwesomeIcon icon={ variant.icon } />
				<span>{ message }</span>
			</div>
			{ onDismiss && (
				<button
					type="button"
					className="wppo-notice__dismiss"
					onClick={ onDismiss }
					aria-label={ __( 'Dismiss', 'performance-optimisation' ) }
				>
					<FontAwesomeIcon icon={ faTimes } />
				</button>
			) }
		</div>
	);
};

export default NoticeBanner;
