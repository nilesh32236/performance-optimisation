/**
 * FeatureCard — Standardized card wrapper for every settings group.
 *
 * @param {Object}                    props             Component props.
 * @param {string}                    [props.title]     Optional card heading.
 * @param {import('react').ReactNode} [props.icon]      Optional icon beside the title.
 * @param {import('react').ReactNode} [props.actions]   Buttons / links in the card header.
 * @param {import('react').ReactNode} [props.footer]    Buttons / links in the card footer.
 * @param {import('react').ReactNode} props.children    Card body content.
 * @param {string}                    [props.className] Extra CSS classes.
 * @param {string}                    [props.titleAs]   Heading level for the title, `'h3'`
 *                                                      through `'h6'`. Use it when the card is
 *                                                      nested under another heading, so the outline
 *                                                      does not skip a level back up.
 */
const FeatureCard = ( {
	title,
	icon,
	actions,
	footer,
	children,
	className,
	titleAs,
} ) => {
	// A heading level only. An invalid tag name would otherwise render
	// silently as an unknown element, with nothing to say so.
	const TitleTag = /^h[3-6]$/.test( titleAs ) ? titleAs : 'h3';

	return (
		<div className={ `wppo-feature-card ${ className || '' }`.trim() }>
			{ ( title || actions ) && (
				<div className="wppo-feature-card__header">
					{ title && (
						<TitleTag>
							{ /* Audit #1420: decorative icon hidden from AT. */ }
							<span aria-hidden="true">{ icon }</span>
							{ title }
						</TitleTag>
					) }
					{ actions && (
						<div className="wppo-feature-card__header-actions">
							{ actions }
						</div>
					) }
				</div>
			) }
			<div className="wppo-feature-card__body">{ children }</div>
			{ footer && (
				<div className="wppo-feature-card__footer">{ footer }</div>
			) }
		</div>
	);
};

export default FeatureCard;
