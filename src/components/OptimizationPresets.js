/**
 * OptimizationPresets component.
 *
 * One-click Safe / Balanced / Aggressive optimization presets with a diff
 * preview before apply, plus the automatic restore-point snapshot status
 * (one-click rollback + settings JSON export).
 *
 * Every `update_settings` save already snapshots a restore point
 * server-side; this card surfaces that undo button on the Dashboard and
 * adds preset bundles on top. A failed apply leaves the prior settings
 * intact and surfaces a NoticeBanner error (fail-open, never fatal).
 * Per-page exclusions (postmeta) are untouched by presets by design.
 *
 * @since NEXT
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faSliders,
	faUndo,
	faDownload,
	faSpinner,
} from '@fortawesome/free-solid-svg-icons';
import {
	apiCall,
	fetchOptimizationPresets,
	applyOptimizationPreset,
	getErrorLogMessage,
	getWppoSettings,
} from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import NoticeBanner from './common/NoticeBanner';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';

/**
 * Preset metadata in display order.
 *
 * @type {Array.<{name:string,label:string,description:string}>}
 */
export const PRESET_ORDER = [
	{
		name: 'safe',
		label: __( 'Safe', 'performance-optimisation' ),
		description: __(
			'Page cache plus lazy-load images. Every aggressive pipeline stays off — the fresh-install baseline.',
			'performance-optimisation'
		),
	},
	{
		name: 'balanced',
		label: __( 'Balanced', 'performance-optimisation' ),
		description: __(
			'Cache plus low-risk wins: HTML/CSS minify, defer, background lazy-load, preload cache and RUM.',
			'performance-optimisation'
		),
	},
	{
		name: 'aggressive',
		label: __( 'Aggressive', 'performance-optimisation' ),
		description: __(
			'Full pipeline including JS minify, delay, combine and critical CSS. Safety guards stay forced on.',
			'performance-optimisation'
		),
	},
];

/**
 * Format a diff value for display.
 *
 * @param {*} value Raw from/to value.
 * @return {string} Display string.
 */
export const formatDiffValue = ( value ) => {
	if ( value === null || value === undefined ) {
		return '—';
	}
	if ( typeof value === 'boolean' ) {
		return value
			? __( 'On', 'performance-optimisation' )
			: __( 'Off', 'performance-optimisation' );
	}
	return String( value );
};

/**
 * Known secret-bearing settings paths stripped before a JSON export.
 *
 * Mirrors Rest::remove_sensitive_settings_from_response() so a downloaded
 * backup can be shared without leaking credentials. The live global is
 * never mutated — the export works on a deep clone.
 *
 * @since NEXT
 * @type {Array.<[string, string]>} [tab, key] pairs.
 */
export const SENSITIVE_EXPORT_PATHS = [
	[ 'performance_audit', 'pagespeed_api_key' ],
	[ 'object_cache', 'password' ],
];

/**
 * Return a deep-cloned copy of the settings with secret values removed.
 *
 * @param {Object} settings Raw settings object.
 * @return {Object} Cloned settings safe for export.
 */
export const stripSensitiveSettings = ( settings ) => {
	const clone =
		settings && typeof settings === 'object'
			? JSON.parse( JSON.stringify( settings ) )
			: {};
	for ( const [ tab, key ] of SENSITIVE_EXPORT_PATHS ) {
		if ( clone[ tab ] && typeof clone[ tab ] === 'object' ) {
			delete clone[ tab ][ key ];
		}
	}
	return clone;
};

/**
 * Export the current settings as a JSON download (restore-point backup).
 *
 * Secret values (API keys, passwords) are stripped before serializing so
 * the file is safe to share; re-importing keeps the stored secrets intact
 * server-side. Fail-open: any failure is reported through `notify`, never
 * thrown.
 *
 * @param {Function} notify useNotice notify callback.
 */
export const exportSettingsJson = ( notify ) => {
	try {
		const settings = stripSensitiveSettings(
			getWppoSettings( 'settings', {} ) || {}
		);
		const blob =
			typeof Blob !== 'undefined'
				? new Blob( [ JSON.stringify( settings, null, 2 ) ], {
						type: 'application/json',
				  } )
				: null;
		if ( ! blob || typeof URL === 'undefined' || ! URL.createObjectURL ) {
			throw new Error( 'Export is not supported in this browser.' );
		}
		const url = URL.createObjectURL( blob );
		const anchor = document.createElement( 'a' );
		anchor.href = url;
		anchor.download = 'wppo-settings.json';
		document.body.appendChild( anchor );
		anchor.click();
		anchor.remove();
		if ( URL.revokeObjectURL ) {
			URL.revokeObjectURL( url );
		}
		notify( {
			type: 'success',
			message: __(
				'Settings exported as JSON.',
				'performance-optimisation'
			),
			durationMs: 5000,
		} );
	} catch ( exportError ) {
		console.error(
			'Error exporting settings:',
			getErrorLogMessage( exportError )
		);
		notify( {
			type: 'error',
			message: __(
				'Failed to export settings.',
				'performance-optimisation'
			),
			durationMs: 5000,
		} );
	}
};

/**
 * @return {Element} The presets + restore-point card.
 */
const OptimizationPresets = () => {
	const [ selected, setSelected ] = useState( 'balanced' );
	const [ diff, setDiff ] = useState( null );
	const [ loadingDiff, setLoadingDiff ] = useState( false );
	const [ applying, setApplying ] = useState( false );
	const [ restoring, setRestoring ] = useState( false );
	const [ snapshot, setSnapshot ] = useState( {
		checked: false,
		hasSnapshot: false,
		takenAt: null,
	} );
	const { notice, notify, dismiss } = useNotice();
	// Preview request sequencing: rapid preset clicks abort the previous
	// in-flight preview and bump the sequence so a slow earlier response
	// can never overwrite the diff for the currently selected preset.
	const previewAbortRef = useRef( null );
	const previewSeqRef = useRef( 0 );

	const refreshSnapshot = useCallback( async ( signal ) => {
		try {
			const response = await apiCall(
				'settings_snapshot',
				{},
				'GET',
				signal
			);
			if ( signal?.aborted ) {
				return;
			}
			if ( response?.success && response?.data ) {
				setSnapshot( {
					checked: true,
					hasSnapshot: !! response.data.has_snapshot,
					takenAt: response.data.taken_at ?? null,
				} );
			}
		} catch {
			// Fail-open: a failed probe leaves the Undo button hidden.
		}
	}, [] );

	useEffect( () => {
		const controller =
			typeof AbortController !== 'undefined'
				? new AbortController()
				: null;
		refreshSnapshot( controller?.signal );
		return () => controller?.abort();
	}, [ refreshSnapshot ] );

	// Abort any in-flight preview on unmount so a slow response can never
	// call setState/notify after the component is gone.
	useEffect( () => {
		return () => previewAbortRef.current?.abort();
	}, [] );

	const previewPreset = useCallback(
		async ( name ) => {
			setSelected( name );
			setLoadingDiff( true );
			dismiss();
			// Abort any previous in-flight preview so only the latest
			// click can settle; the sequence guards mocked transports
			// that ignore AbortSignals.
			if ( previewAbortRef.current ) {
				previewAbortRef.current.abort();
			}
			const controller =
				typeof AbortController !== 'undefined'
					? new AbortController()
					: null;
			previewAbortRef.current = controller;
			const seq = ++previewSeqRef.current;
			const isStale = () =>
				seq !== previewSeqRef.current || controller?.signal?.aborted;
			try {
				const response = await fetchOptimizationPresets(
					name,
					controller?.signal
				);
				if ( isStale() ) {
					return;
				}
				if ( response?.success && response?.data ) {
					const payload = response.data;
					// Narrow (?preset=x) and full-list shapes both handled.
					const entry = payload.diff
						? payload
						: payload.presets?.[ name ];
					setDiff( Array.isArray( entry?.diff ) ? entry.diff : [] );
				} else {
					notify( {
						type: 'error',
						message:
							response?.message ||
							__(
								'Failed to load the preset preview.',
								'performance-optimisation'
							),
						durationMs: 5000,
					} );
				}
			} catch ( previewError ) {
				if (
					controller?.signal?.aborted ||
					previewError?.name === 'AbortError' ||
					isStale()
				) {
					return;
				}
				console.error(
					'Error loading preset preview:',
					getErrorLogMessage( previewError )
				);
				notify( {
					type: 'error',
					message: __(
						'Failed to load the preset preview.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} finally {
				if ( ! isStale() ) {
					setLoadingDiff( false );
				}
			}
		},
		[ dismiss, notify ]
	);

	const applyPreset = useCallback( async () => {
		setApplying( true );
		dismiss();
		try {
			const response = await applyOptimizationPreset( selected );
			if ( response?.success ) {
				const applied = Array.isArray( response.data?.diff )
					? response.data.diff
					: [];
				setDiff( applied );
				notify( {
					type: 'success',
					message:
						response.message ||
						__(
							'Preset applied successfully. A restore point was saved — use Undo to revert.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
				refreshSnapshot();
			} else {
				notify( {
					type: 'error',
					message:
						response?.message ||
						__(
							'Failed to apply the preset. Your settings were left unchanged.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( applyError ) {
			console.error(
				'Error applying preset:',
				getErrorLogMessage( applyError )
			);
			notify( {
				type: 'error',
				message: __(
					'Failed to apply the preset. Your settings were left unchanged.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setApplying( false );
		}
	}, [ dismiss, notify, refreshSnapshot, selected ] );

	const restoreSnapshot = useCallback( async () => {
		setRestoring( true );
		dismiss();
		try {
			const response = await apiCall( 'restore_settings', {} );
			if ( response?.success ) {
				setSnapshot( ( prev ) => ( {
					...prev,
					hasSnapshot: false,
				} ) );
				setDiff( null );
				notify( {
					type: 'success',
					message:
						response.message ||
						__(
							'Settings restored to the previous snapshot.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						response?.message ||
						__(
							'No settings snapshot available to restore.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( restoreError ) {
			console.error(
				'Error restoring settings:',
				getErrorLogMessage( restoreError )
			);
			notify( {
				type: 'error',
				message: __(
					'Error restoring settings.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			setRestoring( false );
		}
	}, [ dismiss, notify ] );

	return (
		<FeatureCard
			title={ __( 'Optimization Presets', 'performance-optimisation' ) }
			icon={ <FontAwesomeIcon icon={ faSliders } aria-hidden="true" /> }
		>
			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }
			<p className="wppo-text-muted">
				{ __(
					'One-click Safe, Balanced or Aggressive bundles with a preview of every change. Every save keeps an automatic restore point.',
					'performance-optimisation'
				) }
			</p>
			<div
				className="wppo-presets__buttons"
				role="group"
				aria-label={ __( 'Presets', 'performance-optimisation' ) }
			>
				{ PRESET_ORDER.map( ( preset ) => (
					<button
						key={ preset.name }
						type="button"
						className={ `wppo-button ${
							selected === preset.name
								? 'wppo-button--primary'
								: 'wppo-button--secondary'
						}` }
						aria-pressed={ selected === preset.name }
						onClick={ () => previewPreset( preset.name ) }
					>
						{ preset.label }
					</button>
				) ) }
			</div>
			<p className="wppo-text-muted wppo-text-small">
				{
					PRESET_ORDER.find( ( preset ) => preset.name === selected )
						?.description
				}
			</p>
			{ loadingDiff && (
				<p className="wppo-text-muted">
					<FontAwesomeIcon
						icon={ faSpinner }
						spin
						aria-hidden="true"
					/>{ ' ' }
					{ __( 'Loading preview…', 'performance-optimisation' ) }
				</p>
			) }
			{ ! loadingDiff && diff && (
				<div className="wppo-presets__diff">
					{ diff.length === 0 ? (
						<p className="wppo-text-muted">
							{ __(
								'This preset already matches your settings — nothing would change.',
								'performance-optimisation'
							) }
						</p>
					) : (
						<>
							<p>
								{ sprintf(
									// translators: %d: number of settings that would change.
									__(
										'%d setting(s) would change:',
										'performance-optimisation'
									),
									diff.length
								) }
							</p>
							<ul className="wppo-presets__diff-list">
								{ diff.map( ( entry, index ) => (
									<li
										key={ `${ entry.tab }.${ entry.key }::${ index }` }
									>
										<code>
											{ entry.tab }.{ entry.key }
										</code>{ ' ' }
										{ formatDiffValue( entry.from ) }
										{ ' → ' }
										{ formatDiffValue( entry.to ) }
									</li>
								) ) }
							</ul>
						</>
					) }
				</div>
			) }
			<div className="wppo-presets__actions">
				<LoadingSubmitButton
					type="button"
					className="wppo-button wppo-button--primary"
					onClick={ applyPreset }
					isLoading={ applying }
					disabled={ loadingDiff }
					label={ sprintf(
						// translators: %s: preset label.
						__( 'Apply %s', 'performance-optimisation' ),
						PRESET_ORDER.find(
							( preset ) => preset.name === selected
						)?.label ?? selected
					) }
					loadingLabel={ __(
						'Applying…',
						'performance-optimisation'
					) }
				/>
				{ snapshot.checked && snapshot.hasSnapshot && (
					<LoadingSubmitButton
						type="button"
						className="wppo-button wppo-button--secondary"
						onClick={ restoreSnapshot }
						isLoading={ restoring }
						label={
							<>
								<FontAwesomeIcon
									icon={ faUndo }
									aria-hidden="true"
									className="wppo-mr-8"
								/>
								{ snapshot.takenAt
									? sprintf(
											// translators: %s: snapshot timestamp.
											__(
												'Undo (saved %s)',
												'performance-optimisation'
											),
											new Date(
												snapshot.takenAt * 1000
											).toLocaleString()
									  )
									: __(
											'Undo last change',
											'performance-optimisation'
									  ) }
							</>
						}
						loadingLabel={ __(
							'Restoring…',
							'performance-optimisation'
						) }
					/>
				) }
				<button
					type="button"
					className="wppo-button wppo-button--secondary"
					onClick={ () => exportSettingsJson( notify ) }
				>
					<FontAwesomeIcon
						icon={ faDownload }
						aria-hidden="true"
						className="wppo-mr-8"
					/>
					{ __( 'Export JSON', 'performance-optimisation' ) }
				</button>
			</div>
		</FeatureCard>
	);
};

export default OptimizationPresets;
