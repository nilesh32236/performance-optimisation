/**
 * A titled group of panels that can be folded away.
 *
 * ## Why this exists
 *
 * The All-diagnostics screen rendered **13 panels in one flat, undifferentiated
 * column** — a measured 9,053px, which is **ten screens of scrolling**, with 22
 * headings and no hierarchy at all. Nothing told a site owner what to read
 * first, and nothing let them skip the parts they came for.
 *
 * Measured per panel, the screen splits cleanly:
 *
 * | group | panels | height |
 * |---|---|---|
 * | How your site is doing | next step, audit, PageSpeed, vitals trends, real-user vitals | **1,547px** |
 * | Advanced tuning | autoloaded options, edge cache, AI adaptive, llms.txt | **3,086px** |
 * | Server and activity | system info, images, activity log | **1,067px** |
 *
 * So the page a site owner arrives for is under two screens; the other 3,086px
 * is one click away instead of in the way. Nothing is removed: every panel stays
 * mounted and every one is reachable without leaving the screen.
 *
 * ## Accessibility
 *
 * A real `<button>` with `aria-expanded` and `aria-controls`, because the
 * affordance is "show more", which is a button and not a heading. The panel is a
 * labelled region, so a screen reader can jump to it once expanded. Collapsed
 * content stays in the DOM rather than being unmounted, so a value being typed
 * into a panel inside a folded group is not silently destroyed.
 *
 * @package
 */

import { useId, useState } from '@wordpress/element';

/**
 * A collapsible group of panels.
 *
 * @param {Object}  props             Component props.
 * @param {string}  props.title       The group's name, e.g. "Advanced tuning".
 * @param {string}  props.summary     One short sentence on what is inside.
 * @param {boolean} props.defaultOpen Open on first render.
 * @param {Node}    props.children    The panels.
 * @param {string}  props.className   Extra class for the group element.
 * @return {Element} The group.
 */
export default function PanelGroup( {
	title,
	summary,
	defaultOpen = true,
	className = '',
	children,
} ) {
	const [ open, setOpen ] = useState( defaultOpen );
	const titleId = useId();
	const summaryId = `${ titleId }-summary`;
	const panelId = `${ titleId }-panel`;

	// D-2: the prop was passed by the caller and silently thrown away, which
	// also made `&--spaced` unreachable dead CSS.
	const classes = [ 'wppo-panel-group', className ]
		.filter( Boolean )
		.join( ' ' );

	return (
		<section className={ classes }>
			<button
				type="button"
				className="wppo-panel-group__toggle"
				aria-expanded={ open }
				aria-controls={ panelId }
				// The summary describes the button, so it is describedby rather
				// than folded into the name. `aria-expanded` already announces
				// the state, so there is no "Collapse this group" tail: it made
				// the button's name fifteen words long.
				aria-describedby={ summary ? summaryId : undefined }
				onClick={ () => setOpen( ( was ) => ! was ) }
			>
				<span className="wppo-panel-group__heading">
					<span className="wppo-panel-group__title">{ title }</span>
					{ summary && (
						<span
							className="wppo-panel-group__summary"
							id={ summaryId }
							// The summary sits *inside* the button, so it joins the
							// accessible name unless it is hidden from it. A
							// description via `aria-describedby` is the right
							// channel: the name should be the title alone.
							aria-hidden="true"
						>
							{ summary }
						</span>
					) }
				</span>
				<span className="wppo-panel-group__chevron" aria-hidden="true">
					{ open ? '▾' : '▸' }
				</span>
			</button>
			<div
				id={ panelId }
				className="wppo-stacked-cards wppo-panel-group__panel"
				hidden={ ! open }
			>
				{ children }
			</div>
		</section>
	);
}
