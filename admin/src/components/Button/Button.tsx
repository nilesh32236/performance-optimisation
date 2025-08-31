/**
 * Button Component
 *
 * @package
 * @since 1.1.0
 */

/**
 * External dependencies
 */
import React from 'react';
import { ButtonProps } from '@types/index';
import { LoadingSpinner } from '@components/LoadingSpinner';
/**
 * Internal dependencies
 */
import './Button.scss';

export const Button: React.FC<ButtonProps> = ( {
	variant = 'primary',
	size = 'medium',
	disabled = false,
	loading = false,
	onClick,
	children,
	className = '',
	...props
} ) => {
	const baseClass = 'wppo-button';
	const classes = [
		baseClass,
		`${ baseClass }--${ variant }`,
		`${ baseClass }--${ size }`,
		disabled && `${ baseClass }--disabled`,
		loading && `${ baseClass }--loading`,
		className,
	]
		.filter( Boolean )
		.join( ' ' );

	const handleClick = (e: React.MouseEvent<HTMLButtonElement>) => {
		if ( ! disabled && ! loading && onClick ) {
			onClick(e);
		}
	};

	return (
		<button 
			type="button"
			className={ classes } 
			onClick={ handleClick } 
			disabled={ disabled || loading } 
			{ ...props }
		>
			{ loading && (
				<span className={ `${ baseClass }__spinner` }>
					<LoadingSpinner size="small" />
				</span>
			) }
			<span className={ `${ baseClass }__content` }>{ children }</span>
		</button>
	);
};
