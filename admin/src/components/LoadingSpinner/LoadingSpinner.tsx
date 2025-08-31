/**
 * Loading Spinner Component
 *
 * @package
 * @since 1.1.0
 */

/**
 * External dependencies
 */
import React from 'react';
import { LoadingSpinnerProps } from '@types/index';
/**
 * Internal dependencies
 */
import './LoadingSpinner.scss';

export const LoadingSpinner: React.FC<LoadingSpinnerProps> = ( {
	size = 'medium',
	color = 'currentColor',
} ) => {
	const baseClass = 'wppo-loading-spinner';
	const classes = [ baseClass, `${ baseClass }--${ size }` ].join( ' ' );

	return (
		<div className={ classes } style={ { color } }>
			<svg
				className={ `${ baseClass }__svg` }
				viewBox="0 0 24 24"
				fill="none"
				xmlns="http://www.w3.org/2000/svg"
			>
				<circle
					className={ `${ baseClass }__circle` }
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
		</div>
	);
};
