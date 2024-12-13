export const CheckboxOption = ({
	label,
	checked,
	onChange,
	name,
	textareaPlaceholder,
	textareaValue,
	onTextareaChange,
	description
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
			{description && (
				<p className="option-description">
					{description}
				</p>
			)}
			{checked && textareaPlaceholder && (
				<textarea
					className="text-area-field"
					placeholder={textareaPlaceholder}
					name={name}
					value={textareaValue}
					onChange={onTextareaChange}
				/>
			)}
		</div>
	);
};