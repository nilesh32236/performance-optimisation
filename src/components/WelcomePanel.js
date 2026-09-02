import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import NoticeBanner from './common/NoticeBanner';

const STEPS = [
	{
		number: 1,
		key: 'cache',
		label: __( 'Enable Page Caching', 'performance-optimisation' ),
		description: __(
			'Speed up your site with static HTML page caching — the single biggest performance win.',
			'performance-optimisation'
		),
		settings: {
			tab: 'cache_settings',
			payload: { enableCache: true },
		},
		isEnabled: () =>
			wppoSettings?.settings?.cache_settings?.enableCache ?? false,
	},
	{
		number: 2,
		key: 'minify',
		label: __( 'Enable JS / CSS Minification', 'performance-optimisation' ),
		description: __(
			'Reduce file sizes by removing whitespace and comments from your CSS and JavaScript.',
			'performance-optimisation'
		),
		settings: {
			tab: 'file_optimisation',
			payload: { minifyJS: true, minifyCSS: true },
		},
		isEnabled: () =>
			( wppoSettings?.settings?.file_optimisation?.minifyJS ?? false ) &&
			( wppoSettings?.settings?.file_optimisation?.minifyCSS ?? false ),
	},
	{
		number: 3,
		key: 'lazyload',
		label: __( 'Enable Lazy Loading', 'performance-optimisation' ),
		description: __(
			'Defer off-screen images and videos so they only load when visitors scroll to them.',
			'performance-optimisation'
		),
		settings: {
			tab: 'image_optimisation',
			payload: { lazyLoadImages: true },
		},
		isEnabled: () =>
			wppoSettings?.settings?.image_optimisation?.lazyLoadImages ?? false,
	},
];

const WelcomePanel = () => {
	const [ visible, setVisible ] = useState(
		wppoSettings?.show_welcome ?? false
	);
	const [ activatingStep, setActivatingStep ] = useState( null );
	const [ dismissing, setDismissing ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	if ( ! visible ) {
		return null;
	}

	const handleStepAction = async ( step ) => {
		setActivatingStep( step.key );
		dismiss();
		try {
			const [ updateRes, dismissRes ] = await Promise.all( [
				apiCall( 'update_settings', {
					tab: step.settings.tab,
					settings: step.settings.payload,
				} ),
				apiCall( 'dismiss_welcome' ),
			] );
			if ( updateRes.success && dismissRes.success ) {
				setVisible( false );
			} else {
				notify( {
					type: 'error',
					message:
						( ! updateRes.success && updateRes.message ) ||
						( ! dismissRes.success && dismissRes.message ) ||
						__(
							'Failed to enable the feature.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			console.error( 'Welcome panel action failed:', error );
			notify( {
				type: 'error',
				message: __(
					'Failed to enable the feature.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setActivatingStep( null );
		}
	};

	const handleDismiss = async () => {
		setDismissing( true );
		dismiss();
		try {
			const res = await apiCall( 'dismiss_welcome' );
			if ( res.success ) {
				setVisible( false );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to dismiss the welcome panel.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			console.error( 'Welcome dismiss failed:', error );
			notify( {
				type: 'error',
				message: __(
					'Failed to dismiss the welcome panel.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setDismissing( false );
		}
	};

	return (
		<FeatureCard
			className="wppo-welcome-panel"
			title={ __(
				'Welcome to Performance Optimisation',
				'performance-optimisation'
			) }
			footer={
				<LoadingSubmitButton
					type="button"
					className="wppo-button wppo-button--secondary"
					onClick={ handleDismiss }
					isLoading={ dismissing }
					label={ __( 'Got it', 'performance-optimisation' ) }
					loadingLabel={ __(
						'Dismissing…',
						'performance-optimisation'
					) }
				/>
			}
		>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<p className="wppo-welcome-panel__intro">
				{ __(
					'Get started in 3 quick steps. Each toggle below activates a key performance feature — no page reload needed.',
					'performance-optimisation'
				) }
			</p>
			<div className="wppo-welcome-steps">
				{ STEPS.map( ( step ) => {
					const enabled = step.isEnabled();
					return (
						<div
							key={ step.key }
							className={ `wppo-welcome-step${
								enabled ? ' wppo-welcome-step--done' : ''
							}` }
						>
							<span className="wppo-welcome-step__number">
								{ enabled ? (
									<svg
										width="16"
										height="16"
										viewBox="0 0 16 16"
										fill="none"
										aria-hidden="true"
									>
										<path
											d="M13.3 4.3L6 11.6 2.7 8.3"
											stroke="currentColor"
											strokeWidth="2"
											strokeLinecap="round"
											strokeLinejoin="round"
										/>
									</svg>
								) : (
									step.number
								) }
							</span>
							<div className="wppo-welcome-step__content">
								<strong className="wppo-welcome-step__label">
									{ step.label }
								</strong>
								<p className="wppo-welcome-step__desc">
									{ step.description }
								</p>
							</div>
							<div className="wppo-welcome-step__action">
								{ enabled ? (
									<span className="wppo-welcome-step__check">
										{ __(
											'Active',
											'performance-optimisation'
										) }
									</span>
								) : (
									<LoadingSubmitButton
										type="button"
										className="wppo-button wppo-button--primary"
										isLoading={
											activatingStep === step.key
										}
										aria-label={
											activatingStep === step.key
												? sprintf(
														/* translators: %s: feature name */
														__(
															'Enabling %s…',
															'performance-optimisation'
														),
														step.label
												  )
												: sprintf(
														/* translators: %s: feature name */
														__(
															'Enable %s',
															'performance-optimisation'
														),
														step.label
												  )
										}
										onClick={ () =>
											handleStepAction( step )
										}
										label={ __(
											'Enable',
											'performance-optimisation'
										) }
										loadingLabel={ __(
											'Enabling…',
											'performance-optimisation'
										) }
									/>
								) }
							</div>
						</div>
					);
				} ) }
			</div>
		</FeatureCard>
	);
};

export default WelcomePanel;
