import { useState, useEffect, useCallback } from '@wordpress/element';
import { apiCall } from '../lib/apiRequest';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faTrash,
	faBroom,
	faCheckCircle,
	faExclamationTriangle,
	faDatabase,
} from '@fortawesome/free-solid-svg-icons';
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
		<div className="settings-form fadeIn">
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'center',
					marginBottom: '40px',
				} }
			>
				<h2 style={ { margin: 0 } }>
					<FontAwesomeIcon
						icon={ faDatabase }
						style={ {
							color: 'var(--wppo-primary)',
							marginRight: '12px',
						} }
					/>
					{ translations.databaseOptimization ||
						'Database Optimization' }
				</h2>
			</div>

			<p
				className="db-cleanup-intro"
				style={ {
					fontSize: '16px',
					color: 'var(--wppo-text-muted)',
					marginBottom: '40px',
					maxWidth: '800px',
				} }
			>
				{ translations.dbCleanupIntro ||
					'Maintain a lean and fast database by removing accumulated junk data, post revisions, and expired transients.' }
			</p>

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

			<div className="db-summary-bar">
				<div className="db-summary-count">
					<div className="db-summary-number">
						{ loadingCounts ? '...' : totalItems }
					</div>
					<div className="db-summary-label">
						{ translations.dbTotalItems || 'Items to Clean' }
					</div>
				</div>
				<LoadingSubmitButton
					className="submit-button"
					style={ {
						background: '#fff',
						color: 'var(--wppo-primary)',
						transform: 'none',
					} }
					onClick={ handleCleanAll }
					isLoading={ loading.all }
					disabled={ totalItems === 0 }
					label={
						<>
							<FontAwesomeIcon icon={ faBroom } />{ ' ' }
							{ translations.dbCleanAll || 'Optimise Everything' }
						</>
					}
					loadingLabel={ translations.dbCleaning || 'Cleaning...' }
				/>
			</div>

			<div className="db-cleanup-grid">
				{ CLEANUP_TYPES.map( ( item ) => (
					<div key={ item.key } className="wppo-card">
						<div
							style={ {
								display: 'flex',
								justifyContent: 'space-between',
								alignItems: 'flex-start',
								marginBottom: '12px',
							} }
						>
							<h4 style={ { margin: 0, fontSize: '16px' } }>
								{ item.label }
							</h4>
							<span
								style={ {
									background:
										( counts[ item.key ] || 0 ) > 0
											? 'var(--wppo-primary-soft)'
											: 'var(--wppo-bg-app)',
									color:
										( counts[ item.key ] || 0 ) > 0
											? 'var(--wppo-primary)'
											: 'var(--wppo-text-light)',
									padding: '4px 12px',
									borderRadius: '20px',
									fontSize: '13px',
									fontWeight: '700',
								} }
							>
								{ loadingCounts
									? '...'
									: counts[ item.key ] || 0 }
							</span>
						</div>
						<p
							style={ {
								fontSize: '14px',
								marginBottom: '24px',
								minHeight: '44px',
							} }
						>
							{ item.description }
						</p>
						<LoadingSubmitButton
							className="submit-button secondary"
							style={ { width: '100%' } }
							onClick={ () => handleCleanup( item.key ) }
							isLoading={ loading[ item.key ] }
							disabled={ ( counts[ item.key ] || 0 ) === 0 }
							label={
								<>
									<FontAwesomeIcon icon={ faTrash } />{ ' ' }
									{ translations.dbClean || 'Clean' }
								</>
							}
							loadingLabel={
								translations.dbCleaning || 'Cleaning...'
							}
						/>
					</div>
				) ) }
			</div>
		</div>
	);
};

export default DatabaseCleanup;
