import { useId } from '@wordpress/element';

/**
 * FeatureHeader — Consistent hero section for every tab.
 *
 * @param {Object}                    props               Component props.
 * @param {string}                    props.title         Page heading.
 * @param {string}                    [props.description] Short subtitle text.
 * @param {import('react').ReactNode} [props.status]      Optional status badge / indicator.
 * @param {import('react').ReactNode} [props.actions]     Buttons rendered on the right.
 * @param {import('react').ReactNode} [props.children]    Extra content below the header row.
 */
const FeatureHeader = ( { title, description, status, actions, children } ) => {
	const titleId = useId();
	return (
		// Audit #1420: landmark section with labelledby title.
		<section className="wppo-feature-header" aria-labelledby={ titleId }>
			<div className="wppo-feature-header__main">
				<div className="wppo-feature-header__title">
					<h2 id={ titleId }>{ title }</h2>
					{ description && <p>{ description }</p> }
					{ status && (
						<div className="wppo-feature-header__status">
							{ status }
						</div>
					) }
				</div>
				{ actions && (
					<div className="wppo-feature-header__actions">
						{ actions }
					</div>
				) }
			</div>
			{ children && (
				<div className="wppo-feature-header__extra">{ children }</div>
			) }
		</section>
	);
};

export default FeatureHeader;
