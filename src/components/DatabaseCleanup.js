import { useState, useEffect, useCallback } from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTrash, faBroom, faCheckCircle, faExclamationTriangle, faSpinner } from '@fortawesome/free-solid-svg-icons';
import LoadingSubmitButton from './common/LoadingSubmitButton';

const translations = wppoSettings.translations;

const CLEANUP_TYPES = [
	{
		key: 'revisions',
		label: translations.dbRevisions || 'Post Revisions',
		description:
			translations.dbRevisionsDesc ||
			'Old versions of your posts saved during editing.',
		icon: faTrash,
	},
	{
		key: 'auto_drafts',
		label: translations.dbAutoDrafts || 'Auto Drafts',
		description:
			translations.dbAutoDraftsDesc ||
			'Automatically saved drafts that are no longer needed.',
		icon: faTrash,
	},
	{
		key: 'trashed_posts',
		label: translations.dbTrashedPosts || 'Trashed Posts',
		description:
			translations.dbTrashedPostsDesc ||
			'Posts that have been moved to the trash.',
		icon: faTrash,
	},
	{
		key: 'spam_comments',
		label: translations.dbSpamComments || 'Spam Comments',
		description:
			translations.dbSpamCommentsDesc || 'Comments marked as spam.',
		icon: faTrash,
	},
	{
		key: 'trashed_comments',
		label: translations.dbTrashedComments || 'Trashed Comments',
		description:
			translations.dbTrashedCommentsDesc ||
			'Comments that have been moved to the trash.',
		icon: faTrash,
	},
	{
		key: 'expired_transients',
		label: translations.dbExpiredTransients || 'Expired Transients',
		description:
			translations.dbExpiredTransientsDesc ||
			'Temporary cached data that has expired.',
		icon: faTrash,
	},
	{
		key: 'orphan_postmeta',
		label: translations.dbOrphanPostmeta || 'Orphaned Post Meta',
		description:
			translations.dbOrphanPostmetaDesc ||
			'Metadata entries with no associated post.',
		icon: faTrash,
	},
];

const DatabaseCleanup = () => {
	const [ counts, setCounts ] = useState( {} );
	const [ loading, setLoading ] = useState( {} );
	const [ loadingCounts, setLoadingCounts ] = useState( true );
	const [ notification, setNotification ] = useState( null );

	// Fetch counts from the server
	const fetchCounts = useCallback( async () => {
		setLoadingCounts( true );
		try {
			const response = await apiCall( 'database_cleanup_counts', {} );
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

	// Auto-dismiss notification
	useEffect( () => {
		if ( notification ) {
			const timer = setTimeout( () => setNotification( null ), 5000 );
			return () => clearTimeout( timer );
		}
	}, [ notification ] );

	const handleCleanup = useCallback(
		async ( type ) => {
			setLoading( ( prev ) => ( { ...prev, [ type ]: true } ) );

			try {
				const response = await apiCall( 'database_cleanup', { type } );

				if ( response.success ) {
					const deleted = response.data?.deleted ?? 0;
					setNotification( {
						type: 'success',
						message: `${
							translations.dbCleanupSuccess || 'Cleaned'
						}: ${ deleted } ${
							translations.dbItemsRemoved || 'items removed'
						}.`,
					} );
					// Refresh counts after cleanup
					fetchCounts();
				} else {
					setNotification( {
						type: 'error',
						message:
							response.message ||
							translations.dbCleanupError ||
							'Cleanup failed.',
					} );
				}
			} catch ( error ) {
				console.error( 'Database cleanup error:', error );
				setNotification( {
					type: 'error',
					message:
						translations.dbCleanupError ||
						'An error occurred during cleanup.',
				} );
			} finally {
				setLoading( ( prev ) => ( { ...prev, [ type ]: false } ) );
			}
		},
		[ fetchCounts ]
	);

	const handleCleanAll = useCallback( async () => {
		setLoading( ( prev ) => ( { ...prev, all: true } ) );

		try {
			const response = await apiCall( 'database_cleanup', {
				type: 'all',
			} );

			if ( response.success ) {
				const results = response.data?.results ?? {};
				const total = Object.values( results ).reduce(
					( sum, val ) => sum + ( parseInt( val ) || 0 ),
					0
				);
				setNotification( {
					type: 'success',
					message: `${
						translations.dbCleanAllSuccess || 'All cleanup complete'
					}: ${ total } ${
						translations.dbTotalItemsRemoved ||
						'total items removed'
					}.`,
				} );
				fetchCounts();
			} else {
				setNotification( {
					type: 'error',
					message:
						response.message ||
						translations.dbCleanupError ||
						'Cleanup failed.',
				} );
			}
		} catch ( error ) {
			console.error( 'Database cleanup error:', error );
			setNotification( {
				type: 'error',
				message:
					translations.dbCleanupError ||
					'An error occurred during cleanup.',
			} );
		} finally {
			setLoading( ( prev ) => ( { ...prev, all: false } ) );
		}
	}, [ fetchCounts ] );

	const totalItems = Object.values( counts ).reduce(
		( sum, val ) => sum + ( parseInt( val ) || 0 ),
		0
	);

	return (
		<div className="settings-form">
			<h2>
				{ translations.databaseOptimization || 'Database Optimization' }
			</h2>
			<p className="db-cleanup-intro">
				{ translations.dbCleanupIntro ||
					'Remove unnecessary data from your WordPress database to improve performance and reduce bloat.' }
			</p>

			{ /* Notification Toast */ }
			{ notification && (
				<div
					className={ `db-notification db-notification--${ notification.type }` }
				>
					<FontAwesomeIcon
						icon={
							notification.type === 'success'
								? faCheckCircle
								: faExclamationTriangle
						}
					/>
					<span>{ notification.message }</span>
				</div>
			) }

			{ /* Summary Bar */ }
			<div className="db-summary-bar">
				<div className="db-summary-count">
					<span className="db-summary-number">
						{ loadingCounts ? '...' : totalItems }
					</span>
					<span className="db-summary-label">
						{ translations.dbTotalItems || 'Total Items to Clean' }
					</span>
				</div>
				<LoadingSubmitButton
					className="db-clean-all-btn"
					onClick={handleCleanAll}
					isLoading={loading.all}
					disabled={totalItems === 0}
					label={
						<>
							<FontAwesomeIcon icon={ faBroom } />{ ' ' }
							{ translations.dbCleanAll || 'Clean All' }
						</>
					}
					loadingLabel={translations.dbCleaning || 'Cleaning...'}
				/>
			</div>

			{ /* Cleanup Cards Grid */ }
			<div className="db-cleanup-grid">
				{ CLEANUP_TYPES.map( ( item ) => (
					<div key={ item.key } className="db-cleanup-card">
						<div className="db-cleanup-card__header">
							<h4>{ item.label }</h4>
							<span
								className={ `db-cleanup-card__count ${
									( counts[ item.key ] || 0 ) > 0
										? 'has-items'
										: ''
								}` }
							>
								{ loadingCounts
									? '...'
									: counts[ item.key ] || 0 }
							</span>
						</div>
						<p className="db-cleanup-card__desc">
							{ item.description }
						</p>
						<LoadingSubmitButton
							className="db-cleanup-card__btn"
							onClick={() => handleCleanup(item.key)}
							isLoading={loading[item.key]}
							disabled={(counts[item.key] || 0) === 0}
							label={
								<>
									<FontAwesomeIcon icon={ faTrash } />{ ' ' }
									{ translations.dbClean || 'Clean' }
								</>
							}
							loadingLabel={translations.dbCleaning || 'Cleaning...'}
						/>
					</div>
				) ) }
			</div>
		</div>
	);
};

export default DatabaseCleanup;
