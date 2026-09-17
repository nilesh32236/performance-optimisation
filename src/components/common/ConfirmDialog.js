import { useEffect, useRef, useCallback, useId } from '@wordpress/element';
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
 * @param {boolean}              [props.isConfirming] Whether the confirm action is in flight; disables both buttons.
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
	isConfirming = false,
} ) => {
	const dialogRef = useRef( null );
	// Audit #1354: unique title id so two mounted dialogs never share one.
	const titleId = useId();
	const focusableRef = useRef( [] );
	const previouslyFocusedRef = useRef( null );

	const handleKeyDown = useCallback(
		( e ) => {
			if ( e.key === 'Escape' ) {
				onCancel();
			}

			// Focus trap — cycles even with a single focusable element so Tab
			// cannot escape the modal in single-control edge cases (e.g. a
			// disabled button leaving one tab stop).
			if ( e.key === 'Tab' && dialogRef.current ) {
				const focusable = focusableRef.current;
				if ( focusable.length === 0 ) {
					e.preventDefault();
					dialogRef.current.focus();
					return;
				}
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];
				if ( ! first || ! last ) {
					return;
				}
				if ( focusable.length === 1 ) {
					e.preventDefault();
					first.focus();
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

	// Audit #1354: rebuild when children change too — async detail
	// lists added while open would otherwise leave a stale trap list.
	const rebuildTrapList = useCallback( () => {
		if ( isOpen && dialogRef.current ) {
			focusableRef.current = Array.from(
				dialogRef.current.querySelectorAll(
					'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
				)
			);
		} else {
			focusableRef.current = [];
		}
	}, [ isOpen ] );
	useEffect( () => {
		rebuildTrapList();
	}, [ rebuildTrapList, children ] );

	useEffect( () => {
		// Audit #1354 review: gate on dialogRef (mounted dialog).
		if ( isOpen && dialogRef.current ) {
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
		/* eslint-disable jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions, jsx-a11y/no-noninteractive-element-interactions -- overlay click is progressive enhancement; Esc + buttons are the keyboard paths (audit #1354). */
		<div className="wppo-dialog-overlay" onClick={ onCancel }>
			<div
				className="wppo-dialog"
				ref={ dialogRef }
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				tabIndex={ -1 }
				onClick={ ( e ) => e.stopPropagation() }
			>
				<h3 id={ titleId }>
					<FontAwesomeIcon
						icon={ faExclamationTriangle }
						aria-hidden="true"
					/>
					{ title }
				</h3>
				<p>{ message }</p>
				{ children }
				<div className="wppo-dialog-actions">
					<button
						type="button"
						className="wppo-button wppo-button--secondary wppo-dialog-cancel"
						onClick={ onCancel }
						disabled={ isConfirming }
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
						disabled={ isConfirming }
						aria-busy={ isConfirming || undefined }
					>
						{ confirmLabel ||
							__( 'Confirm', 'performance-optimisation' ) }
					</button>
				</div>
			</div>
		</div>
	);
	/* eslint-enable jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions, jsx-a11y/no-noninteractive-element-interactions */
};

export default ConfirmDialog;
