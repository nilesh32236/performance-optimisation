import { useCallback, useId } from '@wordpress/element';
import { useInspector } from '../../lib/InspectorContext';

/**
 * A reusable checkbox option component with optional description and nested settings.
 *  * Improved for Premium Indigo Design System.
 *
 * Prefer `children` for nested fields (textarea, inputs, hints). The legacy
 * `textarea*` props are kept for backward compatibility with existing callers
 * but new callers should render their own `<textarea>` as a child instead —
 * this keeps this generic toggle free of caller-specific field props.
 * Use `SwitchField` for a plain boolean toggle with label + description and
 * no nested content; use `CheckboxOption` when nested children appear when
 * checked.
 *
 * @param {Object}               props                       Component props.
 * @param {string}               props.label                 The checkbox label.
 * @param {boolean}              props.checked               Whether the checkbox is checked.
 * @param {Function}             props.onChange              Change handler for the checkbox.
 * @param {string}               props.name                  Name attribute for the checkbox.
 * @param {string}               [props.id]                  Optional ID for the checkbox.
 * @param {string}               [props.textareaName]        Optional name for a nested textarea.
 * @param {string}               [props.textareaPlaceholder] Optional placeholder for the textarea.
 * @param {string}               [props.textareaValue]       Value for the nested textarea.
 * @param {Function}             [props.onTextareaChange]    Change handler for the textarea.
 * @param {string}               [props.description]         Optional description text.
 * @param {import('react').Node} [props.children]            Additional child elements.
 * @param {string}               [props.className]           Optional addition
 * @param {Object}               [props.subject]             Inspector subject.
 *
 *                                                           A checkbox is a setting like any other, so it carries the same inspector
 *                                                           wiring `SettingRow` gives a row - hover, focus, and a pin that survives the
 *                                                           pointer leaving. `leave` and `blur` are guarded where SettingRow's are not,
 *                                                           because this component IS nested inside subject-bearing SettingRows, and an
 *                                                           unguarded release blanks the panel while the pointer is still in the row.
 * @see src/components/common/SwitchField.js for the same shape.al class names.
 */
export const CheckboxOption = ( {
	label,
	checked,
	onChange,
	name,
	id: idProp,
	textareaName,
	textareaPlaceholder,
	textareaValue,
	onTextareaChange,
	description,
	children,
	subject,
	className = '',
} ) => {
	const uid = useId();
	const id = idProp ?? uid;
	const descriptionId = description ? `desc-${ id }` : undefined;

	const { show, hide } = useInspector();

	const enter = useCallback( () => {
		if ( subject ) {
			show( subject, 'hover' );
		}
	}, [ show, subject ] );
	const leave = useCallback( () => {
		if ( subject ) {
			hide( 'hover' );
		}
	}, [ hide, subject ] );
	const focus = useCallback( () => {
		if ( subject ) {
			show( subject, 'focus' );
		}
	}, [ show, subject ] );
	const blur = useCallback( () => {
		if ( subject ) {
			hide( 'focus' );
		}
	}, [ hide, subject ] );

	return (
		<div
			onMouseEnter={ enter }
			onMouseLeave={ leave }
			onFocusCapture={ focus }
			onBlurCapture={ blur }
			className={ `wppo-checkbox-option ${
				checked ? 'wppo-is-checked' : ''
			} ${ className }`.trim() }
		>
			<label htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					name={ name }
					checked={ checked }
					onChange={ onChange }
					aria-describedby={ descriptionId }
				/>
				<span className="wppo-option-label-text">{ label }</span>
			</label>

			{ description && (
				<p id={ descriptionId } className="wppo-option-description">
					{ description }
				</p>
			) }

			{ checked && ( textareaName || children ) && (
				<div className="wppo-nested-content">
					{ textareaName && (
						<div className="wppo-field-group">
							{ /* Audit #1354 review: explicit label element instead of
							a placeholder fallback (placeholders are not labels). */ }
							<label
								className="wppo-screen-reader-text"
								htmlFor={ `${ id }-textarea` }
							>
								{ textareaPlaceholder || label }
							</label>
							<textarea
								id={ `${ id }-textarea` }
								className="wppo-text-area-field"
								placeholder={ textareaPlaceholder || '' }
								name={ textareaName }
								// Audit #1354 review: always controlled so
								// async settings loads never leave stale text;
								// readOnly when no change handler exists.
								value={ textareaValue ?? '' } // Audit #1420: async loads must not flip controlled state.
								onChange={ onTextareaChange }
								readOnly={ ! onTextareaChange }
							/>
						</div>
					) }
					{ children }
				</div>
			) }
		</div>
	);
};

export default CheckboxOption;
