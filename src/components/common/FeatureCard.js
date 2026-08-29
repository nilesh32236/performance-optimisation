/**
 * FeatureCard — Standardized card wrapper for every settings group.
 *
 * @param {Object}                    props                  Component props.
 * @param {string}                    [props.title]          Optional card heading.
 * @param {import('react').ReactNode} [props.icon]           Optional icon beside the title.
 * @param {import('react').ReactNode} [props.actions]        Buttons / links in the card header.
 * @param {import('react').ReactNode} [props.footer]         Buttons / links in the card footer.
 * @param {import('react').ReactNode} props.children         Card body content.
 * @param {string}                    [props.className]      Extra CSS classes.
 * @param {string}                    [props.badge]          Optional badge text (e.g. Recommended).
 * @param {string}                    [props.badgeTone]      Badge tone good|info|warning (default good).
 * @param {string}                    [props.description]    One-line benefit text shown under title.
 * @param {string}                    [props.learnMoreUrl]   Optional Learn more URL.
 * @param {string}                    [props.learnMoreLabel] Learn more link label.
 */
const FeatureCard = ( {
	title,
	icon,
	actions,
	footer,
	children,
	className,
	badge,
	badgeTone = 'good',
	description,
	learnMoreUrl,
	learnMoreLabel,
} ) => (
	<div className={ `wppo-feature-card ${ className || '' }`.trim() }>
		{ ( title || actions || badge || description ) && (
			<div className="wppo-feature-card__header">
				<div className="wppo-feature-card__header-main">
					{ title && (
						<h3>
							{ icon }
							{ title }
							{ badge && (
								<span
									className={ `wppo-status-badge wppo-status-badge--${ badgeTone } wppo-feature-card__badge` }
								>
									{ badge }
								</span>
							) }
						</h3>
					) }
					{ description && (
						<p className="wppo-feature-card__benefit">
							{ description }
							{ learnMoreUrl && (
								<>
									{ ' ' }
									<a
										href={ learnMoreUrl }
										target="_blank"
										rel="noopener noreferrer"
										className="wppo-feature-card__learn-more"
									>
										{ learnMoreLabel || 'Learn more' }
									</a>
								</>
							) }
						</p>
					) }
					{ ! description && learnMoreUrl && (
						<a
							href={ learnMoreUrl }
							target="_blank"
							rel="noopener noreferrer"
							className="wppo-feature-card__learn-more wppo-feature-card__learn-more--standalone"
						>
							{ learnMoreLabel || 'Learn more' }
						</a>
					) }
				</div>
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

export default FeatureCard;
