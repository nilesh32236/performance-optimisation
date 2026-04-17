/**
 * FeatureCard — Standardized card wrapper for every settings group.
 *
 * @param {Object}               props            Component props.
 * @param {string}               [props.title]     Optional card heading.
 * @param {import('react').Node} [props.icon]      Optional icon beside the title.
 * @param {import('react').Node} [props.actions]   Buttons / links in the card header.
 * @param {import('react').Node} props.children    Card body content.
 * @param {string}               [props.className] Extra CSS classes.
 */
const FeatureCard = ( { title, icon, actions, children, className } ) => (
	<div className={ `wppo-feature-card ${ className || '' }`.trim() }>
		{ ( title || actions ) && (
			<div className="wppo-feature-card__header">
				{ title && (
					<h3>
						{ icon }
						{ title }
					</h3>
				) }
				{ actions && (
					<div className="wppo-feature-card__actions">
						{ actions }
					</div>
				) }
			</div>
		) }
		<div className="wppo-feature-card__body">{ children }</div>
	</div>
);

export default FeatureCard;
