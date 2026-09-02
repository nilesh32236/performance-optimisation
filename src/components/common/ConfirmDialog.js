import { useEffect, useRef, useCallback } from '@wordpress/element';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faExclamationTriangle } from '@fortawesome/free-solid-svg-icons';

import { __ } from '@wordpress/i18n';

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
	const focusableRef = useRef( [] );
	const previouslyFocusedRef = useRef( null );

	const handleKeyDown = useCallback(
		( e ) => {
			if ( e.key === 'Escape' ) {
				onCancel();
			}

			// Focus trap — guarded for single-element dialogs.
			if ( e.key === 'Tab' && dialogRef.current ) {
				const focusable = focusableRef.current;
				if ( focusable.length < 2 ) {
					return;
				}
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];
				if ( ! first || ! last ) {
					return;
				}

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
		if ( isOpen && dialogRef.current ) {
			focusableRef.current = Array.from(
				dialogRef.current.querySelectorAll(
					'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
				)
			);
		} else {
			focusableRef.current = [];
		}
	}, [ isOpen ] );

	useEffect( () => {
		if ( isOpen && confirmBtnRef.current ) {
			const cancelBtn = dialogRef.current?.querySelector(
				'.wppo-dialog-cancel'
			);
			if ( cancelBtn ) {
				cancelBtn.focus();
			}
		}
	}, [ isOpen ] );

	const prevOverflowRef = useRef( '' );
	const prevPaddingRef = useRef( '' );

	useEffect( () => {
		const doc = dialogRef.current?.ownerDocument || document;
		if ( isOpen ) {
			previouslyFocusedRef.current = doc.activeElement;
			prevOverflowRef.current = doc.body.style.overflow;
			prevPaddingRef.current = doc.body.style.paddingRight;
			const scrollbarW =
				window.innerWidth - doc.documentElement.clientWidth;
			if ( scrollbarW > 0 ) {
				doc.body.style.paddingRight = `${ scrollbarW }px`;
			}
			doc.addEventListener( 'keydown', handleKeyDown );
			doc.body.style.overflow = 'hidden';
		}
		return () => {
			doc.removeEventListener( 'keydown', handleKeyDown );
			doc.body.style.overflow = prevOverflowRef.current;
			doc.body.style.paddingRight = prevPaddingRef.current;
		};
	}, [ isOpen, handleKeyDown ] );

	// Return focus to the element that opened the dialog when it closes.
	useEffect( () => {
		if ( isOpen || ! previouslyFocusedRef.current ) {
			return;
		}
		const previouslyFocused = previouslyFocusedRef.current;
		previouslyFocusedRef.current = null;
		if (
			previouslyFocused &&
			typeof previouslyFocused.focus === 'function' &&
			previouslyFocused.isConnected
		) {
			previouslyFocused.focus();
		}
	}, [ isOpen ] );

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
						className="wppo-button wppo-button--secondary wppo-dialog-cancel"
						onClick={ onCancel }
					>
						{ cancelLabel ||
							__( 'Cancel', 'performance-optimisation' ) }
					</button>
					<button
						type="button"
						className={ `wppo-button ${
							variant === 'danger'
								? 'wppo-button--danger'
								: 'wppo-button--primary'
						}` }
						onClick={ onConfirm }
						ref={ confirmBtnRef }
					>
						{ confirmLabel ||
							__( 'Confirm', 'performance-optimisation' ) }
					</button>
				</div>
			</div>
		</div>
	);
};

export default ConfirmDialog;
