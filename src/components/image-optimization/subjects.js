/**
 * Inspector copy for the Images screen.
 *
 * Mirrors `file-optimization/subjects.js`: the same `subject()` factory, the
 * same keyed object, the same `subjectFor()` deriving the live "where you stand
 * now" rows.
 *
 * ## Writing rules for these
 *
 * `does` is written from the site owner's side of the screen. It names what
 * happens to their site, never the internal mechanism — "stops the browser
 * loading a full-size image on a phone" rather than naming a resize filter or a
 * image size setting.
 *
 * `cost` is the consequence, stated plainly. This is the screen where the
 * downside is sharpest and least reversible, so it is stated most plainly of
 * all: converting images changes the files your site serves, and anything that
 * points at an old filename directly — a hard-coded URL, an email, a post saved
 * years ago with that path pasted in — stops working. A safe setting says so
 * in one line. A risky one names the specific failure and the guard that
 * prevents it.
 *
 * The subject `title` here does not have to match the on-screen field label.
 * A field label identifies the setting in a list; the inspector explains it, and
 * is free to use plainer words than a cramped label has room for.
 *
 * Never name a function, a meta key, an image size, a directory, or a format
 * constant. Describe what the person's browser does, not what the plugin calls
 * it.
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
		fields.kicker ?? __( 'Images · Delivery', 'performance-optimisation' ),
	...fields,
} );

const IMAGE_SUBJECTS = {
	// --- Conversion ---------------------------------------------------------
	convertImg: subject( 'convertImg', {
		title: __( 'Auto Convert Formats', 'performance-optimisation' ),
		does: __(
			'Serves each image in a smaller modern format, keeping the original alongside it, so a visitor downloads fewer bytes for the same picture.',
			'performance-optimisation'
		),
		detail: __(
			'The picture a visitor sees is unchanged. What changes is the file behind it, which is usually the largest single thing on a typical page.',
			'performance-optimisation'
		),
		cost: __(
			'Converted images get a new filename, so anything pointing straight at the old one — a hard-coded URL, an old email, a path pasted into a post years ago — will stop finding it. The originals are kept, so this is reversible, but a renamed file is the one thing on this screen that can break a link you did not write here.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	conversionFormat: subject( 'conversionFormat', {
		title: __( 'Conversion Format', 'performance-optimisation' ),
		kicker: __( 'Images · Conversion', 'performance-optimisation' ),
		does: __(
			'Which modern format images are converted to. The right answer is usually the first option, which is the format every current browser understands.',
			'performance-optimisation'
		),
		cost: __(
			'A format that some browsers do not understand produces a second request, because the browser discards what it cannot use and asks again. That is slower and heavier than not converting at all.',
			'performance-optimisation'
		),
	} ),

	forceServerSideConversion: subject( 'forceServerSideConversion', {
		title: __( 'Force Server-Side Conversion', 'performance-optimisation' ),
		kicker: __( 'Images · Conversion', 'performance-optimisation' ),
		does: __(
			'Converts every image on every request instead of pre-converting them in the background.',
			'performance-optimisation'
		),
		cost: __(
			'Turn this on only if the background conversion is not producing converted files. As a standing setting it adds work to every page load rather than doing it once, so it will make a busy site slower while appearing to fix the problem.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	clientSideMimeTypeOverride: subject( 'clientSideMimeTypeOverride', {
		title: __(
			'Override Client-Side MIME Types',
			'performance-optimisation'
		),
		kicker: __( 'Images · Delivery', 'performance-optimisation' ),
		does: __(
			'Sends certain images with a different content type than the file extension suggests, so a server configured too narrowly still serves them correctly.',
			'performance-optimisation'
		),
		cost: __(
			'A workaround, not a setting most sites need. If your host already sends the right type, turning this on means every listed image is served with a type some caches and CDNs do not expect, which can stop them being cached at all.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	excludeConvertImages: subject( 'excludeConvertImages', {
		title: __( 'Skip Conversion For', 'performance-optimisation' ),
		kicker: __( 'Images · Conversion', 'performance-optimisation' ),
		does: __(
			'Leaves the matching images exactly as they are, and converts everything else.',
			'performance-optimisation'
		),
		cost: __(
			'One identifier per line. Use it for an image you know is already as small as it can usefully be — a tiny logo, an icon — where converting adds a request for no gain.',
			'performance-optimisation'
		),
	} ),

	discardOversizedSibling: subject( 'discardOversizedSibling', {
		title: __(
			'Discard Oversized Conversions',
			'performance-optimisation'
		),
		kicker: __( 'Images · Conversion', 'performance-optimisation' ),
		does: __(
			'Deletes a converted image when it is no smaller than the original, keeping whichever file is actually lighter.',
			'performance-optimisation'
		),
		detail: __(
			'Some images do not compress well. Without this, those visitors download a converted file that is the same size or larger than what they would have had anyway.',
			'performance-optimisation'
		),
		cost: __(
			'No known downside. The only effect is that those images are served in their original format, which is the point.',
			'performance-optimisation'
		),
	} ),

	// --- Responsive sizing --------------------------------------------------
	maxWidthImgSize: subject( 'maxWidthImgSize', {
		title: __( 'Max Width (px)', 'performance-optimisation' ),
		// The unit is declared, not inferred from the key. A bare number in the
		// panel is meaningless without it, and `maxWidthImgSize` has no "px" in
		// its name to guess from.
		unit: 'px',
		kicker: __( 'Images · Sizing', 'performance-optimisation' ),
		does: __(
			'Caps how wide a converted image may be, so a photo shot at 4000px is not served at 4000px to a phone.',
			'performance-optimisation'
		),
		cost: __(
			'Set this no higher than the widest your layout actually uses. Capping below that makes a large screen look soft, and gaining nothing on the screens that do not need the width.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	maxLongestEdgePx: subject( 'maxLongestEdgePx', {
		title: __( 'Max Longest Edge (px)', 'performance-optimisation' ),
		unit: 'px',
		kicker: __( 'Images · Sizing', 'performance-optimisation' ),
		does: __(
			'Caps the longest side of a converted image, which is what stops a very tall or very wide image from staying enormous after conversion.',
			'performance-optimisation'
		),
		cost: __(
			'Same trade as the width cap: too low and a large screen gets a soft image. Raise it only if you have images that are still large after the width cap.',
			'performance-optimisation'
		),
	} ),

	wrapInPicture: subject( 'wrapInPicture', {
		title: __( 'Wrap in Picture Tag', 'performance-optimisation' ),
		kicker: __( 'Images · Delivery', 'performance-optimisation' ),
		does: __(
			'Lets the browser choose which version of an image to download, instead of taking whatever the page hands it.',
			'performance-optimisation'
		),
		detail: __(
			'This is what makes a phone download the small version rather than the desktop one. It needs the responsive sizes above to be set to do anything.',
			'performance-optimisation'
		),
		cost: __(
			'Adds a small amount of markup per image. A theme or plugin that edits image markup may interact with it, so this is worth turning off first if images start displaying wrongly after an update.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	excludeSize: subject( 'excludeSize', {
		title: __( 'Skip Resizing For', 'performance-optimisation' ),
		kicker: __( 'Images · Sizing', 'performance-optimisation' ),
		does: __(
			'Leaves the matching images at their original size and skips the sizing above for them.',
			'performance-optimisation'
		),
		cost: __(
			'An image skipped here is served as large as it always was, on every device. Use it for an image that has to stay pixel-exact, such as a screenshot or a map.',
			'performance-optimisation'
		),
	} ),

	// --- Lazy loading -------------------------------------------------------
	lazyLoadImages: subject( 'lazyLoadImages', {
		title: __( 'Enable Lazy Load', 'performance-optimisation' ),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Waits to load an image until it is close to the screen, instead of fetching every image on the page immediately.',
			'performance-optimisation'
		),
		detail: __(
			'On a long page this is usually the largest single saving available: a visitor who does not scroll never downloads the images below.',
			'performance-optimisation'
		),
		cost: __(
			'An image that is lazy-loaded appears slightly later than one that is not. Anything a visitor is meant to see straight away — a logo, the first thing in an article — looks better left eager, and there is a field below for that.',
			'performance-optimisation'
		),
	} ),

	excludeFirstImages: subject( 'excludeFirstImages', {
		title: __( 'Leave First N Images Eager', 'performance-optimisation' ),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Leaves the first few images on each page loading normally, whatever the lazy-load setting above says.',
			'performance-optimisation'
		),
		detail: __(
			'This is the guard against the most visible lazy-load problem: a logo or headline image that pops in a moment after the page.',
			'performance-optimisation'
		),
		cost: __(
			'Each image left eager is loaded on every visit whether or not it is looked at, which is the saving lazy loading was meant to give back. Two or three is usually the useful amount.',
			'performance-optimisation'
		),
	} ),

	lazyLoadNative: subject( 'lazyLoadNative', {
		title: __( 'Use Native Lazy Loading', 'performance-optimisation' ),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Lets the browser decide what to defer, rather than using a script to work out when an image is near the screen.',
			'performance-optimisation'
		),
		detail: __(
			'Every current browser does this well and does it without extra code, so this is usually the better option where it is offered.',
			'performance-optimisation'
		),
		cost: __(
			'No known downside, and it removes a script from the page. The trade is that you no longer choose the threshold, so the first-image behaviour above matters more here.',
			'performance-optimisation'
		),
	} ),

	lazyLoadBackgroundImages: subject( 'lazyLoadBackgroundImages', {
		title: __(
			'Lazy-load CSS Background Images',
			'performance-optimisation'
		),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Applies the same deferral to images used as a background, which are otherwise always downloaded.',
			'performance-optimisation'
		),
		cost: __(
			'A background that is deferred is briefly not there, so on a section a visitor sees immediately this can look like a flash. Leave it off if a background is part of your first screen.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	lazyLoadVideos: subject( 'lazyLoadVideos', {
		title: __( 'Video Lazy Loading', 'performance-optimisation' ),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Does not load an embedded video until it is near the screen, and does not show its poster image until then either.',
			'performance-optimisation'
		),
		cost: __(
			'The poster image appears late, so a video placed above the fold can look like empty space for a moment. Set the placeholder below to something close to the real first frame to reduce that.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	enableVideoPlaceholder: subject( 'enableVideoPlaceholder', {
		title: __( 'Video Placeholder', 'performance-optimisation' ),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Shows something in place of a video while it is still loading, so the space is not blank.',
			'performance-optimisation'
		),
		cost: __(
			'Only applies to embeds the plugin recognises. A video hosted elsewhere may keep showing its own player, and the placeholder may be the wrong size for a vertical video.',
			'performance-optimisation'
		),
	} ),

	placeholderType: subject( 'placeholderType', {
		title: __( 'Placeholder Style', 'performance-optimisation' ),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Chooses what stands in for a loading video — a blurred copy of it, a plain colour, or a light progress bar.',
			'performance-optimisation'
		),
		cost: __(
			'A blurred copy is the nicest and costs a little to generate. A colour or a bar costs nothing but looks more obviously like a placeholder.',
			'performance-optimisation'
		),
	} ),

	excludeVideos: subject( 'excludeVideos', {
		title: __( 'Skip Lazy Loading For Videos', 'performance-optimisation' ),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Leaves the matching videos loading normally, and applies the placeholder and deferral above only to the rest.',
			'performance-optimisation'
		),
		cost: __(
			'One identifier per line. A video that plays automatically needs to be on this list, or it will not start until it is scrolled to.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	lazyRenderBelowFold: subject( 'lazyRenderBelowFold', {
		title: __(
			'Lazy-render Below-fold Sections',
			'performance-optimisation'
		),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Builds the parts of a page below the screen only when they are scrolled to, rather than shipping all of it up front.',
			'performance-optimisation'
		),
		cost: __(
			'Heavy-handed, and the most likely setting on this screen to produce a visible problem. Scrolling can stutter on a phone as each section builds, and anything that measures the page height — an ad slot, an anchor link — can end up in the wrong place. Turn it off first if a page misbehaves.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	lazyRenderExcludeBuilders: subject( 'lazyRenderExcludeBuilders', {
		title: __(
			'Exclude Page-builder Sections',
			'performance-optimisation'
		),
		kicker: __( 'Images · Loading', 'performance-optimisation' ),
		does: __(
			'Keeps sections built by your page builder out of the deferral above, so they are sent normally.',
			'performance-optimisation'
		),
		cost: __(
			'No real downside — the sections it protects are the ones most likely to break if deferred. If a page-builder section still misbehaves, add that template here.',
			'performance-optimisation'
		),
	} ),

	// --- Preloading and priority -------------------------------------------
	autoPreloadLCP: subject( 'autoPreloadLCP', {
		title: __( 'Auto-preload LCP Image', 'performance-optimisation' ),
		kicker: __( 'Images · Priority', 'performance-optimisation' ),
		does: __(
			'Finds the image that is the largest thing a visitor actually sees on each page and starts fetching it before the browser reaches it.',
			'performance-optimisation'
		),
		detail: __(
			'That image is what determines when the page looks finished, so starting it early is the most direct improvement to how quickly the page appears to load.',
			'performance-optimisation'
		),
		cost: __(
			'The guess is made per page and can be wrong. A carousel, a gallery or a page with two equally large images may get the wrong one, and preloading the wrong image is worse than preloading none because it competes with the real one for the connection.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	prioritizeLCPImages: subject( 'prioritizeLCPImages', {
		title: __( 'Prioritize LCP Images', 'performance-optimisation' ),
		kicker: __( 'Images · Priority', 'performance-optimisation' ),
		does: __(
			'Tells the browser to fetch the main image ahead of everything else on the page.',
			'performance-optimisation'
		),
		cost: __(
			'Same guess as above, applied more strongly. On a page with a hero image this is usually a clear win; on one without a clear main image it can delay a stylesheet that the page needs in order to display anything at all.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	preloadFrontPageImages: subject( 'preloadFrontPageImages', {
		title: __( 'Preload Front Page Images', 'performance-optimisation' ),
		kicker: __( 'Images · Priority', 'performance-optimisation' ),
		does: __(
			'Fetches the images you list before the browser reaches them, on your front page only.',
			'performance-optimisation'
		),
		cost: __(
			'This switch does nothing on its own — it acts only on the list below, so with that list empty there is no effect to measure. A preloaded image is downloaded on every front-page visit even by visitors who leave immediately.',
			'performance-optimisation'
		),
	} ),

	preloadFrontPageImagesUrls: subject( 'preloadFrontPageImagesUrls', {
		title: __( 'Front Page Image URLs', 'performance-optimisation' ),
		kicker: __( 'Images · Priority', 'performance-optimisation' ),
		does: __(
			'The images to fetch early, one per line.',
			'performance-optimisation'
		),
		cost: __(
			'Only worth listing images that are above the fold on the front page. A front page image changes with whatever is featured, so this list needs editing when the homepage layout changes.',
			'performance-optimisation'
		),
	} ),

	preloadPostTypeImage: subject( 'preloadPostTypeImage', {
		title: __( 'Preload Featured Images', 'performance-optimisation' ),
		kicker: __( 'Images · Priority', 'performance-optimisation' ),
		does: __(
			'Fetches the featured image of each content type you choose, before the browser reaches it.',
			'performance-optimisation'
		),
		cost: __(
			'A page with a featured image at the top gains a little; a page whose featured image sits far down the page gains nothing and pays for the download. Apply it to the content types where the image is genuinely the first thing seen.',
			'performance-optimisation'
		),
	} ),

	// --- Accessibility and helpers -----------------------------------------
	autoAltText: subject( 'autoAltText', {
		title: __( 'Auto-fill Missing Alt Text', 'performance-optimisation' ),
		kicker: __( 'Images · Accessibility', 'performance-optimisation' ),
		does: __(
			'Fills in alt text for images that have none, so a screen reader has something to read instead of skipping the image or announcing its filename.',
			'performance-optimisation'
		),
		cost: __(
			'Generated text is a description of the image, not an accurate account of it. It is a large improvement over no alt text at all, and it is not a substitute for writing it yourself — especially for an image that carries meaning.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	excludePostTypeImgUrl: subject( 'excludePostTypeImgUrl', {
		title: __( 'Skip Alt Text For', 'performance-optimisation' ),
		kicker: __( 'Images · Accessibility', 'performance-optimisation' ),
		does: __(
			'Leaves the matching images without generated alt text.',
			'performance-optimisation'
		),
		cost: __(
			'One identifier per line. Use it for an image that is purely decorative, where a description would be noise. An image that carries information should keep its alt text.',
			'performance-optimisation'
		),
	} ),
};

export default IMAGE_SUBJECTS;

/**
 * Build a subject with its live "where you stand now" rows.
 *
 * @param {string} key      The setting key.
 * @param {Object} settings The live `image_optimisation` values.
 * @return {Object} A subject with a populated `now`.
 */
export const subjectFor = ( key, settings = {} ) => {
	const base = IMAGE_SUBJECTS[ key ];
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
	} else if ( typeof value === 'string' ) {
		// A list reports how many entries it has, including none. A single
		// value — a format name, a size — is reported as it is, because there
		// is nothing to count.
		const trimmed = value.trim();
		if ( trimmed === '' ) {
			now.push( {
				label: __( 'Currently', 'performance-optimisation' ),
				value: __( 'Empty', 'performance-optimisation' ),
				tone: 'idle',
			} );
		} else if ( trimmed.includes( '\n' ) ) {
			const lines = trimmed
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
				label: __( 'Current value', 'performance-optimisation' ),
				value: trimmed,
				tone: 'idle',
			} );
		}
	} else if ( typeof value === 'number' ) {
		now.push( {
			label: __( 'Current value', 'performance-optimisation' ),
			value: base.unit ? `${ value } ${ base.unit }` : String( value ),
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
