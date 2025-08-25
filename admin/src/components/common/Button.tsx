import React, { ButtonHTMLAttributes } from 'react';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
	variant?: 'primary' | 'secondary' | 'danger' | 'ghost';
	size?: 'small' | 'medium' | 'large';
	loading?: boolean;
	icon?: string;
	iconPosition?: 'left' | 'right';
	fullWidth?: boolean;
}

function Button({
	children,
	variant = 'primary',
	size = 'medium',
	loading = false,
	icon,
	iconPosition = 'right',
	fullWidth = false,
	className = '',
	disabled,
	...props
}: ButtonProps) {
	const baseClasses = 'wppo-button';
	const variantClass = `wppo-button--${variant}`;
	const sizeClass = `wppo-button--${size}`;
	const loadingClass = loading ? 'wppo-button--loading' : '';
	const fullWidthClass = fullWidth ? 'wppo-button--full-width' : '';
	
	const buttonClasses = [
		baseClasses,
		variantClass,
		sizeClass,
		loadingClass,
		fullWidthClass,
		className,
	].filter(Boolean).join(' ');

	const isDisabled = disabled || loading;

	const renderIcon = (iconName: string) => (
		<span className={`dashicons dashicons-${iconName}`} aria-hidden="true" />
	);

	const renderContent = () => {
		if (loading) {
			return (
				<>
					<span className="wppo-button-spinner" aria-hidden="true" />
					<span className="wppo-button-text">{children}</span>
				</>
			);
		}

		if (icon) {
			return iconPosition === 'left' ? (
				<>
					{renderIcon(icon)}
					<span className="wppo-button-text">{children}</span>
				</>
			) : (
				<>
					<span className="wppo-button-text">{children}</span>
					{renderIcon(icon)}
				</>
			);
		}

		return <span className="wppo-button-text">{children}</span>;
	};

	return (
		<button
			{...props}
			className={buttonClasses}
			disabled={isDisabled}
			aria-disabled={isDisabled}
		>
			{renderContent()}
		</button>
	);
}

export default Button;