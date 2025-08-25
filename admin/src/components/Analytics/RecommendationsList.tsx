import React from 'react';
import { Button } from '../Button';

interface Recommendation {
	type: string;
	priority: 'high' | 'medium' | 'low';
	title: string;
	description: string;
	actions: string[];
}

interface RecommendationsListProps {
	recommendations: Recommendation[];
}

const RecommendationsList: React.FC<RecommendationsListProps> = ({ recommendations }) => {
	const getPriorityIcon = (priority: string): string => {
		switch (priority) {
			case 'high':
				return 'dashicons-warning';
			case 'medium':
				return 'dashicons-info';
			case 'low':
				return 'dashicons-lightbulb';
			default:
				return 'dashicons-info';
		}
	};

	const getPriorityColor = (priority: string): string => {
		switch (priority) {
			case 'high':
				return 'high';
			case 'medium':
				return 'medium';
			case 'low':
				return 'low';
			default:
				return 'medium';
		}
	};

	if (recommendations.length === 0) {
		return (
			<div className="wppo-recommendations-empty">
				<div className="wppo-recommendations-empty__icon">
					<span className="dashicons dashicons-yes-alt"></span>
				</div>
				<h4>Great job! No recommendations at this time.</h4>
				<p>Your site is performing well with the current optimization settings.</p>
			</div>
		);
	}

	return (
		<div className="wppo-recommendations-list">
			{recommendations.map((recommendation, index) => (
				<div 
					key={index} 
					className={`wppo-recommendation-item wppo-recommendation-item--${getPriorityColor(recommendation.priority)}`}
				>
					<div className="wppo-recommendation-item__header">
						<div className="wppo-recommendation-item__icon">
							<span className={`dashicons ${getPriorityIcon(recommendation.priority)}`}></span>
						</div>
						<div className="wppo-recommendation-item__title-section">
							<h4 className="wppo-recommendation-item__title">
								{recommendation.title}
							</h4>
							<span className={`wppo-recommendation-item__priority wppo-recommendation-item__priority--${getPriorityColor(recommendation.priority)}`}>
								{recommendation.priority.toUpperCase()} PRIORITY
							</span>
						</div>
					</div>
					
					<div className="wppo-recommendation-item__content">
						<p className="wppo-recommendation-item__description">
							{recommendation.description}
						</p>
						
						{recommendation.actions.length > 0 && (
							<div className="wppo-recommendation-item__actions">
								<h5>Recommended Actions:</h5>
								<ul className="wppo-recommendation-actions-list">
									{recommendation.actions.map((action, actionIndex) => (
										<li key={actionIndex} className="wppo-recommendation-action">
											<span className="dashicons dashicons-arrow-right-alt2"></span>
											{action}
										</li>
									))}
								</ul>
							</div>
						)}
					</div>
					
					<div className="wppo-recommendation-item__footer">
						<Button variant="primary" size="small">
							Apply Fix
						</Button>
						<Button variant="tertiary" size="small">
							Learn More
						</Button>
					</div>
				</div>
			))}
		</div>
	);
};

export default RecommendationsList;