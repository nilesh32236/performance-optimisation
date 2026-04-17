import { useState, useEffect, useCallback, useId } from '@wordpress/element';
import { handleChange } from '../lib/util';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faTrash,
	faBroom,
	faCheckCircle,
	faExclamationTriangle,
	faDatabase,
	faCalendarAlt,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import ConfirmDialog from './common/ConfirmDialog';

const translations = wppoSettings.translations;

const CLEANUP_TYPES = [
	{ key: 'revisions', label: 'Post Revisions', description: 'Old versions of your posts saved during editing.', icon: faTrash },
	{ key: 'auto_drafts', label: 'Auto Drafts', description: 'Automatically saved drafts that are no longer needed.', icon: faTrash },
	{ key: 'trashed_posts', label: 'Trashed Posts', description: 'Posts that have been moved to the trash.', icon: faTrash },
	{ key: 'spam_comments', label: 'Spam Comments', description: 'Comments marked as spam.', icon: faTrash },
	{ key: 'trashed_comments', label: 'Trashed Comments', description: 'Comments that have been moved to the trash.', icon: faTrash },
	{ key: 'expired_transients', label: 'Expired Transients', description: 'Temporary cached data that has expired.', icon: faTrash },
	{ key: 'orphan_postmeta', label: 'Orphaned Post Meta', description: 'Metadata entries with no associated post.', icon: faTrash },
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
	const [ confirmDialog, setConfirmDialog ] = useState( { isOpen: false, type: null, label: '' } );

	const fetchCounts = useCallback( async () => {
		setLoadingCounts( true );
		try {
			const response = await apiCall( 'database_cleanup_counts', {}, 'GET' );
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

	const onSubmitSettings = async ( e ) => {
		if ( e ) e.preventDefault();
		setIsSaving( true );
		try {
			await apiCall( 'update_settings', { tab: 'database_cleanup', settings } );
			setNotification( { type: 'success', message: 'Settings saved successfully.' } );
		} catch ( error ) {
			setNotification( { type: 'error', message: 'Error saving settings.' } );
		} finally {
			setIsSaving( false );
		}
	};

	const handleCleanup = async ( type ) => {
		setLoading( ( prev ) => ( { ...prev, [ type ]: true } ) );
		try {
			const response = await apiCall( 'database_cleanup', { type } );
			if ( response.success ) {
				setNotification( { type: 'success', message: `Cleanup successful: ${ response.data?.deleted ?? 0 } items removed.` } );
				fetchCounts();
			}
		} finally {
			setLoading( ( prev ) => ( { ...prev, [ type ]: false } ) );
		}
	};

	const totalItems = Object.values( counts ).reduce( ( sum, val ) => sum + ( parseInt( val ) || 0 ), 0 );

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title="Database Cleanup"
				description="Optimize your database by removing junk data and optimizing table overhead."
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isSaving }
						onClick={ onSubmitSettings }
						label="Save Settings"
					/>
				}
			/>

			{ notification && (
				<div className={ `wppo-notice wppo-notice--${ notification.type }` }>
					<FontAwesomeIcon icon={ notification.type === 'success' ? faCheckCircle : faExclamationTriangle } />
					<span>{ notification.message }</span>
				</div>
			) }

			<div className="wppo-grid-2-col">
				<FeatureCard title="Automated Cleanup" icon={ <FontAwesomeIcon icon={ faCalendarAlt } /> }>
					<div style={ { display: 'grid', gap: '20px' } }>
						<div>
							<label className="field-label">Schedule Frequency</label>
							<select className="wppo-select" name="dbSchedule" value={ settings.dbSchedule } onChange={ handleChange( setSettings ) }>
								<option value="none">None (Manual Only)</option>
								<option value="daily">Daily</option>
								<option value="weekly">Weekly</option>
								<option value="monthly">Monthly</option>
							</select>
						</div>
						<div style={ { display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '16px' } }>
							<div>
								<label className="field-label">Max Age (Days)</label>
								<input className="wppo-input" type="number" name="dbRevMaxAge" value={ settings.dbRevMaxAge } onChange={ handleChange( setSettings ) } />
							</div>
							<div>
								<label className="field-label">Keep Latest</label>
								<input className="wppo-input" type="number" name="dbRevKeepLatest" value={ settings.dbRevKeepLatest } onChange={ handleChange( setSettings ) } />
							</div>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard title="Database Health" icon={ <FontAwesomeIcon icon={ faDatabase } /> } actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--secondary"
						onClick={ () => handleCleanup( 'all' ) }
						isLoading={ loading.all }
						disabled={ totalItems === 0 }
						label="Optimize Everything"
					/>
				}>
					<div style={ { textAlign: 'center', padding: '10px 0' } }>
						<span style={ { fontSize: '48px', fontWeight: '800', display: 'block', color: 'var(--wppo-primary)' } }>
							{ loadingCounts ? '...' : totalItems }
						</span>
						<span className="wppo-text-muted">Total Overhead Items</span>
					</div>
				</FeatureCard>
			</div>

			<div className="wppo-stats-grid" style={ { gridTemplateColumns: 'repeat(3, 1fr)' } }>
				{ CLEANUP_TYPES.map( ( item ) => (
					<FeatureCard key={ item.key } title={ item.label } actions={
						<button className="wppo-button wppo-button--secondary wppo-button--sm" 
								onClick={ () => handleCleanup( item.key ) }
								disabled={ ( counts[ item.key ] || 0 ) === 0 || loading[ item.key ] }>
							{ loading[ item.key ] ? '...' : 'Clean' }
						</button>
					}>
						<div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'center' } }>
							<p className="wppo-text-muted" style={ { margin: 0, fontSize: '13px' } }>{ item.description }</p>
							<span style={ { fontWeight: '700', fontSize: '18px' } }>{ counts[ item.key ] || 0 }</span>
						</div>
					</FeatureCard>
				) ) }
			</div>

			<ConfirmDialog
				isOpen={ confirmDialog.isOpen }
				onConfirm={ () => {
					setConfirmDialog( { ...confirmDialog, isOpen: false } );
					handleCleanup( confirmDialog.type );
				} }
				onCancel={ () => setConfirmDialog( { ...confirmDialog, isOpen: false } ) }
				title="Confirm Cleanup"
				message="This action will permanently delete items from your database. Proceed?"
				confirmLabel="Delete"
				variant="danger"
			/>
		</div>
	);
};

export default DatabaseCleanup;
