/**
 * Inspector copy for the Object Cache screen.
 *
 * Mirrors `file-optimization/subjects.js`: the same `subject()` factory, the
 * same keyed object, the same `subjectFor()` that derives the live "where you
 * stand now" rows. The copy is kept out of the JSX because it is the substance
 * of the redesign, and because it can be unit-tested without rendering.
 *
 * ## Writing rules for these
 *
 * `does` is written from the site owner's side of the screen. It names what
 * happens to their site, never the internal mechanism — "keeps query results in
 * memory so repeat visits skip the database" rather than naming a Redis call.
 *
 * `cost` is the consequence, stated plainly. This screen is where that matters
 * most: every field here is a connection to infrastructure, and the two ways it
 * goes wrong are a silent fallback to no cache at all, and a lock-out after too
 * many failed attempts. A safe setting says so in one line. A risky one names
 * the specific failure and what prevents it.
 *
 * Never name a server address, a password, an internal hostname, a PHP
 * function, a class, or a drop-in file. Describe what the person is deciding,
 * not what the plugin calls it.
 *
 * @since x-release-please-version
 */

import { __ } from '@wordpress/i18n';

/**
 * Build the subject for one setting.
 *
 * @param {string} key    The setting key, e.g. `host`.
 * @param {Object} fields The prose: `title`, `does`, `cost`, and optionally
 *                        `detail`, `costTone`, `kicker`.
 * @return {Object} An inspector subject.
 */
const subject = ( key, fields ) => ( {
	id: `setting:${ key }`,
	kind: 'setting',
	kicker:
		fields.kicker ??
		__( 'Object cache · Connection', 'performance-optimisation' ),
	...fields,
} );

/**
 * Fields whose value must never be shown back to the user.
 *
 * The inspector is rendered on screen, sits in the page for as long as the
 * panel is open, and gets captured in support screenshots. Echoing a stored
 * password into it would turn a diagnostic panel into a place a credential is
 * displayed, and the `now` block exists to answer "what is this set to" — for
 * a secret, "set" is the whole answer anyone needs.
 */
const SECRET_KEYS = new Set( [ 'password' ] );

const OBJECT_CACHE_SUBJECTS = {
	mode: subject( 'mode', {
		title: __( 'Deployment Mode', 'performance-optimisation' ),
		does: __(
			'Chooses the shape of the cache setup you already have: a single server, a Sentinel-managed group, or a sharded cluster.',
			'performance-optimisation'
		),
		detail: __(
			'This has to match what your host actually runs. Picking Sentinel when you have one plain server, or Cluster when you have a plain server, is the single most common reason this screen reports no connection.',
			'performance-optimisation'
		),
		cost: __(
			'The other fields below change with this one, so switching modes replaces the connection details you have entered. Read the new fields before you save.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	host: subject( 'host', {
		title: __( 'Host', 'performance-optimisation' ),
		does: __(
			'The address of the machine running your cache — usually something your host gives you, such as a name on their private network.',
			'performance-optimisation'
		),
		cost: __(
			'No known downside on its own. A wrong address here means no cache, so this is the first thing to check when the connection test fails.',
			'performance-optimisation'
		),
	} ),

	port: subject( 'port', {
		title: __( 'Port', 'performance-optimisation' ),
		does: __(
			'Which door on that machine the cache is listening on. Redis usually uses 6379.',
			'performance-optimisation'
		),
		cost: __(
			'No known downside on its own. Managed hosts often put the cache somewhere other than the usual port, and the value they give you is the one to use rather than the default.',
			'performance-optimisation'
		),
	} ),

	nodes: subject( 'nodes', {
		title: __( 'Server Nodes', 'performance-optimisation' ),
		kicker: __( 'Object cache · Cluster', 'performance-optimisation' ),
		does: __(
			'Lists every server in the cluster, so the plugin can find whichever ones are currently up.',
			'performance-optimisation'
		),
		cost: __(
			'One address per line, and only the addresses that are really cluster members. A node left in the list after it is gone slows every lookup while it is unreachable, which is why a cluster should normally be left to your host rather than configured by hand.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	master_name: subject( 'master_name', {
		title: __( 'Sentinel Master Name', 'performance-optimisation' ),
		kicker: __( 'Object cache · Sentinel', 'performance-optimisation' ),
		does: __(
			'The name Sentinel uses to identify which server is in charge. The plugin asks that server which one to read and write through.',
			'performance-optimisation'
		),
		cost: __(
			'This has to match the name your Sentinel group is configured with. A mismatch does not fail loudly — the plugin falls back to no cache and your site quietly gets slower instead of erroring.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	password: subject( 'password', {
		title: __( 'Auth Password', 'performance-optimisation' ),
		does: __(
			'The password required to talk to the cache, if your host sets one.',
			'performance-optimisation'
		),
		cost: __(
			'Leave it empty if the cache is only reachable from your server. A wrong password is treated the same way as a wrong host: no cache, no error.',
			'performance-optimisation'
		),
	} ),

	database: subject( 'database', {
		title: __( 'Database ID', 'performance-optimisation' ),
		does: __(
			'Which numbered slot your cache data is kept in, so a busy site does not share one with anything else on the same server.',
			'performance-optimisation'
		),
		cost: __(
			'Changing this makes everything currently cached unreachable, so the first request after the change is slow while the cache fills again. Pick a number your host has not already used.',
			'performance-optimisation'
		),
	} ),

	compression: subject( 'compression', {
		title: __( 'Memory Compression', 'performance-optimisation' ),
		does: __(
			'Compresses the cached values as they are stored, so more of them fit in the memory the cache is allowed to use.',
			'performance-optimisation'
		),
		cost: __(
			'Compression trades a little CPU for memory. On a server with room to spare, None is faster; under a tight memory limit, LZF is what stops the cache from filling up and evicting itself.',
			'performance-optimisation'
		),
	} ),

	persistent: subject( 'persistent', {
		title: __( 'Persistent Connections', 'performance-optimisation' ),
		does: __(
			'Keeps the connection to the cache open between requests instead of reconnecting each time.',
			'performance-optimisation'
		),
		detail: __(
			'Most hosts cap how many simultaneous connections a PHP process may open. Leaving this on when that cap is low is a common cause of intermittent failures under traffic.',
			'performance-optimisation'
		),
		cost: __(
			'Holds an open connection and a little memory for the life of the worker. Turn it off if the cache reports connection errors that come and go with traffic.',
			'performance-optimisation'
		),
		costTone: 'warn',
	} ),

	use_tls: subject( 'use_tls', {
		title: __( 'TLS / SSL Encryption', 'performance-optimisation' ),
		does: __(
			'Encrypts the traffic between your site and the cache.',
			'performance-optimisation'
		),
		cost: __(
			'Needed whenever the cache is not on the same machine, because the password travels with every connection. Turn it on for a remote or managed cache; on a local socket there is no traffic to encrypt and it only adds overhead.',
			'performance-optimisation'
		),
	} ),
};

export default OBJECT_CACHE_SUBJECTS;

/**
 * Build a subject with its live "where you stand now" rows.
 *
 * The panel's third question cannot be answered by static copy, so the state
 * is derived here. A screen only has to pass its settings object and every
 * setting gets the block for free.
 *
 * Connection fields need one addition: whether a required value is present is
 * the single most useful thing to know about a cache connection, and a blank
 * field is the usual reason nothing is being cached.
 *
 * @param {string} key      The setting key.
 * @param {Object} settings The live `object_cache` values.
 * @return {Object} A subject with a populated `now`.
 */
export const subjectFor = ( key, settings = {} ) => {
	const base = OBJECT_CACHE_SUBJECTS[ key ];
	if ( ! base ) {
		return undefined;
	}

	const value = settings[ key ];
	const now = [];

	if ( SECRET_KEYS.has( key ) ) {
		now.push( {
			label: __( 'Currently', 'performance-optimisation' ),
			value:
				typeof value === 'string' && value !== ''
					? __( 'Set', 'performance-optimisation' )
					: __( 'Not set', 'performance-optimisation' ),
			tone: 'idle',
		} );
	} else if ( typeof value === 'boolean' ) {
		now.push( {
			label: __( 'Currently', 'performance-optimisation' ),
			value: value
				? __( 'On', 'performance-optimisation' )
				: __( 'Off', 'performance-optimisation' ),
			tone: value ? 'good' : 'idle',
		} );
	} else if ( typeof value === 'string' && value.trim() === '' ) {
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
