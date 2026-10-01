/**
 * Inspector copy for the CSS Optimisation card.
 *
 * The explanations live here rather than inline in the JSX for three reasons:
 * the copy is the substance of the redesign and deserves to be read as prose
 * in one place, a translator can reach it without navigating a 4,000-line
 * component, and the wording can be unit-tested without rendering anything.
 *
 * ## Writing rules for these
 *
 * `does` is written from the site owner's side of the screen. It names what
 * happens to their site, never the internal mechanism — "stops WordPress
 * loading emoji scripts" rather than "removes the wp-emoji handle".
 *
 * `cost` is the consequence, stated plainly. A safe setting says so in one
 * line, because padding it with reassurance trains people to stop reading.
 * A risky one names the *specific* failure and the guard that prevents it.
 * Vague risk ("may cause issues") is worse than no risk at all.
 *
 * @since x-release-please-version
 */

import { __ } from '@wordpress/i18n';

/**
 * Build the subject for one setting.
 *
 * A small factory rather than eight near-identical object literals: every
 * subject needs the same `id`/`kind` shape, and hand-writing that eight times
 * is how one of them ends up with a typo in its id and quietly stops matching
 * its row.
 *
 * @param {string} key    The setting key, e.g. `minifyCSS`.
 * @param {Object} fields The prose: `title`, `does`, `cost`, and optionally
 *                        `detail`, `costTone`, `kicker`.
 * @return {Object} An inspector subject.
 */
const subject = ( key, fields ) => ( {
	id: `setting:${ key }`,
	kind: 'setting',
	kicker:
		fields.kicker ??
		__( 'CSS · Stylesheet pipeline', 'performance-optimisation' ),
	...fields,
} );

const CSS_SUBJECTS = {
	minifyCSS: subject( 'minifyCSS', {
		title: __( 'Minify CSS', 'performance-optimisation' ),
		does: __(
			'Strips the whitespace, comments and line breaks out of every stylesheet before it is sent to the browser.',
			'performance-optimisation'
		),
		detail: __(
			'The stylesheet does exactly the same thing — it is simply a smaller file to download.',
			'performance-optimisation'
		),
		cost: __(
			'No known downside. Fully reversible, and safe with page builders.',
			'performance-optimisation'
		),
	} ),

	combineCSS: subject( 'combineCSS', {
		title: __( 'Combine CSS', 'performance-optimisation' ),
		does: __(
			'Merges every stylesheet on the page into one file, so the browser makes one request instead of many.',
			'performance-optimisation'
		),
		cost: __(
			'Can flash unstyled content on the first visit, because the single file now loads later than the first stylesheet used to. Exclude the problem stylesheets below to fix it.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	removeUnusedCSS: subject( 'removeUnusedCSS', {
		title: __( 'Remove Unused CSS', 'performance-optimisation' ),
		does: __(
			'Checks each page, works out which CSS rules that page actually uses, and strips the rest.',
			'performance-optimisation'
		),
		detail: __(
			'Typically removes 30–80% of a stylesheet, which is the single largest CSS saving available and the one PageSpeed audits for by name.',
			'performance-optimisation'
		),
		cost: __(
			'Aggressive. A rule that only appears after a click — a dropdown, a modal, a tab — can be stripped, because it is not used when the page is scanned. The safelist is the guard: add any selector that must survive.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	criticalCSS: subject( 'criticalCSS', {
		title: __( 'Critical CSS', 'performance-optimisation' ),
		does: __(
			'Pulls out the styles needed for the part of the page you see first, puts them inline, and defers everything else.',
			'performance-optimisation'
		),
		detail: __(
			'The browser can paint the top of the page without waiting for the full stylesheet to download, which is what moves First Contentful Paint.',
			'performance-optimisation'
		),
		cost: __(
			'Adds a small amount of markup to every page. Above the size cap below, the styles are served as a separate file instead, so a very large page cannot bloat its own HTML.',
			'performance-optimisation'
		),
	} ),

	hostGoogleFontsLocally: subject( 'hostGoogleFontsLocally', {
		title: __( 'Host Google Fonts Locally', 'performance-optimisation' ),
		does: __(
			'Downloads the fonts your site uses and serves them from your own server instead of asking Google for them.',
			'performance-optimisation'
		),
		detail: __(
			'Removes a third-party DNS lookup and connection from every page load, and stops your visitors’ IP addresses reaching Google.',
			'performance-optimisation'
		),
		cost: __(
			'Uses a little more of your own disk and bandwidth. Fonts are refreshed when you save settings, so a newly added font needs one save to appear.',
			'performance-optimisation'
		),
	} ),

	fontMetricFallback: subject( 'fontMetricFallback', {
		title: __( 'Font Metric Fallback', 'performance-optimisation' ),
		does: __(
			'Adds size-adjust rules so the fallback font occupies the same space as the real one while it loads.',
			'performance-optimisation'
		),
		detail: __(
			'This is what stops the visible jump when a web font swaps in — the text does not reflow because it never took up the wrong amount of room.',
			'performance-optimisation'
		),
		cost: __(
			'No known downside. It reduces Cumulative Layout Shift and costs nothing to run.',
			'performance-optimisation'
		),
	} ),

	fontSubset: subject( 'fontSubset', {
		title: __( 'Font Subsetting', 'performance-optimisation' ),
		does: __(
			'Ships only the character sets your site actually needs, rather than every alphabet the font supports.',
			'performance-optimisation'
		),
		cost: __(
			'If your site shows text in a character set you have not selected below, those characters fall back to a different font. Latin covers English; add the others only if you publish in them.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	excludeCombineCSS: subject( 'excludeCombineCSS', {
		title: __( 'Exclude from Combining', 'performance-optimisation' ),
		does: __(
			'Lists the stylesheets that stay separate instead of being merged into the combined file.',
			'performance-optimisation'
		),
		cost: __(
			'Each entry is one more request the browser has to make. This list exists to fix a specific visual glitch, not to be used broadly — if it grows long, combining is probably the wrong setting for this site.',
			'performance-optimisation'
		),
	} ),

	excludeCSS: subject( 'excludeCSS', {
		title: __(
			'Exclude CSS from Minification',
			'performance-optimisation'
		),
		does: __(
			'Lists the stylesheets that are left exactly as they are.',
			'performance-optimisation'
		),
		cost: __(
			'Excluded files stay at full size. Add a file here only when minification has visibly broken it.',
			'performance-optimisation'
		),
	} ),

	ccssMaxSize: subject( 'ccssMaxSize', {
		title: __(
			'Critical CSS Max Size (bytes)',
			'performance-optimisation'
		),
		does: __(
			'Sets how much critical CSS may be written into the page itself before it is served as a separate file instead.',
			'performance-optimisation'
		),
		cost: __(
			'Inline CSS is downloaded with the page and cannot be cached separately, so raising this makes the page heavier on repeat visits. The default of 20480 is a reasonable ceiling for most sites.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	ccssSafelistExtra: subject( 'ccssSafelistExtra', {
		title: __( 'Critical CSS Safelist', 'performance-optimisation' ),
		does: __(
			'Lists selectors that are always kept in the critical CSS, even when they are not visible at the top of the page.',
			'performance-optimisation'
		),
		cost: __(
			'Needed for anything hidden and revealed later by JavaScript — menus, modal dialogs, tabs. Each selector you add makes the inline block slightly larger.',
			'performance-optimisation'
		),
	} ),

	ccssExcludedPostTypes: subject( 'ccssExcludedPostTypes', {
		title: __( 'Excluded Post Types', 'performance-optimisation' ),
		does: __(
			'Lists the content types that are skipped entirely by both critical and used CSS.',
			'performance-optimisation'
		),
		cost: __(
			'Builder templates are excluded by default. They render through their own pipeline, where stripping CSS produces a broken page rather than a faster one.',
			'performance-optimisation'
		),
	} ),

	ccssMaxRetries: subject( 'ccssMaxRetries', {
		title: __( 'Critical CSS Max Retries', 'performance-optimisation' ),
		does: __(
			'Sets how many times a template is attempted before it is marked as failed and left alone.',
			'performance-optimisation'
		),
		cost: __(
			'Each retry costs a background job. Raising it helps a template that fails intermittently; lowering it stops a permanently broken template from consuming the queue.',
			'performance-optimisation'
		),
	} ),
};

export default CSS_SUBJECTS;

/**
 * Build a subject with its live "where you stand now" rows.
 *
 * The panel asks three questions in a fixed order, and the third one cannot be
 * answered by static copy — it is the current value. Keeping the prose in
 * `CSS_SUBJECTS` and deriving the state here means a screen only has to pass
 * its settings object, and every setting gets the third block for free.
 *
 * @param {string} key      The setting key.
 * @param {Object} settings The live `file_optimisation` values.
 * @return {Object} A subject with a populated `now`.
 */
export const subjectFor = ( key, settings = {} ) => {
	const base = CSS_SUBJECTS[ key ];
	if ( ! base ) {
		return undefined;
	}

	const value = settings[ key ];
	const now = [];

	if ( typeof value === 'boolean' ) {
		now.push( {
			label: __( 'Currently', 'performance-optimisation' ),
			value: value
				? __( 'On', 'performance-optimisation' )
				: __( 'Off', 'performance-optimisation' ),
			// "Off" is idle, not poor. A setting nobody has turned on yet is
			// exactly what this screen is for.
			tone: value ? 'good' : 'idle',
		} );
	} else if ( typeof value === 'number' || typeof value === 'string' ) {
		now.push( {
			label: __( 'Current value', 'performance-optimisation' ),
			value: String( value ),
			tone: 'idle',
		} );
	} else {
		now.push( {
			label: __( 'Currently', 'performance-optimisation' ),
			value: __( 'Not set', 'performance-optimisation' ),
			tone: 'idle',
		} );
	}

	return { ...base, now };
};
