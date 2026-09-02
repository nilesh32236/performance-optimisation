import { __, _n, sprintf } from '@wordpress/i18n';
import {
	useState,
	useEffect,
	useCallback,
	useContext,
} from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import UnsavedChangesContext from '../lib/UnsavedChangesContext';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDatabase, faCalendarAlt } from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import SwitchField from './common/SwitchField';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import ConfirmDialog from './common/ConfirmDialog';
import NoticeBanner from './common/NoticeBanner';
import Tooltip from './common/Tooltip';

const RISK_BADGE_MAP = {
	revisions: {
		level: 'good',
		label: __( 'Safe', 'performance-optimisation' ),
	},
	expired_transients: {
		level: 'good',
		label: __( 'Safe', 'performance-optimisation' ),
	},
	oembed_cache: {
		level: 'good',
		label: __( 'Safe', 'performance-optimisation' ),
	},
	auto_drafts: {
		level: 'warning',
		label: __( 'Caution', 'performance-optimisation' ),
	},
	trashed_posts: {
		level: 'warning',
		label: __( 'Caution', 'performance-optimisation' ),
	},
	spam_comments: {
		level: 'warning',
		label: __( 'Caution', 'performance-optimisation' ),
	},
	trashed_comments: {
		level: 'warning',
		label: __( 'Caution', 'performance-optimisation' ),
	},
	unattached_media: {
		level: 'warning',
		label: __( 'Caution', 'performance-optimisation' ),
	},
	orphan_postmeta: {
		level: 'poor',
		label: __( 'Review', 'performance-optimisation' ),
	},
};

const CLEANUP_TYPES = [
	{
		key: 'revisions',
		label: __( 'Post Revisions', 'performance-optimisation' ),
		description: __(
			'Old versions of your posts saved during editing.',
			'performance-optimisation'
		),
	},
	{
		key: 'auto_drafts',
		label: __( 'Auto Drafts', 'performance-optimisation' ),
		description: __(
			'Automatically saved drafts that are no longer needed.',
			'performance-optimisation'
		),
	},
	{
		key: 'trashed_posts',
		label: __( 'Trashed Posts', 'performance-optimisation' ),
		description: __(
			'Posts that have been moved to the trash.',
			'performance-optimisation'
		),
	},
	{
		key: 'spam_comments',
		label: __( 'Spam Comments', 'performance-optimisation' ),
		description: __(
			'Comments marked as spam.',
			'performance-optimisation'
		),
	},
	{
		key: 'trashed_comments',
		label: __( 'Trashed Comments', 'performance-optimisation' ),
		description: __(
			'Comments that have been moved to the trash.',
			'performance-optimisation'
		),
	},
	{
		key: 'expired_transients',
		label: __( 'Expired Transients', 'performance-optimisation' ),
		description: __(
			'Temporary cached data that has expired.',
			'performance-optimisation'
		),
	},
	{
		key: 'orphan_postmeta',
		label: __( 'Orphaned Post Meta', 'performance-optimisation' ),
		description: __(
			'Metadata entries with no associated post.',
			'performance-optimisation'
		),
	},
	{
		key: 'unattached_media',
		label: __( 'Unattached Media', 'performance-optimisation' ),
		description: __(
			'Media files uploaded to the library but not attached to any post.',
			'performance-optimisation'
		),
	},
	{
		key: 'oembed_cache',
		label: __( 'oEmbed Cache', 'performance-optimisation' ),
		description: __(
			'Stored embed responses from YouTube, Twitter, Vimeo and other providers.',
			'performance-optimisation'
		),
	},
];

const DatabaseCleanup = ( { options = {} } ) => {
	const defaultSettings = {
		dbSchedule: 'none',
		dbRevMaxAge: 30,
		dbRevKeepLatest: 5,
		dbOptimize: true,
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isSaving, setIsSaving ] = useState( false );
	const { setIsDirty } = useContext( UnsavedChangesContext );
	const [ baseline, setBaseline ] = useState( defaultSettings );
	useEffect( () => {
		setBaseline( { ...defaultSettings, ...options } );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		options.dbSchedule,
		options.dbRevMaxAge,
		options.dbRevKeepLatest,
		options.dbOptimize,
	] );
	useEffect( () => {
		const dirty = JSON.stringify( settings ) !== JSON.stringify( baseline );
		setIsDirty( dirty );
	}, [ settings, baseline, setIsDirty ] );
	useEffect( () => {
		return () => setIsDirty( false );
	}, [ setIsDirty ] );
	const [ counts, setCounts ] = useState( {} );
	const [ loading, setLoading ] = useState( {} );
	const [ loadingCounts, setLoadingCounts ] = useState( true );
	const { notice, notify, dismiss } = useNotice();
	const [ confirmDialog, setConfirmDialog ] = useState( {
		isOpen: false,
		type: null,
		label: '',
	} );

	const fetchCounts = useCallback( async () => {
		setLoadingCounts( true );
		try {
			const response = await apiCall(
				'database_cleanup_counts',
				{},
				'GET'
			);
			if ( response.success && response.data ) {
				setCounts( response.data );
			} else {
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'Failed to load counts.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			console.error( 'Error fetching database cleanup counts:', error );
			notify( {
				type: 'error',
				message: __(
					'Failed to load counts.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setLoadingCounts( false );
		}
	}, [ notify ] );

	useEffect( () => {
		fetchCounts();
	}, [ fetchCounts ] );

	const onSubmitSettings = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setIsSaving( true );
		try {
			const res = await apiCall( 'update_settings', {
				tab: 'database_cleanup',
				settings,
			} );
			if ( res && res.success !== false ) {
				setBaseline( { ...settings } );
				setIsDirty( false );
			}
			notify( {
				type: 'success',
				message: __(
					'Settings saved successfully.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} catch {
			notify( {
				type: 'error',
				message: __(
					'Error saving settings.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setIsSaving( false );
		}
	};

	const handleCleanup = async ( type ) => {
		setLoading( ( prev ) => ( { ...prev, [ type ]: true } ) );
		try {
			const response = await apiCall( 'database_cleanup', { type } );
			if ( response.success ) {
				notify( {
					type: 'success',
					message: sprintf(
						// translators: %d is the number of items removed during cleanup.
						_n(
							'Cleanup successful: %d item removed.',
							'Cleanup successful: %d items removed.',
							response.data?.deleted ?? 0,
							'performance-optimisation'
						),
						response.data?.deleted ?? 0
					),
					durationMs: 5000,
				} );
				fetchCounts();
			} else {
				const failures = response.data?.failures;
				let errorMsg =
					response.message ||
					__( 'Cleanup failed.', 'performance-optimisation' );
				if ( failures ) {
					errorMsg +=
						' ' +
						__( 'Failures:', 'performance-optimisation' ) +
						' ' +
						Object.keys( failures ).join( ', ' );
				}
				notify( {
					type: 'error',
					message: errorMsg,
					durationMs: 5000,
				} );
				if ( response.data?.deleted > 0 ) {
					fetchCounts();
				}
			}
		} catch ( error ) {
			console.error( 'Database cleanup error:', error );
			notify( {
				type: 'error',
				message: __(
					'Error executing cleanup.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setLoading( ( prev ) => ( { ...prev, [ type ]: false } ) );
		}
	};

	const totalItems = Object.values( counts ).reduce(
		( sum, val ) => sum + ( parseInt( val ) || 0 ),
		0
	);

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Database Cleanup', 'performance-optimisation' ) }
				description={ __(
					'Optimize your database by removing junk data and optimizing table overhead.',
					'performance-optimisation'
				) }
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isSaving }
						onClick={ onSubmitSettings }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
					/>
				}
			/>

			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }

			<div className="wppo-stacked-cards">
				<FeatureCard
					title={ __(
						'Automated Database Cleanup',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faCalendarAlt } /> }
				>
					<div className="wppo-field-group">
						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="dbSchedule"
							>
								{ __(
									'Schedule Frequency',
									'performance-optimisation'
								) }
							</label>
							<select
								className="wppo-select"
								id="dbSchedule"
								name="dbSchedule"
								value={ settings.dbSchedule }
								onChange={ handleChange( setSettings ) }
								aria-describedby="dbSchedule-desc"
							>
								<option value="none">
									{ __(
										'None (Manual Only)',
										'performance-optimisation'
									) }
								</option>
								<option value="daily">
									{ __(
										'Daily',
										'performance-optimisation'
									) }
								</option>
								<option value="weekly">
									{ __(
										'Weekly',
										'performance-optimisation'
									) }
								</option>
								<option value="monthly">
									{ __(
										'Monthly',
										'performance-optimisation'
									) }
								</option>
							</select>
							<p
								id="dbSchedule-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								{ __(
									'How often the automated database cleanup routine should run in the background.',
									'performance-optimisation'
								) }
							</p>
						</div>
						<div className="wppo-grid-2-col wppo-mt-24">
							<div>
								<label
									className="wppo-field-label"
									htmlFor="dbRevMaxAge"
								>
									{ __(
										'Revision Max Age (Days)',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input wppo-input--mono"
									type="number"
									inputMode="numeric"
									id="dbRevMaxAge"
									name="dbRevMaxAge"
									min="0"
									value={ settings.dbRevMaxAge }
									onChange={ handleChange( setSettings ) }
									aria-describedby="dbRevMaxAge-desc"
								/>
								<p
									id="dbRevMaxAge-desc"
									className="wppo-text-muted wppo-mt-10 wppo-text-small"
								>
									{ __(
										'Delete post revisions older than this many days (0 for no age limit).',
										'performance-optimisation'
									) }
								</p>
							</div>
							<div>
								<label
									className="wppo-field-label"
									htmlFor="dbRevKeepLatest"
								>
									{ __(
										'Keep Latest Revisions',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input wppo-input--mono"
									type="number"
									inputMode="numeric"
									id="dbRevKeepLatest"
									name="dbRevKeepLatest"
									min="0"
									value={ settings.dbRevKeepLatest }
									onChange={ handleChange( setSettings ) }
									aria-describedby="dbRevKeepLatest-desc"
								/>
								<p
									id="dbRevKeepLatest-desc"
									className="wppo-text-muted wppo-mt-10 wppo-text-small"
								>
									{ __(
										'Always retain this many recent revisions per post, regardless of age.',
										'performance-optimisation'
									) }
								</p>
							</div>
						</div>
						<SwitchField
							label={ __(
								'Optimize tables after cleanup',
								'performance-optimisation'
							) }
							description={ __(
								'Automatically run OPTIMIZE TABLE on affected tables after cleanup to reclaim disk space and rebuild indexes.',
								'performance-optimisation'
							) }
							name="dbOptimize"
							checked={ settings.dbOptimize }
							onChange={ handleChange( setSettings ) }
						/>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Total Database Overhead',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faDatabase } /> }
					footer={
						<LoadingSubmitButton
							className="wppo-button wppo-button--secondary"
							onClick={ () =>
								setConfirmDialog( {
									isOpen: true,
									type: 'all',
									label: __(
										'Optimize Everything',
										'performance-optimisation'
									),
								} )
							}
							isLoading={ loading.all }
							disabled={ totalItems === 0 }
							label={ __(
								'Optimize Everything Now',
								'performance-optimisation'
							) }
						/>
					}
				>
					<div className="wppo-stat-hero">
						<span className="wppo-stat-hero__value">
							{ loadingCounts
								? '…'
								: `${ Number(
										totalItems
								  ).toLocaleString() } ${ _n(
										'item',
										'items',
										totalItems,
										'performance-optimisation'
								  ) }` }
						</span>
						<span className="wppo-stat-hero__label">
							{ __(
								'Total Optimisation Opportunities',
								'performance-optimisation'
							) }
						</span>
						<p className="wppo-text-muted wppo-text-small wppo-mt-10">
							{ totalItems === 0
								? __(
										'Your database is clean — no overhead items detected.',
										'performance-optimisation'
								  )
								: sprintf(
										/* translators: %d is the number of items that can be cleaned. */
										_n(
											'%d item can be safely removed to reclaim space.',
											'%d items can be safely removed to reclaim space.',
											totalItems,
											'performance-optimisation'
										),
										totalItems
								  ) }
						</p>
					</div>
				</FeatureCard>
			</div>

			<div className="wppo-mt-40">
				<h4 className="wppo-section-title">
					{ __(
						'Granular Cleanup Options',
						'performance-optimisation'
					) }
				</h4>
				<div className="wppo-grid-2-col wppo-mt-20">
					{ CLEANUP_TYPES.map( ( item ) => {
						const risk = RISK_BADGE_MAP[ item.key ];
						const count = counts[ item.key ] || 0;
						const isCleanDisabled =
							count === 0 || loading[ item.key ];
						const cleanButton = (
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--secondary wppo-button--sm"
								onClick={ () =>
									setConfirmDialog( {
										isOpen: true,
										type: item.key,
										label: item.label,
									} )
								}
								disabled={ isCleanDisabled }
								isLoading={ loading[ item.key ] }
								label={ __(
									'Clean',
									'performance-optimisation'
								) }
								loadingLabel={ __(
									'Cleaning',
									'performance-optimisation'
								) }
							/>
						);
						return (
							<FeatureCard
								key={ item.key }
								title={ item.label }
								actions={
									<div className="wppo-cleanup-row__actions">
										{ risk && (
											<span
												className={ `wppo-status-badge wppo-status-badge--${ risk.level }` }
											>
												{ risk.label }
											</span>
										) }
										{ isCleanDisabled && count === 0 ? (
											<Tooltip
												content={ __(
													'No items to clean',
													'performance-optimisation'
												) }
											>
												<span
													tabIndex={ 0 }
													aria-label={ __(
														'No items to clean',
														'performance-optimisation'
													) }
												>
													{ cleanButton }
												</span>
											</Tooltip>
										) : (
											cleanButton
										) }
									</div>
								}
							>
								<div className="wppo-cleanup-row">
									<p className="wppo-text-muted wppo-cleanup-row__desc">
										{ item.description }
									</p>
									<span className="wppo-cleanup-row__count wppo-cleanup-row__count--mono">
										{ Number(
											counts[ item.key ] || 0
										).toLocaleString() }
									</span>
								</div>
							</FeatureCard>
						);
					} ) }
				</div>
			</div>

			<ConfirmDialog
				isOpen={ confirmDialog.isOpen }
				onConfirm={ () => {
					setConfirmDialog( { ...confirmDialog, isOpen: false } );
					handleCleanup( confirmDialog.type );
				} }
				onCancel={ () =>
					setConfirmDialog( { ...confirmDialog, isOpen: false } )
				}
				title={
					__( 'Confirm', 'performance-optimisation' ) +
					` ${ confirmDialog.label }`
				}
				message={
					__(
						'This action will permanently delete',
						'performance-optimisation'
					) +
					` ${
						confirmDialog.type === 'all'
							? __( 'overhead items', 'performance-optimisation' )
							: confirmDialog.label.toLowerCase()
					} ` +
					__(
						'from your database. Proceed?',
						'performance-optimisation'
					)
				}
				confirmLabel={ __( 'Delete', 'performance-optimisation' ) }
				variant="danger"
			/>
		</div>
	);
};

export default DatabaseCleanup;
