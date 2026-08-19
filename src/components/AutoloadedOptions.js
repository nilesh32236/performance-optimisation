/**
 * AutoloadedOptions component.
 *
 * Lists the largest autoloaded options (option bloat that inflates every page
 * load). Fetches GET /autoloaded_options on mount.
 *
 * @since 2.18.0
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDatabase, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { apiCall } from '../lib/apiRequest';
import FeatureCard from './common/FeatureCard';

/**
 * @return {Element} The autoloaded-options card.
 */
const AutoloadedOptions = () => {
	const [ options, setOptions ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const response = await apiCall(
				'autoloaded_options',
				{ limit: 20 },
				'GET'
			);
			if ( response.success && response.data?.options ) {
				setOptions( response.data.options );
			} else {
				setError(
					response.message ||
						__(
							'Failed to load autoloaded options.',
							'performance-optimisation'
						)
				);
			}
		} catch ( loadError ) {
			setError(
				__(
					'Failed to load autoloaded options.',
					'performance-optimisation'
				)
			);
			console.error( 'Error fetching autoloaded options:', loadError );
		} finally {
			setLoading( false );
		}
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	const formatSize = ( bytes ) => {
		if ( bytes < 1024 ) {
			return `${ bytes } B`;
		}
		return `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
	};

	let body;
	if ( error ) {
		body = <p className="wppo-text-muted">{ error }</p>;
	} else if ( options.length === 0 && ! loading ) {
		body = (
			<p className="wppo-text-muted">
				{ __(
					'No autoloaded options found.',
					'performance-optimisation'
				) }
			</p>
		);
	} else {
		body = (
			<ul className="wppo-autoloaded-options">
				{ options.map( ( option ) => (
					<li key={ option.option_name }>
						<code>{ option.option_name }</code>
						<span className="wppo-text-muted">
							{ formatSize( option.size ) }
						</span>
					</li>
				) ) }
			</ul>
		);
	}

	return (
		<FeatureCard
			title={ __( 'Autoloaded Options', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faDatabase } /> }
			actions={
				loading && (
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						aria-label={ __(
							'Loading…',
							'performance-optimisation'
						) }
					/>
				)
			}
		>
			<p className="wppo-text-muted">
				{ __(
					'The largest options loaded on every request. Reducing these improves TTFB on shared hosting.',
					'performance-optimisation'
				) }
			</p>
			{ body }
			{ options.length > 0 && (
				<p className="wppo-text-muted wppo-text-small">
					{ sprintf(
						/* translators: %d: number of options listed */
						__( 'Showing %d options.', 'performance-optimisation' ),
						options.length
					) }
				</p>
			) }
		</FeatureCard>
	);
};

export default AutoloadedOptions;
