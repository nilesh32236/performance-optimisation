/**
 * Inspector copy for the Preload screen.
 *
 * Mirrors `file-optimization/subjects.js`: the same `subject()` factory, the
 * same keyed object, the same `subjectFor()` deriving the live "where you stand
 * now" rows.
 *
 * ## Writing rules for these
 *
 * `does` is written from the site owner's side of the screen. It names what
 * happens to their site, never the internal mechanism — "tells the browser to
 * open the connection to your analytics provider early" rather than naming a
 * resource hint.
 *
 * `cost` is the consequence, stated plainly. This screen is where that matters
 * most of all five: every setting here trades bandwidth and connections for
 * speed, and the ways it goes wrong are wasted requests, doubled-up
 * downloads, and — on a connection-hungry site — making things slower. A safe
 * setting says so in one line. A risky one names the specific failure and the
 * guard that prevents it.
 *
 * The most common real-world mistake on this screen is turning on a hint
 * without filling in the list it needs, which produces work with no benefit.
 * Where that is possible, the copy says so rather than leaving it to be
 * discovered.
 *
 * Never name a function, a meta key, a query var, or a resource-hint header.
 * Describe what the person's browser does, not what the plugin calls it.
 *
 * @since x-release-please-version
 */

import { __ } from '@wordpress/i18n';

/**
 * Build the subject for one setting.
 *
 * @param {string} key    The setting key.
 * @param {Object} fields The prose: `title`, `does`, `cost`, and optionally
 *                        `detail`, `costTone`, `kicker`.
 * @return {Object} An inspector subject.
 */
const subject = ( key, fields ) => ( {
	id: `setting:${ key }`,
	kind: 'setting',
	kicker:
		fields.kicker ?? __( 'Preload · Hints', 'performance-optimisation' ),
	...fields,
} );

const PRELOAD_SUBJECTS = {
	// --- Cache warm-up -------------------------------------------------------
	enablePreloadCache: subject( 'enablePreloadCache', {
		title: __( 'Cache Warm-up', 'performance-optimisation' ),
		kicker: __( 'Preload · Cache warm-up', 'performance-optimisation' ),
		does: __(
			'Builds the cached version of your most-visited pages after you publish, so the first visitor does not wait for the page to be generated.',
			'performance-optimisation'
		),
		detail: __(
			'Works best on a host with a cron worker that actually runs. On a quiet site this is often the single biggest perceived-speed win available.',
			'performance-optimisation'
		),
		cost: __(
			'Each warm-up uses a little server work and writes a cached copy of the page to disk. On a very large site that is real work per publish, and the cached copies take up space.',
			'performance-optimisation'
		),
	} ),

	excludePreloadCache: subject( 'excludePreloadCache', {
		title: __( 'Never Warm Up These URLs', 'performance-optimisation' ),
		kicker: __( 'Preload · Cache warm-up', 'performance-optimisation' ),
		does: __(
			'Skips the warm-up for URLs that match, so pages that are different every time are not fetched and cached on a schedule. One URL or path per line.',
			'performance-optimisation'
		),
		detail: __(
			'Use this for a search page, a cart, or anything that shows one person one thing. Caching those is at best pointless and at worst shows the wrong person the wrong page.',
			'performance-optimisation'
		),
		cost: __(
			'Every URL you exclude is a page that stays slow for its first visitor. A broad pattern such as every product page quietly disables the warm-up across a large part of the site.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	// --- Third-party connections --------------------------------------------
	preconnect: subject( 'preconnect', {
		title: __( 'Preconnect', 'performance-optimisation' ),
		kicker: __( 'Preload · Connections', 'performance-optimisation' ),
		does: __(
			'Tells the browser to open the connection to the services you list before it discovers a file from them, so the handshake is already done by the time it is needed.',
			'performance-optimisation'
		),
		detail: __(
			'This is the biggest available win when your slowest requests go to a third party — an analytics provider, a font host, a video service.',
			'performance-optimisation'
		),
		cost: __(
			'This switch does nothing on its own — it acts only on the origins listed below, so with that list empty there is no effect to measure. Each entry then costs an open connection whether or not a file from that host is ever needed, and browsers cap how many can be open at once, so a long list makes the later ones do nothing at all.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	preconnectOrigins: subject( 'preconnectOrigins', {
		title: __( 'Preconnect Origins', 'performance-optimisation' ),
		kicker: __( 'Preload · Connections', 'performance-optimisation' ),
		does: __(
			'The list of hosts the browser should connect to early. One full origin per line, including the scheme. With this list empty, the switch above does nothing at all.',
			'performance-optimisation'
		),
		cost: __(
			'Preconnect does nothing on its own — with this list empty, the switch above has nothing to act on and makes no difference. List only hosts you are certain are on the critical path, and remove any that have stopped being used.',
			'performance-optimisation'
		),
	} ),

	// --- DNS prefetch -------------------------------------------------------
	prefetchDNS: subject( 'prefetchDNS', {
		title: __( 'DNS Prefetch', 'performance-optimisation' ),
		kicker: __( 'Preload · DNS', 'performance-optimisation' ),
		does: __(
			'Resolves the addresses of the hosts you list while the rest of the page is still downloading, so the lookup is not waiting at the moment a file is needed.',
			'performance-optimisation'
		),
		detail: __(
			'Only worth it for hosts that are genuinely on the critical path. A lookup saved on a host that loads later is a lookup you paid for early and never used.',
			'performance-optimisation'
		),
		cost: __(
			'This switch does nothing on its own — it acts only on the origins listed below, so with that list empty there is no effect to measure. Resolving an address the page never uses is wasted work for the person reading it, and unlike opening a connection this is cheap per entry, which is exactly why it is easy to over-fill.',
			'performance-optimisation'
		),
	} ),

	dnsPrefetchOrigins: subject( 'dnsPrefetchOrigins', {
		title: __( 'DNS Prefetch Origins', 'performance-optimisation' ),
		kicker: __( 'Preload · DNS', 'performance-optimisation' ),
		does: __(
			'The list of hosts to resolve early. One origin per line, including the scheme. With this list empty, the switch above does nothing at all.',
			'performance-optimisation'
		),
		cost: __(
			'As above: this list is the whole effect. With it empty the switch does nothing.',
			'performance-optimisation'
		),
	} ),

	// --- Critical assets ----------------------------------------------------
	preloadFonts: subject( 'preloadFonts', {
		title: __( 'Preload Fonts', 'performance-optimisation' ),
		kicker: __( 'Preload · Critical assets', 'performance-optimisation' ),
		does: __(
			'Tells the browser to fetch your font files at the highest priority, as the page starts, rather than when it first reaches the text that needs them.',
			'performance-optimisation'
		),
		detail: __(
			'Without this, text can be invisible for a moment while the font arrives. This is what removes that flash on a first visit.',
			'performance-optimisation'
		),
		cost: __(
			'This switch does nothing on its own — it acts only on the file list below, so with that list empty there is no effect to measure.',
			'performance-optimisation'
		),
	} ),

	preloadCSS: subject( 'preloadCSS', {
		title: __( 'Preload Critical CSS', 'performance-optimisation' ),
		kicker: __( 'Preload · Critical assets', 'performance-optimisation' ),
		does: __(
			'Fetches the stylesheets you list at the highest priority, as the page starts.',
			'performance-optimisation'
		),
		cost: __(
			'This switch does nothing on its own — it acts only on the stylesheet list below, so with that list empty there is no effect to measure. A preloaded stylesheet also holds up rendering while it arrives, so preloading one that is not above the fold moves work earlier without making anything better.',
			'performance-optimisation'
		),
	} ),

	preloadFontsUrls: subject( 'preloadFontsUrls', {
		title: __( 'Preload Fonts', 'performance-optimisation' ),
		kicker: __( 'Preload · Critical assets', 'performance-optimisation' ),
		does: __(
			'Fetches the font files you list before the browser reaches the part of the page that needs them. One URL per line, and prefer the .woff2 format — it is the one every current browser understands, and an older format here means the preloaded file is not the one the page ends up using.',
			'performance-optimisation'
		),
		detail: __(
			'A font is usually the first thing that makes text appear. Preloading it removes the round trip that otherwise delays readable text.',
			'performance-optimisation'
		),
		cost: __(
			'Each preloaded font is downloaded whether or not the visitor ends up seeing text in that weight. Preloading a font that is above the fold on one page and below it on every other page makes those other pages slower.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	autoDiscoverFonts: subject( 'autoDiscoverFonts', {
		title: __( 'Automatically Discover Fonts', 'performance-optimisation' ),
		kicker: __( 'Preload · Critical assets', 'performance-optimisation' ),
		does: __(
			'Finds the font files your theme and pages actually use and preloads them without you listing them.',
			'performance-optimisation'
		),
		detail: __(
			'Usually the better option for most sites, because it tracks the fonts you really have instead of the ones you think you have.',
			'performance-optimisation'
		),
		cost: __(
			'Preloads everything it finds, including a font used on a single template. If that turns out to be too much, turn this off and use the list above instead.',
			'performance-optimisation'
		),
	} ),

	autoLcpPreload: subject( 'autoLcpPreload', {
		title: __(
			'Automatically Preload LCP Hero',
			'performance-optimisation'
		),
		kicker: __( 'Preload · Critical assets', 'performance-optimisation' ),
		does: __(
			'Finds the image that is the largest thing a visitor actually sees on each page and preloads that one.',
			'performance-optimisation'
		),
		detail: __(
			'The largest visible element is what determines when a page looks finished, so starting it earlier is the most direct improvement to how fast the page appears to load.',
			'performance-optimisation'
		),
		cost: __(
			'This is a guess made per page, and it can be wrong — a slideshow or a page with several equally large images may not get the one it needs. The cost of a wrong guess is a preload the visitor did not need.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	preloadCSSUrls: subject( 'preloadCSSUrls', {
		title: __( 'Preload Critical CSS', 'performance-optimisation' ),
		kicker: __( 'Preload · Critical assets', 'performance-optimisation' ),
		does: __(
			'Fetches the stylesheets you list before the browser reaches the part of the page that needs them. One URL per line, listed as they appear in your page markup rather than the rewritten filename the server sends.',
			'performance-optimisation'
		),
		cost: __(
			'Preloading a stylesheet blocks rendering while it arrives, in the same way a normal stylesheet does. On a stylesheet that is not above the fold, this moves work earlier without making anything better.',
			'performance-optimisation'
		),
	} ),

	// --- Speculative loading ------------------------------------------------
	enableSpeculationRules: subject( 'enableSpeculationRules', {
		title: __( 'Enable Speculative Loading', 'performance-optimisation' ),
		kicker: __(
			'Preload · Speculative loading',
			'performance-optimisation'
		),
		does: __(
			'Fetches the next page someone is likely to open before they click, so the click feels instant.',
			'performance-optimisation'
		),
		detail: __(
			'This is the only setting on the screen that works on navigation rather than on the current page. It helps a reader moving between pages; it does nothing for someone arriving from a search result.',
			'performance-optimisation'
		),
		cost: __(
			'Work is done for a page that may never be opened, so a visitor who leaves after one page has been made to pay for two. On a site with a clear next step, such as a multi-step form, that guess is wrong most of the time.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	speculationMode: subject( 'speculationMode', {
		title: __( 'Speculation Mode', 'performance-optimisation' ),
		kicker: __(
			'Preload · Speculative loading',
			'performance-optimisation'
		),
		does: __(
			'Chooses how far ahead the next page is prepared: fetched only, fetched and partly rendered, or fully rendered and ready to show.',
			'performance-optimisation'
		),
		detail: __(
			'Prefetch is the cautious choice and is the right default. Prerender is the fastest and the most expensive, and it is the one to try only after the cheaper modes are working well.',
			'performance-optimisation'
		),
		cost: __(
			'Each step further along costs more work per page and more memory, and on a shared host can make the site feel slow while it happens. Prerendering also means a page the visitor never opens has been built anyway.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	speculationEagerness: subject( 'speculationEagerness', {
		title: __( 'Speculation Eagerness', 'performance-optimisation' ),
		kicker: __(
			'Preload · Speculative loading',
			'performance-optimisation'
		),
		does: __(
			'Decides when the guess is made: on the first sign that someone is likely to click, or as soon as the page is interactive.',
			'performance-optimisation'
		),
		detail: __(
			'Eager is the right choice when most visitors continue to another page, which is typical of an article or a browsable list. Conservative suits a landing page where most people leave.',
			'performance-optimisation'
		),
		cost: __(
			'Eager does that work for every visitor, including the many who never click. On a page most people leave, that is a cost paid for a benefit almost nobody receives.',
			'performance-optimisation'
		),
	} ),

	speculationPrerenderList: subject( 'speculationPrerenderList', {
		title: __( 'Prerender High-Value URLs', 'performance-optimisation' ),
		kicker: __(
			'Preload · Speculative loading',
			'performance-optimisation'
		),
		does: __(
			'Fully prepares the specific pages you list, instead of leaving the browser to guess which links to follow.',
			'performance-optimisation'
		),
		detail: __(
			'This is the precise version of the setting above: use it when you know exactly which page comes next — the next step of a checkout, the next post in a series.',
			'performance-optimisation'
		),
		cost: __(
			'Every listed page is built for every visitor, whether or not they continue. A list of a hundred URLs is a hundred pages of work per visit.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	speculationExcludeUrls: subject( 'speculationExcludeUrls', {
		title: __( 'Speculation Exclude URLs', 'performance-optimisation' ),
		kicker: __(
			'Preload · Speculative loading',
			'performance-optimisation'
		),
		does: __(
			'Stops speculation on URLs that match, and serves those pages as an ordinary page load. One URL or path per line; a plain substring such as /checkout/ matches anything containing it, and wrapping it as #regex# makes it match exactly that pattern instead.',
			'performance-optimisation'
		),
		detail: __(
			'Add a checkout, a logged-in page, or anything where showing the wrong version would be a problem rather than a wasted request.',
			'performance-optimisation'
		),
		cost: __(
			'No real downside — an excluded page is simply loaded the normal way. The only cost is that a page which needed to be fast will not get the benefit.',
			'performance-optimisation'
		),
	} ),
};

export default PRELOAD_SUBJECTS;

/**
 * Build a subject with its live "where you stand now" rows.
 *
 * @param {string} key      The setting key.
 * @param {Object} settings The live `preload` values.
 * @return {Object} A subject with a populated `now`.
 */
export const subjectFor = ( key, settings = {} ) => {
	const base = PRELOAD_SUBJECTS[ key ];
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
			tone: value ? 'good' : 'idle',
		} );
	} else if ( typeof value === 'number' || typeof value === 'string' ) {
		// A list reports how many entries it has, including when that is none.
		// "Is anything in here" is the question that decides whether the switch
		// above it is doing anything at all, and an empty list is the most
		// useful thing this panel can say — so it says "0", not "Empty".
		const lines = String( value )
			.split( '\n' )
			.map( ( l ) => l.trim() )
			.filter( Boolean );
		now.push( {
			label: __( 'Entries', 'performance-optimisation' ),
			value: String( lines.length ),
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
