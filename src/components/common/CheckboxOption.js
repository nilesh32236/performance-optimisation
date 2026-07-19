import { useState } from '@wordpress/element';

/**
 * A reusable checkbox option component with optional description and nested settings.
 *  * Improved for Premium Indigo Design System.
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
	const [ generatedId ] = useState(
		() => `cb-${ Math.random().toString( 36 ).slice( 2, 9 ) }`
	);
	const id = idProp ?? ( description ? generatedId : undefined );
	const descriptionId = description ? `desc-${ id }` : undefined;

	return (
		<div
			className={ `wppo-checkbox-option ${
				checked ? 'wppo-is-checked' : ''
			}` }
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
				<div
					className="wppo-nested-content"
					style={ { marginTop: '20px', paddingLeft: '36px' } }
				>
					{ textareaName && (
						<div className="wppo-field-group">
							<textarea
								className="wppo-text-area-field"
								placeholder={ textareaPlaceholder || '' }
								aria-label={ textareaPlaceholder || label }
								name={ textareaName }
								value={ textareaValue }
								onChange={ onTextareaChange }
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
