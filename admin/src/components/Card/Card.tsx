/**
 * Card Component
 *
 * @package
 * @since 1.1.0
 */

/**
 * External dependencies
 */
import React from 'react';
import { CardProps } from '@types/index';
/**
 * Internal dependencies
 */
import './Card.scss';

export const Card: React.FC<CardProps> = ( {
	title,
	description,
	children,
	className = '',
	actions,
} ) => {
	const baseClass = 'wppo-card';
	const classes = [ baseClass, className ].filter( Boolean ).join( ' ' );

	return (
		<div className={ classes }>
			{ ( title || description || actions ) && (
				<div className={ `${ baseClass }__header` }>
					<div className={ `${ baseClass }__header-content` }>
						{ title && <h3 className={ `${ baseClass }__title` }>{ title }</h3> }
						{ description && (
							<p className={ `${ baseClass }__description` }>{ description }</p>
						) }
					</div>
					{ actions && <div className={ `${ baseClass }__actions` }>{ actions }</div> }
				</div>
			) }
			<div className={ `${ baseClass }__content` }>{ children }</div>
		</div>
	);
};
