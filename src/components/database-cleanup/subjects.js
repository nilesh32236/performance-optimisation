/**
 * Inspector copy for the Automated Database Cleanup card.
 *
 * The explanations live here rather than inline in the JSX for three reasons:
 * the copy is the substance of the redesign and deserves to be read as prose
 * in one place, a translator can reach it without navigating a 1,000-line
 * component, and the wording can be unit-tested without rendering anything.
 *
 * ## Writing rules for these
 *
 * `does` is written from the site owner's side of the screen. It names what
 * happens to their site, never the internal mechanism — "clears old saved
 * copies of your posts" rather than "prunes the postmeta rows by parent".
 *
 * `cost` is the consequence, stated plainly. A safe setting says so in one
 * line, because padding it with reassurance trains people to stop reading.
 * A risky one names the *specific* failure and the guard that prevents it.
 * Vague risk ("may cause issues") is worse than no risk at all.
 *
 * Every claim below is checked against `Database_Cleanup` in
 * `includes/Database/class-database-cleanup.php` rather than written from the
 * field label, because two of the four settings behave in a way the label
 * does not suggest. See the notes on `dbRevMaxAge` and `dbOptimize`.
 *
 * @since x-release-please-version
 */

import { __ } from '@wordpress/i18n';

/**
 * Build the subject for one setting.
 *
 * A small factory rather than four near-identical object literals: every
 * subject needs the same `id`/`kind` shape, and hand-writing that four times
 * is how one of them ends up with a typo in its id and quietly stops matching
 * its row.
 *
 * @param {string} key    The setting key, e.g. `dbSchedule`.
 * @param {Object} fields The prose: `title`, `does`, `cost`, and optionally
 *                        `detail`, `costTone`, `kicker`.
 * @return {Object} An inspector subject.
 */
const subject = ( key, fields ) => ( {
	id: `setting:${ key }`,
	kind: 'setting',
	kicker:
		fields.kicker ?? __( 'Cleanup · Database', 'performance-optimisation' ),
	...fields,
} );

const DATABASE_CLEANUP_SUBJECTS = {
	dbSchedule: subject( 'dbSchedule', {
		title: __( 'Automated Cleanup Schedule', 'performance-optimisation' ),
		does: __(
			'Runs the cleanup on a timer, so the junk data goes away without you opening this screen. Set to None and it only ever runs when you press a Clean button here.',
			'performance-optimisation'
		),
		detail: __(
			'A scheduled run covers every category on this page, not just revisions.',
			'performance-optimisation'
		),
		cost: __(
			'A scheduled run deletes permanently and never asks first. Whatever is in the trash, marked as spam, or uploaded to the media library without being attached to a post is gone for good. Back up the database first if you are not certain that is what you want.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	dbRevMaxAge: subject( 'dbRevMaxAge', {
		title: __( 'Delete Revisions Older Than', 'performance-optimisation' ),
		does: __(
			'Every time somebody edits a post, WordPress keeps a copy of the version before that edit. This sets how old one of those copies has to be before a cleanup is allowed to remove it.',
			'performance-optimisation'
		),
		detail: __(
			'Applies to every revision cleanup, whether it runs on the schedule above or from the Clean button on the Post Revisions card.',
			'performance-optimisation'
		),
		cost: __(
			'Revision history is the only undo you have. The age is held between 1 and 365 days, so 0 means one day rather than no limit, and nothing older than a year is protected by this rule. The setting below is what protects the most recent copies.',
			'performance-optimisation'
		),
	} ),

	dbRevKeepLatest: subject( 'dbRevKeepLatest', {
		title: __(
			'Always Keep the Most Recent Revisions',
			'performance-optimisation'
		),
		does: __(
			'Sets how many of the newest copies of each post are kept no matter how old they are. This is the floor under the age limit above, so those copies are never removed for age.',
			'performance-optimisation'
		),
		detail: __(
			'A post edited fewer times than this number keeps all of its revisions, because there is nothing older left to trim.',
			'performance-optimisation'
		),
		cost: __(
			'Each revision kept is a stored copy of a post, so a high number on a site with thousands of edited posts holds on to a lot of data. The cleanup holds this between 1 and 100.',
			'performance-optimisation'
		),
	} ),

	// A `SwitchField`, not a `SettingField`: the wrapper renders its own label
	// and control, so it is wired through `SettingRow` instead. Noted here
	// because this subject is otherwise easy to lose track of.
	dbOptimize: subject( 'dbOptimize', {
		title: __( 'Rebuild Tables After Cleanup', 'performance-optimisation' ),
		does: __(
			'After a cleanup, rewrites the database tables that were emptied so the space goes back to the disk and the tables stay quick to read.',
			'performance-optimisation'
		),
		detail: __(
			'Only the automated schedule above reads this switch. The Clean All button always rebuilds the tables whether this is on or off, and a Clean button on an individual card never rebuilds them.',
			'performance-optimisation'
		),
		cost: __(
			'Rebuilding locks each table while it works, so on a large site the cleanup takes noticeably longer and the site can feel slow until it finishes. Tables over 1 GB are skipped, which is the guard against a long lock.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),
};

export default DATABASE_CLEANUP_SUBJECTS;

/**
 * Build a subject with its live "where you stand now" rows.
 *
 * The panel asks three questions in a fixed order, and the third one cannot be
 * answered by static copy — it is the current value. Keeping the prose in
 * `DATABASE_CLEANUP_SUBJECTS` and deriving the state here means a screen only
 * has to pass its settings object, and every setting gets the third block for
 * free.
 *
 * @param {string} key      The setting key.
 * @param {Object} settings The live `database_cleanup` values.
 * @return {Object|undefined} A subject with a populated `now`, or `undefined`
 *                            for a key that has no subject.
 */
export const subjectFor = ( key, settings = {} ) => {
	const base = DATABASE_CLEANUP_SUBJECTS[ key ];
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
