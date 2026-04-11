import { useId } from '@wordpress/element';

/**
 * A reusable checkbox option component with optional description and nested settings.
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
} ) => {
	const uid = useId();
	const id = idProp ?? uid;
	const descriptionId = description ? `desc-${ id }` : undefined;

	return (
		<div className="checkbox-option">
			<label htmlFor={ id }>
				<input
					id={ id }
					type="checkbox"
					name={ name }
					checked={ checked }
					onChange={ onChange }
					aria-describedby={ descriptionId }
				/>
				{ label }
			</label>

			{ description && (
				<p id={ descriptionId } className="option-description">
					{ description }
				</p>
			) }

			{ checked && ( textareaName || children ) && (
				<div className="nested-content">
					{ textareaName && (
						<textarea
							className="text-area-field"
							placeholder={ textareaPlaceholder || '' }
							aria-label={ textareaPlaceholder || label }
							name={ textareaName }
							value={ textareaValue }
							onChange={ onTextareaChange }
						/>
					) }
					{ children }
				</div>
			) }
		</div>
	);
};

export default CheckboxOption;
