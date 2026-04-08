import { useId } from '@wordpress/element';

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

			{ checked && textareaName && (
				<textarea
					className="text-area-field"
					placeholder={ textareaPlaceholder || '' }
					aria-label={ textareaPlaceholder || label }
					name={ textareaName }
					value={ textareaValue }
					onChange={ onTextareaChange }
				/>
			) }

			{ checked && children }
		</div>
	);
};

export const handleChange = ( setSettings ) => ( e ) => {
	const { name, type, value, checked } = e.target;

	setSettings( ( prevState ) => ( {
		...prevState,
		[ name ]: 'checkbox' === type ? checked : value,
	} ) );
};
