/**
 * Core task map — shortest paths from user goals to existing controls.
 *
 * Presentational metadata only: each entry names an existing WPPO tab
 * (matching App.js sidebarItems names) so the TaskMap card can navigate
 * via the existing onNavigate(tab) callback. No settings, REST, cache,
 * image, database, Redis, CDN, LiteSpeed, or runtime behavior changes.
 *
 * @since NEXT
 */

import { __ } from '@wordpress/i18n';

/**
 * Compact map of the seven most-looked-for tasks to their existing controls.
 *
 * Labels and blurbs use lazy getters so translations resolve at render
 * time (module-scope __() would freeze the locale at import).
 * The `tab` values must stay in sync with App.js sidebarItems names.
 *
 * @since NEXT
 * @type {Array<{key: string, tab: string, getTask: Function, getBlurb: Function, getAction: Function}>}
 */
export const CORE_TASKS = [
	{
		key: 'caching',
		tab: 'dashboard',
		getTask: () =>
			__(
				'Speed up repeat visits with page cache',
				'performance-optimisation'
			),
		getBlurb: () =>
			__(
				'Static HTML on the Dashboard Page Cache card — the biggest win.',
				'performance-optimisation'
			),
		getAction: () => __( 'Open Dashboard →', 'performance-optimisation' ),
	},
	{
		key: 'lcp',
		tab: 'preload',
		getTask: () =>
			__( 'Improve LCP and Core Web Vitals', 'performance-optimisation' ),
		getBlurb: () =>
			__(
				'Preload key fonts and CSS; diagnose first with the Dashboard audit.',
				'performance-optimisation'
			),
		getAction: () => __( 'Open Preload →', 'performance-optimisation' ),
	},
	{
		key: 'images',
		tab: 'imageOptimization',
		getTask: () =>
			__(
				'Shrink images with WebP and lazy loading',
				'performance-optimisation'
			),
		getBlurb: () =>
			__(
				'Convert, lazy-load, and serve responsive images from one tab.',
				'performance-optimisation'
			),
		getAction: () =>
			__( 'Open Image Optimisation →', 'performance-optimisation' ),
	},
	{
		key: 'css-js',
		tab: 'fileOptimization',
		getTask: () =>
			__(
				'Minify CSS/JS and tune critical CSS',
				'performance-optimisation'
			),
		getBlurb: () =>
			__(
				'Combine, defer, delay, and generate critical CSS in File Optimisation.',
				'performance-optimisation'
			),
		getAction: () =>
			__( 'Open File Optimisation →', 'performance-optimisation' ),
	},
	{
		key: 'database',
		tab: 'databaseCleanup',
		getTask: () =>
			__( 'Clean database overhead', 'performance-optimisation' ),
		getBlurb: () =>
			__(
				'Batched cleanup of revisions, transients, and spam in Database.',
				'performance-optimisation'
			),
		getAction: () => __( 'Open Database →', 'performance-optimisation' ),
	},
	{
		key: 'redis',
		tab: 'objectCache',
		getTask: () =>
			__(
				'Speed up dynamic pages with Redis',
				'performance-optimisation'
			),
		getBlurb: () =>
			__(
				'Standalone, Sentinel, or Cluster status and controls in Object Cache.',
				'performance-optimisation'
			),
		getAction: () =>
			__( 'Open Object Cache →', 'performance-optimisation' ),
	},
	{
		key: 'cdn',
		tab: 'fileOptimization',
		getTask: () =>
			__( 'Serve assets via a CDN', 'performance-optimisation' ),
		getBlurb: () =>
			__(
				'CDN hostname mapping in File Optimisation; edge purge on the Dashboard.',
				'performance-optimisation'
			),
		getAction: () =>
			__( 'Open CDN Settings →', 'performance-optimisation' ),
	},
];
