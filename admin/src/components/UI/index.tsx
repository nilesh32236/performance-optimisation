import React from 'react';

// Button Component
interface ButtonProps {
	variant?: 'primary' | 'secondary';
	size?: 'small' | 'medium' | 'large';
	disabled?: boolean;
	onClick?: () => void;
	children: React.ReactNode;
}

export const Button: React.FC<ButtonProps> = ({ 
	variant = 'primary', 
	size = 'medium', 
	disabled = false, 
	onClick, 
	children 
}) => (
	<button 
		className={`wppo-button wppo-button--${variant} wppo-button--${size}`}
		disabled={disabled}
		onClick={onClick}
	>
		{children}
	</button>
);

// Card Component
interface CardProps {
	title?: string;
	className?: string;
	children: React.ReactNode;
}

export const Card: React.FC<CardProps> = ({ title, className = '', children }) => (
	<div className={`wppo-card ${className}`}>
		{title && <div className="wppo-card-header"><h3>{title}</h3></div>}
		<div className="wppo-card-body">{children}</div>
	</div>
);

// Switch Component
interface SwitchProps {
	checked: boolean;
	onChange: (checked: boolean) => void;
	disabled?: boolean;
	label?: string;
	id?: string;
}

export const Switch: React.FC<SwitchProps> = ({ 
	checked, 
	onChange, 
	disabled = false, 
	label,
	id 
}) => {
	const switchId = id || `switch-${Math.random().toString(36).substr(2, 9)}`;
	
	return (
		<div className={`wppo-switch-container ${disabled ? 'disabled' : ''}`}>
			<label className={`wppo-switch ${disabled ? 'disabled' : ''}`} htmlFor={switchId}>
				<input 
					id={switchId}
					type="checkbox" 
					checked={checked} 
					onChange={(e) => onChange(e.target.checked)}
					disabled={disabled}
					aria-describedby={label ? `${switchId}-label` : undefined}
				/>
				<span className="wppo-switch-slider" aria-hidden="true"></span>
			</label>
			{label && (
				<span id={`${switchId}-label`} className="wppo-switch-label">
					{label}
				</span>
			)}
		</div>
	);
};

// Progress Component
interface ProgressProps {
	value: number;
	max?: number;
	color?: string;
	label?: string;
	showPercentage?: boolean;
}

export const Progress: React.FC<ProgressProps> = ({ 
	value, 
	max = 100, 
	color = 'blue',
	label,
	showPercentage = false 
}) => {
	const percentage = Math.round((value / max) * 100);
	const progressId = `progress-${Math.random().toString(36).substr(2, 9)}`;
	
	return (
		<div className="wppo-progress-container">
			{label && (
				<label htmlFor={progressId} className="wppo-progress-label">
					{label}
					{showPercentage && ` (${percentage}%)`}
				</label>
			)}
			<div 
				className="wppo-progress"
				role="progressbar"
				aria-valuenow={value}
				aria-valuemin={0}
				aria-valuemax={max}
				aria-label={label || `Progress: ${percentage}%`}
				id={progressId}
			>
				<div 
					className={`wppo-progress-bar wppo-progress-bar--${color}`}
					style={{ width: `${percentage}%` }}
				></div>
			</div>
		</div>
	);
};

// Spinner Component
interface SpinnerProps {
	size?: 'small' | 'medium' | 'large';
}

export const Spinner: React.FC<SpinnerProps> = ({ size = 'medium' }) => (
	<div className={`wppo-spinner wppo-spinner--${size}`}></div>
);

// Input Component
interface InputProps {
	value: string;
	onChange: (value: string) => void;
	placeholder?: string;
	type?: string;
}

export const Input: React.FC<InputProps> = ({ 
	value, 
	onChange, 
	placeholder = '', 
	type = 'text' 
}) => (
	<input 
		type={type}
		value={value}
		onChange={(e) => onChange(e.target.value)}
		placeholder={placeholder}
		className="wppo-input"
	/>
);

// TextArea Component
interface TextAreaProps {
	value: string;
	onChange: (value: string) => void;
	placeholder?: string;
	rows?: number;
}

export const TextArea: React.FC<TextAreaProps> = ({ 
	value, 
	onChange, 
	placeholder = '', 
	rows = 4 
}) => (
	<textarea 
		value={value}
		onChange={(e) => onChange(e.target.value)}
		placeholder={placeholder}
		rows={rows}
		className="wppo-textarea"
	/>
);

// Select Component
interface SelectOption {
	value: string | number;
	label: string;
}

interface SelectProps {
	value: string | number;
	onChange: (value: string) => void;
	options: SelectOption[];
}

export const Select: React.FC<SelectProps> = ({ value, onChange, options }) => (
	<select 
		value={value}
		onChange={(e) => onChange(e.target.value)}
		className="wppo-select"
	>
		{options.map(option => (
			<option key={option.value} value={option.value}>
				{option.label}
			</option>
		))}
	</select>
);

// Slider Component
interface SliderProps {
	value: number;
	onChange: (value: number) => void;
	min?: number;
	max?: number;
	step?: number;
}

export const Slider: React.FC<SliderProps> = ({ 
	value, 
	onChange, 
	min = 0, 
	max = 100, 
	step = 1 
}) => (
	<input 
		type="range"
		value={value}
		onChange={(e) => onChange(parseInt(e.target.value))}
		min={min}
		max={max}
		step={step}
		className="wppo-slider"
	/>
);

// Notice Component
interface NoticeProps {
	type?: 'success' | 'warning' | 'error' | 'info';
	children: React.ReactNode;
}

export const Notice: React.FC<NoticeProps> = ({ type = 'info', children }) => (
	<div className={`wppo-notice wppo-notice--${type}`}>
		{children}
	</div>
);
