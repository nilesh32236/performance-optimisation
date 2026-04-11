import React from 'react';
import { useId } from '@wordpress/element';

/**
 * A reusable checkbox option component with optional description and nested settings.
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
