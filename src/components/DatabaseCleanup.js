import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCheckCircle,
	faExclamationTriangle,
	faDatabase,
	faCalendarAlt,
	faTimes,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import ConfirmDialog from './common/ConfirmDialog';

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
];

const DatabaseCleanup = ( { options = {} } ) => {
	const defaultSettings = {
		dbSchedule: 'none',
		dbRevMaxAge: 30,
		dbRevKeepLatest: 5,
		...options,
	};

	const [ settings, setSettings ] = useState( defaultSettings );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ counts, setCounts ] = useState( {} );
	const [ loading, setLoading ] = useState( {} );
	const [ loadingCounts, setLoadingCounts ] = useState( true );
	const [ notification, setNotification ] = useState( null );
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
			}
		} catch ( error ) {
			console.error( 'Error fetching database cleanup counts:', error );
		} finally {
			setLoadingCounts( false );
		}
	}, [] );

	useEffect( () => {
		fetchCounts();
	}, [ fetchCounts ] );

	useEffect( () => {
		if ( notification ) {
			const timer = setTimeout( () => {
				setNotification( null );
			}, 5000 );
			return () => clearTimeout( timer );
		}
	}, [ notification ] );

	const onSubmitSettings = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setIsSaving( true );
		try {
			await apiCall( 'update_settings', {
				tab: 'database_cleanup',
				settings,
			} );
			setNotification( {
				type: 'success',
				message: __(
					'Settings saved successfully.',
					'performance-optimisation'
				),
			} );
		} catch ( error ) {
			setNotification( {
				type: 'error',
				message: __(
					'Error saving settings.',
					'performance-optimisation'
				),
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
				setNotification( {
					type: 'success',
					message:
						__(
							'Cleanup successful:',
							'performance-optimisation'
						) +
						` ${ response.data?.deleted ?? 0 } ` +
						__( 'items removed.', 'performance-optimisation' ),
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
				setNotification( { type: 'error', message: errorMsg } );
				if ( response.data?.deleted > 0 ) {
					fetchCounts();
				}
			}
		} catch ( error ) {
			setNotification( {
				type: 'error',
				message:
					error.message ||
					__(
						'Error executing cleanup.',
						'performance-optimisation'
					),
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

			{ notification && (
				<div
					className={ `wppo-notice wppo-notice--${ notification.type }` }
					role="alert"
					aria-live="polite"
				>
					<div className="wppo-notice__content">
						<FontAwesomeIcon
							icon={
								notification.type === 'success'
									? faCheckCircle
									: faExclamationTriangle
							}
						/>
						<span>{ notification.message }</span>
					</div>
					<button
						className="wppo-notice__dismiss"
						onClick={ () => setNotification( null ) }
						aria-label={ __(
							'Dismiss',
							'performance-optimisation'
						) }
					>
						<FontAwesomeIcon icon={ faTimes } />
					</button>
				</div>
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
									className="wppo-input"
									type="number"
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
									className="wppo-input"
									type="number"
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
							{ loadingCounts ? '...' : totalItems }
						</span>
						<span className="wppo-stat-hero__label">
							{ __(
								'Total Optimization Opportunities',
								'performance-optimisation'
							) }
						</span>
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
					{ CLEANUP_TYPES.map( ( item ) => (
						<FeatureCard
							key={ item.key }
							title={ item.label }
							actions={
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
									disabled={
										( counts[ item.key ] || 0 ) === 0 ||
										loading[ item.key ]
									}
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
							}
						>
							<div
								style={ {
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
								} }
							>
								<p
									className="wppo-text-muted"
									style={ { margin: 0, fontSize: '13px' } }
								>
									{ item.description }
								</p>
								<span
									style={ {
										fontWeight: '700',
										fontSize: '18px',
									} }
								>
									{ counts[ item.key ] || 0 }
								</span>
							</div>
						</FeatureCard>
					) ) }
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
