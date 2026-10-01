/**
 * Inspector copy for the Tools & Settings screen.
 *
 * This screen had no subjects at all, and none of its controls pass through
 * SettingRow, SettingField or SwitchField — they are raw `input`, `select` and
 * `textarea` elements written directly into the markup. That is why the
 * inspector showed its empty state for every one of them, and it is why this
 * file exists rather than four more entries in an existing area.
 *
 * The writing rules are the ones documented at the top of
 * `file-optimization/subjects.js`, and they apply unchanged: `does` is written
 * from the site owner's side and never names the mechanism, `cost` states the
 * consequence plainly, and a risky setting names the specific failure and the
 * guard that prevents it.
 *
 * Only genuine settings get a subject here. Two controls on this screen are
 * action inputs rather than settings — the PageSpeed API key field and the
 * configuration file picker. They perform a one-off action when filled in, and
 * nothing persists that is worth explaining after the fact, so they stay out
 * of the panel for the same reason `wppoSingleTemplate` does.
 *
 * @package
 */

import { __ } from '@wordpress/i18n';

const subject = ( key, fields ) => ( {
	id: `setting:${ key }`,
	kind: 'setting',
	kicker:
		fields.kicker ??
		__( 'Tools · Site settings', 'performance-optimisation' ),
	...fields,
} );

const PLUGIN_SETTINGS_SUBJECTS = {
	autoRescan: subject( 'autoRescan', {
		title: __( 'Auto PageSpeed Re-scan', 'performance-optimisation' ),
		does: __(
			'Sets how often the homepage and your high-value URLs are re-measured in the background, so the scores on this screen stay current without you running a scan by hand.',
			'performance-optimisation'
		),
		detail: __(
			'A scan uses the Google PageSpeed API, and that API has a daily quota per key. Checking too often spends that quota on repeat measurements of pages whose scores rarely move.',
			'performance-optimisation'
		),
		cost: __(
			'Each re-scan consumes PageSpeed API quota, and a quota spent on automatic runs is not available when you want to test a change on demand. Weekly is usually enough to notice a regression.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	highValueUrls: subject( 'highValueUrls', {
		title: __( 'High-value URLs', 'performance-optimisation' ),
		does: __(
			'Lists the pages that matter most to your site, so background scans measure those instead of wherever the scanner happens to start.',
			'performance-optimisation'
		),
		detail: __(
			'One URL per line. Include the homepage, your busiest category or product template, and any page a customer reaches within a click or two of arriving.',
			'performance-optimisation'
		),
		cost: __(
			'Every URL listed is one more PageSpeed scan on each re-scan, against the same daily quota as the frequency above. A long list on a short interval will exhaust it.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),
};

const LABELS = {
	autoRescan: __( 'Re-scan frequency', 'performance-optimisation' ),
	highValueUrls: __( 'Tracked URLs', 'performance-optimisation' ),
};

/**
 * Look up the subject for one setting key, with its current value attached.
 *
 * The third inspector block, "Where you stand now", is rendered from the
 * subject's `now` list rather than from the control, so a subject built
 * without the value loses that block entirely. Every other area's subjectFor
 * takes the settings slice for exactly this reason, and this one matches it.
 *
 * @param {string} key      The setting key.
 * @param {Object} settings Current values, keyed by setting key.
 * @return {Object|undefined} The subject, or undefined when the key has none.
 */
export const subjectFor = ( key, settings = {} ) => {
	const base = PLUGIN_SETTINGS_SUBJECTS[ key ];
	if ( ! base ) {
		return undefined;
	}

	const raw = settings[ key ];
	const empty =
		raw === undefined ||
		raw === null ||
		raw === '' ||
		( Array.isArray( raw ) && raw.length === 0 );
	let shown;
	if ( empty ) {
		shown = __( 'Not set', 'performance-optimisation' );
	} else if ( key === 'highValueUrls' ) {
		// A list reads better as a count than as a wall of URLs in a 320px panel.
		shown = String(
			String( raw )
				.split( '\n' )
				.filter( ( line ) => line.trim() ).length
		);
	} else {
		shown = String( raw );
	}

	return {
		...base,
		now: [
			{
				label: LABELS[ key ],
				value: shown,
				tone: empty ? 'idle' : 'good',
			},
		],
	};
};

export default PLUGIN_SETTINGS_SUBJECTS;
