export const CheckboxOption = ({
	label,
	checked,
	onChange,
	name,
	textareaName,
	textareaPlaceholder,
	textareaValue,
	onTextareaChange,
	description,
	children
}) => {
	return (
		<div className="checkbox-option">
			<label>
				<input
					type="checkbox"
					name={name}
					checked={checked}
					onChange={onChange}
				/>
				{label}
			</label>

			{description && <p className="option-description">{description}</p>}

			{checked && textareaName && (
				<textarea
					className="text-area-field"
					placeholder={textareaPlaceholder || ''}
					name={textareaName}
					value={textareaValue}
					onChange={onTextareaChange}
				/>
			)}

			{checked && children}
		</div>
	);
};

export const handleChange = (setSettings) => (e) => {
	const { name, type, value, checked } = e.target;

	setSettings((prevState) => ({
		...prevState,
		[name]: 'checkbox' === type ? checked : value,
	}));
};