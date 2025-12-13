/**
 * Loading Spinner Component with Accessibility Support
 */
import React from 'react';
import { LoadingSpinnerProps } from '@types/index';


export const LoadingSpinner: React.FC<LoadingSpinnerProps> = ({
	size = 'medium',
	color = 'currentColor',
	label = 'Loading...'
}) => {
	const baseClass = 'wppo-loading-spinner';
	const classes = [baseClass, `${baseClass}--${size}`].join(' ');

	return (
		<div 
			className={classes} 
			style={{ color }}
			role="status"
			aria-label={label}
		>
			<svg
				className={`${baseClass}__svg`}
				viewBox="0 0 24 24"
				fill="none"
				xmlns="http://www.w3.org/2000/svg"
				aria-hidden="true"
			>
				<circle
					className={`${baseClass}__circle`}
					cx="12"
					cy="12"
					r="10"
					stroke="currentColor"
					strokeWidth="2"
					strokeLinecap="round"
					strokeDasharray="31.416"
					strokeDashoffset="31.416"
				/>
			</svg>
			<span className="sr-only">{label}</span>
		</div>
	);
};
