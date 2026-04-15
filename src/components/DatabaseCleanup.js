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
import ConfirmDialog from './common/ConfirmDialog';

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

	// Confirmation dialog handlers.
	const requestCleanup = ( type, label ) => {
		const count = counts[ type ] || 0;
		if ( count === 0 ) {
			return;
		}
		setConfirmDialog( { isOpen: true, type, label } );
	};

	const requestCleanAll = () => {
		if ( totalItems === 0 ) {
			return;
		}
		setConfirmDialog( { isOpen: true, type: 'all', label: '' } );
	};

	const confirmAction = () => {
		const { type } = confirmDialog;
		setConfirmDialog( { isOpen: false, type: null, label: '' } );
		if ( type === 'all' ) {
			handleCleanAll();
		} else {
			handleCleanup( type );
		}
	};

	const cancelAction = () => {
		setConfirmDialog( { isOpen: false, type: null, label: '' } );
	};

	const getConfirmDialogProps = () => {
		if ( confirmDialog.type === 'all' ) {
			return {
				title: translations.confirmDeleteTitle || 'Confirm Deletion',
				message:
					translations.confirmDeleteAll ||
					'This will permanently delete all items across every category. This cannot be undone.',
				confirmLabel: translations.deleteAllBtn || 'Delete All',
			};
		}
		const count = counts[ confirmDialog.type ] || 0;
		return {
			title: translations.confirmDeleteTitle || 'Confirm Deletion',
			message: `${
				translations.confirmDeleteMsg || 'Permanently delete'
			} ${ count } ${ confirmDialog.label }? ${
				translations.confirmDeleteNote ||
				'This action cannot be undone.'
			}`,
			confirmLabel: translations.deleteBtn || 'Delete',
		};
	};

	return (
		<div className="settings-form fadeIn">
			<div className="settings-header-flex">
				<h2>
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

			<p className="db-cleanup-intro">
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
						background: 'var(--wppo-bg-card)',
						color: 'var(--wppo-primary)',
						transform: 'none',
					} }
					onClick={ requestCleanAll }
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
						<div className="db-card-header">
							<h4>{ item.label }</h4>
							<span
								className={ `db-count-badge${
									( counts[ item.key ] || 0 ) > 0
										? ' db-count-badge--active'
										: ''
								}` }
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
							onClick={ () =>
								requestCleanup( item.key, item.label )
							}
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

			{ /* Confirmation Dialog */ }
			<ConfirmDialog
				isOpen={ confirmDialog.isOpen }
				onConfirm={ confirmAction }
				onCancel={ cancelAction }
				variant="danger"
				{ ...getConfirmDialogProps() }
			>
				{ confirmDialog.type === 'all' && totalItems > 0 && (
					<ul className="wppo-dialog-detail-list">
						{ CLEANUP_TYPES.map( ( item ) => {
							const count = counts[ item.key ] || 0;
							return count > 0 ? (
								<li key={ item.key }>
									<span>{ item.label }</span>
									<span>{ count }</span>
								</li>
							) : null;
						} ) }
					</ul>
				) }
			</ConfirmDialog>
		</div>
	);
};

export default DatabaseCleanup;
