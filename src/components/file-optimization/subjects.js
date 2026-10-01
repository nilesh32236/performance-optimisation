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

	// --- Sub-fields of Remove Unused CSS -------------------------------------
	// These only appear once that switch is on, so they are written as part of
	// the same decision rather than as standalone settings: each one is the
	// escape hatch for a specific way that aggressive trimming goes wrong.

	excludeUnusedCSS: subject( 'excludeUnusedCSS', {
		title: __( 'Safelist Selectors', 'performance-optimisation' ),
		does: __(
			'Keeps the listed CSS selectors even on pages that do not appear to use them.',
			'performance-optimisation'
		),
		detail: __(
			'This is where you put anything the scanner cannot see: a dropdown, a modal, a tab, a hover state.',
			'performance-optimisation'
		),
		cost: __(
			'Each selector you keep is CSS that will never be removed, so a long safelist gives back the savings it was meant to protect. Add selectors when something visibly breaks, not in advance.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	unusedCSSSafelistExtra: subject( 'unusedCSSSafelistExtra', {
		title: __(
			'Extra Safelist (builders / dynamic)',
			'performance-optimisation'
		),
		does: __(
			'A second list of selectors to keep, for styles your page builder generates on the fly.',
			'performance-optimisation'
		),
		detail: __(
			'Builder output often changes with the page content, so selectors that worked on one layout can be stripped on another. This list is for those.',
			'performance-optimisation'
		),
		cost: __(
			'Builder-generated classes are renamed on some themes, so entries here can go stale and quietly do nothing. Clear this list when a builder upgrade changes your class names.',
			'performance-optimisation'
		),
	} ),

	unusedCSSRegressionGuard: subject( 'unusedCSSRegressionGuard', {
		title: __( 'Visual regression guard', 'performance-optimisation' ),
		does: __(
			'Serves the full stylesheet instead of the trimmed one whenever trimming would leave too little CSS behind.',
			'performance-optimisation'
		),
		detail: __(
			'Trimming works by comparing a page against its own CSS. A page with almost nothing on it can come back looking like the selector is unused when it is really just late.',
			'performance-optimisation'
		),
		cost: __(
			'Some pages will then load untrimmed CSS, so the saving varies page to page. That is the trade: a slower page rather than a broken one.',
			'performance-optimisation'
		),
	} ),

	unusedCSSRegressionThreshold: subject( 'unusedCSSRegressionThreshold', {
		title: __( 'Minimum Retained CSS (%)', 'performance-optimisation' ),
		does: __(
			"Sets the point below which the guard gives up trimming. If more than this percentage of a page's CSS would be removed, the full stylesheet is served instead.",
			'performance-optimisation'
		),
		cost: __(
			'A higher value is safer but trims less often, so the savings on unusual pages disappear. The range is 5 to 50, and 20 suits most sites.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	usedCSSExcludeUrls: subject( 'usedCSSExcludeUrls', {
		title: __(
			'Disable Used CSS on these URLs',
			'performance-optimisation'
		),
		does: __(
			'Skips used-CSS delivery entirely on URLs that match, and serves those pages the full stylesheet as normal.',
			'performance-optimisation'
		),
		detail: __(
			'Use this for the few pages a scanner cannot handle: a builder editor, a checkout flow, a page that changes completely by query string.',
			'performance-optimisation'
		),
		cost: __(
			'Each match is a page with no CSS saving at all. A broad pattern such as every product page will quietly remove the benefit from a large part of the site.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	usedCSSDeliveryMode: subject( 'usedCSSDeliveryMode', {
		title: __( 'Used CSS Delivery Mode', 'performance-optimisation' ),
		kicker: __( 'CSS · Delivery', 'performance-optimisation' ),
		does: __(
			'Chooses how the CSS a page actually uses reaches the browser.',
			'performance-optimisation'
		),
		detail: __(
			'File and Delay both fall back to the complete stylesheet when the page is not cached. Remove does not: it trusts the trim, and steps itself back to Delay on builder pages.',
			'performance-optimisation'
		),
		cost: __(
			'File and Delay will occasionally serve a heavier page. Remove gives up that safety net in exchange for the smallest CSS, so it is the one to try last and revert first if a page looks wrong.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	// --- HTML optimisation -----------------------------------------------------
	// Minifying a document is riskier than minifying a stylesheet, because the
	// markup is what every other layer parses. The cost line on each of these
	// names the specific way it can break rather than saying "may cause issues",
	// which tells the reader nothing they can act on.

	minifyHTML: subject( 'minifyHTML', {
		kicker: __( 'HTML · Document output', 'performance-optimisation' ),
		title: __( 'Minify HTML', 'performance-optimisation' ),
		does: __(
			'Strips the indentation, comments and blank lines out of the HTML WordPress sends, so the page arrives smaller.',
			'performance-optimisation'
		),
		cost: __(
			'Alters the markup itself. Anything that parses your HTML with a strict regex, or that injects content by string matching, can misbehave; check any plugin that post-processes the final HTML.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	removeHTMLComments: subject( 'removeHTMLComments', {
		kicker: __( 'HTML · Document output', 'performance-optimisation' ),
		title: __( 'Remove HTML Comments', 'performance-optimisation' ),
		does: __(
			'Removes the comments WordPress and your theme leave in the page source before it is sent.',
			'performance-optimisation'
		),
		cost: __(
			'Rarely a problem, but conditional-comment tricks and some caching plugins mark up the HTML with comments they read back later. Turn this off first if a plugin suddenly stops working.',
			'performance-optimisation'
		),
	} ),

	minifyInlineCSS: subject( 'minifyInlineCSS', {
		kicker: __( 'HTML · Document output', 'performance-optimisation' ),
		title: __( 'Minify Inline CSS', 'performance-optimisation' ),
		does: __(
			'Cleans up the CSS that is written directly into the page by your theme and plugins, rather than by the stylesheet settings above.',
			'performance-optimisation'
		),
		cost: __(
			'A theme that inlines deliberately hand-formatted CSS as documentation will lose it. The saving is small, so this is rarely worth the risk on its own.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	minifyInlineJS: subject( 'minifyInlineJS', {
		kicker: __( 'HTML · Document output', 'performance-optimisation' ),
		title: __( 'Minify Inline JavaScript', 'performance-optimisation' ),
		does: __(
			'Strips whitespace and comments from the JavaScript written straight into the page, which is usually added by plugins rather than by a file.',
			'performance-optimisation'
		),
		cost: __(
			'The most likely of these four to break a page. Minifying inline script breaks any plugin that depends on its own formatting or comments, and it can break code that closes over whitespace-sensitive templates. Turn this off first if a plugin stops working after a speed change.',
			'performance-optimisation'
		),
		costTone: 'warn',
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
	} else if ( typeof value === 'string' && value.trim() === '' ) {
		// An empty list is the normal state of a safelist or an exclusion box,
		// and it is the state right after someone clears one. Rendering the raw
		// value left this row blank, which reads as a broken panel rather than
		// as "you have not filled this in".
		now.push( {
			label: __( 'Currently', 'performance-optimisation' ),
			value: __( 'Empty', 'performance-optimisation' ),
			tone: 'idle',
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
