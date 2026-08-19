/**
 * Shared presentational notice banner.
 *
 * Renders the `.wppo-notice` markup used across the admin SPA with the
 * correct modifier class, icon and an optional dismiss button. Pair with
 * the `useNotice()` hook for state and timing.
 *
 * ARIA semantics rely on role semantics only — no explicit `aria-live` is
 * added (adding it alongside an implicit live region causes double
 * announcements). `role="alert"` (implicit assertive live region) is used
 * for errors; non-error notices use `role="status"` (implicit polite live
 * region) so routine success/info/warning feedback does not interrupt
 * screen readers.
 *
 * @since 1.10.0
 */
import { __ } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCheckCircle,
	faExclamationTriangle,
	faTimes,
} from '@fortawesome/free-solid-svg-icons';

const NoticeBanner = ( {
	type = 'info',
	message = '',
	onDismiss,
	className,
} ) => {
	if ( ! message ) {
		return null;
	}

	const icon = type === 'success' ? faCheckCircle : faExclamationTriangle;

	return (
		<div
			className={ `wppo-notice wppo-notice--${ type }${
				className ? ` ${ className }` : ''
			}` }
			role={ type === 'error' ? 'alert' : 'status' }
		>
			<div className="wppo-notice__content">
				<FontAwesomeIcon icon={ icon } />
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
