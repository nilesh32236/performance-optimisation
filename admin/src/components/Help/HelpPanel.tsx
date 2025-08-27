/**
 * External dependencies
 */
import React, { useState } from 'react';
/**
 * Internal dependencies
 */
import { Button } from '../Button';

interface HelpSection {
	id: string;
	title: string;
	content: string;
	links?: Array<{
		text: string;
		url: string;
		external?: boolean;
	}>;
}

interface HelpPanelProps {
	sections: HelpSection[];
	title?: string;
	className?: string;
	collapsible?: boolean;
	defaultExpanded?: boolean;
}

const HelpPanel: React.FC<HelpPanelProps> = ( {
	sections,
	title = 'Help & Documentation',
	className = '',
	collapsible = true,
	defaultExpanded = false,
} ) => {
	const [ isExpanded, setIsExpanded ] = useState( defaultExpanded );
	const [ activeSection, setActiveSection ] = useState<string | null>( null );

	const togglePanel = () => {
		if ( collapsible ) {
			setIsExpanded( ! isExpanded );
		}
	};

	const toggleSection = ( sectionId: string ) => {
		setActiveSection( activeSection === sectionId ? null : sectionId );
	};

	return (
		<div className={ `wppo-help-panel ${ className }` }>
			<div
				className="wppo-help-panel__header"
				onClick={ togglePanel }
				role={ collapsible ? 'button' : undefined }
				tabIndex={ collapsible ? 0 : undefined }
				aria-expanded={ collapsible ? isExpanded : undefined }
			>
				<h3 className="wppo-help-panel__title">
					<span className="wppo-help-panel__icon">
						<span className="dashicons dashicons-editor-help"></span>
					</span>
					{ title }
				</h3>
				{ collapsible && (
					<span className="wppo-help-panel__toggle">
						<span
							className={ `dashicons ${ isExpanded ? 'dashicons-arrow-up-alt2' : 'dashicons-arrow-down-alt2' }` }
						></span>
					</span>
				) }
			</div>

			{ ( isExpanded || ! collapsible ) && (
				<div className="wppo-help-panel__content">
					{ sections.map( ( section ) => (
						<div key={ section.id } className="wppo-help-section">
							<div
								className="wppo-help-section__header"
								onClick={ () => toggleSection( section.id ) }
								role="button"
								tabIndex={ 0 }
								aria-expanded={ activeSection === section.id }
							>
								<h4 className="wppo-help-section__title">{ section.title }</h4>
								<span className="wppo-help-section__toggle">
									<span
										className={ `dashicons ${ activeSection === section.id ? 'dashicons-minus' : 'dashicons-plus-alt' }` }
									></span>
								</span>
							</div>

							{ activeSection === section.id && (
								<div className="wppo-help-section__content">
									<div
										className="wppo-help-section__text"
										dangerouslySetInnerHTML={ { __html: section.content } }
									/>

									{ section.links && section.links.length > 0 && (
										<div className="wppo-help-section__links">
											<h5>Related Links:</h5>
											<ul>
												{ section.links.map( ( link, index ) => (
													<li key={ index }>
														<a
															href={ link.url }
															target={
																link.external ? '_blank' : '_self'
															}
															rel={
																link.external
																	? 'noopener noreferrer'
																	: undefined
															}
														>
															{ link.text }
															{ link.external && (
																<span className="wppo-external-link-icon">
																	<span className="dashicons dashicons-external"></span>
																</span>
															) }
														</a>
													</li>
												) ) }
											</ul>
										</div>
									) }
								</div>
							) }
						</div>
					) ) }

					<div className="wppo-help-panel__footer">
						<div className="wppo-help-panel__actions">
							<Button
								variant="secondary"
								size="small"
								onClick={ () =>
									window.open(
										'/wp-admin/admin.php?page=performance-optimisation-docs',
										'_blank',
									)
								}
							>
								View Full Documentation
							</Button>
							<Button
								variant="tertiary"
								size="small"
								onClick={ () =>
									window.open(
										'https://wordpress.org/support/plugin/performance-optimisation/',
										'_blank',
									)
								}
							>
								Get Support
							</Button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
};

export default HelpPanel;
