import React from 'react';

// Button Component
interface ButtonProps {
	variant?: 'primary' | 'secondary';
	size?: 'small' | 'medium' | 'large';
	disabled?: boolean;
	onClick?: () => void;
	children: React.ReactNode;
	className?: string;
}

export const Button: React.FC<ButtonProps> = ({ 
	variant = 'primary', 
	size = 'medium', 
	disabled = false, 
	onClick, 
	children,
	className = ''
}) => {
	const baseClasses = 'inline-flex items-center justify-center font-medium rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2';
	
	const variantClasses = {
		primary: 'bg-primary-500 hover:bg-primary-600 text-white focus:ring-primary-500',
		secondary: 'bg-white hover:bg-gray-50 text-gray-700 border border-gray-300 focus:ring-primary-500'
	};
	
	const sizeClasses = {
		small: 'px-3 py-1.5 text-sm',
		medium: 'px-4 py-2 text-sm',
		large: 'px-6 py-3 text-base'
	};
	
	const disabledClasses = disabled ? 'opacity-50 cursor-not-allowed' : '';
	
	return (
		<button 
			className={`${baseClasses} ${variantClasses[variant]} ${sizeClasses[size]} ${disabledClasses} ${className}`}
			disabled={disabled}
			onClick={onClick}
		>
			{children}
		</button>
	);
};

// Card Component
interface CardProps {
	title?: string;
	className?: string;
	children: React.ReactNode;
}

export const Card: React.FC<CardProps> = ({ title, className = '', children }) => (
	<div className={`bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden ${className}`}>
		{title && (
			<div className="px-6 py-4 bg-gradient-to-r from-gray-50 to-gray-100 border-b border-gray-200">
				<h3 className="text-lg font-semibold text-gray-900">{title}</h3>
			</div>
		)}
		<div className="p-6">{children}</div>
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
		<div className={`flex items-center gap-3 ${disabled ? 'opacity-50 pointer-events-none' : ''}`}>
			<button
				type="button"
				className={`relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 ${
					checked ? 'bg-primary-500' : 'bg-gray-200'
				}`}
				role="switch"
				aria-checked={checked}
				aria-labelledby={label ? `${switchId}-label` : undefined}
				onClick={() => onChange(!checked)}
				disabled={disabled}
			>
				<span
					className={`pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out ${
						checked ? 'translate-x-5' : 'translate-x-0'
					}`}
				/>
			</button>
			{label && (
				<span id={`${switchId}-label`} className="text-sm font-medium text-gray-700 cursor-pointer" onClick={() => onChange(!checked)}>
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
	
	const colorClasses = {
		blue: 'bg-primary-500',
		green: 'bg-success-500',
		orange: 'bg-warning-500',
		red: 'bg-error-500'
	};
	
	return (
		<div className="mb-4">
			{label && (
				<div className="flex justify-between items-center mb-2">
					<span className="text-sm font-medium text-gray-700">{label}</span>
					{showPercentage && (
						<span className="text-sm text-gray-500">{percentage}%</span>
					)}
				</div>
			)}
			<div className="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
				<div 
					className={`h-full rounded-full transition-all duration-300 ease-out ${colorClasses[color as keyof typeof colorClasses] || colorClasses.blue}`}
					style={{ width: `${percentage}%` }}
				/>
			</div>
		</div>
	);
};

// Spinner Component
interface SpinnerProps {
	size?: 'small' | 'medium' | 'large';
}

export const Spinner: React.FC<SpinnerProps> = ({ size = 'medium' }) => {
	const sizeClasses = {
		small: 'h-4 w-4',
		medium: 'h-6 w-6',
		large: 'h-8 w-8'
	};
	
	return (
		<div className={`animate-spin rounded-full border-2 border-gray-300 border-t-primary-500 ${sizeClasses[size]}`} />
	);
};

// Input Component
interface InputProps {
	value: string;
	onChange: (value: string) => void;
	placeholder?: string;
	type?: string;
	className?: string;
}

export const Input: React.FC<InputProps> = ({ 
	value, 
	onChange, 
	placeholder = '', 
	type = 'text',
	className = ''
}) => (
	<input 
		type={type}
		value={value}
		onChange={(e) => onChange(e.target.value)}
		placeholder={placeholder}
		className={`block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm ${className}`}
	/>
);

// TextArea Component
interface TextAreaProps {
	value: string;
	onChange: (value: string) => void;
	placeholder?: string;
	rows?: number;
	className?: string;
}

export const TextArea: React.FC<TextAreaProps> = ({ 
	value, 
	onChange, 
	placeholder = '', 
	rows = 4,
	className = ''
}) => (
	<textarea 
		value={value}
		onChange={(e) => onChange(e.target.value)}
		placeholder={placeholder}
		rows={rows}
		className={`block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm placeholder-gray-400 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm resize-vertical ${className}`}
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
	className?: string;
}

export const Select: React.FC<SelectProps> = ({ value, onChange, options, className = '' }) => (
	<select 
		value={value}
		onChange={(e) => onChange(e.target.value)}
		className={`block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm ${className}`}
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
	className?: string;
}

export const Slider: React.FC<SliderProps> = ({ 
	value, 
	onChange, 
	min = 0, 
	max = 100, 
	step = 1,
	className = ''
}) => (
	<input 
		type="range"
		value={value}
		onChange={(e) => onChange(parseInt(e.target.value))}
		min={min}
		max={max}
		step={step}
		className={`w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer slider ${className}`}
	/>
);

// Notice Component
interface NoticeProps {
	type?: 'success' | 'warning' | 'error' | 'info';
	children: React.ReactNode;
	className?: string;
}

export const Notice: React.FC<NoticeProps> = ({ type = 'info', children, className = '' }) => {
	const typeClasses = {
		success: 'bg-success-50 border-success-200 text-success-800',
		warning: 'bg-warning-50 border-warning-200 text-warning-800',
		error: 'bg-error-50 border-error-200 text-error-800',
		info: 'bg-primary-50 border-primary-200 text-primary-800'
	};
	
	const iconClasses = {
		success: 'text-success-400',
		warning: 'text-warning-400',
		error: 'text-error-400',
		info: 'text-primary-400'
	};
	
	const icons = {
		success: '✓',
		warning: '⚠',
		error: '✕',
		info: 'i'
	};
	
	return (
		<div className={`flex items-center gap-3 p-4 rounded-md border mb-6 ${typeClasses[type]} ${className}`}>
			<div className={`flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold text-white ${iconClasses[type]}`}>
				{icons[type]}
			</div>
			<div className="flex-1">
				{children}
			</div>
		</div>
	);
};
