/**
 * The admin's information architecture.
 *
 * Seven undifferentiated top-level tabs became five task-oriented areas. No
 * capability was removed: every one of the original seven screens is still
 * present, and each is now reachable as a sub-item of exactly one area. Keeping
 * the map in one module means navigation, the URL contract and the tests cannot
 * disagree about what exists or where it lives.
 *
 * @package
 */

import {
	faTachometerAlt,
	faFileCode,
	faBullseye,
	faImages,
	faDatabase,
	faServer,
	faTools,
	faCode,
} from '@fortawesome/free-solid-svg-icons';
import { __ } from '@wordpress/i18n';

/** The default area when the URL names none. */
export const DEFAULT_SECTION = 'overview';

/**
 * The five areas, in sidebar order.
 *
 * `purpose` is the one line that answers "why would I go here?", which the old
 * tab labels never did.
 */
export const SECTIONS = [
	{
		id: 'overview',
		label: __( 'Overview', 'performance-optimisation' ),
		icon: faTachometerAlt,
		purpose: __(
			'What is working, what is not configured, and what to do next.',
			'performance-optimisation'
		),
		items: [
			{
				id: 'overview',
				label: __( 'Summary', 'performance-optimisation' ),
			},
			{
				// The existing Dashboard keeps all thirteen panels; it is
				// relocated, not reduced, so no capability is lost.
				id: 'dashboard',
				label: __( 'All diagnostics', 'performance-optimisation' ),
			},
		],
	},
	{
		id: 'speed',
		label: __( 'Speed', 'performance-optimisation' ),
		icon: faFileCode,
		purpose: __(
			'Make pages load faster: assets, scripts, preloading and server rules.',
			'performance-optimisation'
		),
		items: [
			{
				id: 'fileOptimization',
				label: __( 'Assets & Scripts', 'performance-optimisation' ),
				icon: faCode,
			},
			{
				id: 'preload',
				label: __( 'Preload', 'performance-optimisation' ),
				icon: faBullseye,
			},
		],
	},
	{
		id: 'media',
		label: __( 'Media', 'performance-optimisation' ),
		icon: faImages,
		purpose: __(
			'Make images smaller, load sooner, and avoid shifting the page.',
			'performance-optimisation'
		),
		items: [
			{
				id: 'imageOptimization',
				label: __( 'Images', 'performance-optimisation' ),
			},
		],
	},
	{
		id: 'data-system',
		label: __( 'Data & System', 'performance-optimisation' ),
		icon: faDatabase,
		purpose: __(
			'Database cleanup and server-side caching, with the risk of each made clear.',
			'performance-optimisation'
		),
		items: [
			{
				id: 'databaseCleanup',
				label: __( 'Database Cleanup', 'performance-optimisation' ),
				icon: faDatabase,
			},
			{
				id: 'objectCache',
				label: __( 'Object Cache', 'performance-optimisation' ),
				icon: faServer,
			},
		],
	},
	{
		id: 'manage',
		label: __( 'Manage', 'performance-optimisation' ),
		icon: faTools,
		purpose: __(
			'Activity, API keys, import and export, and other maintenance tasks.',
			'performance-optimisation'
		),
		items: [
			{
				id: 'tools',
				label: __( 'Tools & Settings', 'performance-optimisation' ),
				icon: faTools,
			},
		],
	},
];

/** Every valid area id, in order. */
export const SECTION_IDS = SECTIONS.map( ( section ) => section.id );

/**
 * Every legacy screen id, so a bookmarked URL from before the redesign still
 * resolves instead of dumping the user on Overview.
 */

/**
 * The sub-items of an area, or an empty list for an unknown one.
 *
 * @param {string} sectionId Area id.
 * @return {Array} Sub-items.
 */
export const itemsForSection = ( sectionId ) =>
	SECTIONS.find( ( section ) => section.id === sectionId )?.items ?? [];
