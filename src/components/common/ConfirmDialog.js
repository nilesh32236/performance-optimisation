/* eslint-disable @wordpress/no-global-active-element */
import { useEffect, useRef, useCallback } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';

const translations = wppoSettings.translations;

/**
 * A reusable confirmation dialog component for destructive actions.
 *
 * @param {Object}               props                Component props.
 * @param {boolean}              props.isOpen         Whether the dialog is visible.
 * @param {Function}             props.onConfirm      Callback fired on confirm.
 * @param {Function}             props.onCancel       Callback fired on cancel or Escape.
 * @param {string}               props.title          Dialog heading.
 * @param {string}               props.message        Dialog body text.
 * @param {string}               [props.confirmLabel] Label for the confirm button.
 * @param {string}               [props.cancelLabel]  Label for the cancel button.
 * @param {string}               [props.variant]      'warning' | 'danger' — controls confirm button style.
 * @param {import('react').Node} [props.children]     Optional extra content (e.g., a detail list).
 */
const ConfirmDialog = ( {
	isOpen,
	onConfirm,
	onCancel,
	title,
	message,
	confirmLabel,
	cancelLabel,
	variant = 'danger',
	children,
} ) => {
	const dialogRef = useRef( null );
	const confirmBtnRef = useRef( null );

	const handleKeyDown = useCallback(
		( e ) => {
			if ( e.key === 'Escape' ) {
				onCancel();
			}

			// Focus trap.
			if ( e.key === 'Tab' && dialogRef.current ) {
				const focusable = dialogRef.current.querySelectorAll(
					'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
				);
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];

				if ( e.shiftKey ) {
					if (
						dialogRef.current?.ownerDocument?.activeElement ===
						first
					) {
						e.preventDefault();
						last.focus();
					}
				} else if (
					dialogRef.current?.ownerDocument?.activeElement === last
				) {
					e.preventDefault();
					first.focus();
				}
			}
		},
		[ onCancel ]
	);

	useEffect( () => {
		if ( isOpen && confirmBtnRef.current ) {
			// Focus the cancel button (safer default) on open.
			const cancelBtn = dialogRef.current?.querySelector(
				'.wppo-dialog-cancel'
			);
			if ( cancelBtn ) {
				cancelBtn.focus();
			}
		}
	}, [ isOpen ] );

	useEffect( () => {
		if ( isOpen ) {
			const doc = dialogRef.current?.ownerDocument || document;
			doc.addEventListener( 'keydown', handleKeyDown );
			// Prevent body scroll while dialog is open.
			doc.body.style.overflow = 'hidden';
		}
		return () => {
			const doc = dialogRef.current?.ownerDocument || document;
			doc.removeEventListener( 'keydown', handleKeyDown );
			doc.body.style.overflow = '';
		};
	}, [ isOpen, handleKeyDown ] );

	if ( ! isOpen ) {
		return null;
	}

	return (
		<div
			className="wppo-dialog-overlay"
			onClick={ onCancel }
			role="presentation"
		>
			{ /* eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-noninteractive-element-interactions */ }
			<div
				className="wppo-dialog"
				ref={ dialogRef }
				role="dialog"
				aria-modal="true"
				aria-labelledby="wppo-dialog-title"
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h3 id="wppo-dialog-title">
					<FontAwesomeIcon icon={ faExclamationTriangle } />
					{ title }
				</h3>
				<p>{ message }</p>
				{ children }
				<div className="wppo-dialog-actions">
					<button
						type="button"
						className="submit-button secondary wppo-dialog-cancel"
						onClick={ onCancel }
					>
						{ cancelLabel || translations.cancel || 'Cancel' }
					</button>
					<button
						type="button"
						className={ `submit-button ${
							variant === 'danger' ? 'danger' : ''
						}` }
						onClick={ onConfirm }
						ref={ confirmBtnRef }
					>
						{ confirmLabel || translations.confirm || 'Confirm' }
					</button>
				</div>
			</div>
		</div>
	);
};

export default ConfirmDialog;
