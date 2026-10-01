/**
 * SettingRow — wires any setting to the inspector.
 *
 * This is the one component every settings screen uses, which is what keeps
 * the behaviour identical across all fourteen tabs: hover, keyboard focus and
 * an explicit click all load the same subject, and the precedence between them
 * lives in `lib/InspectorContext.js` rather than being re-implemented per
 * screen.
 *
 * It **wraps** the existing field components rather than replacing them. Those
 * already render their own label and description (`SwitchField`,
 * `CheckboxOption`, and the rest), so this adds only what they lack: the
 * pointer/focus wiring, the explain affordance, and the selected state.
 *
 * ```jsx
 * <SettingRow subject={ { title: 'Minify CSS', does: '…' } }>
 *   <SwitchField label="Minify CSS" description="…" checked={ v } onChange={ fn } />
 * </SettingRow>
 * ```
 *
 * @since x-release-please-version
 */

import { useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useInspector } from '../../lib/InspectorContext';

/**
 * Wrap a setting so it can explain itself.
 *
 * @param {Object} props           Component props.
 * @param {Object} props.subject   The inspector subject. See `InspectorPanel`.
 * @param {Node}   props.children  The field component.
 * @param {string} props.className Extra class for the row.
 * @return {Element} The row.
 */
export default function SettingRow( { subject, children, className = '' } ) {
	const { subject: active, show, hide, setOpen } = useInspector();
	const isActive = Boolean( subject ) && active?.id === subject?.id;

	const enter = useCallback( () => {
		if ( subject ) {
			show( subject, 'hover' );
		}
	}, [ show, subject ] );

	const leave = useCallback( () => hide( 'hover' ), [ hide ] );
	const focus = useCallback( () => {
		if ( subject ) {
			show( subject, 'focus' );
		}
	}, [ show, subject ] );
	const blur = useCallback( () => hide( 'focus' ), [ hide ] );

	// An explicit click pins the subject: it survives the pointer leaving and
	// the row losing focus, which is what makes the panel readable when the
	// explanation is longer than the row.
	const pin = useCallback( () => {
		if ( subject ) {
			show( subject, 'pinned' );
			setOpen( true );
		}
	}, [ show, subject, setOpen ] );

	if ( ! subject ) {
		return <div className={ className }>{ children }</div>;
	}

	return (
		<div
			className={ `wppo-setting-row${
				isActive ? ' wppo-setting-row--active' : ''
			} ${ className }`.trim() }
			onMouseEnter={ enter }
			onMouseLeave={ leave }
			onFocusCapture={ focus }
			onBlurCapture={ blur }
		>
			<div className="wppo-setting-row__field">{ children }</div>
			<button
				type="button"
				className="wppo-setting-row__explain"
				onClick={ pin }
				aria-label={ sprintf(
					/* translators: %s: the setting's name. */
					__( 'Explain %s', 'performance-optimisation' ),
					subject.title
				) }
			>
				<span aria-hidden="true">?</span>
			</button>
		</div>
	);
}
