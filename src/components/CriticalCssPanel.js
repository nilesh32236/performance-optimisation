import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	faCheckCircle,
	faExclamationTriangle,
	faClock,
	faTimesCircle,
} from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import useNotice from '../lib/useNotice';
import NoticeBanner from './common/NoticeBanner';
import { formatBytes } from '../lib/util';

const STATUS_CONFIG = {
	ready: {
		icon: faCheckCircle,
		className: 'wppo-badge--success',
		label: __( 'Generated', 'performance-optimisation' ),
	},
	pending: {
		icon: faClock,
		className: 'wppo-badge--info',
		label: __( 'Pending', 'performance-optimisation' ),
	},
	failed: {
		icon: faTimesCircle,
		className: 'wppo-badge--error',
		label: __( 'Failed', 'performance-optimisation' ),
	},
	none: {
		icon: faExclamationTriangle,
		className: 'wppo-badge--warning',
		label: __( 'Not Generated', 'performance-optimisation' ),
	},
};

const CriticalCssPanel = ( { status = {}, onRegenerate } ) => {
	const [ isRegenerating, setIsRegenerating ] = useState( false );
	const { notice, notify, dismiss } = useNotice();

	const handleRegenerate = async () => {
		setIsRegenerating( true );
		try {
			await onRegenerate();
		} catch ( err ) {
			console.error( 'Failed to regenerate CCSS', err );
			notify( {
				type: 'error',
				message: __(
					'Failed to regenerate Critical CSS.',
					'performance-optimisation'
				),
			} );
		} finally {
			setIsRegenerating( false );
		}
	};

	const entries = Object.entries( status );

	return (
		<div className="wppo-ccss-panel wppo-mt-20">
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<div className="wppo-field-label">
				{ __( 'Critical CSS Status', 'performance-optimisation' ) }
			</div>
			<p className="wppo-text-muted wppo-mb-12">
				{ __(
					'Critical CSS is generated per template. Regenerate after theme changes.',
					'performance-optimisation'
				) }
			</p>
			{ entries.length > 0 ? (
				<div className="wppo-ccss-status-list wppo-mb-16">
					{ entries.map( ( [ hash, entry ] ) => {
						const statusKey =
							typeof entry === 'string' ? entry : entry.status;
						const label =
							typeof entry === 'object' && entry.label
								? entry.label
								: hash.substring( 0, 8 ) + '…';
						const size =
							typeof entry === 'object' &&
							Number.isFinite( entry.size )
								? entry.size
								: null;
						const truncated =
							typeof entry === 'object' && !! entry.truncated;
						const config =
							STATUS_CONFIG[ statusKey ] || STATUS_CONFIG.none;
						return (
							<div key={ hash } className="wppo-ccss-status-item">
								<span className="wppo-ccss-status-hash">
									{ label }
									{ null !== size && size > 0 && (
										<span className="wppo-text-muted">
											{ truncated
												? sprintf(
														/* translators: 1: file size, 2: "capped" label. */
														__(
															'— %1$s (%2$s)',
															'performance-optimisation'
														),
														formatBytes( size ),
														__(
															'capped',
															'performance-optimisation'
														)
												  )
												: sprintf(
														/* translators: %s: file size. */
														__(
															'— %s',
															'performance-optimisation'
														),
														formatBytes( size )
												  ) }
										</span>
									) }
								</span>
								<span
									className={ `wppo-badge ${ config.className }` }
								>
									<FontAwesomeIcon icon={ config.icon } />
									{ config.label }
								</span>
							</div>
						);
					} ) }
				</div>
			) : (
				<div className="wppo-text-muted wppo-mb-16">
					{ __(
						'No templates found. Save settings and regenerate.',
						'performance-optimisation'
					) }
				</div>
			) }
			<LoadingSubmitButton
				className="wppo-button wppo-button--secondary"
				isLoading={ isRegenerating }
				onClick={ handleRegenerate }
				label={ __( 'Regenerate All', 'performance-optimisation' ) }
			/>
		</div>
	);
};

export default CriticalCssPanel;
