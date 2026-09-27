/**
 * The Overview's status sentences.
 *
 * `overviewStatus.js` is a pure module: it returns a message key and its
 * arguments, never English prose, and never imports `wp.i18n`. That keeps the
 * status model testable and free of WordPress globals — but it also means the
 * copy has to live here, on the component side, or the Overview is
 * English-only.
 *
 * It was English-only. Every string was correctly wrapped in `__()`; the
 * template that ships to translators was never rebuilt *and* these 23 sentences
 * were never in it, because they were never handed to a translation function at
 * all. An audit found:
 *
 *     "The plugin did not report"          in .pot: 0
 *     "Page cache is on"                   in .pot: 0
 *     "is enabled and the server is reachable" in .pot: 0
 *     "Running on WordPress"               in .pot: 0
 *
 * Every entry must exist for every key in `DETAIL`; `detailCopy.test.js`
 * fails if one is missing, because a key with no copy would otherwise render a
 * blank row.
 *
 * @package
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Build one translator. The returned function takes the model's arguments and
 * produces the finished sentence.
 *
 * `order` is the model's argument names **in the order the placeholders expect
 * them**. An earlier version took a `{ wp: '%1$s' }` mapping whose labels looked
 * like they controlled position; they did not, because the argument array is
 * built from key order. So a swap of those labels passed every test while
 * meaning nothing. An ordered array says what it does.
 *
 * @param {string} Text  Format string.
 * @param {Array}  order Model argument names, in placeholder order.
 * @return {Function} A callable that formats this message.
 */
const t =
	( Text, order = [] ) =>
	( args = {} ) =>
		sprintf( Text, ...order.map( ( key ) => args[ key ] ) );

/**
 * The copy for every key `overviewStatus.js` can emit.
 *
 * @type {Object<string, Function>}
 */
export const DETAIL_COPY = {
	'cache-unavailable': t(
		__(
			'Cache state is not available right now.',
			'performance-optimisation'
		)
	),
	'cache-unknown': t(
		__(
			'The plugin did not report whether the page cache is enabled.',
			'performance-optimisation'
		)
	),
	'cache-off': t(
		__(
			'Page cache is turned off. Turning it on is usually the single biggest speed win.',
			'performance-optimisation'
		)
	),
	'cache-unreadable': t(
		__(
			'Page cache is switched on, but the plugin could not read how much it has stored. Open Speed to check the cache status.',
			'performance-optimisation'
		)
	),
	'cache-empty': t(
		__(
			'Page cache is switched on but nothing is cached yet. The next visitor will generate a page.',
			'performance-optimisation'
		)
	),
	'cache-active': t(
		// translators: %s: how much the page cache is currently storing.
		__(
			'Page cache is on, with %s of cached pages stored.',
			'performance-optimisation'
		),
		[ 'stored' ]
	),

	'object-unavailable': t(
		__(
			'Object cache state is not available right now.',
			'performance-optimisation'
		)
	),
	'object-off': t(
		__(
			'Object cache (Redis or Memcached) is off. Useful for busy or dynamic sites; not needed everywhere.',
			'performance-optimisation'
		)
	),
	'object-unknown': t(
		__(
			'The plugin did not report whether the object cache is enabled.',
			'performance-optimisation'
		)
	),
	'object-no-extension': t(
		__(
			'Object cache is enabled but the Redis extension is not available, so it cannot be used.',
			'performance-optimisation'
		)
	),
	'object-foreign-dropin': t(
		__(
			'Another plugin installed the object-cache drop-in, so this one is not active.',
			'performance-optimisation'
		)
	),
	'object-circuit-open': t(
		__(
			'Object cache is enabled but the safety breaker is open, so it is switched off until it recovers.',
			'performance-optimisation'
		)
	),
	'object-bypassed': t(
		__(
			'Object cache is enabled but is currently bypassed because of recent failures.',
			'performance-optimisation'
		)
	),
	'object-unreachable': t(
		__(
			'Object cache is enabled but the server is not reachable, so it is not speeding anything up.',
			'performance-optimisation'
		)
	),
	'object-reachable': t(
		__(
			'Object cache is enabled and the server is reachable.',
			'performance-optimisation'
		)
	),
	'object-reachability-unknown': t(
		__(
			'Object cache is enabled, but the plugin has not reported whether the server is reachable.',
			'performance-optimisation'
		)
	),

	'system-unavailable': t(
		__(
			'Server details are not available right now.',
			'performance-optimisation'
		)
	),
	'system-unknown': t(
		__(
			'The plugin did not report the PHP or WordPress version.',
			'performance-optimisation'
		)
	),
	'system-versions': t(
		// translators: 1: WordPress version, 2: PHP version.
		__(
			'Running on WordPress %1$s and PHP %2$s.',
			'performance-optimisation'
		),
		[ 'wp', 'php' ]
	),

	'vital-unmeasured': t(
		// translators: %s: advice on what to do about this metric.
		__( 'No real-user data yet. %s', 'performance-optimisation' ),
		[ 'hint' ]
	),
	'vital-good': t(
		// translators: 1: metric name, 2: the measured value.
		__( '%1$s is good at %2$s.', 'performance-optimisation' ),
		[ 'label', 'value' ]
	),
	'vital-attention': t(
		// translators: 1: metric name, 2: the measured value, 3: advice.
		__( '%1$s could be better (%2$s). %3$s', 'performance-optimisation' ),
		[ 'label', 'value', 'hint' ]
	),
	'vital-poor': t(
		// translators: 1: metric name, 2: the measured value, 3: advice.
		__( '%1$s is poor at %2$s. %3$s', 'performance-optimisation' ),
		[ 'label', 'value', 'hint' ]
	),
};

/**
 * Each metric's display name and the advice shown with it.
 *
 * These are English here rather than in the model precisely so they can be
 * translated. The first version interpolated them straight from the model into
 * these format strings, which meant a German translator would have received
 * `"%1$s ist schlecht bei %2$s. %3$s"` with `%1$s` still reading
 * "Loading (LCP)" — a mixed-language sentence, which is worse than the
 * homogeneous English one it replaced. An independent review caught it.
 */
/** The row headings, so nothing the user reads is English-only. */
export const ROW_COPY = {
	'page-cache': __( 'Page cache', 'performance-optimisation' ),
	'object-cache': __( 'Object cache', 'performance-optimisation' ),
	compatibility: __( 'Compatibility', 'performance-optimisation' ),
};

export const VITAL_COPY = {
	lcp: {
		label: __( 'Loading (LCP)', 'performance-optimisation' ),
		hint: __(
			'How long the main content takes to appear.',
			'performance-optimisation'
		),
	},
	cls: {
		label: __( 'Visual stability (CLS)', 'performance-optimisation' ),
		hint: __(
			'How much the page jumps around while loading.',
			'performance-optimisation'
		),
	},
	inp: {
		label: __( 'Responsiveness (INP)', 'performance-optimisation' ),
		hint: __(
			'How quickly the page reacts to a tap or click.',
			'performance-optimisation'
		),
	},
};

/**
 * Turn a model's `{ labelKey, hintKey }` pair into translated strings.
 *
 * An unknown metric falls back to the key itself, so a gap shows up as visible
 * text rather than a blank row.
 *
 * @param {Object} args The model's `detailArgs`.
 * @return {Object} `{ label, hint }` in the caller's language.
 */
const resolveVital = ( args = {} ) => {
	const copy = VITAL_COPY[ args.labelKey ] ?? {};
	return {
		label: copy.label ?? args.labelKey ?? '',
		hint:
			VITAL_COPY[ args.hintKey ]?.hint ??
			VITAL_COPY[ args.labelKey ]?.hint ??
			'',
	};
};

/**
 * A row's visible label, translated.
 *
 * Non-vital rows carry a plain `label` the model owns (Page cache, Object
 * cache, Compatibility) and those are stable nouns a translator can reasonably
 * keep; only the metric names needed moving, because they were being
 * interpolated into a sentence.
 *
 * @param {Object} row A row from the status model.
 * @return {string} The label to show.
 */
export const renderLabel = ( row ) => {
	const key = row?.labelKey;
	if ( ! key ) {
		return '';
	}
	// A metric is keyed `lcp`/`cls`/`inp`; a row is keyed `page-cache`. Both
	// are translated here, so no heading the user reads is English-only.
	return (
		VITAL_COPY[ key ]?.label ??
		( Object.hasOwn( ROW_COPY, key ) ? ROW_COPY[ key ] : key )
	);
};

/**
 * Render one row's status sentence.
 *
 * An unknown key renders nothing rather than a raw key, so a gap between the
 * model and this map is visible in the UI instead of looking like content.
 *
 * @param {Object} row A row from the status model.
 * @return {string} The translated sentence, or an empty string.
 */
export const renderDetail = ( row ) => {
	// `Object.hasOwn`, not a truthy lookup: a plain object literal inherits
	// `constructor`, `toString` and `__proto__` from its prototype, so
	// `renderDetail({ detailKey: '__proto__' })` used to throw.
	if ( ! row || ! Object.hasOwn( DETAIL_COPY, row.detailKey ) ) {
		return '';
	}
	const args = { ...row.detailArgs, ...resolveVital( row.detailArgs ) };
	return DETAIL_COPY[ row.detailKey ]( args );
};
