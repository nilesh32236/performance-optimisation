/**
 * QuickActionsCard — the Overview's "what can I do next" panel.
 *
 * Every action is a real, already-existing capability reached through the same
 * `apiCall` the rest of the admin uses, so whatever guard that endpoint carries
 * is still in force. Nothing here is a shortcut around a confirmation.
 *
 * The one action that changes state (clearing the cache) carries its own busy
 * key, so starting it never spins the navigation buttons beside it. That is the
 * button-local loading rule the campaign is built on.
 *
 * @package
 */

import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	faArrowRight,
	faBroom,
	faDatabase,
	faGaugeHigh,
	faImages,
} from '@fortawesome/free-solid-svg-icons';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';

import LoadingSubmitButton from '../common/LoadingSubmitButton';
import { apiCall, getErrorLogMessage } from '../../lib/apiRequest';
import useNotice from '../../lib/useNotice';

/**
 * The Overview quick actions card.
 *
 * @param {Object}   props            Component props.
 * @param {Function} props.onNavigate Called with an area id to open.
 * @return {Object} The card.
 */
export default function QuickActionsCard( { onNavigate } ) {
	const { notify } = useNotice();
	// One key per action. A single boolean here is exactly the bug the previous
	// campaign's #849 was about, and it is easy to reintroduce.
	const [ busy, setBusy ] = useState( null );

	const clearCache = useCallback( async () => {
		setBusy( 'clear-cache' );
		try {
			const result = await apiCall( 'clear_cache', {} );
			if ( result && result.success === false ) {
				throw new Error(
					result.message ||
						__(
							'The cache could not be cleared.',
							'performance-optimisation'
						)
				);
			}
			notify( {
				type: 'success',
				message: __(
					'Cache cleared. The next visitor will get a fresh page.',
					'performance-optimisation'
				),
			} );
		} catch ( error ) {
			notify( { type: 'error', message: getErrorLogMessage( error ) } );
		} finally {
			// Always released, so a failure cannot leave the button stuck.
			setBusy( null );
		}
	}, [ notify ] );

	const goTo = useCallback(
		( area ) => () => {
			if ( typeof onNavigate === 'function' ) {
				onNavigate( area );
			}
		},
		[ onNavigate ]
	);

	return (
		<section
			className="wppo-card wppo-overview__card"
			aria-labelledby="wppo-quick-actions-title"
		>
			<h2 className="wppo-card__title" id="wppo-quick-actions-title">
				{ __( 'Quick actions', 'performance-optimisation' ) }
			</h2>

			<LoadingSubmitButton
				type="button"
				className="wppo-button wppo-button--secondary"
				isLoading={ busy === 'clear-cache' }
				loadingLabel={ __( 'Clearing…', 'performance-optimisation' ) }
				onClick={ clearCache }
				label={
					<>
						<FontAwesomeIcon icon={ faBroom } />{ ' ' }
						{ __(
							'Clear the page cache',
							'performance-optimisation'
						) }
					</>
				}
			/>
			<p className="wppo-overview__action-hint">
				{ __(
					'The next visitor gets a freshly generated page.',
					'performance-optimisation'
				) }
			</p>

			<ul className="wppo-overview__links">
				<li>
					<button
						type="button"
						className="wppo-button wppo-button--link"
						onClick={ goTo( 'media' ) }
					>
						<FontAwesomeIcon icon={ faImages } />{ ' ' }
						{ __( 'Review images', 'performance-optimisation' ) }
						<FontAwesomeIcon icon={ faArrowRight } />
					</button>
				</li>
				<li>
					<button
						type="button"
						className="wppo-button wppo-button--link"
						onClick={ goTo( 'data-system' ) }
					>
						<FontAwesomeIcon icon={ faDatabase } />{ ' ' }
						{ __(
							'Review the database',
							'performance-optimisation'
						) }
						<FontAwesomeIcon icon={ faArrowRight } />
					</button>
				</li>
				<li>
					<button
						type="button"
						className="wppo-button wppo-button--link"
						onClick={ goTo( 'speed' ) }
					>
						<FontAwesomeIcon icon={ faGaugeHigh } />{ ' ' }
						{ __(
							'Tune speed settings',
							'performance-optimisation'
						) }
						<FontAwesomeIcon icon={ faArrowRight } />
					</button>
				</li>
			</ul>
		</section>
	);
}
