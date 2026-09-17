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
	// Unique title id per instance so two simultaneously mounted dialogs
	// never produce duplicate ids / mislabelled dialogs (audit #1354).
	const titleId = useId();

	const handleKeyDown = useCallback(
		( e ) => {
			if ( e.key === 'Escape' ) {
				onCancel();
			}

			// Focus trap — cycles even with a single focusable element so Tab
			// cannot escape the modal in single-control edge cases (e.g. a
			// disabled button leaving one tab stop).
			if ( e.key === 'Tab' && dialogRef.current ) {
				// Rebuilt live on every keydown so dynamic children added
				// while open (e.g. async detail lists) join the trap cycle
				// instead of leaving a stale list (audit #1354).
				const focusable = Array.from(
					dialogRef.current.querySelectorAll(
						'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
					)
				);
				focusableRef.current = focusable;
				if ( focusable.length === 0 ) {
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

	useEffect( () => {
		if ( isOpen && dialogRef.current ) {
			focusableRef.current = Array.from(
				dialogRef.current.querySelectorAll(
					'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
				)
			);
		} else {
			focusableRef.current = [];
		}
		// Children included so async content added while open refreshes the
		// trap list (the keydown handler also rebuilds live per press).
	}, [ isOpen, children ] );

	useEffect( () => {
		if ( ! isOpen ) {
			return;
		}
		// Gate on the element actually focused: the cancel button is the
		// initial focus target, and the confirm ref may be null on first
		// paint (or the cancel button absent) — either way focus the dialog
		// or cancel control that exists.
		const cancelBtn = dialogRef.current?.querySelector(
			'.wppo-dialog-cancel'
		);
		if ( cancelBtn ) {
			cancelBtn.focus();
		} else {
			confirmBtnRef.current?.focus();
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
		<div className="wppo-dialog-overlay">
			{ /* Separate backdrop button (not a wrapper): a clickable div
				with role="presentation" strips button semantics so AT loses
				the close affordance (audit #1354). A real button keeps its
				semantics and keyboard support. */ }
			<button
				type="button"
				className="wppo-dialog-overlay__backdrop"
				onClick={ onCancel }
				aria-label={ __( 'Close dialog', 'performance-optimisation' ) }
			/>
			<div
				className="wppo-dialog"
				ref={ dialogRef }
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
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
