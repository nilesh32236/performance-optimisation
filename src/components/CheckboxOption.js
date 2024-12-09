// src/components/CheckboxOption.js
import React from 'react';

const CheckboxOption = ({ label, description, checked, name, onChange }) => {
	return (
		<div className="checkbox-option">
			<label>
				<input
					type="checkbox"
					name={name}
					checked={checked}
					onChange={onChange}
				/>
				<span className="option-label">{label}</span>
			</label>
			<p className="option-description">{description}</p>
		</div>
	);
};

export default CheckboxOption;
