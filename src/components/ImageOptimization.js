import {
	useState,
	useEffect,
	useContext,
	useMemo,
	useRef,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { handleChange } from '../lib/util';
import { apiCall, getErrorLogMessage } from '../lib/apiRequest';
import useNotice from '../lib/useNotice';
import useUnsavedChanges from '../lib/useUnsavedChanges';
import UnsavedChangesContext from '../lib/UnsavedChangesContext';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faEye,
	faMagic,
	faCloudUploadAlt,
} from '@fortawesome/free-solid-svg-icons';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import NoticeBanner from './common/NoticeBanner';

const CLIENT_SIDE_MIME_OPTIONS = [
	{ value: 'image/jpeg', label: 'JPEG' },
	{ value: 'image/png', label: 'PNG' },
	{ value: 'image/gif', label: 'GIF' },
	{ value: 'image/webp', label: 'WebP' },
	{ value: 'image/avif', label: 'AVIF' },
	{ value: 'image/heic', label: 'HEIC' },
	{ value: 'image/heif', label: 'HEIF' },
	{ value: 'image/heic-sequence', label: 'HEIC Sequence' },
	{ value: 'image/jxl', label: 'JPEG XL' },
];

const DEFAULT_CLIENT_SIDE_MIME_TYPES = [
	'image/jpeg',
	'image/png',
	'image/gif',
	'image/webp',
	'image/avif',
];

/**
 * Human-readable labels for LCP candidate sources. Unknown future sources
 * fall back to the raw token at the render site.
 */
const lcpSourceLabels = {
	manual: __( 'Manual pin', 'performance-optimisation' ),
	rum: __( 'RUM field data', 'performance-optimisation' ),
	od: __( 'Optimization Detective', 'performance-optimisation' ),
	pagespeed: __( 'PageSpeed', 'performance-optimisation' ),
};

/**
 * Coerce the longest-edge cap to a non-negative integer.
 *
 * Mirrors the PHP sanitizer (2560 default, 0 disables): negatives,
 * fractions, '' or non-numeric payloads never stay in state.
 *
 * @since 2.0.0
 * @param {*}      value    Raw option value.
 * @param {number} fallback Fallback when unparseable.
 * @return {number} Coerced integer >= 0.
 */
const coerceLongestEdge = ( value, fallback ) => {
	if (
		Array.isArray( value ) ||
		typeof value === 'boolean' ||
		value === null ||
		value === undefined
	) {
		return fallback;
	}
	// Mirror PHP is_numeric(): whitespace-only strings are not numeric, and
	// Number('   ') would otherwise coerce to 0 instead of the 2560 fallback.
	if ( typeof value === 'string' && value.trim() === '' ) {
		return fallback;
	}
	// PHP is_numeric() rejects hex/binary/octal literals while Number()
	// parses them (Number('0x100') === 256), so guard explicitly to keep
	// UI/server parity (see normalizeRetries in FileOptimization).
	if ( typeof value === 'string' && /^0[xXoObB]/.test( value.trim() ) ) {
		return fallback;
	}
	const num = Number( value );
	if ( ! Number.isFinite( num ) ) {
		return fallback;
	}
	return Math.max( 0, Math.trunc( num ) );
};

// Audit #1401: single placeholderType derivation (was copy-pasted in
// init + baseline-sync; a new value needs one edit now).
// @since 2.3.0
const derivePlaceholderType = ( opts ) =>
	opts.placeholderType ?? ( opts.replacePlaceholderWithSVG ? 'svg' : 'none' );

const ImageOptimization = ( { options = {} } ) => {
	const defaultSettings = {
		lazyLoadImages: false,
		lazyLoadNative: true,
		lazyLoadBackgroundImages: false,
		wrapInPicture: true,
		excludeFirstImages: 0,
		excludeImages: '',

		lazyLoadVideos: false,
		enableVideoPlaceholder: false,
		excludeVideos: '',
		convertImg: false,
		conversionFormat: 'webp',
		excludeConvertImages: '',
		preloadFrontPageImages: false,
		preloadFrontPageImagesUrls: '',
		preloadPostTypeImage: false,
		selectedPostType: [],
		availablePostTypes: [],
		excludePostTypeImgUrl: '',
		maxWidthImgSize: 0,
		excludeSize: '',
		autoPreloadLCP: false,
		prioritizeLCPImages: false,
		lcp_guardrails: true,
		lcp_first_n: 3,
		autoAltText: false,
		maxLongestEdgePx: 2560,
		lazyRenderBelowFold: false,
		lazyRenderExcludeBuilders: true,
		clientSideMimeTypeOverride: false,
		clientSideMimeTypes: DEFAULT_CLIENT_SIDE_MIME_TYPES,
		forceServerSideConversion: false,
		discardOversizedSibling: true,
		...options,
		placeholderType:
			options.placeholderType ??
			( options.replacePlaceholderWithSVG ? 'svg' : 'none' ),
	};

	// Coerce AFTER the spread so a corrupt non-numeric payload cannot flow
	// into state via the ...options override above (PHP sanitizes to 2560/0).
	defaultSettings.maxLongestEdgePx = coerceLongestEdge(
		options.maxLongestEdgePx,
		2560
	);

	const [ settings, setSettings ] = useState( defaultSettings );

	useEffect( () => {
		if ( ! options || Object.keys( options ).length === 0 ) {
			return;
		}
		setSettings( ( prev ) => ( {
			...prev,
			...options,
			maxLongestEdgePx: coerceLongestEdge(
				options.maxLongestEdgePx,
				prev.maxLongestEdgePx ?? 2560
			),
			placeholderType: derivePlaceholderType( options ),
			clientSideMimeTypes: Array.isArray( options.clientSideMimeTypes )
				? options.clientSideMimeTypes
				: prev.clientSideMimeTypes,
			selectedPostType: Array.isArray( options.selectedPostType )
				? options.selectedPostType
				: prev.selectedPostType,
			availablePostTypes: Array.isArray( options.availablePostTypes )
				? options.availablePostTypes
				: prev.availablePostTypes,
		} ) );
		// Baseline is intentionally derived per-key (not per-object-identity)
		// so parent re-renders with an identical payload do not reset the form.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		options.lazyLoadImages,
		options.lazyLoadNative,
		options.lazyLoadBackgroundImages,
		options.wrapInPicture,
		options.excludeFirstImages,
		options.excludeImages,
		options.lazyLoadVideos,
		options.enableVideoPlaceholder,
		options.excludeVideos,
		options.convertImg,
		options.conversionFormat,
		options.excludeConvertImages,
		options.preloadFrontPageImages,
		options.preloadFrontPageImagesUrls,
		options.preloadPostTypeImage,
		options.selectedPostType,
		options.availablePostTypes,
		options.excludePostTypeImgUrl,
		options.maxWidthImgSize,
		options.excludeSize,
		options.autoPreloadLCP,
		options.prioritizeLCPImages,
		options.lcp_guardrails,
		options.lcp_first_n,
		options.autoAltText,
		options.maxLongestEdgePx,
		options.lazyRenderBelowFold,
		options.lazyRenderExcludeBuilders,
		options.clientSideMimeTypeOverride,
		options.clientSideMimeTypes,
		options.forceServerSideConversion,
		options.discardOversizedSibling,
		options.placeholderType,
		options.replacePlaceholderWithSVG,
	] );

	const [ isLoading, setIsLoading ] = useState( false );
	// Audit #1420: single memoized change handler instead of a new
	// closure per input per render (mirrors FileOptimization).
	const onFieldChange = useMemo(
		() => handleChange( setSettings ),
		[ setSettings ]
	);
	const { notice, notify, dismiss } = useNotice();
	const { setIsDirty } = useContext( UnsavedChangesContext );
	const [ baseline, setBaseline ] = useState( defaultSettings );
	// LCP preload candidate surfaced from RUM / Optimization Detective field
	// data via the lcp_preload_candidate REST route. Null means no measured
	// candidate yet — the manual per-post LCP URL picker remains the fallback.
	const [ lcpCandidate, setLcpCandidate ] = useState( null );
	const [ lcpCandidateSource, setLcpCandidateSource ] = useState( '' );
	const [ isCandidateLoading, setIsCandidateLoading ] = useState( false );
	const [ isApplyingLcp, setIsApplyingLcp ] = useState( false );

	// Mount/abort guards for the two `update_settings` save paths below.
	// ObjectCache.js and PreloadSettings.js already do this (audit #1420);
	// ImageOptimization was the one settings tab that did not, so a save that
	// resolved after unmount still called setState and fired a notice banner.
	const isMountedRef = useRef( true );
	const lcpSaveControllerRef = useRef( null );
	const submitControllerRef = useRef( null );
	useEffect( () => {
		isMountedRef.current = true;
		return () => {
			isMountedRef.current = false;
			if ( lcpSaveControllerRef.current ) {
				lcpSaveControllerRef.current.abort();
			}
			if ( submitControllerRef.current ) {
				submitControllerRef.current.abort();
			}
		};
	}, [] );

	useEffect( () => {
		let cancelled = false;
		// Audit #1354: cancel the request on unmount, not just ignore it.
		const controller = new AbortController();
		setIsCandidateLoading( true );
		// Promise.resolve() so a mocked apiCall resolving to undefined
		// (and any sync throw) still lands in the fail-open path.
		// Default to the front page ('/'): without an explicit path the
		// server resolves the admin REST context (REQUEST_URI of the
		// wp-json route) and always misses, so no candidate would surface.
		Promise.resolve()
			.then( () =>
				apiCall(
					'lcp_preload_candidate?path=' + encodeURIComponent( '/' ),
					{},
					'GET',
					controller.signal
				)
			)
			.then( ( res ) => {
				if ( cancelled ) {
					return;
				}
				if (
					res &&
					res.success &&
					res.data &&
					res.data.candidate &&
					res.data.candidate.url
				) {
					setLcpCandidate( res.data.candidate );
					setLcpCandidateSource( res.data.source || '' );
				}
			} )
			.catch( () => {
				// Fail-open: no candidate is not an error state; the manual
				// per-post picker path still works.
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setIsCandidateLoading( false );
				}
			} );
		return () => {
			cancelled = true;
			controller.abort();
		};
	}, [] );

	const applyLcpCandidate = async () => {
		if ( ! lcpCandidate || ! lcpCandidate.url ) {
			return;
		}
		// Audit #1420: guard rapid clicks like PreloadSettings handleResume.
		if ( isApplyingLcp ) {
			return;
		}
		setIsApplyingLcp( true );
		const controller = new AbortController();
		lcpSaveControllerRef.current = controller;
		try {
			const next = {
				...settings,
				autoPreloadLCP: true,
				prioritizeLCPImages: true,
			};
			const res = await apiCall(
				'update_settings',
				{
					tab: 'image_optimisation',
					settings: next,
				},
				'POST',
				controller.signal
			);
			if ( controller.signal.aborted || ! isMountedRef.current ) {
				return;
			}
			if ( res && res.success ) {
				// Merge functionally so edits typed during the request
				// are not clobbered by the pre-await snapshot.
				setSettings( ( prev ) => ( {
					...prev,
					autoPreloadLCP: true,
					prioritizeLCPImages: true,
				} ) );
				setBaseline( ( prev ) => ( {
					...prev,
					autoPreloadLCP: true,
					prioritizeLCPImages: true,
				} ) );
				setIsDirty( false );
				notify( {
					type: 'success',
					message: __(
						'LCP auto-preload and prioritization enabled: measured heroes will preload with fetchpriority high and skip lazy loading.',
						'performance-optimisation'
					),
					durationMs: 5000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						( res && res.message ) ||
						__(
							'Could not apply the LCP preload.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			// An abort on unmount is not a user-facing failure.
			if ( controller.signal.aborted || ! isMountedRef.current ) {
				return;
			}
			// Audit #1354: translated string to users; raw error to console.
			console.error(
				'Could not apply the LCP preload.',
				getErrorLogMessage( error )
			);
			notify( {
				type: 'error',
				message: __(
					'Could not apply the LCP preload.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			if ( isMountedRef.current ) {
				setIsApplyingLcp( false );
			}
		}
	};
	useEffect( () => {
		setBaseline( {
			...defaultSettings,
			...options,
			maxLongestEdgePx: coerceLongestEdge(
				options.maxLongestEdgePx,
				defaultSettings.maxLongestEdgePx ?? 2560
			),
			placeholderType: derivePlaceholderType( options ),
			clientSideMimeTypes: Array.isArray( options.clientSideMimeTypes )
				? options.clientSideMimeTypes
				: defaultSettings.clientSideMimeTypes,
			selectedPostType: Array.isArray( options.selectedPostType )
				? options.selectedPostType
				: defaultSettings.selectedPostType,
			availablePostTypes: Array.isArray( options.availablePostTypes )
				? options.availablePostTypes
				: defaultSettings.availablePostTypes,
		} );
		// Baseline is intentionally derived per-key (not per-object-identity)
		// so parent re-renders with an identical payload do not reset the form.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		options.lazyLoadImages,
		options.lazyLoadNative,
		options.lazyLoadBackgroundImages,
		options.wrapInPicture,
		options.excludeFirstImages,
		options.excludeImages,
		options.lazyLoadVideos,
		options.enableVideoPlaceholder,
		options.excludeVideos,
		options.convertImg,
		options.conversionFormat,
		options.excludeConvertImages,
		options.preloadFrontPageImages,
		options.preloadFrontPageImagesUrls,
		options.preloadPostTypeImage,
		options.selectedPostType,
		options.availablePostTypes,
		options.excludePostTypeImgUrl,
		options.maxWidthImgSize,
		options.excludeSize,
		options.autoPreloadLCP,
		options.prioritizeLCPImages,
		options.lcp_guardrails,
		options.lcp_first_n,
		options.autoAltText,
		options.maxLongestEdgePx,
		options.lazyRenderBelowFold,
		options.lazyRenderExcludeBuilders,
		options.clientSideMimeTypeOverride,
		options.clientSideMimeTypes,
		options.forceServerSideConversion,
		options.discardOversizedSibling,
		options.placeholderType,
		options.replacePlaceholderWithSVG,
	] );
	useUnsavedChanges( settings, baseline );
	const mimeList = Array.isArray( settings.clientSideMimeTypes )
		? settings.clientSideMimeTypes
		: [];

	const togglePostType = ( type ) => {
		setSettings( ( prev ) => {
			const prevSel = Array.isArray( prev.selectedPostType )
				? prev.selectedPostType
				: [];
			const newSelected = prevSel.includes( type )
				? prevSel.filter( ( t ) => t !== type )
				: [ ...prevSel, type ];
			return { ...prev, selectedPostType: newSelected };
		} );
	};

	const toggleClientSideMimeTypeOverride = ( event ) => {
		const enabled = event.target.checked;
		setSettings( ( prev ) => {
			const hasSelection =
				Array.isArray( prev.clientSideMimeTypes ) &&
				prev.clientSideMimeTypes.length > 0;
			return {
				...prev,
				clientSideMimeTypeOverride: enabled,
				clientSideMimeTypes:
					enabled && ! hasSelection
						? DEFAULT_CLIENT_SIDE_MIME_TYPES
						: prev.clientSideMimeTypes,
			};
		} );
	};

	const toggleClientSideMimeType = ( mime ) => {
		setSettings( ( prev ) => {
			const current = Array.isArray( prev.clientSideMimeTypes )
				? prev.clientSideMimeTypes
				: [];
			const next = current.includes( mime )
				? current.filter( ( m ) => m !== mime )
				: [ ...current, mime ];
			return { ...prev, clientSideMimeTypes: next };
		} );
	};

	const onSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		// Audit #1420: re-entrancy guard like PreloadSettings.
		if ( isLoading ) {
			return;
		}
		setIsLoading( true );
		// Audit #1354: clear any prior notice before a new save attempt.
		dismiss();
		const controller = new AbortController();
		submitControllerRef.current = controller;
		try {
			const res = await apiCall(
				'update_settings',
				{
					tab: 'image_optimisation',
					settings,
				},
				'POST',
				controller.signal
			);
			if ( controller.signal.aborted || ! isMountedRef.current ) {
				return;
			}

			if ( res.success ) {
				setBaseline( { ...settings } );
				setIsDirty( false );
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'Settings saved successfully.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Error saving settings.',
							'performance-optimisation'
						),
					durationMs: 5000,
				} );
			}
		} catch ( error ) {
			// An abort on unmount is not a user-facing failure.
			if ( controller.signal.aborted || ! isMountedRef.current ) {
				return;
			}
			// Audit #1420: raw backend text stays in console; UI gets the
			// translated generic string.
			console.error(
				'Save image settings failed:',
				getErrorLogMessage( error )
			);
			notify( {
				type: 'error',
				message: __(
					'Error saving settings.',
					'performance-optimisation'
				),
				durationMs: 5000,
			} );
		} finally {
			if ( isMountedRef.current ) {
				setIsLoading( false );
			}
		}
	};

	let lcpCandidateBody;
	if ( isCandidateLoading ) {
		lcpCandidateBody = (
			<p className="wppo-text-muted wppo-text-small">
				{ __(
					'Looking for field-measured LCP data…',
					'performance-optimisation'
				) }
			</p>
		);
	} else if ( lcpCandidate && lcpCandidate.url ) {
		lcpCandidateBody = (
			<>
				<p className="wppo-text-small">
					<code className="wppo-textarea--mono">
						{ lcpCandidate.url }
					</code>
				</p>
				{ lcpCandidateSource && (
					<p className="wppo-text-muted wppo-text-small">
						{ __( 'Source:', 'performance-optimisation' ) }{ ' ' }
						{ lcpSourceLabels[ lcpCandidateSource ] ||
							// Audit #1420: translated fallback; raw token only in console.
							__( 'Unknown source', 'performance-optimisation' ) }
					</p>
				) }
				<LoadingSubmitButton
					className="wppo-button wppo-button--secondary"
					isLoading={ isApplyingLcp }
					onClick={ applyLcpCandidate }
					type="button"
					label={ __(
						'Preload this image',
						'performance-optimisation'
					) }
				/>
				<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
					{ __(
						'One click enables LCP auto-preload and prioritization so measured heroes preload with fetchpriority high and skip lazy loading (width and height preserved). This saves the whole Image Optimisation form, including any other unsaved changes above.',
						'performance-optimisation'
					) }
				</p>
			</>
		);
	} else {
		lcpCandidateBody = (
			<p className="wppo-text-muted wppo-text-small">
				{ __(
					'No field-measured LCP candidate yet. Pin the hero with the per-post “LCP Image URL” picker, or run a PageSpeed scan and check back.',
					'performance-optimisation'
				) }
			</p>
		);
	}

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'Image Optimisation', 'performance-optimisation' ) }
				description={ __(
					'Optimize media delivery with advanced lazy loading, next-gen formats, and preloading rules.',
					'performance-optimisation'
				) }
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isLoading }
						onClick={ onSubmit }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
					/>
				}
			/>

			{ notice && (
				<NoticeBanner
					type={ notice.type }
					message={ notice.message }
					onDismiss={ dismiss }
				/>
			) }

			<div className="wppo-stacked-cards">
				<FeatureCard
					title={ __( 'Lazy Loading', 'performance-optimisation' ) }
					icon={ <FontAwesomeIcon icon={ faEye } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Enable Lazy Load',
								'performance-optimisation'
							) }
							description={ __(
								'Images below the fold are loaded only when the user scrolls near them. Reduces initial page weight and improves Largest Contentful Paint (LCP) for above-the-fold content.',
								'performance-optimisation'
							) }
							name="lazyLoadImages"
							checked={ settings.lazyLoadImages }
							onChange={ onFieldChange }
						/>

						{ settings.lazyLoadImages && (
							<div className="wppo-field-nest">
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="excludeFirstImages"
									>
										{ __(
											'Exclude First N Images',
											'performance-optimisation'
										) }
									</label>
									<input
										className="wppo-input"
										id="excludeFirstImages"
										type="number"
										inputMode="numeric"
										name="excludeFirstImages"
										value={ settings.excludeFirstImages }
										onChange={ onFieldChange }
										aria-describedby="excludeFirstImages-desc"
									/>
									<p
										id="excludeFirstImages-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										{ __(
											'Skip lazy loading for the first N images on the page. Set to 1–3 to ensure your hero/banner image loads immediately without waiting for scroll.',
											'performance-optimisation'
										) }
									</p>
								</div>
								<SwitchField
									label={ __(
										'Use Native Lazy Loading',
										'performance-optimisation'
									) }
									description={ __(
										'Use the browser\'s native loading="lazy" attribute instead of JavaScript-based IntersectionObserver. On by default; disable to fall back to the legacy JS lazy loader. Supported in all modern browsers and reduces JS overhead.',
										'performance-optimisation'
									) }
									name="lazyLoadNative"
									checked={ settings.lazyLoadNative }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Lazy-load CSS Background Images',
										'performance-optimisation'
									) }
									description={ __(
										'Defer inline background-image URLs (e.g. hero and section backgrounds) until they scroll near the viewport.',
										'performance-optimisation'
									) }
									name="lazyLoadBackgroundImages"
									checked={
										settings.lazyLoadBackgroundImages
									}
									onChange={ onFieldChange }
								/>
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="placeholderType"
									>
										{ __(
											'Placeholder Type',
											'performance-optimisation'
										) }
									</label>
									<select
										className="wppo-select"
										id="placeholderType"
										name="placeholderType"
										value={ settings.placeholderType }
										onChange={ onFieldChange }
									>
										<option value="none">
											{ __(
												'None',
												'performance-optimisation'
											) }
										</option>
										<option value="svg">
											{ __(
												'SVG Placeholder (Lightweight)',
												'performance-optimisation'
											) }
										</option>
										<option value="dominant_color">
											{ __(
												'Dominant Color (Extracted from Image)',
												'performance-optimisation'
											) }
										</option>
										<option value="lqip">
											{ __(
												'LQIP (Blur Preview)',
												'performance-optimisation'
											) }
										</option>
									</select>
									<div className="wppo-help-box wppo-mt-10 wppo-text-small wppo-text-muted">
										<strong>
											{ __(
												'None',
												'performance-optimisation'
											) }
											:
										</strong>{ ' ' }
										{ __(
											'The src attribute is removed until the image is in view.',
											'performance-optimisation'
										) }
										<br />
										<strong>
											{ __(
												'SVG',
												'performance-optimisation'
											) }
											:
										</strong>{ ' ' }
										{ __(
											'Lightweight inline SVG while the real image loads. Prevents layout shift.',
											'performance-optimisation'
										) }
										<br />
										<strong>
											{ __(
												'Dominant Color',
												'performance-optimisation'
											) }
											:
										</strong>{ ' ' }
										{ __(
											'Extracted during image conversion. Smooth background-color fade transition.',
											'performance-optimisation'
										) }
										<br />
										<strong>
											{ __(
												'LQIP',
												'performance-optimisation'
											) }
											:
										</strong>{ ' ' }
										{ __(
											'20×20 blurred preview. Images must be re-optimized for LQIP to take effect.',
											'performance-optimisation'
										) }
									</div>
								</div>
							</div>
						) }

						<SwitchField
							label={ __(
								'Wrap in Picture Tag',
								'performance-optimisation'
							) }
							description={ __(
								'Wrap <img> elements in a <picture> element to enable serving next-gen formats (WebP/AVIF) with a fallback for older browsers. Required for format conversion to work.',
								'performance-optimisation'
							) }
							name="wrapInPicture"
							checked={ settings.wrapInPicture }
							onChange={ onFieldChange }
						/>

						<SwitchField
							label={ __(
								'Auto-fill Missing Alt Text',
								'performance-optimisation'
							) }
							description={ __(
								'Automatically generate alt text for images missing it, derived from the file name. Existing alt attributes are never changed. Improves accessibility and SEO with no extra requests.',
								'performance-optimisation'
							) }
							name="autoAltText"
							checked={ settings.autoAltText }
							onChange={ onFieldChange }
						/>

						<SwitchField
							label={ __(
								'Lazy-render Below-fold Sections',
								'performance-optimisation'
							) }
							description={ __(
								'Defer rendering of below-fold sections, footer widgets, and comments with content-visibility:auto plus a size reserve. Pure CSS with no JavaScript; unsupported browsers ignore it.',
								'performance-optimisation'
							) }
							name="lazyRenderBelowFold"
							checked={ settings.lazyRenderBelowFold }
							onChange={ onFieldChange }
						/>

						{ settings.lazyRenderBelowFold && (
							<div className="wppo-field-nest">
								<SwitchField
									label={ __(
										'Exclude Page-builder Sections',
										'performance-optimisation'
									) }
									description={ __(
										'Skip Elementor and Divi sections whose internal structure may be unsafe to lazy-render. Gutenberg groups, footers, and comments are still optimized.',
										'performance-optimisation'
									) }
									name="lazyRenderExcludeBuilders"
									checked={
										settings.lazyRenderExcludeBuilders
									}
									onChange={ onFieldChange }
								/>
							</div>
						) }
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __( 'Video & Media', 'performance-optimisation' ) }
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<SwitchField
							label={ __(
								'Video Lazy Loading',
								'performance-optimisation'
							) }
							description={ __(
								'Defer loading of <iframe> and <video> embeds until they enter the viewport. Significantly reduces initial page load time for pages with embedded YouTube, Vimeo, or other media.',
								'performance-optimisation'
							) }
							name="lazyLoadVideos"
							checked={ settings.lazyLoadVideos }
							onChange={ onFieldChange }
						/>

						{ settings.lazyLoadVideos && (
							<div className="wppo-field-nest">
								<SwitchField
									label={ __(
										'Video Placeholder',
										'performance-optimisation'
									) }
									description={ __(
										'Replace YouTube embeds with lightweight thumbnail previews. The actual video player loads only when the user clicks the play button, saving up to 800KB per embed.',
										'performance-optimisation'
									) }
									name="enableVideoPlaceholder"
									checked={ settings.enableVideoPlaceholder }
									onChange={ onFieldChange }
								/>
							</div>
						) }

						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="excludeVideos"
							>
								{ __(
									'Exclude from Video Lazy Load',
									'performance-optimisation'
								) }
							</label>
							<textarea
								className="wppo-textarea wppo-textarea--mono"
								id="excludeVideos"
								name="excludeVideos"
								rows="3"
								placeholder={ __(
									'Class names or partial URLs (one per line)',
									'performance-optimisation'
								) }
								value={ settings.excludeVideos }
								onChange={ onFieldChange }
								aria-describedby="excludeVideos-desc"
							/>
							<p
								id="excludeVideos-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								{ __(
									'Enter CSS class names or partial URLs of embeds that should always load immediately.',
									'performance-optimisation'
								) }
							</p>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Next-Gen Conversion',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						{ ( typeof wppoSettings !== 'undefined'
							? wppoSettings?.client_side_media_processing_enabled
							: false ) === true &&
							! settings.forceServerSideConversion && (
								<NoticeBanner
									type="info"
									message={ __(
										'WordPress 7.1+ is handling image conversion in the browser (client-side media processing). New uploads skip this plugin\'s server-side WebP/AVIF conversion. Enable "Force Server-Side Conversion" below to use the plugin\'s own pipeline.',
										'performance-optimisation'
									) }
									className="wppo-mb-20"
								/>
							) }
						<SwitchField
							label={ __(
								'Auto Convert Formats',
								'performance-optimisation'
							) }
							description={ __(
								'Automatically convert uploaded JPEG/PNG images to modern formats (WebP or AVIF). Modern formats are 25–50 percent smaller than JPEG at the same quality, directly improving page speed scores.',
								'performance-optimisation'
							) }
							name="convertImg"
							checked={ settings.convertImg }
							onChange={ onFieldChange }
						/>

						{ settings.convertImg && (
							<div className="wppo-field-nest">
								<div className="wppo-field">
									<label
										className="wppo-field-label"
										htmlFor="conversionFormat"
									>
										{ __(
											'Target Format',
											'performance-optimisation'
										) }
									</label>
									<select
										className="wppo-select"
										id="conversionFormat"
										name="conversionFormat"
										value={ settings.conversionFormat }
										onChange={ onFieldChange }
									>
										<option value="webp">
											{ __(
												'WebP (Standard — 95%+ browser support)',
												'performance-optimisation'
											) }
										</option>
										<option value="avif">
											{ __(
												'AVIF (Maximum Compression — newer browsers only)',
												'performance-optimisation'
											) }
										</option>
										<option value="both">
											{ __(
												'Both (Best Compatibility — serves AVIF where supported, WebP as fallback)',
												'performance-optimisation'
											) }
										</option>
									</select>
								</div>
								<div className="wppo-field wppo-field--spaced">
									<label
										className="wppo-field-label"
										htmlFor="excludeConvertImages"
									>
										{ __(
											'Exclude from Conversion',
											'performance-optimisation'
										) }
									</label>
									<textarea
										className="wppo-textarea wppo-textarea--mono"
										id="excludeConvertImages"
										name="excludeConvertImages"
										rows="2"
										placeholder={ __(
											'Partial URLs (one per line)',
											'performance-optimisation'
										) }
										value={ settings.excludeConvertImages }
										onChange={ onFieldChange }
										aria-describedby="excludeConvertImages-desc"
									/>
									<p
										id="excludeConvertImages-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										{ __(
											'Images matching these partial URLs will keep their original format. Useful for logos or images where exact color accuracy matters.',
											'performance-optimisation'
										) }
									</p>
								</div>
								<div className="wppo-field wppo-field--spaced">
									<SwitchField
										label={ __(
											'Discard Oversized Conversions',
											'performance-optimisation'
										) }
										description={ __(
											'Compare each converted file against its source and keep the source when the conversion is larger. Guarantees image conversion never increases byte size.',
											'performance-optimisation'
										) }
										name="discardOversizedSibling"
										checked={
											settings.discardOversizedSibling
										}
										onChange={ onFieldChange }
									/>
								</div>
							</div>
						) }

						<div className="wppo-field wppo-field--spaced">
							<SwitchField
								label={ __(
									'Override Client-Side MIME Types',
									'performance-optimisation'
								) }
								description={ __(
									'Control which image formats WordPress 7.1+ client-side media processing handles in the browser. Disable AVIF to avoid duplicating this plugin’s AVIF output, or add HEIC/HEIF/HEIC Sequence/JPEG XL when the browser decoder is available (wasm-vips 7.1+ feature plugin). Only applies on WordPress 7.1+; older versions are unaffected and unsupported formats are ignored via intersection with core.',
									'performance-optimisation'
								) }
								name="clientSideMimeTypeOverride"
								checked={ settings.clientSideMimeTypeOverride }
								onChange={ toggleClientSideMimeTypeOverride }
							/>
							{ settings.clientSideMimeTypeOverride && (
								<fieldset className="wppo-mt-12 wppo-fieldset-reset">
									<legend className="wppo-field-label">
										{ __(
											'Formats to Process in the Browser',
											'performance-optimisation'
										) }
									</legend>
									<div className="wppo-post-types-grid--chips">
										{ CLIENT_SIDE_MIME_OPTIONS.map(
											( option ) => (
												<label
													key={ option.value }
													htmlFor={ `client-mime-${ option.value }` }
													className={ `wppo-post-type-chip ${
														mimeList.includes(
															option.value
														)
															? 'wppo-post-type-chip--active'
															: ''
													}` }
												>
													<input
														type="checkbox"
														id={ `client-mime-${ option.value }` }
														className="screen-reader-text"
														checked={ mimeList.includes(
															option.value
														) }
														onChange={ () =>
															toggleClientSideMimeType(
																option.value
															)
														}
													/>
													{ option.label }
												</label>
											)
										) }
									</div>
									<p className="wppo-text-muted wppo-mt-10 wppo-text-small">
										{ __(
											'Unchecking a format makes the browser skip it during upload, falling back to server-side processing. Unchecking every format disables browser-side processing (empty list is supported by core). Formats core cannot process are ignored — HEIC/HEIC Sequence/JPEG XL appear only when this build\u2019s wasm-vips includes that decoder; JPEG is always safe.',
											'performance-optimisation'
										) }
									</p>
								</fieldset>
							) }
						</div>

						<div className="wppo-field wppo-field--spaced">
							<SwitchField
								label={ __(
									'Force Server-Side Conversion',
									'performance-optimisation'
								) }
								description={ __(
									"Disable WordPress 7.1+ client-side (in-browser) media processing and use this plugin's own server-side WebP/AVIF conversion instead. Only applies on WordPress 7.1+; older versions are unaffected.",
									'performance-optimisation'
								) }
								name="forceServerSideConversion"
								checked={ settings.forceServerSideConversion }
								onChange={ onFieldChange }
							/>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Responsive Limits',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faMagic } /> }
				>
					<div className="wppo-field-group">
						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="maxWidthImgSize"
							>
								{ __(
									'Max Image Width (px)',
									'performance-optimisation'
								) }
							</label>
							<input
								className="wppo-input"
								id="maxWidthImgSize"
								type="number"
								inputMode="numeric"
								name="maxWidthImgSize"
								value={ settings.maxWidthImgSize }
								onChange={ onFieldChange }
								aria-describedby="maxWidthImgSize-desc"
							/>
							<p
								id="maxWidthImgSize-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								{ __(
									'Images wider than this value will have a',
									'performance-optimisation'
								) }{ ' ' }
								<code>max-width</code>{ ' ' }
								{ __(
									'style applied. Set to',
									'performance-optimisation'
								) }{ ' ' }
								<code>0</code>{ ' ' }
								{ __(
									'to disable. Useful for preventing oversized images from breaking layouts on small screens.',
									'performance-optimisation'
								) }
							</p>
						</div>
						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="excludeSize"
							>
								{ __(
									'Exclude Classes from Max Width',
									'performance-optimisation'
								) }
							</label>
							<input
								className="wppo-input wppo-textarea--mono"
								id="excludeSize"
								type="text"
								name="excludeSize"
								placeholder={ __(
									'e.g. 300, 600, 1200',
									'performance-optimisation'
								) }
								value={ settings.excludeSize }
								onChange={ onFieldChange }
								aria-describedby="excludeSize-desc"
							/>
							<p
								id="excludeSize-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								{ __(
									'Comma-separated image width values (pixels). Images with these widths in srcset will be skipped.',
									'performance-optimisation'
								) }
							</p>
						</div>
						<div className="wppo-field">
							<label
								className="wppo-field-label"
								htmlFor="maxLongestEdgePx"
							>
								{ __(
									'Max Longest Edge (px)',
									'performance-optimisation'
								) }
							</label>
							<input
								className="wppo-input"
								id="maxLongestEdgePx"
								type="number"
								inputMode="numeric"
								min="0"
								step="1"
								name="maxLongestEdgePx"
								value={ settings.maxLongestEdgePx }
								onChange={ onFieldChange }
								aria-describedby="maxLongestEdgePx-desc"
							/>
							<p
								id="maxLongestEdgePx-desc"
								className="wppo-text-muted wppo-mt-10 wppo-text-small"
							>
								{ __(
									'Converted WebP/AVIF outputs are downscaled so the longest edge never exceeds this value. The original upload is kept untouched. Set to 0 to disable.',
									'performance-optimisation'
								) }
							</p>
						</div>
					</div>
				</FeatureCard>

				<FeatureCard
					title={ __(
						'Advanced Preloading',
						'performance-optimisation'
					) }
					icon={ <FontAwesomeIcon icon={ faCloudUploadAlt } /> }
				>
					<div className="wppo-stacked-cards">
						<div className="wppo-field wppo-lcp-candidate">
							<span className="wppo-field-label">
								{ __(
									'Detected LCP Candidate',
									'performance-optimisation'
								) }
							</span>
							{ lcpCandidateBody }
						</div>
						<div>
							<SwitchField
								label={ __(
									'Auto-preload LCP Image',
									'performance-optimisation'
								) }
								description={ __(
									'Automatically detect and preload the Largest Contentful Paint (LCP) image from PageSpeed scan data. Requires a configured PageSpeed API key. Falls back to featured image when no PageSpeed data is available.',
									'performance-optimisation'
								) }
								name="autoPreloadLCP"
								checked={ settings.autoPreloadLCP }
								onChange={ onFieldChange }
							/>
						</div>
						<div>
							<SwitchField
								label={ __(
									'Prioritize LCP Images in Final HTML',
									'performance-optimisation'
								) }
								description={ __(
									'Remove loading="lazy" from the first N images and set fetchpriority="high" on the detected LCP image in the finalized page HTML. Requires WordPress 6.9+ for full effect; falls back gracefully on older versions.',
									'performance-optimisation'
								) }
								name="prioritizeLCPImages"
								checked={ settings.prioritizeLCPImages }
								onChange={ onFieldChange }
							/>
						</div>
						<div>
							<SwitchField
								label={ __(
									'Preload Front Page Images',
									'performance-optimisation'
								) }
								description={ __(
									'Inject <link rel="preload"> hints for critical images on your homepage. Tells the browser to fetch these images at the highest priority, improving LCP scores for your most visited page.',
									'performance-optimisation'
								) }
								name="preloadFrontPageImages"
								checked={ settings.preloadFrontPageImages }
								onChange={ onFieldChange }
							/>
							{ settings.preloadFrontPageImages && (
								<div className="wppo-field wppo-mt-12">
									<label
										className="wppo-field-label"
										htmlFor="preloadFrontPageImagesUrls"
									>
										{ __(
											'Front Page Image URLs to Preload',
											'performance-optimisation'
										) }
									</label>
									<textarea
										className="wppo-textarea wppo-textarea--mono"
										id="preloadFrontPageImagesUrls"
										name="preloadFrontPageImagesUrls"
										rows="3"
										placeholder="/wp-content/uploads/hero.jpg"
										value={
											settings.preloadFrontPageImagesUrls
										}
										onChange={ onFieldChange }
										aria-describedby="preloadFrontPageImagesUrls-desc"
									/>
									<p
										id="preloadFrontPageImagesUrls-desc"
										className="wppo-text-muted wppo-mt-10 wppo-text-small"
									>
										{ __(
											'One URL per line. Only add above-the-fold images — preloading too many images can hurt performance.',
											'performance-optimisation'
										) }
									</p>
								</div>
							) }
						</div>
						<div>
							<SwitchField
								label={ __(
									'Preload Featured Images',
									'performance-optimisation'
								) }
								description={ __(
									'Automatically add preload hints for the featured image of posts and pages. Select which post types to apply this to below. Improves LCP for archive and single post pages.',
									'performance-optimisation'
								) }
								name="preloadPostTypeImage"
								checked={ settings.preloadPostTypeImage }
								onChange={ onFieldChange }
							/>
							{ settings.preloadPostTypeImage && (
								<>
									<div className="wppo-post-types-grid--chips">
										{ settings.availablePostTypes.map(
											( type ) => (
												<label
													key={ type }
													htmlFor={ `type-${ type }` }
													className={ `wppo-post-type-chip ${
														settings.selectedPostType.includes(
															type
														)
															? 'wppo-post-type-chip--active'
															: ''
													}` }
												>
													<input
														type="checkbox"
														id={ `type-${ type }` }
														className="screen-reader-text"
														checked={ settings.selectedPostType.includes(
															type
														) }
														onChange={ () =>
															togglePostType(
																type
															)
														}
													/>
													{ type }
												</label>
											)
										) }
									</div>
									<div className="wppo-field wppo-field--spaced">
										<label
											className="wppo-field-label"
											htmlFor="excludePostTypeImgUrl"
										>
											{ __(
												'Exclude URLs from Preload',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="excludePostTypeImgUrl"
											name="excludePostTypeImgUrl"
											rows="2"
											placeholder={ __(
												'Partial URLs (one per line)',
												'performance-optimisation'
											) }
											value={
												settings.excludePostTypeImgUrl
											}
											onChange={ handleChange(
												setSettings
											) }
											aria-describedby="excludePostTypeImgUrl-desc"
										/>
										<p
											id="excludePostTypeImgUrl-desc"
											className="wppo-text-muted wppo-mt-10 wppo-text-small"
										>
											{ __(
												'Partial URLs, one per line. Matching images will not be preloaded.',
												'performance-optimisation'
											) }
										</p>
									</div>
								</>
							) }
						</div>
					</div>
				</FeatureCard>
			</div>
		</div>
	);
};

export default ImageOptimization;
