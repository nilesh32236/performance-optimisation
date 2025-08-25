import React, { useState } from 'react';

interface HelpTooltipProps {
	content: string;
	title?: string;
	position?: 'top' | 'bottom' | 'left' | 'right';
	size?: 'small' | 'medium' | 'large';
	className?: string;
	children?: React.ReactNode;
}

const HelpTooltip: React.FC<HelpTooltipProps> = ({
	content,
	title,
	position = 'top',
	size = 'medium',
	className = '',
	children
}) => {
	const [isVisible, setIsVisible] = useState(false);

	const showTooltip = () => setIsVisible(true);
	const hideTooltip = () => setIsVisible(false);

	const getTooltipClasses = () => {
		const baseClasses = 'wppo-help-tooltip';
		const positionClass = `wppo-help-tooltip--${position}`;
		const sizeClass = `wppo-help-tooltip--${size}`;
		const visibleClass = isVisible ? 'wppo-help-tooltip--visible' : '';
		
		return `${baseClasses} ${positionClass} ${sizeClass} ${visibleClass} ${className}`.trim();
	};

	return (
		<div className="wppo-help-tooltip-container">
			<div
				className="wppo-help-trigger"
				onMouseEnter={showTooltip}
				onMouseLeave={hideTooltip}
				onFocus={showTooltip}
				onBlur={hideTooltip}
				tabIndex={0}
				role="button"
				aria-describedby={isVisible ? 'wppo-tooltip-content' : undefined}
			>
				{children || (
					<span className="wppo-help-icon">
						<span className="dashicons dashicons-editor-help"></span>
					</span>
				)}
			</div>
			
			<div
				id="wppo-tooltip-content"
				className={getTooltipClasses()}
				role="tooltip"
				aria-hidden={!isVisible}
			>
				{title && (
					<div className="wppo-help-tooltip__header">
						<strong>{title}</strong>
					</div>
				)}
				<div className="wppo-help-tooltip__content">
					{content}
				</div>
				<div className="wppo-help-tooltip__arrow"></div>
			</div>
		</div>
	);
};

export default HelpTooltip;