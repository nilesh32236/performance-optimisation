import { __ } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCompass } from '@fortawesome/free-solid-svg-icons';
import FeatureCard from './common/FeatureCard';

/**
 * Existing feature paths for the most common performance tasks.
 *
 * These are navigation shortcuts only. They do not inspect telemetry, apply
 * settings, or make recommendations; they keep the existing seven-tab
 * structure discoverable for beginners and advanced users alike.
 */
export const FEATURE_TASK_LINKS = [
	{
		id: 'caching',
		label: __( 'Enable caching', 'performance-optimisation' ),
		destination: __( 'Dashboard → Page Cache', 'performance-optimisation' ),
		targetTab: 'dashboard',
		risk: __( 'Recommended', 'performance-optimisation' ),
	},
	{
		id: 'lcp',
		label: __( 'Improve LCP', 'performance-optimisation' ),
		destination: __(
			'Preload → Critical Assets Preloading',
			'performance-optimisation'
		),
		targetTab: 'preload',
		risk: __( 'Recommended', 'performance-optimisation' ),
	},
	{
		id: 'cwv',
		label: __( 'Improve Core Web Vitals', 'performance-optimisation' ),
		destination: __(
			'Dashboard → Performance Audit',
			'performance-optimisation'
		),
		targetTab: 'dashboard',
		risk: __( 'Recommended', 'performance-optimisation' ),
	},
	{
		id: 'images',
		label: __( 'Image optimization', 'performance-optimisation' ),
		destination: __( 'Image Optimization', 'performance-optimisation' ),
		targetTab: 'imageOptimization',
		risk: __( 'Recommended', 'performance-optimisation' ),
	},
	{
		id: 'css-js',
		label: __( 'Optimize CSS and JavaScript', 'performance-optimisation' ),
		destination: __( 'File Optimization', 'performance-optimisation' ),
		targetTab: 'fileOptimization',
		risk: __( 'Safe', 'performance-optimisation' ),
	},
	{
		id: 'database',
		label: __( 'Clean database', 'performance-optimisation' ),
		destination: __( 'Database Cleanup', 'performance-optimisation' ),
		targetTab: 'databaseCleanup',
		risk: __( 'Requires confirmation', 'performance-optimisation' ),
	},
	{
		id: 'redis',
		label: __( 'Enable Redis', 'performance-optimisation' ),
		destination: __( 'Redis Object Cache', 'performance-optimisation' ),
		targetTab: 'objectCache',
		risk: __( 'Advanced', 'performance-optimisation' ),
	},
	{
		id: 'cdn',
		label: __( 'Configure CDN', 'performance-optimisation' ),
		destination: __(
			'File Optimization → Network',
			'performance-optimisation'
		),
		targetTab: 'fileOptimization',
		risk: __( 'Advanced', 'performance-optimisation' ),
	},
];

const FeatureTaskLinks = ( { onNavigate = () => {} } ) => (
	<FeatureCard
		title={ __( 'Find a setting', 'performance-optimisation' ) }
		icon={ <FontAwesomeIcon icon={ faCompass } aria-hidden="true" /> }
	>
		<p className="wppo-text-muted">
			{ __(
				'Jump to an existing control without changing settings. Each path opens the feature where you can review the explanation, safety notes, and undo options.',
				'performance-optimisation'
			) }
		</p>
		<nav
			aria-label={ __( 'Feature shortcuts', 'performance-optimisation' ) }
		>
			<ul className="wppo-feature-task-list">
				{ FEATURE_TASK_LINKS.map( ( task ) => (
					<li key={ task.id }>
						<button
							type="button"
							className="wppo-feature-task-link"
							onClick={ () => onNavigate( task.targetTab ) }
							aria-label={ `${ task.label } — ${ task.destination }` }
						>
							<span className="wppo-feature-task-link__label">
								{ task.label }
							</span>
							<span className="wppo-feature-task-link__destination">
								{ task.destination }
							</span>
							<span className="wppo-feature-task-link__risk">
								{ task.risk }
							</span>
						</button>
					</li>
				) ) }
			</ul>
		</nav>
	</FeatureCard>
);

export default FeatureTaskLinks;
