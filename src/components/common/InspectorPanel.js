/**
 * Inspector panel.
 *
 * The persistent right-hand zone. Whatever control or metric is focused gets
 * explained here, always answering the same three questions in the same order,
 * because the order is the point: people read "what it does" and stop, so the
 * consequence has to be somewhere they will still reach.
 *
 * ## The subject contract
 *
 * Consumers pass a plain object. Everything except `title` and `does` is
 * optional, so a minimal subject is two strings:
 *
 * ```js
 * {
 *   id:        'setting:minifyCSS',       // stable key, used for React identity
 *   title:     'Minify CSS',
 *   kicker:    'CSS · Stylesheet pipeline', // small line of context
 *   status:    'good',                     // good | warn | bad | idle
 *   statusLabel: 'On',                     // overrides the tone's default word
 *   does:      'Removes whitespace and comments from stylesheets.',
 *   detail:    'At most two more sentences.', // optional
 *   cost:      'The consequence, stated plainly.', // optional
 *   costTone:  'warn',                     // optional emphasis for `cost`
 *   now:       [ { label: 'File size', value: '18.2 KB', tone: 'good' } ],
 *   related:   [ { label: 'Combine CSS', onSelect: fn } ],
 * }
 * ```
 *
 * @since x-release-please-version
 */

import { __ } from '@wordpress/i18n';

import { useInspector } from '../../lib/InspectorContext';
import SignalChip from './SignalChip';

/**
 * One titled block. Three of these are the whole panel body.
 *
 * @param {Object} root0          Component props.
 * @param {string} root0.eyebrow  The uppercase label for the block.
 * @param {string} root0.tone     Optional `warn` or `bad` emphasis, used when
 *                                the block carries a real consequence.
 * @param {Node}   root0.children The block body.
 * @return {Element} The block.
 */
const Block = ( { eyebrow, tone = null, children } ) => (
	<section
		className={ `wppo-inspector__block${
			tone ? ` wppo-inspector__block--${ tone }` : ''
		}` }
	>
		<h3 className="wppo-eyebrow">{ eyebrow }</h3>
		{ children }
	</section>
);

const InspectorPanel = () => {
	const { subject, isOpen, setOpen, clear } = useInspector();

	const panelId = 'wppo-inspector-body';

	return (
		<aside
			className={ `wppo-inspector${
				isOpen ? '' : ' wppo-inspector--collapsed'
			}` }
			aria-label={ __( 'Inspector', 'performance-optimisation' ) }
		>
			<div className="wppo-inspector__bar">
				<span className="wppo-eyebrow">
					{ __( 'Inspector', 'performance-optimisation' ) }
				</span>
				<button
					type="button"
					className="wppo-inspector__toggle"
					aria-expanded={ isOpen }
					aria-controls={ panelId }
					onClick={ () => setOpen( ( was ) => ! was ) }
				>
					{ isOpen
						? __( 'Hide', 'performance-optimisation' )
						: __( 'Show', 'performance-optimisation' ) }
				</button>
			</div>

			<div
				id={ panelId }
				className="wppo-inspector__body"
				hidden={ ! isOpen }
			>
				{ subject ? (
					// Polite, not assertive: moving through a settings list with
					// the keyboard changes this often, and an assertive region
					// would interrupt on every row.
					<div aria-live="polite">
						<header className="wppo-inspector__subject">
							<div className="wppo-inspector__subject-head">
								<h2>{ subject.title }</h2>
								{ subject.status && (
									<SignalChip
										tone={ subject.status }
										label={ subject.statusLabel }
										small
									/>
								) }
							</div>
							{ subject.kicker && (
								<p className="wppo-inspector__kicker">
									{ subject.kicker }
								</p>
							) }
						</header>

						<Block
							eyebrow={ __(
								'What it does',
								'performance-optimisation'
							) }
						>
							<p>{ subject.does }</p>
							{ subject.detail && <p>{ subject.detail }</p> }
						</Block>

						{ subject.cost && (
							<Block
								eyebrow={ __(
									'What it costs you',
									'performance-optimisation'
								) }
								tone={ subject.costTone }
							>
								<p>{ subject.cost }</p>
							</Block>
						) }

						{ subject.now?.length > 0 && (
							<Block
								eyebrow={ __(
									'Where you stand now',
									'performance-optimisation'
								) }
							>
								<dl className="wppo-inspector__now">
									{ subject.now.map( ( row ) => (
										<div
											key={ row.label }
											className="wppo-inspector__now-row"
										>
											<dt>{ row.label }</dt>
											<dd
												className={
													row.tone
														? `wppo-inspector__now-value wppo-tone-${ row.tone }`
														: 'wppo-inspector__now-value'
												}
											>
												{ row.value }
											</dd>
										</div>
									) ) }
								</dl>
							</Block>
						) }

						{ subject.related?.length > 0 && (
							<Block
								eyebrow={ __(
									'Related settings',
									'performance-optimisation'
								) }
							>
								<ul className="wppo-inspector__related">
									{ subject.related.map( ( item ) => (
										<li key={ item.label }>
											<button
												type="button"
												onClick={ () => {
													item.onSelect?.();
													setOpen( true );
												} }
											>
												{ item.label }
											</button>
										</li>
									) ) }
								</ul>
							</Block>
						) }

						<button
							type="button"
							className="wppo-inspector__clear"
							onClick={ clear }
						>
							{ __(
								'Clear selection',
								'performance-optimisation'
							) }
						</button>
					</div>
				) : (
					<div className="wppo-inspector__empty">
						<p>
							{ __(
								'Focus any setting and this panel explains what it does, what it costs you, and where you stand now.',
								'performance-optimisation'
							) }
						</p>
					</div>
				) }
			</div>
		</aside>
	);
};

export default InspectorPanel;
