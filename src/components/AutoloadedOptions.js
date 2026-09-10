/**
 * AutoloadedOptions component.
 *
 * Lists the largest autoloaded options (option bloat that inflates every page
 * load). Fetches GET /autoloaded_options on mount.
 *
 * One-click remediation (issue #934): a dry-run report shows the savings
 * before anything is touched, apply flips only non-core options above the
 * size threshold to autoload off, and every flip is revertible per option.
 *
 * @since 2.18.0
 * @since NEXT Added dry-run report, apply, and per-option revert via
 *             POST /autoload_remediate.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faDatabase, faSpinner } from '@fortawesome/free-solid-svg-icons';
import { apiCall } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import NoticeBanner from './common/NoticeBanner';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';

/**
 * @return {Element} The autoloaded-options card.
 */
const AutoloadedOptions = () => {
	const [ options, setOptions ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ report, setReport ] = useState( null );
	const [ appliedSummary, setAppliedSummary ] = useState( null );
	const [ remediated, setRemediated ] = useState( {} );
	const [ checking, setChecking ] = useState( false );
	const [ applying, setApplying ] = useState( false );
	const [ revertingAll, setRevertingAll ] = useState( false );
	const [ reverting, setReverting ] = useState( {} );
	const { notice, notify, dismiss } = useNotice();

	const load = useCallback(
		async ( signal ) => {
			setLoading( true );
			dismiss();
			try {
				const response = await apiCall(
					'autoloaded_options?limit=20',
					{},
					'GET',
					signal
				);
				if ( signal?.aborted ) {
					return;
				}
				if ( response.success && response.data?.options ) {
					setOptions( response.data.options );
				} else {
					notify( {
						type: 'error',
						message:
							response.message ||
							__(
								'Failed to load autoloaded options.',
								'performance-optimisation'
							),
						durationMs: 5000,
					} );
				}
			} catch ( loadError ) {
				if ( signal?.aborted || loadError?.name === 'AbortError' ) {
					return;
				}
				notify( {
					type: 'error',
					message: __(
						'Failed to load autoloaded options.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
				console.error(
					'Error fetching autoloaded options:',
					loadError
				);
			} finally {
				if ( ! signal?.aborted ) {
					setLoading( false );
				}
			}
		},
		[ notify, dismiss ]
	);

	useEffect( () => {
		const controller = new AbortController();
		load( controller.signal );
		return () => controller.abort();
	}, [ load ] );

	const runDryRun = useCallback( async () => {
		setChecking( true );
		try {
			const response = await apiCall( 'autoload_remediate', {
				mode: 'dry_run',
			} );
			if ( response.success && response.data ) {
				setReport( response.data );
				setAppliedSummary( null );
				setRemediated( response.data.remediated || {} );
			} else {
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'Failed to build the remediation report.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( dryRunError ) {
			console.error( 'Error building remediation report:', dryRunError );
			notify( {
				type: 'error',
				message: __(
					'Failed to build the remediation report.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setChecking( false );
		}
	}, [ notify ] );

	const applyFix = useCallback( async () => {
		setApplying( true );
		try {
			const response = await apiCall( 'autoload_remediate', {
				mode: 'apply',
			} );
			if ( response.success && response.data ) {
				// Keep the dry-run report intact: the apply payload has no
				// count/options, so store it separately for the applied summary.
				setAppliedSummary( response.data );
				setRemediated( response.data.remediated || {} );
				// Reload first: load() dismisses stale notices, so notify after.
				await load();
				notify( {
					type: 'success',
					message: sprintf(
						/* translators: %d: number of options remediated. */
						__(
							'Remediation applied to %d options.',
							'performance-optimisation'
						),
						response.data.applied?.length || 0
					),
					durationMs: 5000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'Failed to apply remediation.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( applyError ) {
			console.error( 'Error applying remediation:', applyError );
			notify( {
				type: 'error',
				message: __(
					'Failed to apply remediation.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setApplying( false );
		}
	}, [ notify, load ] );

	const revertOption = useCallback(
		async ( optionName ) => {
			setReverting( ( prev ) => ( { ...prev, [ optionName ]: true } ) );
			try {
				const response = await apiCall( 'autoload_remediate', {
					mode: 'revert',
					option: optionName,
				} );
				if ( response.success ) {
					setRemediated( ( prev ) => {
						const next = { ...prev };
						delete next[ optionName ];
						return next;
					} );
					setAppliedSummary( ( prev ) => {
						if ( ! prev || ! Array.isArray( prev.applied ) ) {
							return prev;
						}
						const applied = prev.applied.filter(
							( item ) => item?.option_name !== optionName
						);
						return {
							...prev,
							applied,
							bytes_saved: applied.reduce(
								( sum, item ) => sum + ( item?.size || 0 ),
								0
							),
						};
					} );
					// Reload first: load() dismisses stale notices, so notify after.
					await load();
					notify( {
						type: 'success',
						message: sprintf(
							/* translators: %s: option name. */
							__(
								'Reverted autoload for %s.',
								'performance-optimisation'
							),
							optionName
						),
						durationMs: 5000,
					} );
				} else {
					notify( {
						type: 'error',
						message:
							response.message ||
							__(
								'Failed to revert option.',
								'performance-optimisation'
							),
						durationMs: 5000,
					} );
				}
			} catch ( revertError ) {
				console.error( 'Error reverting option:', revertError );
				notify( {
					type: 'error',
					message: __(
						'Failed to revert option.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} finally {
				setReverting( ( prev ) => ( {
					...prev,
					[ optionName ]: false,
				} ) );
			}
		},
		[ notify, load ]
	);

	const revertAll = useCallback( async () => {
		setRevertingAll( true );
		try {
			const response = await apiCall( 'autoload_remediate', {
				mode: 'revert_all',
			} );
			if ( response.success ) {
				setRemediated( {} );
				setAppliedSummary( null );
				// Reload first: load() dismisses stale notices, so notify after.
				await load();
				notify( {
					type: 'success',
					message: __(
						'All remediated options reverted.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						response.message ||
						__(
							'Failed to revert options.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( revertAllError ) {
			console.error( 'Error reverting options:', revertAllError );
			notify( {
				type: 'error',
				message: __(
					'Failed to revert options.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setRevertingAll( false );
		}
	}, [ notify, load ] );

	const formatSize = ( bytes ) => {
		if ( bytes < 1024 ) {
			return `${ bytes } B`;
		}
		if ( bytes < 1048576 ) {
			return `${ ( bytes / 1024 ).toFixed( 1 ) } KB`;
		}
		if ( bytes < 1073741824 ) {
			return `${ ( bytes / 1048576 ).toFixed( 1 ) } MB`;
		}
		return `${ ( bytes / 1073741824 ).toFixed( 1 ) } GB`;
	};

	const remediatedNames = Object.keys( remediated );

	let body = null;
	if ( options.length === 0 && ! loading ) {
		body = (
			<p className="wppo-text-muted">
				{ __(
					'No autoloaded options found.',
					'performance-optimisation'
				) }
			</p>
		);
	} else if ( options.length > 0 ) {
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
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
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
			<div className="wppo-mt-20">
				<h4 className="wppo-section-title">
					{ __(
						'One-click autoload remediation',
						'performance-optimisation'
					) }
				</h4>
				<p className="wppo-text-muted wppo-text-small">
					{ __(
						'Dry run shows the savings before touching anything. Only non-core options above the size threshold are flipped, and every flip can be reverted.',
						'performance-optimisation'
					) }
				</p>
				<div className="wppo-button-row wppo-mt-10">
					<LoadingSubmitButton
						type="button"
						className="wppo-button wppo-button--secondary wppo-button--sm"
						onClick={ runDryRun }
						isLoading={ checking }
						disabled={ applying || revertingAll }
						label={ __(
							'Check savings',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Checking…',
							'performance-optimisation'
						) }
					/>
					{ report && report.count > 0 && (
						<LoadingSubmitButton
							type="button"
							className="wppo-button wppo-button--primary wppo-button--sm"
							onClick={ applyFix }
							isLoading={ applying }
							disabled={ checking || revertingAll }
							label={ __(
								'Apply fix',
								'performance-optimisation'
							) }
							loadingLabel={ __(
								'Applying…',
								'performance-optimisation'
							) }
						/>
					) }
					{ remediatedNames.length > 0 && (
						<LoadingSubmitButton
							type="button"
							className="wppo-button wppo-button--secondary wppo-button--sm"
							onClick={ revertAll }
							isLoading={ revertingAll }
							disabled={ checking || applying }
							label={ __(
								'Revert all',
								'performance-optimisation'
							) }
							loadingLabel={ __(
								'Reverting…',
								'performance-optimisation'
							) }
						/>
					) }
				</div>
				{ appliedSummary && Array.isArray( appliedSummary.applied ) && (
					<div className="wppo-mt-10">
						<p className="wppo-text-small">
							{ sprintf(
								/* translators: %1$d: option count, %2$s: bytes saved. */
								__(
									'Applied fix: %1$d options now save %2$s. Core options untouched.',
									'performance-optimisation'
								),
								appliedSummary.applied.length || 0,
								formatSize( appliedSummary.bytes_saved || 0 )
							) }
						</p>
					</div>
				) }
				{ report && (
					<div className="wppo-mt-10">
						<p className="wppo-text-small">
							{ sprintf(
								/* translators: %1$d: option count, %2$s: bytes saved. */
								__(
									'Dry run: %1$d options would save %2$s. Core options untouched.',
									'performance-optimisation'
								),
								report.count || 0,
								formatSize( report.bytes_saved || 0 )
							) }
						</p>
						{ report.options?.length > 0 && (
							<ul className="wppo-autoloaded-options">
								{ report.options.map( ( option ) => (
									<li key={ option.option_name }>
										<code>{ option.option_name }</code>
										<span className="wppo-text-muted">
											{ formatSize( option.size ) }
										</span>
									</li>
								) ) }
							</ul>
						) }
					</div>
				) }
				{ remediatedNames.length > 0 && (
					<div className="wppo-mt-10">
						<p className="wppo-text-small">
							{ __(
								'Remediated options (click Revert to restore):',
								'performance-optimisation'
							) }
						</p>
						<ul className="wppo-autoloaded-options">
							{ remediatedNames.map( ( name ) => (
								<li key={ name }>
									<code>{ name }</code>
									<LoadingSubmitButton
										type="button"
										className="wppo-button wppo-button--secondary wppo-button--sm"
										onClick={ () => revertOption( name ) }
										isLoading={ reverting[ name ] }
										label={ __(
											'Revert',
											'performance-optimisation'
										) }
										loadingLabel={ __(
											'Reverting…',
											'performance-optimisation'
										) }
									/>
								</li>
							) ) }
						</ul>
					</div>
				) }
			</div>
		</FeatureCard>
	);
};

export default AutoloadedOptions;
