import { useState, useRef, useEffect, useMemo } from '@wordpress/element';
import {
	apiCall,
	fetchRecentActivities,
	getErrorLogMessage,
	isValidScanUrl,
} from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import useUnsavedChanges from '../lib/useUnsavedChanges';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faFileExport,
	faFileImport,
	faCheckCircle,
	faExclamationCircle,
	faHistory,
	faTachometerAlt,
	faUndo,
} from '@fortawesome/free-solid-svg-icons';
import ConfirmDialog from './common/ConfirmDialog';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import NoticeBanner from './common/NoticeBanner';
import CheckboxOption from './common/CheckboxOption';

import { __, sprintf, _n } from '@wordpress/i18n';

// Keep in sync with PHP Util::ALLOWED_SETTINGS_KEYS (single source).
// At runtime the list is also available as wppoSettings.allowedSettingsKeys
// via wp_localize_script; this fallback ensures correctness before the
// script is localized (e.g. in Jest without wppoSettings).
const FALLBACK_ALLOWED_KEYS = [
	'file_optimisation',
	'preload_settings',
	'image_optimisation',
	'database_cleanup',
	'object_cache',
	'performance_audit',
	'cache_settings',
	'litespeed_integration',
	'llms_txt',
];
// Audit #1354: resolved lazily at call time — a module-load snapshot
// goes stale when wppoSettings is localised after import.
const getAllowedImportKeys = () => {
	if (
		typeof wppoSettings !== 'undefined' &&
		Array.isArray( wppoSettings.allowedSettingsKeys ) &&
		wppoSettings.allowedSettingsKeys.length
	) {
		return wppoSettings.allowedSettingsKeys;
	}
	return FALLBACK_ALLOWED_KEYS;
};

/**
 * Maximum accepted settings-file size (512KB). Real exports are <100KB;
 * larger files risk freezing the admin UI during parse.
 *
 * @since 2.0.0
 */
const MAX_IMPORT_BYTES = 512 * 1024;

/**
 * Maximum nesting depth accepted in an imported settings file.
 *
 * @since 2.0.0
 */
const MAX_IMPORT_DEPTH = 10;

/**
 * Maximum top-level keys accepted in an imported settings file.
 *
 * Mirrors the enforced invariant in validateImportData(): every top-level
 * key must be in ALLOWED_IMPORT_KEYS, so the cap is the allowlist length —
 * not an independent limit. Nested objects/arrays use
 * MAX_IMPORT_NESTED_KEYS instead.
 *
 * @since 2.0.0
 */
// Audit #1354 review: the cap must track the live allowlist, not the
// fallback length, or a newly-added server tab is rejected client-side.
// Kept as a constant for the static MAX check; validateImportData uses
// the live list (see below).
const MAX_IMPORT_TOP_KEYS = 64;

/**
 * Maximum keys/entries accepted in a nested object or array inside an
 * imported settings file. Real exports are small; larger values risk
 * freezing the admin UI during parse.
 *
 * @since 2.0.0
 */
const MAX_IMPORT_NESTED_KEYS = 1000;

/**
 * Key names that must never be accepted in imported settings or copied
 * during secret redaction. Assigning to `__proto__` on a plain object
 * mutates its prototype (prototype pollution); `constructor`/`prototype`
 * keys are the companion gadget path.
 *
 * @since NEXT
 * @param {string} key Raw object key.
 * @return {boolean} True when the key is a pollution vector.
 */
const isPollutionKey = ( key ) =>
	key === '__proto__' || key === 'constructor' || key === 'prototype';

const validateImportData = ( data ) => {
	if ( ! data || typeof data !== 'object' || Array.isArray( data ) ) {
		return false;
	}
	const keys = Object.keys( data );
	if ( keys.length === 0 ) {
		return false;
	}
	if ( keys.length > MAX_IMPORT_TOP_KEYS ) {
		return false;
	}
	const allowedKeys = getAllowedImportKeys();
	if ( keys.length > allowedKeys.length ) {
		return false;
	}
	return keys.every( ( key ) => {
		if (
			isPollutionKey( key ) ||
			! allowedKeys.includes( key ) ||
			typeof data[ key ] !== 'object' ||
			data[ key ] === null ||
			Array.isArray( data[ key ] )
		) {
			return false;
		}
		return isValidImportValue( data[ key ], 1 );
	} );
};

/**
 * Pattern matching nested secret keys redacted on export (Redis password,
 * Cloudflare/Bunny tokens, nonces, generic *key/*token/*secret/password).
 * The generic [_-]keys? suffix covers auth_key, consumer_key, private_key,
 * google_key, etc.; the separator requirement avoids matching words like
 * "monkey" that merely end in "key". The separator-optional api[_-]?keys?
 * alternative covers separator-less 'apikey'/'apiKey' variants.
 *
 * @since 2.0.0
 */
const SECRET_KEY_PATTERN =
	/(?:[_-]keys?|api[_-]?keys?|password|passwd|secret|api[_-]?token|auth[_-]?token|cloudflare|bunny|token|nonce)$/i;

/**
 * Deep-clone an object while masking every nested key matching
 * SECRET_KEY_PATTERN with 'REDACTED'.
 *
 * @since 2.0.0
 * @param {*} value Value to redact.
 * @return {*} Redacted clone.
 */
const redactSecrets = ( value ) => {
	if ( Array.isArray( value ) ) {
		return value.map( redactSecrets );
	}
	if ( value && typeof value === 'object' ) {
		const out = {};
		Object.entries( value ).forEach( ( [ key, val ] ) => {
			// Never copy pollution vectors: out['__proto__'] = … would
			// mutate the clone's prototype instead of creating a key.
			if ( isPollutionKey( key ) ) {
				return;
			}
			if (
				SECRET_KEY_PATTERN.test( key ) &&
				typeof val === 'string' &&
				val
			) {
				out[ key ] = 'REDACTED';
			} else {
				out[ key ] = redactSecrets( val );
			}
		} );
		return out;
	}
	return value;
};

/**
 * Recursively validate an imported settings value: only plain objects,
 * strings, numbers, booleans, null and arrays of leaves are allowed.
 * Rejects excessive depth, oversized strings and non-plain values.
 * Server-side allowlist + PHP sanitization remains authoritative.
 *
 * Arrays may contain plain objects (real exports carry
 * file_optimisation.cdnMapping as an array of { key, value } rows), so
 * items recurse with the same depth guard instead of being rejected.
 *
 * @since 2.0.0
 * @param {*}      value Value to check.
 * @param {number} depth Current depth.
 * @return {boolean} True when the value is safe to forward to the server.
 */
const isValidImportValue = ( value, depth ) => {
	if ( depth > MAX_IMPORT_DEPTH ) {
		return false;
	}
	if ( value === null ) {
		return true;
	}
	const type = typeof value;
	if ( type === 'string' ) {
		return value.length <= MAX_IMPORT_BYTES;
	}
	if ( type === 'number' || type === 'boolean' ) {
		return true;
	}
	if ( Array.isArray( value ) ) {
		if ( value.length > MAX_IMPORT_NESTED_KEYS ) {
			return false;
		}
		return value.every( ( item ) => isValidImportValue( item, depth + 1 ) );
	}
	if ( type === 'object' ) {
		const proto = Object.getPrototypeOf( value );
		if ( proto !== Object.prototype && proto !== null ) {
			return false;
		}
		const keys = Object.keys( value );
		if ( keys.length > MAX_IMPORT_NESTED_KEYS ) {
			return false;
		}
		return keys.every(
			( key ) =>
				! isPollutionKey( key ) &&
				isValidImportValue( value[ key ], depth + 1 )
		);
	}
	return false;
};

const PluginSetting = ( { options } ) => {
	const [ selectedFile, setSelectedFile ] = useState( null );
	const [ isImporting, setIsImporting ] = useState( false );
	const {
		notice: importNotice,
		notify: notifyImport,
		dismiss: dismissImport,
	} = useNotice();
	const {
		notice: undoNotice,
		notify: notifyUndo,
		dismiss: dismissUndo,
	} = useNotice();
	const [ undoAvailable, setUndoAvailable ] = useState( false );
	const [ isRestoring, setIsRestoring ] = useState( false );
	const [ confirmImport, setConfirmImport ] = useState( false );
	const fileInputRef = useRef( null );
	const cancelledRef = useRef( false );
	const readerRef = useRef( null );
	const restoreControllerRef = useRef( null );
	const saveAuditControllerRef = useRef( null );
	const settingsMountedRef = useRef( true );
	useEffect( () => {
		return () => {
			settingsMountedRef.current = false;
			if ( restoreControllerRef.current ) {
				restoreControllerRef.current.abort();
			}
			if ( saveAuditControllerRef.current ) {
				saveAuditControllerRef.current.abort();
			}
		};
	}, [] );

	useEffect( () => {
		return () => {
			cancelledRef.current = true;
			if (
				readerRef.current &&
				readerRef.current.readyState ===
					( typeof FileReader !== 'undefined'
						? FileReader.LOADING
						: 1 )
			) {
				try {
					readerRef.current.abort();
				} catch {
					// Ignore abort errors during unmount.
				}
			}
			readerRef.current = null;
		};
	}, [] );

	// One-click undo: check whether a prior-settings snapshot exists.
	// Best-effort — a failed check simply leaves the Undo button disabled.
	// Wrapped in Promise.resolve() so a mocked apiCall returning a
	// non-promise (e.g. undefined in Jest) cannot throw synchronously.
	useEffect( () => {
		let cancelled = false;
		Promise.resolve( apiCall( 'settings_snapshot', {}, 'GET' ) )
			.then( ( data ) => {
				if ( cancelled ) {
					return;
				}
				if ( data?.success && data?.data?.has_snapshot ) {
					setUndoAvailable( true );
				}
			} )
			.catch( () => {
				// Ignore: no snapshot advertised.
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	const restoreSettings = async () => {
		if ( restoreControllerRef.current ) {
			restoreControllerRef.current.abort();
		}
		restoreControllerRef.current = new AbortController();
		const signal = restoreControllerRef.current.signal;
		setIsRestoring( true );
		dismissUndo();
		try {
			const data = await apiCall(
				'restore_settings',
				{},
				'POST',
				signal
			);
			if ( signal.aborted || ! settingsMountedRef.current ) {
				return;
			}
			if ( data?.success ) {
				setUndoAvailable( false );
				notifyUndo( {
					type: 'success',
					message:
						data.message ||
						__(
							'Settings restored to the previous snapshot.',
							'performance-optimisation'
						),
				} );
			} else {
				notifyUndo( {
					type: 'error',
					message:
						data?.message ||
						__(
							'No settings snapshot available to restore.',
							'performance-optimisation'
						),
				} );
			}
		} catch ( restoreError ) {
			if ( signal.aborted || restoreError?.name === 'AbortError' ) {
				return;
			}
			if ( ! settingsMountedRef.current ) {
				return;
			}
			console.error(
				'Error restoring settings:',
				getErrorLogMessage( restoreError )
			);
			notifyUndo( {
				type: 'error',
				message: __(
					'Error restoring settings.',
					'performance-optimisation'
				),
			} );
		} finally {
			restoreControllerRef.current = null;
			if ( ! signal.aborted && settingsMountedRef.current ) {
				setIsRestoring( false );
			}
		}
	};

	// Phase 2 — PageSpeed API key state.
	// Security: use boolean flag only, do not expose the actual key to the client.
	const [ apiKeyConfigured, setApiKeyConfigured ] = useState(
		typeof wppoSettings !== 'undefined'
			? wppoSettings.performance_audit?.pagespeedApiKeyConfigured ?? false
			: false
	);

	const [ newApiKey, setNewApiKey ] = useState( '' );
	const [ savingApiKey, setSavingApiKey ] = useState( false );
	const {
		notice: apiKeyNotice,
		notify: notifyApiKey,
		dismiss: dismissApiKey,
	} = useNotice();

	// Phase 2 — auto PageSpeed re-scan frequency state.
	const [ autoRescan, setAutoRescan ] = useState(
		typeof wppoSettings !== 'undefined'
			? wppoSettings.performance_audit?.autoRescan ?? ''
			: ''
	);
	const [ savingAutoRescan, setSavingAutoRescan ] = useState( false );

	// Monitoring: Server-Timing header + high-value URLs for auto re-scan.
	const storedAudit =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.performance_audit ?? {}
			: {};
	const [ serverTimingEnabled, setServerTimingEnabled ] = useState(
		!! storedAudit.server_timing_enabled
	);
	const [ rumEnabled, setRumEnabled ] = useState(
		!! storedAudit.rum_enabled
	);
	const [ highValueUrls, setHighValueUrls ] = useState(
		Array.isArray( storedAudit.high_value_urls )
			? storedAudit.high_value_urls.join( '\n' )
			: ''
	);
	const [ savingMonitoring, setSavingMonitoring ] = useState( false );

	// Audit #1420: resync when the global arrives late. Per-key deps so
	// parent re-renders do not reset the form; saving guards so an
	// in-progress edit is never clobbered.
	const liveAudit =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.performance_audit ?? {}
			: {};
	useEffect( () => {
		if ( ! savingMonitoring && ! savingAutoRescan ) {
			setServerTimingEnabled( !! liveAudit.server_timing_enabled );
			setRumEnabled( !! liveAudit.rum_enabled );
			setHighValueUrls(
				Array.isArray( liveAudit.high_value_urls )
					? liveAudit.high_value_urls.join( '\n' )
					: ''
			);
		}
	}, [
		liveAudit.server_timing_enabled,
		liveAudit.rum_enabled,
		liveAudit.high_value_urls,
		savingMonitoring,
		savingAutoRescan,
	] );
	const [ baseline, setBaseline ] = useState( {
		newApiKey: '',
		autoRescan,
		serverTimingEnabled: !! storedAudit.server_timing_enabled,
		rumEnabled: !! storedAudit.rum_enabled,
		highValueUrls: Array.isArray( storedAudit.high_value_urls )
			? storedAudit.high_value_urls.join( '\n' )
			: '',
	} );
	const currentMonitoringSettings = useMemo(
		() => ( {
			newApiKey,
			autoRescan,
			serverTimingEnabled,
			rumEnabled,
			highValueUrls,
		} ),
		[
			newApiKey,
			autoRescan,
			serverTimingEnabled,
			rumEnabled,
			highValueUrls,
		]
	);
	useUnsavedChanges( currentMonitoringSettings, baseline );

	// Audit #1401: shared saver owning dismiss/apiCall/notify/baseline/undo
	// with a guaranteed flag reset — the three savers below keep only
	// payload building and post-save side effects.
	const savePerformanceAudit = async (
		settingsPatch,
		{
			setSaving,
			successNotice,
			errorNotice,
			catchNotice,
			logLabel,
			onSuccess,
		}
	) => {
		if ( saveAuditControllerRef.current ) {
			saveAuditControllerRef.current.abort();
		}
		saveAuditControllerRef.current = new AbortController();
		const signal = saveAuditControllerRef.current.signal;
		setSaving( true );
		dismissApiKey();
		try {
			const currentSettings =
				typeof wppoSettings !== 'undefined'
					? wppoSettings?.settings?.performance_audit ?? {}
					: {};
			const response = await apiCall(
				'update_settings',
				{
					tab: 'performance_audit',
					settings: { ...currentSettings, ...settingsPatch },
				},
				'POST',
				signal
			);
			if ( signal.aborted || ! settingsMountedRef.current ) {
				return;
			}
			if ( response.success ) {
				setUndoAvailable( true );
				if ( onSuccess ) {
					onSuccess( response );
				}
				notifyApiKey(
					typeof successNotice === 'function'
						? successNotice( response )
						: successNotice
				);
			} else {
				notifyApiKey( {
					type: 'error',
					message: response.message || errorNotice,
				} );
			}
		} catch ( err ) {
			if ( signal.aborted || err?.name === 'AbortError' ) {
				return;
			}
			if ( ! settingsMountedRef.current ) {
				return;
			}
			notifyApiKey( {
				type: 'error',
				message: catchNotice || errorNotice,
			} );
			console.error( logLabel, getErrorLogMessage( err ) );
		} finally {
			saveAuditControllerRef.current = null;
			if ( ! signal.aborted && settingsMountedRef.current ) {
				setSaving( false );
			}
		}
	};

	const saveMonitoring = async () => {
		const urls = highValueUrls
			.split( '\n' )
			.map( ( url ) => url.trim() )
			.filter( Boolean );
		// Defense-in-depth: drop non-same-origin http(s) lines client-side
		// (mirrors runPerformanceScan/queuePagespeedScan). Server-side host
		// allowlisting + rate limiting remains authoritative.
		const validUrls = urls.filter( isValidScanUrl );
		const invalidUrls = urls.filter( ( url ) => ! isValidScanUrl( url ) );
		if ( invalidUrls.length > 0 && validUrls.length === 0 ) {
			setSavingMonitoring( true );
			notifyApiKey( {
				type: 'error',
				message: sprintf(
					/* translators: %s: comma-separated list of rejected URLs */
					__(
						'No valid URLs to save. Rejected: %s. URLs must be same-origin http(s) URLs.',
						'performance-optimisation'
					),
					invalidUrls.join( ', ' )
				),
			} );
			setSavingMonitoring( false );
			return;
		}
		const savedHighValueUrls = validUrls.join( '\n' );
		await savePerformanceAudit(
			{
				server_timing_enabled: serverTimingEnabled,
				rum_enabled: rumEnabled,
				high_value_urls: validUrls,
			},
			{
				setSaving: setSavingMonitoring,
				successNotice: () =>
					invalidUrls.length > 0
						? {
								type: 'warning',
								message: sprintf(
									/* translators: 1: skipped count, 2: skipped URLs. */
									_n(
										'Monitoring settings saved. Skipped %1$s invalid URL: %2$s.',
										'Monitoring settings saved. Skipped %1$s invalid URLs: %2$s.',
										invalidUrls.length,
										'performance-optimisation'
									),
									invalidUrls.length,
									invalidUrls.join( ', ' )
								),
						  }
						: {
								type: 'success',
								message: __(
									'Monitoring settings saved.',
									'performance-optimisation'
								),
						  },
				errorNotice: __(
					'Failed to save monitoring settings.',
					'performance-optimisation'
				),
				catchNotice: __(
					'Error saving monitoring settings.',
					'performance-optimisation'
				),
				logLabel: 'Save monitoring error:',
				onSuccess: () => {
					if ( invalidUrls.length > 0 ) {
						setHighValueUrls( savedHighValueUrls );
					}
					setBaseline( ( prev ) => ( {
						...prev,
						serverTimingEnabled,
						rumEnabled,
						highValueUrls: savedHighValueUrls,
					} ) );
				},
			}
		);
	};

	const saveAutoRescan = async () => {
		await savePerformanceAudit(
			{ auto_rescan: autoRescan },
			{
				setSaving: setSavingAutoRescan,
				successNotice: {
					type: 'success',
					message: __(
						'Auto-rescan frequency saved.',
						'performance-optimisation'
					),
				},
				errorNotice: __(
					'Failed to save auto-rescan frequency.',
					'performance-optimisation'
				),
				catchNotice: __(
					'Error saving auto-rescan frequency.',
					'performance-optimisation'
				),
				logLabel: 'Save auto-rescan error:',
				onSuccess: () => {
					setBaseline( ( prev ) => ( { ...prev, autoRescan } ) );
				},
			}
		);
	};

	const saveApiKey = async () => {
		const trimmedKey = newApiKey.trim();
		await savePerformanceAudit(
			{ ...( trimmedKey ? { pagespeed_api_key: trimmedKey } : {} ) },
			{
				setSaving: setSavingApiKey,
				successNotice: {
					type: 'success',
					message: __( 'API key saved.', 'performance-optimisation' ),
				},
				errorNotice: __(
					'Failed to save API key.',
					'performance-optimisation'
				),
				catchNotice: __(
					'Error saving API key.',
					'performance-optimisation'
				),
				logLabel: 'Save API key error:',
				onSuccess: () => {
					setNewApiKey( '' );
					setApiKeyConfigured( true );
					setBaseline( ( prev ) => ( { ...prev, newApiKey: '' } ) );
				},
			}
		);
	};

	// Activity log state
	const [ logEntries, setLogEntries ] = useState( [] );
	const [ logLoading, setLogLoading ] = useState( false );
	const [ logLoaded, setLogLoaded ] = useState( false );
	const [ logPage, setLogPage ] = useState( 1 );
	const [ logTotalPages, setLogTotalPages ] = useState( 1 );
	const {
		notice: logNotice,
		notify: notifyLog,
		dismiss: dismissLog,
	} = useNotice();

	const getTimestamp = () => {
		return new Date()
			.toISOString()
			.replace( /[:T]/g, '-' )
			.split( '.' )[ 0 ];
	};

	// Audit #1420: abortable log fetch — unmount/rapid pagination cannot
	// set state on an unmounted component.
	const logControllerRef = useRef( null );
	useEffect( () => {
		return () => {
			if ( logControllerRef.current ) {
				logControllerRef.current.abort();
			}
		};
	}, [] );

	const loadActivityLog = async ( page = 1 ) => {
		if ( logControllerRef.current ) {
			logControllerRef.current.abort();
		}
		logControllerRef.current = new AbortController();
		const signal = logControllerRef.current.signal;
		setLogLoading( true );
		dismissLog();
		try {
			const data = await fetchRecentActivities( page, signal );
			if ( signal.aborted ) {
				return;
			}
			if ( data?.activities ) {
				setLogEntries( data.activities );
				setLogPage( data.current_page || 1 );
				setLogTotalPages( data.total_pages || 1 );
				setLogLoaded( true );
			}
		} catch ( err ) {
			// Audit #1420: aborts are expected (unmount/pagination), not errors.
			if ( signal.aborted || err?.name === 'AbortError' ) {
				return;
			}
			notifyLog( {
				type: 'error',
				message: __(
					'Failed to load activity log.',
					'performance-optimisation'
				),
			} );
			console.error(
				'Failed to load activity log:',
				getErrorLogMessage( err )
			);
		} finally {
			if ( ! signal.aborted ) {
				setLogLoading( false );
			}
		}
	};

	const exportSettings = () => {
		// Security: redact all nested secrets (API keys, passwords, tokens)
		// before writing the export to disk. Server-side sanitization is
		// authoritative; this is defense-in-depth for the plaintext file.
		let safeOptions;
		try {
			safeOptions = redactSecrets(
				JSON.parse( JSON.stringify( options ) )
			);
		} catch ( exportError ) {
			console.error(
				'Failed to export settings:',
				getErrorLogMessage( exportError )
			);
			notifyImport( {
				type: 'error',
				message: __(
					'Failed to export settings.',
					'performance-optimisation'
				),
			} );
			return;
		}

		const blob = new Blob( [ JSON.stringify( safeOptions, null, 2 ) ], {
			type: 'application/json',
		} );
		const link = document.createElement( 'a' );
		link.href = URL.createObjectURL( blob );
		link.download = `plugin-settings_${ getTimestamp() }.json`;
		document.body.appendChild( link );
		link.click();
		link.remove();
		// Defer revocation so the download can start before the object URL is
		// released (Firefox can abort the save otherwise).
		setTimeout( () => URL.revokeObjectURL( link.href ), 0 );
	};

	const handleFileSelection = ( event ) => {
		const file = event.target.files[ 0 ];
		setSelectedFile( file || null );
		dismissImport();
	};

	const resetFileInput = () => {
		setSelectedFile( null );
		if ( fileInputRef.current ) {
			fileInputRef.current.value = '';
		}
	};

	const importSettings = () => {
		if ( ! selectedFile ) {
			notifyImport( {
				type: 'error',
				message: __(
					'Please select a file first.',
					'performance-optimisation'
				),
			} );
			return;
		}

		setIsImporting( true );

		// Client-side size guard: reject oversized files before reading so a
		// large/malicious file cannot freeze the admin UI. Server-side
		// allowlist + sanitization remains authoritative.
		if ( selectedFile.size && selectedFile.size > MAX_IMPORT_BYTES ) {
			notifyImport( {
				type: 'error',
				message: __(
					'Invalid settings file. The file must contain valid plugin settings.',
					'performance-optimisation'
				),
			} );
			setIsImporting( false );
			resetFileInput();
			return;
		}

		const reader = new FileReader();
		readerRef.current = reader;

		// Audit #1401: one shared failure handler — byte-identical twins
		// drift (e.g. only one clears the failed selection).
		const handleReaderFailure = () => {
			readerRef.current = null;
			if ( cancelledRef.current ) {
				return;
			}
			notifyImport( {
				type: 'error',
				message: __( 'Error reading file', 'performance-optimisation' ),
			} );
			setIsImporting( false );
			resetFileInput();
		};
		reader.onerror = handleReaderFailure;
		reader.onabort = handleReaderFailure;

		reader.onload = ( e ) => {
			readerRef.current = null;
			if ( cancelledRef.current ) {
				return;
			}
			try {
				const fileData = JSON.parse( e.target.result );

				if ( ! validateImportData( fileData ) ) {
					notifyImport( {
						type: 'error',
						message: __(
							'Invalid settings file. The file must contain valid plugin settings.',
							'performance-optimisation'
						),
					} );
					setIsImporting( false );
					resetFileInput();
					return;
				}

				apiCall( 'import_settings', {
					action: 'import_settings',
					settings: fileData,
				} )
					.then( ( data ) => {
						if ( cancelledRef.current ) {
							return;
						}
						notifyImport( {
							type: data.success ? 'success' : 'error',
							message:
								data.message ||
								( data.success
									? __(
											'File imported successfully',
											'performance-optimisation'
									  )
									: __(
											'Import failed',
											'performance-optimisation'
									  ) ),
						} );
						if ( data.success ) {
							resetFileInput();
							setUndoAvailable( true );
						}
					} )
					.catch( () => {
						if ( cancelledRef.current ) {
							return;
						}
						notifyImport( {
							type: 'error',
							message: __(
								'Error reading file',
								'performance-optimisation'
							),
						} );
					} )
					.finally( () => {
						if ( ! cancelledRef.current ) {
							setIsImporting( false );
						}
					} );
			} catch {
				if ( cancelledRef.current ) {
					return;
				}
				notifyImport( {
					type: 'error',
					message: __(
						'Invalid file format. Please select a valid JSON file.',
						'performance-optimisation'
					),
				} );
				// Audit #1354: clear the invalid selection like every other
				// failure path so a retry starts clean.
				resetFileInput();
				setIsImporting( false );
			}
		};
		reader.readAsText( selectedFile );
	};

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Tools', 'performance-optimisation' ) }
				description={ __(
					'Manage your plugin configuration, view the full optimisation activity log, and import or export settings.',
					'performance-optimisation'
				) }
			/>

			{ importNotice && (
				<NoticeBanner
					type={ importNotice.type }
					message={ importNotice.message }
					onDismiss={ dismissImport }
				/>
			) }

			<div className="wppo-stacked-cards">
				{ /* Activity Log */ }
				<FeatureCard
					title={ __(
						'Optimisation Activity Log',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faHistory } /> }
					footer={
						logLoaded && logTotalPages > 1 ? (
							<div className="wppo-log-pagination">
								<button
									type="button"
									className="wppo-button wppo-button--secondary wppo-button--sm"
									disabled={ logPage <= 1 || logLoading }
									onClick={ () =>
										loadActivityLog( logPage - 1 )
									}
								>
									<span aria-hidden="true">{ '← ' }</span>
									{ __(
										'Previous',
										'performance-optimisation'
									) }
								</button>
								<span className="wppo-log-pagination__info">
									{ sprintf(
										/* translators: 1: current page number, 2: total number of pages */
										__(
											'Page %1$s of %2$s',
											'performance-optimisation'
										),
										logPage,
										logTotalPages
									) }
								</span>
								<button
									type="button"
									className="wppo-button wppo-button--secondary wppo-button--sm"
									disabled={
										logPage >= logTotalPages || logLoading
									}
									onClick={ () =>
										loadActivityLog( logPage + 1 )
									}
								>
									{ __( 'Next', 'performance-optimisation' ) }
									<span aria-hidden="true">{ ' →' }</span>
								</button>
							</div>
						) : null
					}
				>
					{ ! logLoaded && (
						<div className="wppo-log-trigger">
							<p
								id="wppo-activity-log-desc"
								className="wppo-text-muted"
							>
								{ __(
									'A full timestamped record of every cache clear, image optimisation, database cleanup, and settings change performed by the plugin.',
									'performance-optimisation'
								) }
							</p>
							<LoadingSubmitButton
								type="button"
								className="wppo-button wppo-button--secondary"
								onClick={ () => loadActivityLog( 1 ) }
								aria-describedby="wppo-activity-log-desc"
								isLoading={ logLoading }
								loadingLabel={ __(
									'Loading log…',
									'performance-optimisation'
								) }
							>
								<FontAwesomeIcon icon={ faHistory } />
								{ __(
									'Load Activity Log',
									'performance-optimisation'
								) }
							</LoadingSubmitButton>
						</div>
					) }

					{ logNotice && (
						<>
							<NoticeBanner
								type={ logNotice.type }
								message={ logNotice.message }
								onDismiss={ dismissLog }
							/>
							<button
								type="button"
								className="wppo-button wppo-button--secondary wppo-button--sm wppo-mt-8"
								onClick={ () => loadActivityLog( logPage ) }
							>
								{ __( 'Retry', 'performance-optimisation' ) }
							</button>
						</>
					) }

					{ logLoaded && (
						<>
							{ logEntries.length > 0 ? (
								<ul className="wppo-activity-list wppo-activity-list--full">
									{ logEntries.map( ( entry ) => (
										<li key={ entry.id }>
											<div className="wppo-activity-text">
												{ entry.activity }
											</div>
											{ entry.created_at && (
												<time
													className="wppo-activity-time"
													dateTime={
														entry.created_at
													}
												>
													{ new Date(
														entry.created_at.replace(
															' ',
															'T'
														)
													).toLocaleString() }
												</time>
											) }
										</li>
									) ) }
								</ul>
							) : (
								<div className="wppo-empty-state">
									{ __(
										'No activity recorded yet.',
										'performance-optimisation'
									) }
								</div>
							) }
						</>
					) }
				</FeatureCard>

				{ /* Phase 2 — PageSpeed API Key (v1.6.0) */ }
				<FeatureCard
					title={ __(
						'Google PageSpeed API Key',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faTachometerAlt } /> }
				>
					<p
						id="pagespeed-api-key-desc"
						className="wppo-text-muted wppo-mb-16"
					>
						{ __(
							'Required to run PageSpeed Insights scans. Get a free key from',
							'performance-optimisation'
						) }{ ' ' }
						<a
							href="https://developers.google.com/speed/docs/insights/v5/get-started#APIKey"
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __(
								'Google Cloud Console',
								'performance-optimisation'
							) }
						</a>
						{ '. ' }
						{ __(
							'See Google docs for quota and billing details.',
							'performance-optimisation'
						) }
					</p>

					<div
						className={ `wppo-notice wppo-notice--${
							apiKeyConfigured ? 'success' : 'warning'
						} wppo-mb-16` }
						role="status"
						aria-live="polite"
					>
						<FontAwesomeIcon
							icon={
								apiKeyConfigured
									? faCheckCircle
									: faExclamationCircle
							}
							className="wppo-mr-8"
							aria-hidden="true"
						/>
						{ apiKeyConfigured
							? __(
									'API key is configured.',
									'performance-optimisation'
							  )
							: __(
									'API key is not configured.',
									'performance-optimisation'
							  ) }
					</div>

					{ apiKeyNotice && (
						<NoticeBanner
							type={ apiKeyNotice.type }
							message={ apiKeyNotice.message }
							className="wppo-mb-16"
							onDismiss={ dismissApiKey }
						/>
					) }

					<div className="wppo-field">
						<label
							className="wppo-field-label"
							htmlFor="pagespeed-api-key"
						>
							{ __( 'New API Key', 'performance-optimisation' ) }
						</label>
						<input
							type="password"
							id="pagespeed-api-key"
							className="wppo-input"
							value={ newApiKey }
							onChange={ ( e ) => setNewApiKey( e.target.value ) }
							placeholder={
								apiKeyConfigured
									? __(
											'Leave empty to keep current key',
											'performance-optimisation'
									  )
									: __( 'AIza…', 'performance-optimisation' )
							}
							autoComplete="off"
							aria-describedby="pagespeed-api-key-desc"
						/>
					</div>

					<LoadingSubmitButton
						className="wppo-button wppo-button--primary wppo-mt-16"
						onClick={ saveApiKey }
						isLoading={ savingApiKey }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					/>

					<hr className="wppo-divider wppo-my-20" />

					<div className="wppo-field">
						<label
							className="wppo-field-label"
							htmlFor="auto-rescan-frequency"
						>
							{ __(
								'Auto PageSpeed Re-scan',
								'performance-optimisation'
							) }
						</label>
						<p
							id="auto-rescan-desc"
							className="wppo-text-muted wppo-text-small wppo-mb-8"
						>
							{ __(
								'Automatically re-run PageSpeed scans on your homepage and high-value URLs to build Web Vitals trend history. Requires a configured API key.',
								'performance-optimisation'
							) }
						</p>
						<select
							id="auto-rescan-frequency"
							className="wppo-select"
							value={ autoRescan }
							onChange={ ( e ) =>
								setAutoRescan( e.target.value )
							}
							disabled={ ! apiKeyConfigured }
							aria-describedby="auto-rescan-desc"
						>
							<option value="">
								{ __( 'Disabled', 'performance-optimisation' ) }
							</option>
							<option value="daily">
								{ __( 'Daily', 'performance-optimisation' ) }
							</option>
							<option value="weekly">
								{ __( 'Weekly', 'performance-optimisation' ) }
							</option>
						</select>
					</div>

					<LoadingSubmitButton
						className="wppo-button wppo-button--secondary wppo-mt-16"
						onClick={ saveAutoRescan }
						isLoading={ savingAutoRescan }
						disabled={ ! apiKeyConfigured }
						label={ __(
							'Save Auto-rescan',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					/>
				</FeatureCard>

				{ /* Monitoring: Server-Timing + high-value URLs */ }
				<FeatureCard
					title={ __( 'Monitoring', 'performance-optimisation' ) }
					icon={
						<i
							className="fas fa-tachometer-alt"
							aria-hidden="true"
						></i>
					} // Audit #1420: decorative.
				>
					<CheckboxOption
						checked={ serverTimingEnabled }
						onChange={ ( e ) =>
							setServerTimingEnabled( e.target.checked )
						}
						label={ __(
							'Enable Server-Timing Header',
							'performance-optimisation'
						) }
						description={ __(
							'Emit a Server-Timing header with template and database timings on front-end responses (WP 6.9+).',
							'performance-optimisation'
						) }
						className="wppo-checkbox-option--spaced"
					/>
					<CheckboxOption
						checked={ rumEnabled }
						onChange={ ( e ) => setRumEnabled( e.target.checked ) }
						label={ __(
							'Collect Real-user Web Vitals',
							'performance-optimisation'
						) }
						description={ __(
							'Measure LCP, CLS, INP, FCP and TTFB from real visitors on the front end. Aggregated anonymously by day and page.',
							'performance-optimisation'
						) }
						className="wppo-checkbox-option--spaced"
					/>
					<label
						htmlFor="wppo-high-value-urls"
						className="wppo-field-label"
					>
						{ __( 'High-value URLs', 'performance-optimisation' ) }
					</label>
					<textarea
						id="wppo-high-value-urls"
						className="wppo-textarea wppo-textarea--mono"
						rows={ 4 }
						value={ highValueUrls }
						onChange={ ( e ) => setHighValueUrls( e.target.value ) }
						placeholder={ [
							'https://example.com/about/',
							'https://example.com/contact/',
						].join( '\n' ) }
						aria-describedby="wppo-high-value-urls-desc"
					/>
					<p className="wppo-text-muted wppo-text-small">
						{ __(
							'Plain text, one per line. URLs must be on the same origin.',
							'performance-optimisation'
						) }
					</p>
					<p
						id="wppo-high-value-urls-desc"
						className="wppo-text-muted wppo-text-small"
					>
						{ __(
							'One URL per line. These pages are scanned by the auto PageSpeed re-scan alongside your homepage.',
							'performance-optimisation'
						) }
					</p>
					<LoadingSubmitButton
						className="wppo-button wppo-button--secondary wppo-mt-16"
						onClick={ saveMonitoring }
						isLoading={ savingMonitoring }
						label={ __(
							'Save Monitoring',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Saving…',
							'performance-optimisation'
						) }
					/>
				</FeatureCard>

				{ /* One-click undo — restores the prior settings snapshot. */ }
				<FeatureCard
					title={ __(
						'Undo Last Settings Change',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faUndo } /> }
				>
					<p className="wppo-text-muted wppo-mb-16">
						{ __(
							'Restores your settings to how they were before the last save or import. Useful if a recent change broke your site.',
							'performance-optimisation'
						) }
					</p>
					{ undoNotice && (
						<NoticeBanner
							type={ undoNotice.type }
							message={ undoNotice.message }
							className="wppo-mb-16"
							onDismiss={ dismissUndo }
						/>
					) }
					<LoadingSubmitButton
						className="wppo-button wppo-button--secondary"
						onClick={ restoreSettings }
						isLoading={ isRestoring }
						disabled={ ! undoAvailable }
						label={ __(
							'Undo Last Change',
							'performance-optimisation'
						) }
						loadingLabel={ __(
							'Restoring…',
							'performance-optimisation'
						) }
					/>
					{ ! undoAvailable && ! undoNotice && (
						<p className="wppo-text-muted wppo-text-small wppo-mt-10">
							{ __(
								'No snapshot available yet. A snapshot is saved automatically before each settings change.',
								'performance-optimisation'
							) }
						</p>
					) }
				</FeatureCard>

				<div className="wppo-grid-2-col">
					{ /* Export */ }
					<FeatureCard
						title={ __(
							'Export Configuration',
							'performance-optimisation'
						) }
						icon={ <FontAwesomeIcon icon={ faFileExport } /> }
					>
						<p className="wppo-text-muted wppo-mb-16">
							{ __(
								'Download your current plugin settings as a JSON file for backup or migration to another site.',
								'performance-optimisation'
							) }
						</p>
						<p className="wppo-text-muted wppo-text-small wppo-mb-16">
							{ __(
								'Sensitive keys are redacted automatically. File is formatted JSON.',
								'performance-optimisation'
							) }
						</p>
						<LoadingSubmitButton
							className="wppo-button wppo-button--primary"
							onClick={ exportSettings }
							label={ __(
								'Download JSON',
								'performance-optimisation'
							) }
						/>
					</FeatureCard>

					{ /* Import — danger zone — uses .wppo-danger-zone tokens (D-17). */ }
					<div className="wppo-danger-zone">
						<FeatureCard
							title={ __(
								'Import Configuration',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faFileImport } /> }
						>
							<p
								className="wppo-text-muted"
								id="import-config-desc"
							>
								{ __(
									'Upload a previously exported settings file to restore your configuration. This will overwrite all current settings.',
									'performance-optimisation'
								) }
							</p>
							<div className="wppo-field wppo-mt-24">
								<label
									className="wppo-field-label"
									htmlFor="import-config"
								>
									{ __(
										'Select configuration file',
										'performance-optimisation'
									) }
								</label>
								<input
									type="file"
									id="import-config"
									// Audit #1354: match by extension as well — some
									// browsers filter file pickers by extension.
									accept="application/json,.json"
									onChange={ handleFileSelection }
									ref={ fileInputRef }
									className="wppo-input"
									aria-describedby="import-config-desc"
								/>
								<p className="wppo-text-muted wppo-text-small wppo-mt-10">
									{ __(
										'Only .json files exported from this plugin are accepted.',
										'performance-optimisation'
									) }
								</p>
							</div>
							<LoadingSubmitButton
								className="wppo-button wppo-button--secondary wppo-mt-24"
								onClick={ () => {
									if ( selectedFile ) {
										setConfirmImport( true );
									}
								} }
								disabled={ ! selectedFile || isImporting }
								isLoading={ isImporting }
								label={ __(
									'Import Settings',
									'performance-optimisation'
								) }
								loadingLabel={ __(
									'Importing…',
									'performance-optimisation'
								) }
							/>
						</FeatureCard>
					</div>
				</div>
			</div>

			<ConfirmDialog
				isOpen={ confirmImport }
				onConfirm={ () => {
					setConfirmImport( false );
					importSettings();
				} }
				onCancel={ () => setConfirmImport( false ) }
				title={ __( 'Confirm Import', 'performance-optimisation' ) }
				message={ __(
					'Importing this file will overwrite all current plugin settings. This cannot be undone. Continue?',
					'performance-optimisation'
				) }
				confirmLabel={ __( 'Confirm', 'performance-optimisation' ) }
				variant="danger"
			/>
		</div>
	);
};

export {
	validateImportData,
	redactSecrets,
	isValidImportValue,
	isPollutionKey,
	MAX_IMPORT_BYTES,
	MAX_IMPORT_DEPTH,
	MAX_IMPORT_TOP_KEYS,
	MAX_IMPORT_NESTED_KEYS,
	SECRET_KEY_PATTERN,
};

export default PluginSetting;
