import { __, sprintf } from '@wordpress/i18n';
import {
	useState,
	useRef,
	useEffect,
	useContext,
	useCallback,
	useMemo,
	memo,
} from '@wordpress/element';
import { handleChange, toTextLines } from '../lib/util';
import useUnsavedChanges, { stableStringify } from '../lib/useUnsavedChanges';
import {
	apiCall,
	commitSettingsCache,
	getErrorLogMessage,
	getWppoSettings,
	isValidScanUrl,
	runPerformanceScan,
} from '../lib/apiRequest';
import { modeLabel } from '../lib/litespeed';
import { isSafeHttpUrl } from '../lib/urls';
import useNotice from '../lib/useNotice';
import UnsavedChangesContext from '../lib/UnsavedChangesContext';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
	faCode,
	faRocket,
	faStore,
	faServer,
	faShieldAlt,
	faExclamationTriangle,
	faSpinner,
} from '@fortawesome/free-solid-svg-icons';
import Tooltip from './common/Tooltip';
import FeatureHeader from './common/FeatureHeader';
import FeatureCard from './common/FeatureCard';
import LoadingSubmitButton from './common/LoadingSubmitButton';
import SwitchField from './common/SwitchField';
import NoticeBanner from './common/NoticeBanner';

import CriticalCssPanel from './CriticalCssPanel';

// Per-instance row ids (issue #1274 review): a module counter + Date.now()
// leaks across mounts/tests and is non-deterministic, so each component
// instance owns a useRef counter seeded once. The generator itself is
// wrapped in useCallback so child renders do not see a new closure identity
// every render.
const useCdnRowId = () => {
	const ref = useRef( 0 );
	return useCallback( () => {
		ref.current += 1;
		return `cdn-${ ref.current }`;
	}, [] );
};

// Memoized sub-tab button: without this, the inline onClick/onKeyDown
// closures in the tab list below recreate every render and force the full
// subtree to reconcile on each settings keystroke.
const SubTabButton = memo(
	( { tab, index, isActive, tabRef, onSelect, onKeyDown } ) => (
		<button
			id={ `tab-${ tab.id }` }
			ref={ tabRef }
			className={ `wppo-sub-tab${
				isActive ? ' wppo-sub-tab--active' : ''
			}` }
			onClick={ () => onSelect( tab.id ) }
			onKeyDown={ ( e ) => onKeyDown( e, index ) }
			type="button"
			role="tab"
			tabIndex={ isActive ? 0 : -1 }
			aria-selected={ isActive }
			aria-controls={ `panel-${ tab.id }` }
		>
			<FontAwesomeIcon icon={ tab.icon } />
			{ tab.label }
		</button>
	)
);

// Default builder-template exclusions (single source of truth for the
// normalize fallback and the form defaults below).
const CCSS_EXCLUDED_DEFAULT = 'fl-builder-template\nelementor_library';

// Normalize the max-retries input the same way PHP sanitizes it
// (is_numeric whole-value check, (int) truncation, clamped 0..5,
// fail-open to 5). Number() mirrors PHP is_numeric() except for
// hex/binary/octal literals (e.g. '0x3'): PHP is_numeric() rejects
// them, so they are guarded explicitly to fail open to 5.
// Math.trunc() mirrors the (int) cast for values like '3.7'.
// Exported for direct Jest coverage.
// @since NEXT
export const normalizeRetries = ( value ) => {
	if ( typeof value === 'number' ) {
		return Number.isFinite( value )
			? Math.min( 5, Math.max( 0, Math.trunc( value ) ) )
			: 5;
	}
	if ( Array.isArray( value ) ) {
		return 5;
	}
	const s = String( value ?? '' ).trim();
	if ( '' === s ) {
		return 5;
	}
	// PHP is_numeric() rejects hex/binary/octal while Number() parses
	// them, so guard explicitly to keep UI/server parity.
	if ( /^0[xXoObB]/.test( s ) ) {
		return 5;
	}
	const n = Number( s );
	if ( ! Number.isFinite( n ) ) {
		return 5;
	}
	return Math.min( 5, Math.max( 0, Math.trunc( n ) ) );
};

// Normalize the used-CSS delivery mode the same way PHP sanitizes it
// (lowercase + trim, allowlisted, fail-open to 'file') so the UI never
// disagrees with the server on a single render (issue #1220).
// Exported for direct Jest coverage.
// @since NEXT
export const normalizeDeliveryMode = ( value ) => {
	if ( typeof value !== 'string' ) {
		return 'file';
	}
	const mode = value.toLowerCase().trim();
	return [ 'file', 'delay', 'async', 'remove' ].includes( mode )
		? mode
		: 'file';
};

// Textarea-backed file-optimisation keys: newline-delimited lists the backend
// may return as arrays (via sanitize/process_urls). Every one normalises
// through toTextLines() so a backend array can never reach a controlled
// textarea or be dropped to '' inconsistently between init, baseline, and
// sync (issue #1259 review).
const FILE_OPT_TEXTAREA_KEYS = [
	'excludeJS',
	'excludeCSS',
	'excludeCombineCSS',
	'excludeDeferJS',
	'excludeDelayJS',
	'delayJSThirdPartyDenylist',
	'delayJSThirdPartyAllowlist',
	'delayJSExcludeUrls',
	'usedCSSExcludeUrls',
	'delayJSIdleList',
	'delayJSViewportList',
	'delayJSPriority',
	'excludeUrlToKeepJSCSS',
	'removeCssJsHandle',
	'excludeUnusedCSS',
	'unusedCSSSafelistExtra',
	'ccssSafelistExtra',
	'ccssExcludedPostTypes',
	// Array-backed multi-select rendered as a single value: normalizing
	// through toTextLines() keeps backend arrays consistent with the form
	// instead of resetting to the 'latin' fallback below.
	'fontSubsetSubsets',
];

// Every file-optimisation key synced from incoming props in the baseline +
// sync effects below. Single source of truth for both dep arrays so adding a
// setting needs one edit, not three (issue #1259 review).
const FILE_OPT_SYNC_KEYS = [
	'safeMode',
	'elementorSafeMode',
	'minifyJS',
	'excludeJS',
	'minifyCSS',
	'excludeCSS',
	'combineCSS',
	'excludeCombineCSS',
	'minifyHTML',
	'deferJS',
	'excludeDeferJS',
	'delayJS',
	'excludeDelayJS',
	'delayJSCommercePreset',
	'delayJSBuilderPreset',
	'delayJSInteractionPreset',
	'delayJSConsentPreset',
	'delayJSAnalyticsPreset',
	'delayJSGalleryPreset',
	'delayJSJqueryPreset',
	'delayJSINPPreset',
	'delayJSExternalOnly',
	'delayJSThirdParty',
	'delayJSThirdPartyDenylist',
	'delayJSThirdPartyAllowlist',
	'delayJSExcludeUrls',
	'usedCSSExcludeUrls',
	'delayJSDefaultStrategy',
	'delayJSIdleList',
	'delayJSViewportList',
	'delayJSPriority',
	'delayJSIdleTimeout',
	'removeWooCSSJS',
	'excludeUrlToKeepJSCSS',
	'removeCssJsHandle',
	'enableServerRules',
	'criticalCSS',
	'ccssMaxSize',
	'ccssSafelistExtra',
	'ccssExcludedPostTypes',
	'ccssMaxRetries',
	'hostGoogleFontsLocally',
	'fontMetricFallback',
	'fontSubset',
	'fontSubsetSubsets',
	'cdnURL',
	'cdnMapping',
	'removeUnusedCSS',
	'excludeUnusedCSS',
	'unusedCSSSafelistExtra',
	'unusedCSSRegressionGuard',
	'unusedCSSRegressionThreshold',
	'usedCSSDeliveryMode',
	'disableEmojis',
	'disableEmbeds',
	'disableDashicons',
	'disableXMLRPC',
	'disableRestApiLinks',
	'disableRssFeeds',
	'disableShortlinks',
	'disableGeneratorTag',
	'disableJQueryMigrate',
	'disablePasswordStrength',
	'disableSelfPingbacks',
	'disableRSD',
	'disableWLWManifest',
	'disableGlobalStyles',
	'disableClassicThemeStyles',
	'disableWooCartFragments',
	'disableRecentCommentsStyle',
	'disableCommentReply',
	'disableOEmbedDiscovery',
	'disableBlockWidgets',
	'blockAssetsOnDemand',
	'loadAllCoreBlockAssets',
	'heartbeatControl',
	'minifyInlineCSS',
	'minifyInlineJS',
	'removeHTMLComments',
];

// Server-side scans run unauthenticated, so they can never carry the
// admin preview session: strip the preview query args and scan the
// plain production URL instead of implying a staged measurement.
//
// Only absolute http(s) URLs are accepted: relative paths, protocol-
// relative values and non-http(s) schemes (javascript:, data:, …)
// return '' so callers can abort instead of forwarding a tampered
// server-provided preview_url to the resource-intensive scan endpoint.
// Server-side host allowlisting + rate limiting remains authoritative.
// Exported for direct Jest coverage.
// @since NEXT
export const stripPreviewParams = ( url ) => {
	if ( ! isSafeHttpUrl( url ) ) {
		return '';
	}
	try {
		const parsed = new URL( url );
		parsed.searchParams.delete( 'wppo_preview' );
		parsed.searchParams.delete( '_wppo_preview_nonce' );
		// Hardening: strip embedded credentials and the fragment so a
		// credentialed same-host URL can never land in logs/telemetry.
		// Server-side same-host allowlisting remains authoritative.
		parsed.username = '';
		parsed.password = '';
		parsed.hash = '';
		return parsed.toString();
	} catch {
		return '';
	}
};

// Assign stable row ids to CDN-mapping rows.
//
// Index-derived ids (`cdn-row-N`) are deterministic across rebuilds, so a
// parent re-render that syncs raw (id-less) server options over local state
// reuses the same keys instead of remounting every row and losing focus.
// Rows created via "Add Mapping" carry counter ids (`cdn-N`) from
// useCdnRowId and pass through untouched.
// @since NEXT
export const withCdnRowIds = ( mapping ) => {
	const list = Array.isArray( mapping ) ? mapping : [];
	const used = new Set(
		list.map( ( entry ) =>
			entry && typeof entry === 'object' ? entry.id : undefined
		)
	);
	return list.map( ( entry, index ) => {
		const base =
			entry && typeof entry === 'object' && ! Array.isArray( entry )
				? entry
				: {};
		if (
			( typeof base.id === 'string' && '' !== base.id ) ||
			( typeof base.id === 'number' && Number.isFinite( base.id ) )
		) {
			return { ...base };
		}
		let candidate = `cdn-row-${ index }`;
		let suffix = 0;
		while ( used.has( candidate ) ) {
			suffix += 1;
			candidate = `cdn-row-${ index }-${ suffix }`;
		}
		used.add( candidate );
		return { ...base, id: candidate };
	} );
};
// Strip client-only CDN row ids before submit/baseline/dirty-compare so the
// payload never persists synthetic ids server-side and the id-less server
// baseline compares clean against local state.
// @since NEXT
export const stripCdnRowIds = ( source = {} ) => {
	if ( ! source || typeof source !== 'object' ) {
		return source;
	}
	if ( ! Array.isArray( source.cdnMapping ) ) {
		return source;
	}
	return {
		...source,
		cdnMapping: source.cdnMapping.map( ( entry ) => {
			if ( ! entry || typeof entry !== 'object' ) {
				return entry;
			}
			if ( ! ( 'id' in entry ) ) {
				return entry;
			}
			const next = { ...entry };
			delete next.id;
			return next;
		} ),
	};
};
// Backward-compatibility alias for the pre-rename `stripCdnIds` export
// (kept so existing imports/tests keep working).
// @since NEXT
export const stripCdnIds = stripCdnRowIds;
// Numeric clamps mirroring the server-side sanitizers so a raw server value
// can never reach state/submit verbatim (display/UX parity — the server
// stays authoritative).
// Booleans/arrays fail open (true must not coerce to 1 via Number()), and
// hex/octal/binary literals fail open to match PHP is_numeric() parity
// (see normalizeRetries).
// @since NEXT
export const normalizeIdleTimeout = ( value ) => {
	if ( typeof value === 'boolean' || Array.isArray( value ) ) {
		return 3000;
	}
	const s = String( value ?? '' ).trim();
	if ( '' === s || /^0[xXoObB]/.test( s ) ) {
		return 3000;
	}
	let n;
	if ( typeof value === 'number' ) {
		n = value;
	} else {
		n = Number( s );
	}
	if ( ! Number.isFinite( n ) || n <= 0 ) {
		return 3000;
	}
	return Math.min( 20000, Math.max( 500, Math.trunc( n ) ) );
};
// @since NEXT
export const normalizeCcssMaxSize = ( value ) => {
	if ( typeof value === 'boolean' || Array.isArray( value ) ) {
		return 20480;
	}
	const s = String( value ?? '' ).trim();
	if ( '' === s || /^0[xXoObB]/.test( s ) ) {
		return 20480;
	}
	const n = typeof value === 'number' ? value : Number( s );
	if ( ! Number.isFinite( n ) || n <= 0 ) {
		return 20480;
	}
	return Math.trunc( n );
};
// @since NEXT
export const normalizeRegressionThreshold = ( value ) => {
	if ( typeof value === 'boolean' || Array.isArray( value ) ) {
		return 20;
	}
	const s = String( value ?? '' ).trim();
	if ( '' === s || /^0[xXoObB]/.test( s ) ) {
		return 20;
	}
	const n = typeof value === 'number' ? value : Number( s );
	if ( ! Number.isFinite( n ) ) {
		return 20;
	}
	const t = Math.trunc( n );
	return t >= 5 && t <= 50 ? t : 20;
};
// toTextLines() (after spread, so backend arrays win correctly), delivery
// mode via the PHP-mirroring allowlist, CCSS retries clamped 0..5, blank
// CCSS exclusions reset to the builder defaults (mirroring PHP), font
// subsets with the 'latin' fallback. Idempotent — safe to run on init,
// baseline, and sync payloads.
const normalizeFileOpt = ( source = {} ) => {
	const next = { ...source };
	for ( const key of FILE_OPT_TEXTAREA_KEYS ) {
		if ( key in next ) {
			next[ key ] = toTextLines( next[ key ] );
		}
	}
	// Partial slices (e.g. the sandbox promote payload, which only carries
	// staged script keys) must not reset unrelated production fields: every
	// default below is guarded by `in` so absent keys stay absent and the
	// promote merge (`{ ...prev, ...synced }`) preserves custom
	// exclusions/CDN/mode. Full payloads (init/baseline/sync) always carry
	// these keys, so their defaults still apply there.
	if ( 'usedCSSDeliveryMode' in next ) {
		next.usedCSSDeliveryMode = normalizeDeliveryMode(
			next.usedCSSDeliveryMode
		);
	}
	if ( 'ccssMaxRetries' in next ) {
		next.ccssMaxRetries = normalizeRetries( next.ccssMaxRetries );
	}
	// Numeric display parity: the sync path spreads raw server values, so
	// clamp here (mirroring the server sanitizers) instead of letting
	// out-of-range values reach state/submit verbatim. The server stays
	// authoritative; this only keeps the UI consistent on a single render.
	if ( 'delayJSIdleTimeout' in next ) {
		next.delayJSIdleTimeout = normalizeIdleTimeout(
			next.delayJSIdleTimeout
		);
	}
	if ( 'ccssMaxSize' in next ) {
		next.ccssMaxSize = normalizeCcssMaxSize( next.ccssMaxSize );
	}
	if ( 'unusedCSSRegressionThreshold' in next ) {
		next.unusedCSSRegressionThreshold = normalizeRegressionThreshold(
			next.unusedCSSRegressionThreshold
		);
	}
	// An empty or whitespace-only exclusions string normalizes to the
	// builder defaults (mirroring PHP, where empty keeps defaults) so the
	// UI never shows "no exclusions" while the server enforces the builder
	// defaults (issue #1274 review). Guarded by `in` so partial slices
	// (sandbox promote) never inject the default over production values.
	if ( 'ccssExcludedPostTypes' in next ) {
		if (
			'string' !== typeof next.ccssExcludedPostTypes ||
			'' === next.ccssExcludedPostTypes.trim()
		) {
			next.ccssExcludedPostTypes = CCSS_EXCLUDED_DEFAULT;
		}
	}
	// Fail-open for corrupted cache: a non-array truthy cdnMapping (e.g. a
	// string) would crash every `.map` render site, so reset to [] here —
	// the single choke point — instead of guarding each call site. Guarded
	// by `in` so partial slices never wipe a production mapping.
	if ( 'cdnMapping' in next && ! Array.isArray( next.cdnMapping ) ) {
		next.cdnMapping = [];
	}
	if (
		'fontSubsetSubsets' in next &&
		typeof next.fontSubsetSubsets !== 'string'
	) {
		next.fontSubsetSubsets = 'latin';
	}
	// The textarea loop above joins arrays but maps a missing key to '':
	// restore the 'latin' default for a missing value so backfill matches
	// the historical default.
	if (
		next.fontSubsetSubsets === '' &&
		( source.fontSubsetSubsets === undefined ||
			source.fontSubsetSubsets === null )
	) {
		next.fontSubsetSubsets = 'latin';
	}
	return next;
};

const FileOptimization = ( {
	options = {},
	serverRules = null,
	serverRulesError = false,
	ccssStatus = {},
	ccssError = false,
	onRetryServerRules,
	onCcssRefresh,
	onCcssRetry,
} ) => {
	const [ activeSubTab, setActiveSubTab ] = useState( 'assets' );
	const tabRefs = useRef( {} );
	const tabRefCallbacks = useRef( {} );

	// Stable per-tab ref callbacks: caching by tab id avoids creating a new
	// inline closure every render (which would detach/re-attach every tab
	// ref on each keystroke-driven re-render).
	const getTabRef = useCallback( ( id ) => {
		if ( ! tabRefCallbacks.current[ id ] ) {
			tabRefCallbacks.current[ id ] = ( el ) => {
				if ( el ) {
					tabRefs.current[ id ] = el;
				} else {
					delete tabRefs.current[ id ];
				}
			};
		}
		return tabRefCallbacks.current[ id ];
	}, [] );

	const cdnRowId = useCdnRowId();
	// Memoized on the incoming options object identity so local keystroke
	// renders reuse the derived defaults instead of re-spreading ~70 keys
	// and re-joining every textarea list on each render.
	const defaultSettings = useMemo( () => {
		const base = normalizeFileOpt( {
			safeMode: options.safeMode !== undefined ? options.safeMode : false,
			elementorSafeMode:
				options.elementorSafeMode !== undefined
					? options.elementorSafeMode
					: true,
			minifyJS: false,
			excludeJS: '',
			minifyCSS: false,
			excludeCSS: '',
			combineCSS: false,
			excludeCombineCSS: '',
			minifyHTML: false,
			deferJS: false,
			excludeDeferJS: '',
			delayJS: false,
			excludeDelayJS: '',
			delayJSDefaultStrategy:
				options.delayJSDefaultStrategy || 'interaction',
			delayJSINPPreset: options.delayJSINPPreset || false,
			delayJSExternalOnly:
				options.delayJSExternalOnly !== undefined
					? options.delayJSExternalOnly
					: false,
			delayJSThirdParty:
				options.delayJSThirdParty !== undefined
					? options.delayJSThirdParty
					: false,
			// Raw values: the single normalizeFileOpt() call below joins
			// backend arrays via toTextLines(), so no manual calls here.
			delayJSThirdPartyDenylist: options.delayJSThirdPartyDenylist,
			delayJSThirdPartyAllowlist: options.delayJSThirdPartyAllowlist,
			delayJSBuilderPreset:
				options.delayJSBuilderPreset !== undefined
					? options.delayJSBuilderPreset
					: true,
			delayJSCommercePreset:
				options.delayJSCommercePreset !== undefined
					? options.delayJSCommercePreset
					: true,
			delayJSInteractionPreset:
				options.delayJSInteractionPreset !== undefined
					? options.delayJSInteractionPreset
					: true,
			delayJSConsentPreset:
				options.delayJSConsentPreset !== undefined
					? options.delayJSConsentPreset
					: false,
			delayJSAnalyticsPreset:
				options.delayJSAnalyticsPreset !== undefined
					? options.delayJSAnalyticsPreset
					: false,
			delayJSGalleryPreset:
				options.delayJSGalleryPreset !== undefined
					? options.delayJSGalleryPreset
					: false,
			delayJSJqueryPreset:
				options.delayJSJqueryPreset !== undefined
					? options.delayJSJqueryPreset
					: false,
			delayJSExcludeUrls:
				typeof options.delayJSExcludeUrls === 'string'
					? options.delayJSExcludeUrls
					: '',
			usedCSSExcludeUrls:
				typeof options.usedCSSExcludeUrls === 'string'
					? options.usedCSSExcludeUrls
					: '',
			delayJSIdleList: options.delayJSIdleList || '',
			delayJSViewportList: options.delayJSViewportList || '',
			delayJSPriority: options.delayJSPriority || '',
			delayJSIdleTimeout: options.delayJSIdleTimeout || 3000,
			removeWooCSSJS: false,
			excludeUrlToKeepJSCSS: '',
			removeCssJsHandle: '',
			enableServerRules: false,
			criticalCSS: false,
			ccssMaxSize: options.ccssMaxSize || 20480,
			ccssSafelistExtra:
				typeof options.ccssSafelistExtra === 'string'
					? options.ccssSafelistExtra
					: '',
			ccssExcludedPostTypes:
				typeof options.ccssExcludedPostTypes === 'string'
					? options.ccssExcludedPostTypes
					: CCSS_EXCLUDED_DEFAULT,
			ccssMaxRetries: normalizeRetries( options.ccssMaxRetries ),
			hostGoogleFontsLocally: false,
			fontMetricFallback: false,
			fontSubset: false,
			fontSubsetSubsets: options.fontSubsetSubsets,
			cdnURL: '',
			cdnMapping: options.cdnMapping || [],
			removeUnusedCSS: false,
			excludeUnusedCSS: '',
			unusedCSSSafelistExtra: options.unusedCSSSafelistExtra || '',
			unusedCSSRegressionGuard:
				options.unusedCSSRegressionGuard !== undefined
					? options.unusedCSSRegressionGuard
					: true,
			unusedCSSRegressionThreshold:
				options.unusedCSSRegressionThreshold ?? 20,
			disableEmojis: false,
			disableEmbeds: false,
			disableDashicons: false,
			disableXMLRPC: false,
			disableRestApiLinks: false,
			disableRssFeeds: false,
			disableShortlinks: false,
			disableGeneratorTag: false,
			disableJQueryMigrate: false,
			disablePasswordStrength: false,
			disableSelfPingbacks: false,
			disableRSD: false,
			disableWLWManifest: false,
			disableGlobalStyles: false,
			disableClassicThemeStyles: false,
			disableWooCartFragments: false,
			disableRecentCommentsStyle: false,
			disableCommentReply: false,
			disableOEmbedDiscovery: false,
			disableBlockWidgets: false,
			// Mirrors the pre-6.9 PHP default. PHP always emits the key on WP 6.9+ (where
			// core loads block assets on demand by default), so this fallback is only used
			// on older cores and never contradicts the backend default.
			blockAssetsOnDemand: false,
			loadAllCoreBlockAssets: false,
			heartbeatControl: 'default',
			minifyInlineCSS: false,
			minifyInlineJS: false,
			removeHTMLComments: true,
			...options,
		} );
		// Backfill stable row ids so CDN-mapping rows keep identity across
		// add/remove (index keys would reuse the wrong input state/focus).
		// Index-derived ids are deterministic across rebuilds, so a synced
		// raw server payload reuses the same keys instead of remounting
		// rows and losing focus. All other normalization lives in the
		// single normalizeFileOpt() call above: one truth to keep in sync.
		base.cdnMapping = withCdnRowIds( base.cdnMapping );
		return base;
	}, [ options ] );

	// Lazy-init from the memoized defaults: the state initializer runs once,
	// so later renders never pay the derivation cost through useState.
	const [ settings, setSettings ] = useState( () => defaultSettings );
	// Single stable change handler shared by every input in this large form:
	// calling handleChange( setSettings ) inline would allocate a new closure
	// per input (~80 inputs) on every render, churning GC on each keystroke.
	// setSettings is a stable React setter, so memoizing once is safe.
	// @since NEXT
	const onFieldChange = useMemo(
		() => handleChange( setSettings ),
		[ setSettings ]
	);
	// Single CDN-mapping field updater replacing six duplicated inline
	// setSettings closures (one per field key). Stable identity avoids
	// re-allocating O(rows) closures and re-rendering the large form on
	// every CDN keystroke.
	// @since NEXT
	const updateCdnEntry = useCallback( ( idx, key, value ) => {
		setSettings( ( prev ) => {
			const m = [ ...( prev.cdnMapping || [] ) ];
			m[ idx ] = { ...m[ idx ], [ key ]: value };
			return { ...prev, cdnMapping: m };
		} );
	}, [] );
	// Split busy flags: saving the form must not show the Save button as
	// loading while a background regen runs, nor disable unrelated regen
	// actions while saving.
	const [ isSaving, setIsSaving ] = useState( false );
	const [ isRegenerating, setIsRegenerating ] = useState( false );
	const [ isPurging, setIsPurging ] = useState( false );
	const { notice, notify, dismiss } = useNotice();
	const {
		notice: purgeNotice,
		notify: notifyPurge,
		dismiss: dismissPurge,
	} = useNotice();
	// Used-CSS staleness (issue #1220): last-regen time + stale flag from the
	// read-only used_css_status endpoint, shown as a warning banner while
	// removeUnusedCSS is on. Fail-open: a failed fetch simply hides the banner.
	const [ usedCssStatus, setUsedCssStatus ] = useState( null );
	const refreshUsedCssStatus = useCallback( async () => {
		try {
			const res = await apiCall( 'used_css_status', {}, 'GET' );
			if ( res && res.success && res.data ) {
				setUsedCssStatus( res.data );
			}
		} catch {
			// Fail-open: leave the banner hidden.
		}
	}, [] );
	useEffect( () => {
		if ( ! options.removeUnusedCSS ) {
			// Clear a stale banner when the feature is toggled off.
			setUsedCssStatus( null );
			return;
		}
		// Reuse the shared fetcher so staleness logic lives in one place
		// (issue #1220). Fail-open: a failed fetch leaves the banner hidden.
		refreshUsedCssStatus();
	}, [ options.removeUnusedCSS, refreshUsedCssStatus ] );
	// Upgrade auto-purge status (issue #1276): SPA-visible last-purge
	// reason + safe-mode preview link bypassing minify (?wppo_nocache=1).
	// Seeded from wppoSettings.upgradePurge, refreshed from the read-only
	// upgrade_purge_status endpoint. Fail-open: a failed fetch keeps the seed.
	const initialUpgradePurge = getWppoSettings( 'upgradePurge', {} ) || {};
	const [ upgradePurge, setUpgradePurge ] = useState( {
		last_purge:
			initialUpgradePurge.last_purge ||
			initialUpgradePurge.lastPurge ||
			null,
		safe_preview_url:
			initialUpgradePurge.safe_preview_url ||
			initialUpgradePurge.safePreviewUrl ||
			'',
	} );
	const [ isPurgingDerived, setIsPurgingDerived ] = useState( false );
	const purgingDerivedRef = useRef( false );
	const {
		notice: derivedPurgeNotice,
		notify: notifyDerivedPurge,
		dismiss: dismissDerivedPurge,
	} = useNotice();
	const refreshUpgradePurgeStatus = useCallback( async () => {
		try {
			const res = await apiCall( 'upgrade_purge_status', {}, 'GET' );
			if ( res && res.success && res.data ) {
				setUpgradePurge( ( prev ) => ( {
					last_purge: res.data.last_purge || null,
					safe_preview_url:
						res.data.safe_preview_url || prev.safe_preview_url,
				} ) );
			}
		} catch {
			// Fail-open: keep the seeded wppoSettings value.
		}
	}, [] );
	// Note: no auto-fetch on mount/tab-open — the status is seeded from
	// wppoSettings.upgradePurge (localized by PHP) so existing mocked-apiCall
	// flows are unaffected; refresh runs after a manual derived purge.
	const handlePurgeDerivedCaches = async () => {
		if ( isPurgingDerived || purgingDerivedRef.current ) {
			return;
		}
		purgingDerivedRef.current = true;
		setIsPurgingDerived( true );
		dismissDerivedPurge();
		try {
			const res = await apiCall( 'purge_derived_caches' );
			if ( res && res.success ) {
				if (
					res.data &&
					typeof res.data.reason === 'string' &&
					typeof res.data.time === 'number'
				) {
					setUpgradePurge( ( prev ) => ( {
						...prev,
						last_purge: res.data,
					} ) );
				}
				notifyDerivedPurge( {
					type: 'success',
					message:
						res.message ||
						__(
							'Page cache, used CSS and critical CSS purged.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
				await Promise.all( [
					refreshUpgradePurgeStatus(),
					refreshUsedCssStatus(),
				] );
			} else {
				notifyDerivedPurge( {
					type: 'error',
					message:
						( res && res.message ) ||
						__(
							'Failed to purge derived caches.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error(
				'Failed to purge derived caches.',
				getErrorLogMessage( err )
			);
			notifyDerivedPurge( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			purgingDerivedRef.current = false;
			setIsPurgingDerived( false );
		}
	};
	// Sandbox preview (issue #1163): visitor-safe admin preview of
	// delay/defer/combine with one-click promote/discard + in-preview perf test.
	const [ sandboxStaged, setSandboxStaged ] = useState( null );
	const [ sandboxPreviewUrl, setSandboxPreviewUrl ] = useState( '' );
	const [ sandboxBusy, setSandboxBusy ] = useState( false );
	const {
		notice: sandboxNotice,
		notify: notifySandbox,
		dismiss: dismissSandbox,
	} = useNotice();
	const buildStagedFromForm = () => ( {
		delayJS: !! settings.delayJS,
		deferJS: !! settings.deferJS,
		combineCSS: !! settings.combineCSS,
		elementorSafeMode: !! settings.elementorSafeMode,
		// Staging must mirror what the preview renderer consumes:
		// safe_minify_js reads delayJSExternalOnly and minifyInlineJS from
		// the effective slice, so omitting them would silently drop the
		// toggles from the admin preview.
		delayJSExternalOnly: !! settings.delayJSExternalOnly,
		minifyInlineJS: !! settings.minifyInlineJS,
		delayJSThirdParty: !! settings.delayJSThirdParty,
		delayJSThirdPartyDenylist: toTextLines(
			settings.delayJSThirdPartyDenylist
		),
		delayJSThirdPartyAllowlist: toTextLines(
			settings.delayJSThirdPartyAllowlist
		),
		excludeDelayJS: toTextLines( settings.excludeDelayJS ),
		excludeDeferJS: toTextLines( settings.excludeDeferJS ),
		excludeCombineCSS: toTextLines( settings.excludeCombineCSS ),
	} );
	// Hydrate sandbox state when the Scripts tab opens so a staged
	// experiment from a prior session is visible without re-staging.
	// Skipped when staged data is already present (e.g. just staged in
	// this session) so rapid tab toggling does not fire redundant
	// authenticated calls.
	useEffect( () => {
		if ( activeSubTab !== 'scripts' ) {
			return;
		}
		if (
			sandboxStaged &&
			typeof sandboxStaged === 'object' &&
			Object.keys( sandboxStaged ).length > 0
		) {
			return;
		}
		let cancelled = false;
		( async () => {
			try {
				const status = await apiCall( 'sandbox_preview', {}, 'GET' );
				if (
					cancelled ||
					! status ||
					! status.success ||
					! status.data
				) {
					return;
				}
				if (
					status.data.staged &&
					typeof status.data.staged === 'object' &&
					Object.keys( status.data.staged ).length > 0
				) {
					setSandboxStaged( status.data.staged );
				}
				if ( status.data.preview_url ) {
					setSandboxPreviewUrl( status.data.preview_url );
				}
			} catch {
				// Best-effort: the stage/promote/discard controls still work
				// without prior status.
			}
		} )();
		return () => {
			cancelled = true;
		};
	}, [ activeSubTab, sandboxStaged ] );
	const handleSandboxSave = async () => {
		setSandboxBusy( true );
		try {
			const res = await apiCall( 'sandbox_save', {
				settings: buildStagedFromForm(),
			} );
			if ( res && res.success ) {
				setSandboxStaged( res.data ? res.data.staged || {} : {} );
				try {
					const status = await apiCall(
						'sandbox_preview',
						{},
						'GET'
					);
					if ( status && status.success && status.data ) {
						setSandboxPreviewUrl( status.data.preview_url || '' );
					}
				} catch {
					// Preview link is best-effort; staged state above is enough.
				}
				notifySandbox( {
					type: 'success',
					message: __(
						'Preview staged. Open the preview link as admin — visitors still see production markup.',
						'performance-optimisation'
					),
				} );
			} else {
				notifySandbox( {
					type: 'error',
					message: __(
						'Could not stage the preview.',
						'performance-optimisation'
					),
				} );
			}
		} catch {
			notifySandbox( {
				type: 'error',
				message: __(
					'Could not stage the preview.',
					'performance-optimisation'
				),
			} );
		} finally {
			setSandboxBusy( false );
		}
	};
	const handleSandboxPromote = async () => {
		setSandboxBusy( true );
		try {
			const res = await apiCall( 'sandbox_promote', {} );
			if ( res && res.success ) {
				// Sync the production baseline so the form reflects the
				// promoted values: prefer the production slice returned by
				// the endpoint, falling back to the last staged values.
				const promotedSlice =
					res.data &&
					typeof res.data === 'object' &&
					res.data.file_optimisation &&
					typeof res.data.file_optimisation === 'object'
						? res.data.file_optimisation
						: sandboxStaged;
				if ( promotedSlice && typeof promotedSlice === 'object' ) {
					// Normalize through the shared normalizer so backend
					// arrays never reach controlled textareas verbatim
					// (which would render "a,b" while the baseline stays
					// equal and the bad display persists as clean).
					// normalizeFileOpt() only defaults keys present in the
					// slice, so unmentioned production fields survive the
					// merge below instead of resetting to builder defaults.
					const synced = normalizeFileOpt( { ...promotedSlice } );
					// Re-attach deterministic row ids so synced server rows
					// keep React keys instead of remounting; the baseline
					// stays id-less to match the server payload. Guarded so
					// a partial slice without a mapping never injects [].
					if ( 'cdnMapping' in synced ) {
						synced.cdnMapping = withCdnRowIds( synced.cdnMapping );
					}
					setSettings( ( prev ) => ( { ...prev, ...synced } ) );
					setBaseline( ( prev ) => ( {
						...prev,
						...stripCdnRowIds( synced ),
					} ) );
				}
				setSandboxStaged( {} );
				setSandboxPreviewUrl( '' );
				notifySandbox( {
					type: 'success',
					message: __(
						'Preview promoted to production.',
						'performance-optimisation'
					),
				} );
			} else {
				notifySandbox( {
					type: 'error',
					// The REST envelope is { data, success, message }: data
					// is null on failure (or an object on validation-style
					// failures), so prefer the string message to avoid
					// rendering [object Object].
					message:
						( res &&
							typeof res.message === 'string' &&
							res.message ) ||
						__( 'Could not promote.', 'performance-optimisation' ),
				} );
			}
		} catch {
			notifySandbox( {
				type: 'error',
				message: __( 'Could not promote.', 'performance-optimisation' ),
			} );
		} finally {
			setSandboxBusy( false );
		}
	};
	const handleSandboxDiscard = async () => {
		setSandboxBusy( true );
		try {
			const res = await apiCall( 'sandbox_discard', {} );
			if ( res && res.success ) {
				setSandboxStaged( {} );
				// Clear the stale preview link (its nonce and staged values
				// no longer exist) so it cannot be reopened.
				setSandboxPreviewUrl( '' );
				notifySandbox( {
					type: 'success',
					message: __(
						'Preview discarded. Production settings unchanged.',
						'performance-optimisation'
					),
				} );
			} else {
				notifySandbox( {
					type: 'error',
					message: __(
						'Could not discard.',
						'performance-optimisation'
					),
				} );
			}
		} catch {
			notifySandbox( {
				type: 'error',
				message: __( 'Could not discard.', 'performance-optimisation' ),
			} );
		} finally {
			setSandboxBusy( false );
		}
	};
	const handleSandboxPerfTest = async () => {
		if ( ! sandboxPreviewUrl ) {
			return;
		}
		// Route through the shared validated wrapper: isValidScanUrl enforces
		// an absolute same-origin http(s) URL before the resource-intensive
		// performance_scan endpoint is hit. A tampered preview_url (or a
		// javascript:/data: value) is rejected client-side instead of being
		// forwarded; server-side allowlisting + rate limiting stays
		// authoritative.
		const scanUrl = stripPreviewParams( sandboxPreviewUrl );
		if ( ! isValidScanUrl( scanUrl ) ) {
			notifySandbox( {
				type: 'error',
				message: __(
					'Perf test blocked: the preview URL is not a valid same-origin http(s) URL.',
					'performance-optimisation'
				),
			} );
			return;
		}
		setSandboxBusy( true );
		try {
			const res = await runPerformanceScan( scanUrl );
			if ( res && res.success ) {
				notifySandbox( {
					type: 'success',
					message: __(
						'Perf test finished on the production URL. Server-side scans cannot use the admin preview session, so staged settings were not measured — open the admin preview link to verify visually.',
						'performance-optimisation'
					),
				} );
			} else {
				notifySandbox( {
					type: 'error',
					message: __(
						'Perf test failed.',
						'performance-optimisation'
					),
				} );
			}
		} catch {
			notifySandbox( {
				type: 'error',
				message: __( 'Perf test failed.', 'performance-optimisation' ),
			} );
		} finally {
			setSandboxBusy( false );
		}
	};
	const { setIsDirty } = useContext( UnsavedChangesContext );
	// Client-only CDN row ids never enter the baseline: the server payload
	// is id-less, so comparing id-bearing state against an id-bearing
	// baseline would report a permanent dirty state.
	const [ baseline, setBaseline ] = useState( () =>
		stripCdnRowIds( defaultSettings )
	);
	// Baseline is intentionally derived per-key (not per-object-identity)
	// so parent re-renders with an identical payload do not reset the form.
	// A stableStringify deep-compare skips the update when the derived
	// baseline is deep-equal: reference-type option values (arrays/objects)
	// change identity on every parent render, so the "identical payload"
	// claim only holds after a value comparison.
	// Deps are FILE_OPT_SYNC_KEYS mapped over options (non-literal by design;
	// the shared key list is the single source of truth).
	useEffect(
		() => {
			const next = stripCdnRowIds(
				normalizeFileOpt( { ...defaultSettings, ...options } )
			);
			setBaseline( ( prev ) =>
				stableStringify( prev ) === stableStringify( next )
					? prev
					: next
			);
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		FILE_OPT_SYNC_KEYS.map( ( key ) => options[ key ] )
	);
	// Dirty-compare on the id-stripped form state so CDN row bookkeeping
	// never flags the form dirty on its own.
	const settingsForCompare = useMemo(
		() => stripCdnRowIds( settings ),
		[ settings ]
	);
	useUnsavedChanges( settingsForCompare, baseline );

	// Sync local state when parent props change after mount.
	// Per-key deps (not object identity) plus an empty-options guard plus a
	// deep-compare skip so parent re-renders with an identical payload — or
	// a replacement of the global settings object while the user is editing —
	// do not merge saved values over in-progress edits.
	// Mirrors PreloadSettings/ImageOptimization.
	// Deps are FILE_OPT_SYNC_KEYS mapped over options (non-literal by design).
	useEffect(
		() => {
			if ( ! options || Object.keys( options ).length === 0 ) {
				return;
			}
			setSettings( ( prev ) => {
				// Shared normalizeFileOpt(): every textarea-backed key routes
				// through toTextLines() after spread, so backend arrays join
				// (never drop to '' or reach a controlled textarea), matching
				// init and baseline (#1217 review, issue #1259 review).
				const merged = normalizeFileOpt( {
					...prev,
					...options,
				} );
				// Deterministic index ids re-mint the same keys for the same
				// order, so synced server rows keep focus instead of
				// remounting.
				merged.cdnMapping = withCdnRowIds( merged.cdnMapping );
				return stableStringify( stripCdnRowIds( merged ) ) ===
					stableStringify( stripCdnRowIds( prev ) )
					? prev
					: merged;
			} );
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		FILE_OPT_SYNC_KEYS.map( ( key ) => options[ key ] )
	);

	// INP-first preset (#932): one-click idle + viewport delay with 60s
	// heartbeat. Enabling fills delayJS/strategy/heartbeat client-side (only
	// when still on their defaults); disabling leaves manual values intact so
	// the interaction-only default + manual lists act as fallback.
	const handleINPPresetToggle = ( e ) => {
		const checked = e.target.checked;
		setSettings( ( prev ) => {
			const next = { ...prev, delayJSINPPreset: checked };
			if ( checked ) {
				next.delayJS = true;
				if (
					! prev.delayJSDefaultStrategy ||
					'interaction' === prev.delayJSDefaultStrategy
				) {
					next.delayJSDefaultStrategy = 'idle';
				}
				if (
					! prev.heartbeatControl ||
					'default' === prev.heartbeatControl
				) {
					next.heartbeatControl = '60s';
				}
			}
			return next;
		} );
	};

	// LiteSpeed integration (Phase 1 — safe coexistence).
	const litespeedInfo =
		typeof wppoSettings !== 'undefined' ? wppoSettings?.litespeed : null;
	const isLiteSpeed = !! litespeedInfo?.detected;
	const optimizerDisabled = !! litespeedInfo?.optimizer_disabled;
	const effectiveMode = litespeedInfo?.effective_mode || 'standalone';
	const lscacheActive = !! litespeedInfo?.lscache_active;
	const liteSpeedModeFromSettings =
		typeof wppoSettings !== 'undefined'
			? wppoSettings?.settings?.litespeed_integration?.mode || 'auto'
			: 'auto';
	const [ litespeedMode, setLitespeedMode ] = useState(
		liteSpeedModeFromSettings
	);
	useEffect( () => {
		setLitespeedMode( liteSpeedModeFromSettings );
	}, [ liteSpeedModeFromSettings ] );
	const [ savingLiteSpeed, setSavingLiteSpeed ] = useState( false );
	const pausedTooltip = __(
		'Paused — LiteSpeed Cache owns optimisation (change in Network → LiteSpeed)',
		'performance-optimisation'
	);
	const effectiveLabel = modeLabel( effectiveMode );
	const effectiveBadgeClass =
		effectiveMode === 'litespeed'
			? 'wppo-status-badge--warning'
			: 'wppo-status-badge--good';
	// Shared 'none' drop-in fallback label (single source so a future
	// textdomain/key change needs one edit, not two).
	const noneLabel = __( 'none', 'performance-optimisation' );

	const handleSaveLiteSpeedMode = async () => {
		setSavingLiteSpeed( true );
		try {
			const res = await apiCall( 'update_settings', {
				tab: 'litespeed_integration',
				settings: { mode: litespeedMode },
			} );
			if ( res.success ) {
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'LiteSpeed settings saved.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
				// Mutate global so Dashboard banner + next mount reflect new mode without reload.
				if ( res.data ) {
					commitSettingsCache( res.data );
				}
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to save LiteSpeed settings.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error( 'LiteSpeed save failed', getErrorLogMessage( err ) );
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setSavingLiteSpeed( false );
		}
	};

	const withNotification = async (
		apiCallPromise,
		successMessage,
		errorMessage
	) => {
		// Regen-scoped busy flag: the Save button stays interactive while a
		// background regen runs.
		setIsRegenerating( true );
		dismiss();

		try {
			const res = await apiCallPromise;
			if ( res.success ) {
				notify( {
					type: 'success',
					message: res.message || successMessage,
					durationMs: 3000,
				} );
			} else {
				notify( {
					type: 'error',
					message: res.message || errorMessage,
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error( errorMessage, getErrorLogMessage( err ) );
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setIsRegenerating( false );
		}
	};

	const handleRegenerateCss = async () => {
		await withNotification(
			( async () => {
				const res = await apiCall( 'regenerate_ccss' );
				if ( res?.success && onCcssRefresh ) {
					onCcssRefresh();
				}
				return res;
			} )(),
			__(
				'Critical CSS regeneration queued.',
				'performance-optimisation'
			),
			__(
				'Failed to regenerate critical CSS.',
				'performance-optimisation'
			)
		);
	};

	const handleRegenerateUsedCSS = async () => {
		setIsRegenerating( true );
		dismiss();
		try {
			const saveRes = await apiCall( 'update_settings', {
				tab: 'file_optimisation',
				settings: stripCdnRowIds( { ...settings } ),
			} );
			if ( ! saveRes.success ) {
				notify( {
					type: 'error',
					message:
						saveRes.message ||
						__(
							'Failed to regenerate used CSS.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
				return;
			}
			setBaseline( stripCdnRowIds( { ...settings } ) );
			setIsDirty( false );
			const res = await apiCall( 'used_css_regenerate' );
			if ( res.success ) {
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'Used CSS regeneration queued.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
				// Refresh the staleness banner so it does not linger after a
				// successful regen until remount (issue #1220).
				refreshUsedCssStatus();
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to regenerate used CSS.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error(
				'Failed to regenerate used CSS.',
				getErrorLogMessage( err )
			);
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setIsRegenerating( false );
		}
	};

	const handlePurgeUsedCssCache = async () => {
		setIsPurging( true );
		dismissPurge();
		try {
			const res = await apiCall( 'purge_used_css_cache' );
			if ( res.success ) {
				notifyPurge( {
					type: 'success',
					message:
						res.message ||
						__(
							'Page cache and used CSS purged.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
				// A purge invalidates used-CSS, so the staleness banner must
				// reflect the new state immediately (issue #1220).
				refreshUsedCssStatus();
			} else {
				notifyPurge( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to purge page cache and used CSS.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error(
				'Failed to purge page cache and used CSS.',
				getErrorLogMessage( err )
			);
			notifyPurge( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setIsPurging( false );
		}
	};

	const [ singlePostId, setSinglePostId ] = useState( '' );
	const [ singleTemplate, setSingleTemplate ] = useState( '' );

	const handleRegenerateSingleCcss = async ( hash ) => {
		// Defense-in-depth before hitting the privileged regenerate_ccss
		// route: trim, cap length, and reject control characters. An empty
		// input notifies instead of silently returning (dead click).
		const raw = String( hash ?? singleTemplate ?? '' )
			.trim()
			.slice( 0, 200 );
		if ( '' === raw || /[\u0000-\u001F\u007F]/.test( raw ) ) {
			notify( {
				type: 'error',
				message: __(
					'Enter a template to regenerate.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
			return;
		}
		const template = raw;
		// Gate feedback on the queued count (issue #1274 review): a
		// skipped/unknown template returns success with queued:0, which
		// must surface the server's distinct message (info) instead of
		// the generic "queued" success toast.
		setIsRegenerating( true );
		dismiss();
		try {
			const res = await apiCall( 'regenerate_ccss', { template } );
			// A missing queued key is not an explicit 0: the server always
			// sends queued on this route, so a missing key means an
			// unexpected envelope — treat it as success, not as a skip.
			// Any positive count is success (single-template calls queue
			// exactly one; >= 1 also covers multi-queue responses).
			const queued = res?.data?.queued;
			if (
				res?.success &&
				( undefined === queued || Number( queued ) >= 1 )
			) {
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'Critical CSS regeneration queued for template.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			} else if ( res?.success ) {
				notify( {
					type: 'info',
					message:
						res.message ||
						__(
							'Template skipped: nothing queued.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res?.message ||
						__(
							'Failed to regenerate critical CSS for template.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
				return;
			}
			if ( onCcssRefresh ) {
				onCcssRefresh();
			}
		} catch ( err ) {
			console.error(
				'Failed to regenerate CCSS for template',
				getErrorLogMessage( err )
			);
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setIsRegenerating( false );
		}
	};

	const handleRegenerateSingleUsedCss = async () => {
		const postId = parseInt( singlePostId, 10 );
		if ( ! Number.isFinite( postId ) || postId <= 0 ) {
			notify( {
				type: 'error',
				message: __(
					'Enter a valid post ID to regenerate.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
			return;
		}
		setIsRegenerating( true );
		dismiss();
		try {
			const res = await apiCall( 'used_css_regenerate', {
				post_id: postId,
			} );
			notify( {
				type: res.success ? 'success' : 'error',
				message:
					res.message ||
					( res.success
						? __(
								'Used CSS regeneration queued.',
								'performance-optimisation'
						  )
						: __(
								'Failed to regenerate used CSS.',
								'performance-optimisation'
						  ) ),
				durationMs: 3000,
			} );
			if ( res.success ) {
				refreshUsedCssStatus();
			}
		} catch ( err ) {
			console.error(
				'Failed to regenerate used CSS for post.',
				getErrorLogMessage( err )
			);
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setIsRegenerating( false );
		}
	};

	const handleSubmit = async ( e ) => {
		if ( e ) {
			e.preventDefault();
		}
		setIsSaving( true );
		dismiss();
		try {
			const res = await apiCall( 'update_settings', {
				tab: 'file_optimisation',
				// Strip client-only CDN row ids: they are React keys, not
				// settings, and must never persist server-side.
				settings: stripCdnRowIds( { ...settings } ),
			} );
			if ( res.success ) {
				setBaseline( stripCdnRowIds( { ...settings } ) );
				setIsDirty( false );
				notify( {
					type: 'success',
					message:
						res.message ||
						__(
							'Settings updated successfully.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			} else {
				notify( {
					type: 'error',
					message:
						res.message ||
						__(
							'Failed to update settings.',
							'performance-optimisation'
						),
					durationMs: 3000,
				} );
			}
		} catch ( err ) {
			console.error(
				'Failed to update settings.',
				getErrorLogMessage( err )
			);
			notify( {
				type: 'error',
				message: __(
					'An unexpected error occurred.',
					'performance-optimisation'
				),
				durationMs: 3000,
			} );
		} finally {
			setIsSaving( false );
		}
	};

	const subTabs = useMemo(
		() => [
			{
				id: 'assets',
				label: __( 'Assets', 'performance-optimisation' ),
				icon: faCode,
			},
			{
				id: 'scripts',
				label: __( 'Scripts', 'performance-optimisation' ),
				icon: faRocket,
			},
			{
				id: 'ecommerce',
				label: __( 'E-Commerce', 'performance-optimisation' ),
				icon: faStore,
			},
			{
				id: 'network',
				label: __( 'Network', 'performance-optimisation' ),
				icon: faServer,
			},
			{
				id: 'core',
				label: __( 'Core', 'performance-optimisation' ),
				icon: faShieldAlt,
			},
		],
		[]
	);
	const handleSubTabSelect = useCallback( ( id ) => {
		setActiveSubTab( id );
	}, [] );
	const handleSubTabKeyDown = useCallback(
		( e, index ) => {
			let nextIndex;
			if ( e.key === 'ArrowRight' ) {
				nextIndex = ( index + 1 ) % subTabs.length;
			} else if ( e.key === 'ArrowLeft' ) {
				nextIndex = ( index - 1 + subTabs.length ) % subTabs.length;
			} else if ( e.key === 'Home' ) {
				nextIndex = 0;
			} else if ( e.key === 'End' ) {
				nextIndex = subTabs.length - 1;
			} else {
				return;
			}

			e.preventDefault();
			const nextTab = subTabs[ nextIndex ];
			setActiveSubTab( nextTab.id );

			// Move focus to the next button.
			const nextButton = tabRefs.current[ nextTab.id ];
			if ( nextButton ) {
				nextButton.focus();
			}
		},
		[ subTabs ]
	);

	return (
		<div className="wppo-dashboard-view">
			<FeatureHeader
				title={ __( 'File Optimisation', 'performance-optimisation' ) }
				description={ __(
					'Fine-tune how your site delivers CSS, JS, and HTML for maximum performance.',
					'performance-optimisation'
				) }
				actions={
					<LoadingSubmitButton
						className="wppo-button wppo-button--primary"
						isLoading={ isSaving }
						onClick={ handleSubmit }
						label={ __(
							'Save Settings',
							'performance-optimisation'
						) }
					/>
				}
			>
				{ notice && (
					<NoticeBanner
						type={ notice.type }
						message={ notice.message }
						className="wppo-mb-20"
						onDismiss={ dismiss }
					/>
				) }

				<div className="wppo-sub-tabs" role="tablist">
					{ subTabs.map( ( tab, index ) => (
						<SubTabButton
							key={ tab.id }
							tab={ tab }
							index={ index }
							isActive={ activeSubTab === tab.id }
							tabRef={ getTabRef( tab.id ) }
							onSelect={ handleSubTabSelect }
							onKeyDown={ handleSubTabKeyDown }
						/>
					) ) }
				</div>
			</FeatureHeader>

			<div className="wppo-tab-content">
				{ activeSubTab === 'assets' && (
					<div
						id="panel-assets"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-assets"
					>
						<FeatureCard
							title={ __(
								'CSS Optimisation',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							{ optimizerDisabled && (
								<div className="wppo-notice wppo-notice--warning wppo-mb-12">
									<FontAwesomeIcon
										icon={ faExclamationTriangle }
									/>{ ' ' }
									{ __(
										'Optimisation paused — LiteSpeed Cache owns CSS/JS optimisation (change in Network → LiteSpeed).',
										'performance-optimisation'
									) }
								</div>
							) }
							<div className="wppo-field-group">
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Minify CSS',
											'performance-optimisation'
										) }
										description={ __(
											'Remove whitespace and comments from stylesheets to reduce file size.',
											'performance-optimisation'
										) }
										name="minifyCSS"
										checked={ settings.minifyCSS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Combine CSS',
											'performance-optimisation'
										) }
										description={ __(
											'Merge all CSS files into a single file to reduce the number of HTTP requests.',
											'performance-optimisation'
										) }
										name="combineCSS"
										checked={ settings.combineCSS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.combineCSS && (
									<div className="wppo-notice wppo-notice--warning wppo-mt-12">
										<FontAwesomeIcon
											icon={ faExclamationTriangle }
										/>{ ' ' }
										{ __(
											'May cause FOUC — test in incognito and exclude problematic files above.',
											'performance-optimisation'
										) }
									</div>
								) }
								{ settings.combineCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeCombineCSS"
										>
											{ __(
												'Exclude from Combining',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="excludeCombineCSS"
											name="excludeCombineCSS"
											rows="3"
											placeholder={ __(
												'e.g. handle-name or /wp-content/…/style.css',
												'performance-optimisation'
											) }
											value={ settings.excludeCombineCSS }
											onChange={ onFieldChange }
											aria-describedby="excludeCombineCSS-desc"
										/>
										<p
											id="excludeCombineCSS-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'One handle or partial URL per line. Fix FOUC by excluding problematic files.',
												'performance-optimisation'
											) }
										</p>
									</div>
								) }
								<Tooltip
									content={
										optimizerDisabled
											? pausedTooltip
											: __(
													'Removes CSS rules not used on the current page, similar to PurgeCSS. Reduces page weight significantly.',
													'performance-optimisation'
											  )
									}
								>
									<SwitchField
										label={ __(
											'Remove Unused CSS',
											'performance-optimisation'
										) }
										description={ __(
											'Scan pages and remove CSS rules that are not used. Reduces file size by 30–80% and helps pass PageSpeed audits.',
											'performance-optimisation'
										) }
										name="removeUnusedCSS"
										checked={ settings.removeUnusedCSS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.removeUnusedCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeUnusedCSS"
										>
											{ __(
												'Safelist Selectors',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="excludeUnusedCSS"
											name="excludeUnusedCSS"
											rows="4"
											placeholder={ __(
												'e.g. .my-dynamic-class',
												'performance-optimisation'
											) }
											value={ settings.excludeUnusedCSS }
											onChange={ onFieldChange }
											aria-describedby="excludeUnusedCSS-desc"
										/>
										<p
											id="excludeUnusedCSS-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'One selector per line — kept even if unused. Use to fix missing styles.',
												'performance-optimisation'
											) }
										</p>
										<label
											className="wppo-field-label wppo-mt-16"
											htmlFor="unusedCSSSafelistExtra"
										>
											{ __(
												'Extra Safelist (builders / dynamic)',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="unusedCSSSafelistExtra"
											name="unusedCSSSafelistExtra"
											rows="3"
											placeholder={ __(
												'e.g. .elementor-widget-container',
												'performance-optimisation'
											) }
											value={
												settings.unusedCSSSafelistExtra
											}
											onChange={ onFieldChange }
											aria-describedby="unusedCSSSafelistExtra-desc"
										/>
										<p
											id="unusedCSSSafelistExtra-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'Additional selectors always preserved — use for builder or JS-injected classes.',
												'performance-optimisation'
											) }
										</p>
										<label
											className="wppo-field-label wppo-mt-16"
											htmlFor="usedCSSExcludeUrls"
										>
											{ __(
												'Disable Used CSS on these URLs',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="usedCSSExcludeUrls"
											name="usedCSSExcludeUrls"
											rows="3"
											placeholder={ __(
												'e.g. /checkout/',
												'performance-optimisation'
											) }
											value={
												settings.usedCSSExcludeUrls
											}
											onChange={ onFieldChange }
											aria-describedby="usedCSSExcludeUrls-desc"
										/>
										<p
											id="usedCSSExcludeUrls-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'One per line — URL substring or #regex#. Used CSS is skipped on matching URLs only.',
												'performance-optimisation'
											) }
										</p>
										<div className="wppo-mt-16">
											<SwitchField
												label={ __(
													'Visual regression guard',
													'performance-optimisation'
												) }
												description={ __(
													'Fall back to the full stylesheet when trimming keeps too little CSS (over-aggressive purge).',
													'performance-optimisation'
												) }
												name="unusedCSSRegressionGuard"
												checked={
													settings.unusedCSSRegressionGuard
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
										</div>
										{ settings.unusedCSSRegressionGuard && (
											<>
												<label
													className="wppo-field-label wppo-mt-16"
													htmlFor="unusedCSSRegressionThreshold"
												>
													{ __(
														'Minimum Retained CSS (%)',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													type="number"
													inputMode="numeric"
													id="unusedCSSRegressionThreshold"
													name="unusedCSSRegressionThreshold"
													min="5"
													max="50"
													step="1"
													value={
														settings.unusedCSSRegressionThreshold
													}
													onChange={ onFieldChange }
													aria-describedby="unusedCSSRegressionThreshold-desc"
												/>
												<p
													id="unusedCSSRegressionThreshold-desc"
													className="wppo-text-muted wppo-text-small wppo-mt-8"
												>
													{ __(
														'Below this retained percentage the full stylesheet is served instead (5–50, default: 20).',
														'performance-optimisation'
													) }
												</p>
											</>
										) }
										<label
											className="wppo-field-label wppo-mt-16"
											htmlFor="usedCSSDeliveryMode"
										>
											{ __(
												'Used CSS Delivery Mode',
												'performance-optimisation'
											) }
										</label>
										<select
											className="wppo-select"
											id="usedCSSDeliveryMode"
											name="usedCSSDeliveryMode"
											value={
												settings.usedCSSDeliveryMode ||
												'file'
											}
											onChange={ onFieldChange }
											aria-describedby="usedCSSDeliveryMode-desc"
										>
											<option value="file">
												{ __(
													'File (render-blocking used CSS)',
													'performance-optimisation'
												) }
											</option>
											<option value="delay">
												{ __(
													'Delay (full CSS on interaction)',
													'performance-optimisation'
												) }
											</option>
											<option value="async">
												{ __(
													'Async (preload + swap)',
													'performance-optimisation'
												) }
											</option>
											<option value="remove">
												{ __(
													'Remove (strip full CSS, auto-downgrades on builders)',
													'performance-optimisation'
												) }
											</option>
										</select>
										<p
											id="usedCSSDeliveryMode-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'File and Delay never serve unstyled pages on a cache miss — the full stylesheet is served instead. Remove auto-downgrades to Delay on builder pages.',
												'performance-optimisation'
											) }
										</p>
										{ usedCssStatus &&
											usedCssStatus.is_stale && (
												<NoticeBanner
													type="warning"
													className="wppo-mt-12"
													message={
														usedCssStatus.last_regen_human
															? sprintf(
																	// translators: %s: last used-CSS regeneration time.
																	__(
																		'Used CSS looks stale — last regenerated at %s. Builder or theme updates may have outrun regeneration; use Regenerate Used CSS below.',
																		'performance-optimisation'
																	),
																	usedCssStatus.last_regen_human
															  )
															: __(
																	'Used CSS looks stale — it has never been regenerated. Use Regenerate Used CSS below.',
																	'performance-optimisation'
															  )
													}
												/>
											) }
										<NoticeBanner
											type="info"
											className="wppo-mt-12"
											message={ __(
												'Smoke test: verify key pages in a logged-out (incognito) window after enabling — used CSS is generated from the logged-out view.',
												'performance-optimisation'
											) }
										/>
										{ purgeNotice && (
											<NoticeBanner
												type={ purgeNotice.type }
												message={ purgeNotice.message }
												onDismiss={ dismissPurge }
											/>
										) }
										<button
											className="wppo-button wppo-button--secondary wppo-mt-12"
											onClick={ handleRegenerateUsedCSS }
											type="button"
											disabled={ isRegenerating }
										>
											{ __(
												'Regenerate Used CSS',
												'performance-optimisation'
											) }
										</button>
										<button
											className="wppo-button wppo-button--secondary wppo-mt-12"
											onClick={ handlePurgeUsedCssCache }
											type="button"
											disabled={ isPurging }
										>
											{ __(
												'Purge Page Cache + Used CSS',
												'performance-optimisation'
											) }
										</button>
										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="wppoSinglePostId"
											>
												{ __(
													'Regenerate Used CSS for Post ID',
													'performance-optimisation'
												) }
											</label>
											<div className="wppo-inline-row">
												<input
													className="wppo-input"
													type="number"
													inputMode="numeric"
													id="wppoSinglePostId"
													min="1"
													step="1"
													placeholder={ __(
														'123',
														'performance-optimisation'
													) }
													value={ singlePostId }
													onChange={ ( e ) =>
														setSinglePostId(
															e.target.value
														)
													}
												/>
												<button
													className="wppo-button wppo-button--secondary"
													type="button"
													disabled={ isRegenerating }
													onClick={
														handleRegenerateSingleUsedCss
													}
												>
													{ __(
														'Regenerate Post',
														'performance-optimisation'
													) }
												</button>
											</div>
											<p className="wppo-text-muted wppo-mt-8 wppo-text-small">
												{ __(
													'Builder-template post types are skipped automatically.',
													'performance-optimisation'
												) }
											</p>
										</div>
									</div>
								) }
								{ settings.minifyCSS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeCSS"
										>
											{ __(
												'Exclude CSS from Minification',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="excludeCSS"
											name="excludeCSS"
											rows="3"
											placeholder={ __(
												'e.g. handle-name or /wp-content/…/critical.css',
												'performance-optimisation'
											) }
											value={ settings.excludeCSS }
											onChange={ onFieldChange }
											aria-describedby="excludeCSS-desc"
										/>
										<p
											id="excludeCSS-desc"
											className="wppo-text-muted wppo-text-small wppo-mt-8"
										>
											{ __(
												'One handle or partial URL per line.',
												'performance-optimisation'
											) }
										</p>
									</div>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Critical CSS',
											'performance-optimisation'
										) }
										description={ __(
											'Generate and inline above-the-fold CSS, then defer full stylesheets. Improves FCP and LCP by eliminating render-blocking CSS.',
											'performance-optimisation'
										) }
										name="criticalCSS"
										checked={ settings.criticalCSS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.criticalCSS && (
									<>
										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="ccssMaxSize"
											>
												{ __(
													'Critical CSS Max Size (bytes)',
													'performance-optimisation'
												) }
											</label>
											<input
												className="wppo-input"
												type="number"
												inputMode="numeric"
												id="ccssMaxSize"
												name="ccssMaxSize"
												min="1024"
												max="102400"
												step="1024"
												value={ settings.ccssMaxSize }
												onChange={ onFieldChange }
												aria-describedby="ccssMaxSize-desc"
											/>
											<p
												id="ccssMaxSize-desc"
												className="wppo-text-muted wppo-mt-8 wppo-text-small"
											>
												{ __(
													'Inline output above this size is served from a per-template file with cache busting instead (default: 20480).',
													'performance-optimisation'
												) }
											</p>
										</div>
										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="ccssSafelistExtra"
											>
												{ __(
													'Critical CSS Safelist',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
												id="ccssSafelistExtra"
												name="ccssSafelistExtra"
												rows="3"
												placeholder={ __(
													'e.g. .modal-open',
													'performance-optimisation'
												) }
												value={
													typeof settings.ccssSafelistExtra ===
													'string'
														? settings.ccssSafelistExtra
														: ''
												}
												onChange={ onFieldChange }
												aria-describedby="ccssSafelistExtra-desc"
											/>
											<p
												id="ccssSafelistExtra-desc"
												className="wppo-text-muted wppo-mt-8 wppo-text-small"
											>
												{ __(
													'One selector per line — always kept in Critical CSS, even when not above the fold. Use for hidden or JS-injected selectors. Empty keeps current behaviour.',
													'performance-optimisation'
												) }
											</p>
										</div>
										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="ccssExcludedPostTypes"
											>
												{ __(
													'Excluded Post Types (Critical / Used CSS)',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
												id="ccssExcludedPostTypes"
												name="ccssExcludedPostTypes"
												rows="2"
												placeholder={ __(
													'fl-builder-template, elementor_library',
													'performance-optimisation'
												) }
												value={
													typeof settings.ccssExcludedPostTypes ===
													'string'
														? settings.ccssExcludedPostTypes
														: ''
												}
												onChange={ onFieldChange }
												aria-describedby="ccssExcludedPostTypes-desc"
											/>
											<p
												id="ccssExcludedPostTypes-desc"
												className="wppo-text-muted wppo-mt-8 wppo-text-small"
											>
												{ __(
													'One post type per line or comma-separated — builder templates are skipped, never error-looped. Empty keeps the builder defaults.',
													'performance-optimisation'
												) }
											</p>
										</div>
										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="ccssMaxRetries"
											>
												{ __(
													'Critical CSS Max Retries',
													'performance-optimisation'
												) }
											</label>
											<input
												className="wppo-input"
												type="number"
												inputMode="numeric"
												id="ccssMaxRetries"
												name="ccssMaxRetries"
												min="0"
												max="5"
												step="1"
												value={ normalizeRetries(
													settings.ccssMaxRetries
												) }
												onChange={ onFieldChange }
												aria-describedby="ccssMaxRetries-desc"
											/>
											<p
												id="ccssMaxRetries-desc"
												className="wppo-text-muted wppo-mt-8 wppo-text-small"
											>
												{ __(
													'Consecutive failures before a template is marked failed (0–5, default 5).',
													'performance-optimisation'
												) }
											</p>
										</div>
										<div className="wppo-field wppo-mt-16">
											<label
												className="wppo-field-label"
												htmlFor="wppoSingleTemplate"
											>
												{ __(
													'Regenerate Critical CSS for Template',
													'performance-optimisation'
												) }
											</label>
											<div className="wppo-inline-row">
												<input
													className="wppo-input"
													type="text"
													id="wppoSingleTemplate"
													placeholder={ __(
														'single',
														'performance-optimisation'
													) }
													value={ singleTemplate }
													onChange={ ( e ) =>
														setSingleTemplate(
															e.target.value
														)
													}
												/>
												<button
													className="wppo-button wppo-button--secondary"
													type="button"
													onClick={ () =>
														handleRegenerateSingleCcss()
													}
												>
													{ __(
														'Regenerate Template',
														'performance-optimisation'
													) }
												</button>
											</div>
										</div>
										{ ccssError && (
											<div className="wppo-notice wppo-notice--error">
												<span>
													{ __(
														'Unable to load Critical CSS status.',
														'performance-optimisation'
													) }
												</span>
												{ onCcssRetry && (
													<button
														className="wppo-button wppo-button--secondary"
														onClick={ onCcssRetry }
													>
														{ __(
															'Retry',
															'performance-optimisation'
														) }
													</button>
												) }
											</div>
										) }
										<CriticalCssPanel
											status={ ccssStatus }
											onRegenerate={ handleRegenerateCss }
											onRegenerateSingle={
												handleRegenerateSingleCcss
											}
										/>
									</>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Host Google Fonts Locally',
											'performance-optimisation'
										) }
										description={ __(
											'Automatically detect Google Fonts and serve them from your own server. Eliminates external DNS lookups, improves GDPR compliance, and applies font-display: swap.',
											'performance-optimisation'
										) }
										name="hostGoogleFontsLocally"
										checked={
											settings.hostGoogleFontsLocally
										}
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Font Metric Fallback',
											'performance-optimisation'
										) }
										description={ __(
											'Inject metric-matched system fallback (size-adjust/ascent-override) to reduce CLS when Google Fonts load.',
											'performance-optimisation'
										) }
										name="fontMetricFallback"
										checked={ settings.fontMetricFallback }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Font Subsetting',
											'performance-optimisation'
										) }
										description={ __(
											'Keep only selected unicode subsets (default: latin) in self-hosted Google Fonts CSS to reduce font weight. Off by default.',
											'performance-optimisation'
										) }
										name="fontSubset"
										checked={ settings.fontSubset }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.fontSubset && (
									<div className="wppo-field wppo-mt-16">
										<label
											className="wppo-field-label"
											htmlFor="fontSubsetSubsets"
										>
											{ __(
												'Font Subsets',
												'performance-optimisation'
											) }
										</label>
										<input
											className="wppo-input"
											type="text"
											id="fontSubsetSubsets"
											name="fontSubsetSubsets"
											value={
												typeof settings.fontSubsetSubsets ===
												'string'
													? settings.fontSubsetSubsets
													: 'latin'
											}
											onChange={ onFieldChange }
											disabled={ optimizerDisabled }
											aria-describedby="fontSubsetSubsets-desc"
										/>
										<p
											id="fontSubsetSubsets-desc"
											className="wppo-text-muted wppo-mt-8 wppo-text-small"
										>
											{ __(
												'Comma-separated unicode subsets to keep (e.g. latin,latin-ext).',
												'performance-optimisation'
											) }
										</p>
									</div>
								) }
							</div>
						</FeatureCard>

						<FeatureCard
							title={ __(
								'HTML Optimisation',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faCode } /> }
						>
							<div className="wppo-field-group">
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Minify HTML',
											'performance-optimisation'
										) }
										description={ __(
											'Compress the HTML output of your website by removing unnecessary whitespace and comments.',
											'performance-optimisation'
										) }
										name="minifyHTML"
										checked={ settings.minifyHTML }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<SwitchField
									label={ __(
										'Remove HTML Comments',
										'performance-optimisation'
									) }
									description={ __(
										'Strip HTML comments from the output (except IE conditional comments).',
										'performance-optimisation'
									) }
									name="removeHTMLComments"
									checked={ settings.removeHTMLComments }
									onChange={ onFieldChange }
								/>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Minify Inline CSS',
											'performance-optimisation'
										) }
										description={ __(
											'Minify CSS within <style> tags using the PHP minifier.',
											'performance-optimisation'
										) }
										name="minifyInlineCSS"
										checked={ settings.minifyInlineCSS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Minify Inline JavaScript',
											'performance-optimisation'
										) }
										description={ __(
											'Minify JavaScript within <script> tags using the PHP minifier.',
											'performance-optimisation'
										) }
										name="minifyInlineJS"
										checked={ settings.minifyInlineJS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'scripts' && (
					<div
						id="panel-scripts"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-scripts"
					>
						<FeatureCard
							title={ __(
								'JavaScript Loading',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faRocket } /> }
						>
							{ optimizerDisabled && (
								<div className="wppo-notice wppo-notice--warning wppo-mb-12">
									<FontAwesomeIcon
										icon={ faExclamationTriangle }
									/>{ ' ' }
									{ __(
										'Optimisation paused — LiteSpeed Cache owns JS optimisation (change in Network → LiteSpeed).',
										'performance-optimisation'
									) }
								</div>
							) }
							<div className="wppo-field-group">
								<SwitchField
									label={ __(
										'Safe mode — one-click recovery',
										'performance-optimisation'
									) }
									description={ __(
										'Instantly disable Delay JS, Defer JS and Remove Unused CSS without losing their settings. Turn off to restore your previous configuration. Per-page disables and ?nocache also bypass these optimisations.',
										'performance-optimisation'
									) }
									name="safeMode"
									checked={ settings.safeMode }
									onChange={ onFieldChange }
									disabled={ optimizerDisabled }
								/>
								{ settings.safeMode && (
									<NoticeBanner
										type="warning"
										message={ __(
											'Safe mode is on — Delay, Defer and Used CSS are paused. Your settings are preserved.',
											'performance-optimisation'
										) }
									/>
								) }
								<div className="wppo-field wppo-upgrade-purge">
									<h4
										className="wppo-field-label"
										id="wppo-upgrade-purge-heading"
									>
										{ __(
											'Upgrade safety — auto-purge on update',
											'performance-optimisation'
										) }
									</h4>
									<p
										className="wppo-field-description"
										id="wppo-upgrade-purge-desc"
									>
										{ __(
											'Plugin, theme and core updates auto-clear the page cache, purge used and critical CSS, and bump combined-asset versions so the first visit never shows unstyled content. Use the safe preview link to verify styled output (it bypasses minify via ?wppo_nocache=1).',
											'performance-optimisation'
										) }
									</p>
									{ upgradePurge &&
										upgradePurge.last_purge &&
										upgradePurge.last_purge.reason && (
											<NoticeBanner
												type="info"
												message={ sprintf(
													// translators: %s: last purge reason.
													__(
														'Last purge: %s',
														'performance-optimisation'
													),
													upgradePurge.last_purge
														.reason
												) }
											/>
										) }
									{ derivedPurgeNotice && (
										<NoticeBanner
											type={ derivedPurgeNotice.type }
											message={
												derivedPurgeNotice.message
											}
											onDismiss={ dismissDerivedPurge }
										/>
									) }
									<div
										className="wppo-sandbox-actions"
										aria-describedby="wppo-upgrade-purge-desc"
									>
										<LoadingSubmitButton
											type="button"
											className="wppo-button wppo-button--secondary"
											isLoading={ isPurgingDerived }
											onClick={ handlePurgeDerivedCaches }
											label={ __(
												'Purge Derived Caches',
												'performance-optimisation'
											) }
										/>
										{ upgradePurge &&
											upgradePurge.safe_preview_url &&
											isSafeHttpUrl(
												upgradePurge.safe_preview_url
											) && (
												<a
													className="wppo-button wppo-button--secondary"
													href={
														upgradePurge.safe_preview_url
													}
													target="_blank"
													rel="noopener noreferrer"
												>
													{ __(
														'Open safe preview (bypasses minify)',
														'performance-optimisation'
													) }
												</a>
											) }
									</div>
								</div>
								<SwitchField
									label={ __(
										'Elementor-safe mode — builder-proof by default',
										'performance-optimisation'
									) }
									description={ __(
										'Keep Combine CSS off on Elementor-built pages and auto-purge the page cache when Elementor regenerates its CSS. Stage risky changes via Sandbox preview before promoting.',
										'performance-optimisation'
									) }
									name="elementorSafeMode"
									checked={ !! settings.elementorSafeMode }
									onChange={ onFieldChange }
									disabled={ optimizerDisabled }
								/>
								{ !! settings.elementorSafeMode && (
									<NoticeBanner
										type="info"
										message={ __(
											'Elementor-safe mode is on — Combine CSS steps aside on builder pages and Elementor CSS regens auto-purge the affected page.',
											'performance-optimisation'
										) }
									/>
								) }
								<div className="wppo-field wppo-sandbox-preview">
									<p className="wppo-field-label">
										{ __(
											'Sandbox preview — test Delay / Defer / Combine / Elementor-safe safely',
											'performance-optimisation'
										) }
									</p>
									<p className="wppo-field-description">
										{ __(
											'Stage the current Delay, Defer and Combine settings, preview them as admin via a no-cache link (visitors keep production markup), then promote or discard. The perf test below always measures the production URL; staged settings are verified visually via the admin preview link.',
											'performance-optimisation'
										) }
									</p>
									{ sandboxNotice && (
										<NoticeBanner
											type={ sandboxNotice.type }
											message={ sandboxNotice.message }
											onDismiss={ dismissSandbox }
										/>
									) }
									<div className="wppo-sandbox-actions">
										<button
											type="button"
											className="wppo-button wppo-button--secondary"
											onClick={ handleSandboxSave }
											disabled={ sandboxBusy }
										>
											{ __(
												'Stage preview',
												'performance-optimisation'
											) }
										</button>
										{ sandboxPreviewUrl &&
											( isSafeHttpUrl(
												sandboxPreviewUrl
											) ? (
												<a
													className="wppo-button wppo-button--secondary"
													href={ sandboxPreviewUrl }
													target="_blank"
													rel="noopener noreferrer"
												>
													{ __(
														'Open admin preview',
														'performance-optimisation'
													) }
												</a>
											) : (
												<span
													className="wppo-button wppo-button--secondary"
													aria-disabled="true"
												>
													{ __(
														'Open admin preview',
														'performance-optimisation'
													) }
												</span>
											) ) }
										<button
											type="button"
											className="wppo-button wppo-button--secondary"
											onClick={ handleSandboxPerfTest }
											disabled={
												sandboxBusy ||
												! sandboxPreviewUrl
											}
										>
											{ __(
												'Run perf test (production URL)',
												'performance-optimisation'
											) }
										</button>
										<button
											type="button"
											className="wppo-button wppo-button--primary"
											onClick={ handleSandboxPromote }
											disabled={ sandboxBusy }
										>
											{ __(
												'Promote',
												'performance-optimisation'
											) }
										</button>
										<button
											type="button"
											className="wppo-button wppo-button--secondary"
											onClick={ handleSandboxDiscard }
											disabled={ sandboxBusy }
										>
											{ __(
												'Discard',
												'performance-optimisation'
											) }
										</button>
									</div>
									{ sandboxStaged &&
										Object.keys( sandboxStaged ).length >
											0 && (
											<p className="wppo-field-description">
												{ __(
													'A staged preview exists. Visitors still see production markup.',
													'performance-optimisation'
												) }
											</p>
										) }
									<p className="wppo-field-description">
										{ __(
											'Perf tests run server-side without your admin session, so they always measure the production URL — staged settings are previewed visually via the admin link above, not via the perf test.',
											'performance-optimisation'
										) }
									</p>
								</div>
								{ notice && (
									<NoticeBanner
										type={ notice.type }
										message={ notice.message }
										onDismiss={ dismiss }
									/>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Minify JavaScript',
											'performance-optimisation'
										) }
										description={ __(
											'Compress JS files by removing whitespace and comments to reduce execution time.',
											'performance-optimisation'
										) }
										name="minifyJS"
										checked={ settings.minifyJS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Defer JavaScript',
											'performance-optimisation'
										) }
										description={ __(
											'Load scripts after the page renders to prevent render-blocking and improve page speed.',
											'performance-optimisation'
										) }
										name="deferJS"
										checked={ settings.deferJS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
								{ settings.deferJS && (
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="excludeDeferJS"
										>
											{ __(
												'Exclude from Deferring',
												'performance-optimisation'
											) }
										</label>
										<textarea
											className="wppo-textarea wppo-textarea--mono"
											id="excludeDeferJS"
											name="excludeDeferJS"
											rows="3"
											placeholder={ __(
												'e.g. jquery or /wp-includes/…/script.js',
												'performance-optimisation'
											) }
											value={ settings.excludeDeferJS }
											onChange={ onFieldChange }
										/>
										<p className="wppo-text-muted wppo-text-small wppo-mt-8">
											{ __(
												'One handle or partial URL per line. jQuery, Elementor and WooCommerce handles are excluded by a built-in preset (filter: wppo_defer_js_preset_exclusions).',
												'performance-optimisation'
											) }
										</p>
									</div>
								) }
								<Tooltip
									content={
										optimizerDisabled ? pausedTooltip : ''
									}
								>
									<SwitchField
										label={ __(
											'Delay JavaScript Execution',
											'performance-optimisation'
										) }
										description={ __(
											'Delay all scripts until the user interacts (keyboard/mouse) or load during idle/viewport. Reduces initial CPU usage but may break immediate functionality — test carefully.',
											'performance-optimisation'
										) }
										name="delayJS"
										checked={ settings.delayJS }
										onChange={ onFieldChange }
										disabled={ optimizerDisabled }
									/>
								</Tooltip>
							</div>
						</FeatureCard>

						{ ( settings.minifyJS || settings.delayJS ) && (
							<FeatureCard
								title={ __(
									'Script Rules',
									'performance-optimisation'
								) }
								icon={ <FontAwesomeIcon icon={ faRocket } /> }
							>
								<div className="wppo-field-group">
									{ settings.minifyJS && (
										<div className="wppo-field">
											<label
												className="wppo-field-label"
												htmlFor="excludeJS"
											>
												{ __(
													'Exclude from Minification',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
												id="excludeJS"
												name="excludeJS"
												rows="3"
												placeholder={ __(
													'e.g. handle-name or /wp-content/…/critical.js',
													'performance-optimisation'
												) }
												value={ settings.excludeJS }
												onChange={ onFieldChange }
											/>
											<p className="wppo-text-muted wppo-text-small wppo-mt-8">
												{ __(
													'One handle or partial URL per line.',
													'performance-optimisation'
												) }
											</p>
										</div>
									) }
									{ settings.delayJS && (
										<>
											<SwitchField
												label={ __(
													'INP-first preset: idle + viewport with 60s heartbeat',
													'performance-optimisation'
												) }
												description={ __(
													'One-click preset for better responsiveness. Cart, checkout, and builder previews stay excluded automatically.',
													'performance-optimisation'
												) }
												name="delayJSINPPreset"
												checked={
													settings.delayJSINPPreset
												}
												onChange={
													handleINPPresetToggle
												}
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'External scripts only',
													'performance-optimisation'
												) }
												description={ __(
													'Delay only external scripts (with src). Inline scripts stay un-delayed — safer on builder pages.',
													'performance-optimisation'
												) }
												name="delayJSExternalOnly"
												checked={
													settings.delayJSExternalOnly
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'Delay third-party scripts only',
													'performance-optimisation'
												) }
												description={ __(
													'One-click delay of known third-party scripts (analytics, ads, social, chat, embeds) plus any cross-origin script. First-party scripts stay eager. Cart, checkout and account stay excluded.',
													'performance-optimisation'
												) }
												name="delayJSThirdParty"
												checked={
													settings.delayJSThirdParty
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											{ settings.delayJSThirdParty && (
												<>
													<div className="wppo-field wppo-mt-16">
														<label
															className="wppo-field-label"
															htmlFor="delayJSThirdPartyDenylist"
														>
															{ __(
																'Extra third-party patterns to delay',
																'performance-optimisation'
															) }
														</label>
														<textarea
															className="wppo-textarea wppo-textarea--mono"
															id="delayJSThirdPartyDenylist"
															name="delayJSThirdPartyDenylist"
															rows="3"
															disabled={
																optimizerDisabled
															}
															placeholder={ __(
																'e.g. cdn.example.com/tracker',
																'performance-optimisation'
															) }
															value={
																settings.delayJSThirdPartyDenylist
															}
															onChange={
																onFieldChange
															}
														/>
														<p className="wppo-text-muted wppo-text-small wppo-mt-8">
															{ __(
																'One per line — added to the curated built-in denylist. Empty uses the built-in list.',
																'performance-optimisation'
															) }
														</p>
													</div>
													<div className="wppo-field wppo-mt-16">
														<label
															className="wppo-field-label"
															htmlFor="delayJSThirdPartyAllowlist"
														>
															{ __(
																'Third-party allowlist (never delay)',
																'performance-optimisation'
															) }
														</label>
														<textarea
															className="wppo-textarea wppo-textarea--mono"
															id="delayJSThirdPartyAllowlist"
															name="delayJSThirdPartyAllowlist"
															rows="3"
															disabled={
																optimizerDisabled
															}
															placeholder={ __(
																'e.g. consent-manager.js',
																'performance-optimisation'
															) }
															value={
																settings.delayJSThirdPartyAllowlist
															}
															onChange={
																onFieldChange
															}
														/>
														<p className="wppo-text-muted wppo-text-small wppo-mt-8">
															{ __(
																'One per line — always wins over the denylist. Use for scripts that must stay eager.',
																'performance-optimisation'
															) }
														</p>
													</div>
												</>
											) }
											<SwitchField
												label={ __(
													'Builder safe preset',
													'performance-optimisation'
												) }
												description={ __(
													'Keep Elementor, Divi, Bricks, WPBakery, Oxygen, block runtimes and sliders un-delayed by default. Disable only if you manage exclusions manually.',
													'performance-optimisation'
												) }
												name="delayJSBuilderPreset"
												checked={
													settings.delayJSBuilderPreset
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'Commerce safe preset',
													'performance-optimisation'
												) }
												description={ __(
													'Keep jQuery and cart-fragments/checkout handles un-delayed by default. Disable only if you manage exclusions manually.',
													'performance-optimisation'
												) }
												name="delayJSCommercePreset"
												checked={
													settings.delayJSCommercePreset
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'First-click interaction preset',
													'performance-optimisation'
												) }
												description={ __(
													'Keep Elementor popups/dialogs, mobile-menu toggles, and add-to-cart handles un-delayed so first clicks never need a second click.',
													'performance-optimisation'
												) }
												name="delayJSInteractionPreset"
												checked={
													settings.delayJSInteractionPreset
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'Consent compatibility preset',
													'performance-optimisation'
												) }
												description={ __(
													'Keep consent banners and scanners (CookieYes, Cookiebot, Complianz, Borlabs, OneTrust) un-delayed. Off by default; enable if your banner breaks.',
													'performance-optimisation'
												) }
												name="delayJSConsentPreset"
												checked={
													settings.delayJSConsentPreset
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'Analytics compatibility preset',
													'performance-optimisation'
												) }
												description={ __(
													'Keep analytics beacons (GA4 gtag, Matomo, Plausible) un-delayed so hits are not lost before interaction. Off by default.',
													'performance-optimisation'
												) }
												name="delayJSAnalyticsPreset"
												checked={
													settings.delayJSAnalyticsPreset
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'Gallery compatibility preset',
													'performance-optimisation'
												) }
												description={ __(
													'Keep galleries and lightboxes (PhotoSwipe, Fancybox, Envira, FooGallery) un-delayed so they work before interaction. Off by default.',
													'performance-optimisation'
												) }
												name="delayJSGalleryPreset"
												checked={
													settings.delayJSGalleryPreset
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<SwitchField
												label={ __(
													'jQuery legacy preset',
													'performance-optimisation'
												) }
												description={ __(
													'Keep jQuery UI and legacy jQuery plugins un-delayed for themes with jQuery-dependent widgets. Off by default; shops are already covered by the commerce preset.',
													'performance-optimisation'
												) }
												name="delayJSJqueryPreset"
												checked={
													settings.delayJSJqueryPreset
												}
												onChange={ onFieldChange }
												disabled={ optimizerDisabled }
											/>
											<div className="wppo-field">
												<label
													className="wppo-field-label"
													htmlFor="excludeDelayJS"
												>
													{ __(
														'Scripts to Delay',
														'performance-optimisation'
													) }
												</label>
												<textarea
													className="wppo-textarea wppo-textarea--mono"
													id="excludeDelayJS"
													name="excludeDelayJS"
													rows="3"
													placeholder={ __(
														'e.g. googletagmanager.com/gtag',
														'performance-optimisation'
													) }
													value={
														settings.excludeDelayJS
													}
													onChange={ onFieldChange }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — partial URL or keyword (e.g. gtag).',
														'performance-optimisation'
													) }
												</p>
											</div>
											<div className="wppo-field wppo-mt-16">
												<label
													className="wppo-field-label"
													htmlFor="delayJSExcludeUrls"
												>
													{ __(
														'Disable Delay JS on these URLs',
														'performance-optimisation'
													) }
												</label>
												<textarea
													className="wppo-textarea wppo-textarea--mono"
													id="delayJSExcludeUrls"
													name="delayJSExcludeUrls"
													rows="3"
													placeholder={ __(
														'e.g. /checkout/',
														'performance-optimisation'
													) }
													value={
														settings.delayJSExcludeUrls
													}
													onChange={ onFieldChange }
													aria-describedby="delayJSExcludeUrls-desc"
												/>
												<p
													id="delayJSExcludeUrls-desc"
													className="wppo-text-muted wppo-text-small wppo-mt-8"
												>
													{ __(
														'One per line — URL substring or #regex#. Delay is skipped on matching URLs only.',
														'performance-optimisation'
													) }
												</p>
											</div>

											<div className="wppo-field wppo-mt-16">
												<label
													className="wppo-field-label"
													htmlFor="delayJSDefaultStrategy"
												>
													{ __(
														'Default Load Strategy',
														'performance-optimisation'
													) }
												</label>
												<select
													className="wppo-select"
													id="delayJSDefaultStrategy"
													name="delayJSDefaultStrategy"
													value={
														settings.delayJSDefaultStrategy
													}
													onChange={ onFieldChange }
												>
													<option value="interaction">
														{ __(
															'Interaction (load on user interaction)',
															'performance-optimisation'
														) }
													</option>
													<option value="idle">
														{ __(
															'Idle (load during browser idle)',
															'performance-optimisation'
														) }
													</option>
													<option value="viewport">
														{ __(
															'Viewport (load when near viewport)',
															'performance-optimisation'
														) }
													</option>
												</select>
												<p className="wppo-text-muted wppo-mt-8 wppo-text-small">
													{ __(
														'Default strategy for delayed scripts that are not in a specific list below.',
														'performance-optimisation'
													) }
												</p>
											</div>

											{ ( settings.delayJSDefaultStrategy ===
												'idle' ||
												settings.delayJSIdleList ) && (
												<div className="wppo-field wppo-mt-16">
													<label
														className="wppo-field-label"
														htmlFor="delayJSIdleTimeout"
													>
														{ __(
															'Idle Timeout (ms)',
															'performance-optimisation'
														) }
													</label>
													<input
														className="wppo-input"
														type="number"
														inputMode="numeric"
														id="delayJSIdleTimeout"
														name="delayJSIdleTimeout"
														min="500"
														max="30000"
														step="100"
														value={
															settings.delayJSIdleTimeout
														}
														onChange={
															onFieldChange
														}
														aria-describedby="delayJSIdleTimeout-desc"
													/>
													<p
														id="delayJSIdleTimeout-desc"
														className="wppo-text-muted wppo-mt-8 wppo-text-small"
													>
														{ __(
															'Maximum time (ms) to wait before loading idle scripts (default: 3000).',
															'performance-optimisation'
														) }
													</p>
												</div>
											) }

											<div className="wppo-field wppo-mt-16">
												<label
													className="wppo-field-label"
													htmlFor="delayJSIdleList"
												>
													{ __(
														'Scripts to Load When Idle',
														'performance-optimisation'
													) }
												</label>
												<textarea
													className="wppo-textarea wppo-textarea--mono"
													id="delayJSIdleList"
													name="delayJSIdleList"
													rows="3"
													placeholder={ __(
														'e.g. analytics.js',
														'performance-optimisation'
													) }
													value={
														settings.delayJSIdleList
													}
													onChange={ onFieldChange }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — loads via requestIdleCallback during browser idle.',
														'performance-optimisation'
													) }
												</p>
											</div>

											<div className="wppo-field wppo-mt-16">
												<label
													className="wppo-field-label"
													htmlFor="delayJSViewportList"
												>
													{ __(
														'Scripts to Load in Viewport',
														'performance-optimisation'
													) }
												</label>
												<textarea
													className="wppo-textarea wppo-textarea--mono"
													id="delayJSViewportList"
													name="delayJSViewportList"
													rows="3"
													placeholder={ __(
														'e.g. chat-widget.js',
														'performance-optimisation'
													) }
													value={
														settings.delayJSViewportList
													}
													onChange={ onFieldChange }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — loads when near viewport.',
														'performance-optimisation'
													) }
												</p>
											</div>

											<div className="wppo-field wppo-mt-16">
												<label
													className="wppo-field-label"
													htmlFor="delayJSPriority"
												>
													{ __(
														'Script Priority',
														'performance-optimisation'
													) }
												</label>
												<textarea
													className="wppo-textarea wppo-textarea--mono"
													id="delayJSPriority"
													name="delayJSPriority"
													rows="3"
													placeholder={ __(
														'e.g. critical:high',
														'performance-optimisation'
													) }
													value={
														settings.delayJSPriority
													}
													onChange={ onFieldChange }
												/>
												<p className="wppo-text-muted wppo-text-small wppo-mt-8">
													{ __(
														'One per line — handle:priority (high, normal, low). High loads first.',
														'performance-optimisation'
													) }
												</p>
											</div>

											<div className="wppo-notice wppo-notice--warning wppo-mt-16">
												<FontAwesomeIcon
													icon={
														faExclamationTriangle
													}
												/>{ ' ' }
												<span>
													{ __(
														'Delaying scripts can break immediate functionality. Test carefully.',
														'performance-optimisation'
													) }
												</span>
											</div>
											{ settings.delayJS &&
												( ! settings.delayJSCommercePreset ||
													! settings.delayJSBuilderPreset ||
													! settings.delayJSInteractionPreset ) && (
													<NoticeBanner
														type="warning"
														message={ __(
															'Aggressive mode: a safe preset is off — jQuery, cart, builder or slider scripts may be delayed. Re-enable presets unless you manage exclusions manually.',
															'performance-optimisation'
														) }
													/>
												) }
											<div className="wppo-notice wppo-notice--info wppo-mt-16">
												<span>
													{ __(
														'Safe mode: WooCommerce, Elementor and form scripts are auto-excluded, localised inline scripts are preserved, and Delay-JS is skipped on cart, checkout and form pages. Exclude single URLs above or disable Delay JS per page via the post editor Asset Manager. Customize via the wppo_delay_js_exclusions filter.',
														'performance-optimisation'
													) }
												</span>
											</div>
										</>
									) }
								</div>
							</FeatureCard>
						) }
					</div>
				) }

				{ activeSubTab === 'ecommerce' && (
					<div
						id="panel-ecommerce"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-ecommerce"
					>
						<FeatureCard
							title={ __(
								'WooCommerce Core',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faStore } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label={ __(
										'Optimize WooCommerce Assets',
										'performance-optimisation'
									) }
									description={ __(
										'Disable WooCommerce scripts and styles on non-ecommerce pages (e.g. blog, about). This reduces page weight but may break cart widgets on custom pages — verify your checkout flow after enabling.',
										'performance-optimisation'
									) }
									name="removeWooCSSJS"
									checked={ settings.removeWooCSSJS }
									onChange={ onFieldChange }
								/>

								{ settings.removeWooCSSJS && (
									<>
										<div className="wppo-notice wppo-notice--warning">
											<FontAwesomeIcon
												icon={ faExclamationTriangle }
											/>
											<span>
												{ __(
													'This may break carts on custom pages. Verify your checkout flow.',
													'performance-optimisation'
												) }
											</span>
										</div>
										<div className="wppo-field">
											<label
												className="wppo-field-label"
												htmlFor="excludeUrlToKeepJSCSS"
											>
												{ __(
													'Keep Assets on These URLs',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
												id="excludeUrlToKeepJSCSS"
												name="excludeUrlToKeepJSCSS"
												rows="4"
												placeholder={ __(
													'e.g. shop/.* (regex supported)',
													'performance-optimisation'
												) }
												value={
													settings.excludeUrlToKeepJSCSS
												}
												onChange={ onFieldChange }
											/>
											<p className="wppo-text-muted wppo-text-small wppo-mt-8">
												{ __(
													'One pattern per line — regex supported.',
													'performance-optimisation'
												) }
											</p>
										</div>
										<div className="wppo-field">
											<label
												className="wppo-field-label"
												htmlFor="removeCssJsHandle"
											>
												{ __(
													'Remove Specific CSS/JS Handles',
													'performance-optimisation'
												) }
											</label>
											<textarea
												className="wppo-textarea wppo-textarea--mono"
												id="removeCssJsHandle"
												name="removeCssJsHandle"
												rows="4"
												placeholder={ __(
													'e.g. woocommerce-smallscreen',
													'performance-optimisation'
												) }
												value={
													settings.removeCssJsHandle
												}
												onChange={ onFieldChange }
											/>
											<p className="wppo-text-muted wppo-text-small wppo-mt-8">
												{ __(
													'One handle per line.',
													'performance-optimisation'
												) }
											</p>
										</div>
									</>
								) }
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'network' && (
					<div
						id="panel-network"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-network"
					>
						{ isLiteSpeed && (
							<FeatureCard
								title={ __(
									'LiteSpeed Integration',
									'performance-optimisation'
								) }
								icon={ <FontAwesomeIcon icon={ faServer } /> }
							>
								<div className="wppo-field-group">
									<div className="wppo-mb-12">
										<span
											className={ `wppo-status-badge wppo-status-badge--${
												lscacheActive ? 'poor' : 'good'
											} wppo-mr-8` }
										>
											{ lscacheActive
												? __(
														'LSCache Active',
														'performance-optimisation'
												  )
												: __(
														'LSCache Inactive',
														'performance-optimisation'
												  ) }
										</span>
										<span className="wppo-status-badge wppo-status-badge--good wppo-mr-8">
											{ __(
												'Detected: LiteSpeed',
												'performance-optimisation'
											) }
										</span>
										<span
											className={ `wppo-status-badge ${ effectiveBadgeClass }` }
										>
											{ __(
												'Effective:',
												'performance-optimisation'
											) }{ ' ' }
											{ effectiveLabel }
										</span>
									</div>
									{ lscacheActive &&
										effectiveMode === 'litespeed' && (
											<NoticeBanner
												type="warning"
												message={ __(
													'LiteSpeed Cache owns page cache & optimisation in the current mode — WPPO combiners/minifiers are paused to prevent double processing.',
													'performance-optimisation'
												) }
												className="wppo-mb-12"
											/>
										) }
									{ lscacheActive &&
										liteSpeedModeFromSettings ===
											'auto' && (
											<NoticeBanner
												type="info"
												message={ __(
													'Both plugins are active in Auto mode. Choose the cache owner below.',
													'performance-optimisation'
												) }
												className="wppo-mb-12"
											/>
										) }
									<div className="wppo-field">
										<label
											className="wppo-field-label"
											htmlFor="litespeedMode"
										>
											{ __(
												'Cache Owner',
												'performance-optimisation'
											) }
										</label>
										<select
											className="wppo-select"
											id="litespeedMode"
											value={ litespeedMode }
											onChange={ ( e ) =>
												setLitespeedMode(
													e.target.value
												)
											}
										>
											<option value="auto">
												{ __(
													'Auto — Detect (recommended)',
													'performance-optimisation'
												) }
											</option>
											<option value="wppo">
												{ __(
													'WPPO owns cache',
													'performance-optimisation'
												) }
											</option>
											<option value="litespeed">
												{ __(
													'LiteSpeed owns cache',
													'performance-optimisation'
												) }
											</option>
											<option value="standalone">
												{ __(
													'Standalone — Ignore LiteSpeed',
													'performance-optimisation'
												) }
											</option>
										</select>
										<p className="wppo-text-muted wppo-text-small wppo-mt-8">
											{ __(
												'In Auto, WPPO owns cache when LiteSpeed Cache is not active; otherwise LiteSpeed owns it.',
												'performance-optimisation'
											) }
										</p>
									</div>
									<LoadingSubmitButton
										className="wppo-button wppo-button--secondary"
										isLoading={ savingLiteSpeed }
										onClick={ handleSaveLiteSpeedMode }
										label={ __(
											'Save LiteSpeed Mode',
											'performance-optimisation'
										) }
									/>
									{ serverRules?.litespeed?.dropin && (
										<div className="wppo-mt-16">
											<p className="wppo-text-muted wppo-text-small">
												<strong>
													{ __(
														'Drop-in status:',
														'performance-optimisation'
													) }
												</strong>{ ' ' }
												{ __(
													'Page cache:',
													'performance-optimisation'
												) }{ ' ' }
												{ litespeedInfo?.dropin
													?.advanced_cache ||
													serverRules.litespeed.dropin
														?.advanced_cache ||
													noneLabel }{ ' ' }
												—{ ' ' }
												{ __(
													'Object cache:',
													'performance-optimisation'
												) }{ ' ' }
												{ litespeedInfo?.dropin
													?.object_cache ||
													serverRules.litespeed.dropin
														?.object_cache ||
													noneLabel }
											</p>
											{ ( litespeedInfo?.dropin
												?.object_cache === 'foreign' ||
												litespeedInfo?.dropin
													?.advanced_cache ===
													'foreign' ||
												serverRules.litespeed.dropin
													?.advanced_cache ===
													'foreign' ||
												serverRules.litespeed.dropin
													?.object_cache ===
													'foreign' ) && (
												<div className="wppo-notice wppo-notice--warning wppo-mt-8">
													<FontAwesomeIcon
														icon={
															faExclamationTriangle
														}
													/>{ ' ' }
													{ __(
														'Only one object-cache.php / advanced-cache.php can exist. Choose the handler in Tools → Object Cache.',
														'performance-optimisation'
													) }
												</div>
											) }
											{ litespeedInfo?.dropin
												?.advanced_cache ===
												'litespeed' && (
												<div className="wppo-notice wppo-notice--info wppo-mt-8">
													{ __(
														'Page cache drop-in is owned by LiteSpeed — WPPO file cache is bypassed in this mode.',
														'performance-optimisation'
													) }
												</div>
											) }
										</div>
									) }
								</div>
							</FeatureCard>
						) }
						<FeatureCard
							title={ __(
								'Server Rules',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faServer } /> }
						>
							<div className="wppo-field-group">
								{ serverRulesError ? (
									<div className="wppo-notice wppo-notice--error">
										<span>
											{ __(
												'Unable to load server configuration. Check your server setup.',
												'performance-optimisation'
											) }
										</span>
										{ onRetryServerRules && (
											<button
												className="wppo-button wppo-button--secondary"
												onClick={ onRetryServerRules }
											>
												{ __(
													'Retry',
													'performance-optimisation'
												) }
											</button>
										) }
									</div>
								) : null }
								{ serverRules === null && ! serverRulesError ? (
									<div className="wppo-loading-placeholder">
										<FontAwesomeIcon
											icon={ faSpinner }
											spin
										/>
										<span>
											{ __(
												'Loading server configuration…',
												'performance-optimisation'
											) }
										</span>
									</div>
								) : null }
								{ serverRules !== null && ! serverRulesError ? (
									<>
										<Tooltip
											content={
												serverRules?.server_type !==
													'apache' &&
												serverRules?.server_type !==
													'litespeed'
													? __(
															'Server rules require Apache or LiteSpeed.',
															'performance-optimisation'
													  )
													: ''
											}
										>
											<SwitchField
												label={ __(
													'Enable Server Rules (.htaccess)',
													'performance-optimisation'
												) }
												description={
													serverRules?.server_type ===
													'litespeed'
														? __(
																'Write performance rules (browser caching, GZIP compression, etc.) directly to your .htaccess file for server-level optimisation. LiteSpeed is Apache-compatible — restart OpenLiteSpeed after changes. Ensure you have FTP access for recovery if something goes wrong.',
																'performance-optimisation'
														  )
														: __(
																'Write performance rules (browser caching, GZIP compression, etc.) directly to your .htaccess file for server-level optimisation. Requires Apache. Ensure you have FTP access for recovery if something goes wrong.',
																'performance-optimisation'
														  )
												}
												name="enableServerRules"
												checked={
													( serverRules?.server_type ===
														'apache' ||
														serverRules?.server_type ===
															'litespeed' ) &&
													settings.enableServerRules
												}
												disabled={
													serverRules?.server_type !==
														'apache' &&
													serverRules?.server_type !==
														'litespeed'
												}
												onChange={ onFieldChange }
											/>
										</Tooltip>

										{ ( serverRules?.server_type ===
											'apache' ||
											serverRules?.server_type ===
												'litespeed' ) &&
											settings.enableServerRules && (
												<div className="wppo-notice wppo-notice--warning">
													<FontAwesomeIcon
														icon={
															faExclamationTriangle
														}
													/>
													<span>
														{ serverRules?.server_type ===
														'litespeed'
															? __(
																	'This modifies your .htaccess. LiteSpeed is Apache-compatible — restart OpenLiteSpeed after changes. Ensure you have FTP access for recovery.',
																	'performance-optimisation'
															  )
															: __(
																	'This modifies your .htaccess. Ensure you have FTP access for recovery.',
																	'performance-optimisation'
															  ) }
													</span>
												</div>
											) }

										{ serverRules?.server_type ===
											'litespeed' && (
											<div className="wppo-notice wppo-notice--info wppo-mt-20">
												<FontAwesomeIcon
													icon={ faServer }
												/>
												<span>
													<strong>
														{ __(
															'LiteSpeed Detected:',
															'performance-optimisation'
														) }
													</strong>{ ' ' }
													{ __(
														'LiteSpeed is Apache-compatible. Server rules use the same .htaccess as Apache — restart OpenLiteSpeed after changes.',
														'performance-optimisation'
													) }
												</span>
											</div>
										) }

										{ serverRules?.server_type ===
											'nginx' && (
											<div className="wppo-nginx-rules wppo-mt-20">
												<div className="wppo-notice wppo-notice--info wppo-mb-16">
													<FontAwesomeIcon
														icon={ faServer }
													/>
													<span>
														<strong>
															{ __(
																'Nginx Detected:',
																'performance-optimisation'
															) }
														</strong>{ ' ' }
														{ __(
															'Server rules cannot be applied automatically on Nginx. Please copy the rules below into your server configuration.',
															'performance-optimisation'
														) }
													</span>
												</div>
												<div className="wppo-field-label">
													{ __(
														'Nginx Configuration',
														'performance-optimisation'
													) }
												</div>
												<pre className="wppo-code-block">
													<code>
														{ serverRules.nginx }
													</code>
												</pre>
												<p className="wppo-text-muted wppo-mt-12 wppo-text-13">
													{ __(
														'Add these rules inside your',
														'performance-optimisation'
													) }{ ' ' }
													<code>
														server { '{' } ...{ ' ' }
														{ '}' }
													</code>{ ' ' }
													{ __(
														'block, then restart Nginx.',
														'performance-optimisation'
													) }
												</p>
											</div>
										) }

										{ serverRules?.server_type ===
											'other' && (
											<div className="wppo-notice wppo-notice--warning wppo-mt-20">
												<FontAwesomeIcon
													icon={
														faExclamationTriangle
													}
												/>
												<span>
													{ __(
														'Unrecognised server software. Automatic rules are only available for Apache (.htaccess).',
														'performance-optimisation'
													) }
												</span>
											</div>
										) }
									</>
								) : null }
							</div>
						</FeatureCard>

						<FeatureCard
							title={ __(
								'CDN Settings',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faServer } /> }
						>
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="cdnURL"
								>
									{ __(
										'CDN Hostname',
										'performance-optimisation'
									) }
								</label>
								<input
									className="wppo-input"
									type="url"
									id="cdnURL"
									name="cdnURL"
									placeholder="https://cdn.example.com"
									value={ settings.cdnURL }
									onChange={ onFieldChange }
									aria-describedby="cdnURL-desc"
								/>
								<p
									id="cdnURL-desc"
									className="wppo-text-muted wppo-mt-10 wppo-text-small"
								>
									{ __(
										'Enter your CDN hostname. All static asset URLs (JS, CSS, images) will be rewritten to load from this domain, reducing latency for global visitors.',
										'performance-optimisation'
									) }
								</p>
								{ settings.cdnURL &&
									Array.isArray( settings.cdnMapping ) &&
									settings.cdnMapping.length > 0 && (
										<div className="wppo-notice wppo-notice--info wppo-mt-12">
											{ __(
												'Migrated to mapping #0 — cdnURL kept for backward compatibility.',
												'performance-optimisation'
											) }
										</div>
									) }
							</div>
							<div className="wppo-field wppo-mt-16">
								<span className="wppo-field-label">
									{ __(
										'CDN Mapping (parity with LSCWP)',
										'performance-optimisation'
									) }
								</span>
								<p className="wppo-text-muted wppo-text-small wppo-mb-12">
									{ __(
										'One-to-many mapping by origin/dir/filetype. Up to 5 entries. Use * wildcard in Origin Dir (e.g. wp-content/*).',
										'performance-optimisation'
									) }
								</p>
								{ ( settings.cdnMapping || [] ).map(
									( entry, idx ) => (
										<div
											key={ entry.id ?? idx }
											className="wppo-mt-12 wppo-file-opt-card"
										>
											<div className="wppo-field">
												<label
													className="wppo-field-label"
													htmlFor={ `wppo-cdn-url-${ idx }` }
												>
													{ __(
														'CDN URL',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													id={ `wppo-cdn-url-${ idx }` }
													type="url"
													placeholder="https://cdn.example.com"
													value={
														entry.cdn_url || ''
													}
													onChange={ ( e ) =>
														updateCdnEntry(
															idx,
															'cdn_url',
															e.target.value
														)
													}
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												<label
													className="wppo-field-label"
													htmlFor={ `wppo-cdn-ori-${ idx }` }
												>
													{ __(
														'Origin URL (ori)',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													id={ `wppo-cdn-ori-${ idx }` }
													type="url"
													placeholder="https://example.com"
													value={ entry.ori || '' }
													onChange={ ( e ) =>
														updateCdnEntry(
															idx,
															'ori',
															e.target.value
														)
													}
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												<label
													className="wppo-field-label"
													htmlFor={ `wppo-cdn-ori-dir-${ idx }` }
												>
													{ __(
														'Origin Dir (ori_dir) — wildcard * allowed, pipe-separated',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													id={ `wppo-cdn-ori-dir-${ idx }` }
													type="text"
													placeholder="wp-content|wp-includes"
													value={
														entry.ori_dir || ''
													}
													onChange={ ( e ) =>
														updateCdnEntry(
															idx,
															'ori_dir',
															e.target.value
														)
													}
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												<label
													className="wppo-field-label"
													htmlFor={ `wppo-cdn-include-dirs-${ idx }` }
												>
													{ __(
														'Include Dirs',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													id={ `wppo-cdn-include-dirs-${ idx }` }
													type="text"
													placeholder="wp-content|wp-includes"
													value={
														entry.include_dirs || ''
													}
													onChange={ ( e ) =>
														updateCdnEntry(
															idx,
															'include_dirs',
															e.target.value
														)
													}
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												<label
													className="wppo-field-label"
													htmlFor={ `wppo-cdn-include-filetypes-${ idx }` }
												>
													{ __(
														'Include Filetypes (comma-separated)',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													id={ `wppo-cdn-include-filetypes-${ idx }` }
													type="text"
													placeholder="jpg,png,css,js"
													value={
														entry.include_filetypes ||
														''
													}
													onChange={ ( e ) =>
														updateCdnEntry(
															idx,
															'include_filetypes',
															e.target.value
														)
													}
												/>
											</div>
											<div className="wppo-field wppo-mt-8">
												<label
													className="wppo-field-label"
													htmlFor={ `wppo-cdn-attr-${ idx }` }
												>
													{ __(
														'CDN Attr allowlist (cdn_attr) — e.g. src,href,srcset',
														'performance-optimisation'
													) }
												</label>
												<input
													className="wppo-input"
													id={ `wppo-cdn-attr-${ idx }` }
													type="text"
													placeholder="src,href,srcset"
													value={
														entry.cdn_attr || ''
													}
													onChange={ ( e ) =>
														updateCdnEntry(
															idx,
															'cdn_attr',
															e.target.value
														)
													}
												/>
											</div>
											<button
												className="wppo-button wppo-button--secondary wppo-mt-8"
												type="button"
												onClick={ () => {
													setSettings( ( prev ) => {
														const m = [
															...( prev.cdnMapping ||
																[] ),
														];
														m.splice( idx, 1 );
														return {
															...prev,
															cdnMapping: m,
														};
													} );
												} }
											>
												{ __(
													'Remove',
													'performance-optimisation'
												) }
											</button>
										</div>
									)
								) }
								{ ( settings.cdnMapping || [] ).length < 5 && (
									<button
										className="wppo-button wppo-button--secondary wppo-mt-12"
										type="button"
										onClick={ () => {
											setSettings( ( prev ) => {
												const m = [
													...( prev.cdnMapping ||
														[] ),
												];
												m.push( {
													id: cdnRowId(),
													cdn_url: '',
													ori: '',
													ori_dir: '',
													include_dirs:
														'wp-content|wp-includes',
													include_filetypes: '',
													cdn_attr: '',
												} );
												return {
													...prev,
													cdnMapping: m,
												};
											} );
										} }
									>
										{ __(
											'Add Mapping',
											'performance-optimisation'
										) }
									</button>
								) }
							</div>
						</FeatureCard>
					</div>
				) }

				{ activeSubTab === 'core' && (
					<div
						id="panel-core"
						className="wppo-stacked-cards"
						role="tabpanel"
						aria-labelledby="tab-core"
					>
						<FeatureCard
							title={ __(
								'Cleanup Core Bloat',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faShieldAlt } /> }
						>
							<div className="wppo-field-group">
								<SwitchField
									label={ __(
										'Disable Emojis',
										'performance-optimisation'
									) }
									description={ __(
										"Remove the WordPress emoji script and stylesheet. Saves ~10 KB per page if you don't use emojis in your content.",
										'performance-optimisation'
									) }
									name="disableEmojis"
									checked={ settings.disableEmojis }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Embeds',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the oEmbed script that allows embedding external content. Saves ~1 HTTP request if you do not embed tweets, YouTube videos, etc.',
										'performance-optimisation'
									) }
									name="disableEmbeds"
									checked={ settings.disableEmbeds }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Dashicons (Frontend)',
										'performance-optimisation'
									) }
									description={ __(
										'Prevent the WordPress admin icon font from loading on the frontend for logged-out users. Only disable if your theme does not use Dashicons.',
										'performance-optimisation'
									) }
									name="disableDashicons"
									checked={ settings.disableDashicons }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable XML-RPC',
										'performance-optimisation'
									) }
									description={ __(
										'Block the XML-RPC endpoint (xmlrpc.php). Reduces attack surface and server load. Only disable if you do not use Jetpack, mobile apps, or remote publishing.',
										'performance-optimisation'
									) }
									name="disableXMLRPC"
									checked={ settings.disableXMLRPC }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Remove REST API Links',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the REST API discovery link and oEmbed links from the front end. The REST API itself stays active.',
										'performance-optimisation'
									) }
									name="disableRestApiLinks"
									checked={ settings.disableRestApiLinks }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable RSS Feeds',
										'performance-optimisation'
									) }
									description={ __(
										'Redirect all feed requests to the home page and remove feed discovery links. Only for sites that do not use feeds.',
										'performance-optimisation'
									) }
									name="disableRssFeeds"
									checked={ settings.disableRssFeeds }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Remove Shortlinks',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the rel=shortlink tag from page output.',
										'performance-optimisation'
									) }
									name="disableShortlinks"
									checked={ settings.disableShortlinks }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Remove Generator Meta Tag',
										'performance-optimisation'
									) }
									description={ __(
										'Hide the WordPress version meta generator tag.',
										'performance-optimisation'
									) }
									name="disableGeneratorTag"
									checked={ settings.disableGeneratorTag }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Remove jQuery Migrate',
										'performance-optimisation'
									) }
									description={ __(
										'Drop the jquery-migrate dependency to save a request. Only disable if your theme/plugins do not rely on deprecated jQuery APIs.',
										'performance-optimisation'
									) }
									name="disableJQueryMigrate"
									checked={ settings.disableJQueryMigrate }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Remove Password Strength Meter',
										'performance-optimisation'
									) }
									description={ __(
										'Prevent the password strength meter script from loading on the front end.',
										'performance-optimisation'
									) }
									name="disablePasswordStrength"
									checked={ settings.disablePasswordStrength }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Self-pingbacks',
										'performance-optimisation'
									) }
									description={ __(
										'Stop posts from pinging themselves when linking to their own site.',
										'performance-optimisation'
									) }
									name="disableSelfPingbacks"
									checked={ settings.disableSelfPingbacks }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable RSD Link',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the EditURI (RSD) link used by remote clients.',
										'performance-optimisation'
									) }
									name="disableRSD"
									checked={ settings.disableRSD }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable WLW Manifest',
										'performance-optimisation'
									) }
									description={ __(
										'Remove the Windows Live Writer manifest link.',
										'performance-optimisation'
									) }
									name="disableWLWManifest"
									checked={ settings.disableWLWManifest }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Global Styles',
										'performance-optimisation'
									) }
									description={ __(
										'Remove WordPress global styles and SVG filters.',
										'performance-optimisation'
									) }
									name="disableGlobalStyles"
									checked={ settings.disableGlobalStyles }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Classic Theme Styles',
										'performance-optimisation'
									) }
									description={ __(
										'Remove classic theme stylesheet.',
										'performance-optimisation'
									) }
									name="disableClassicThemeStyles"
									checked={
										settings.disableClassicThemeStyles
									}
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Woo Cart Fragments',
										'performance-optimisation'
									) }
									description={ __(
										'Prevent WooCommerce cart fragments AJAX polling.',
										'performance-optimisation'
									) }
									name="disableWooCartFragments"
									checked={ settings.disableWooCartFragments }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Recent Comments Style',
										'performance-optimisation'
									) }
									description={ __(
										'Remove recent comments widget inline CSS.',
										'performance-optimisation'
									) }
									name="disableRecentCommentsStyle"
									checked={
										settings.disableRecentCommentsStyle
									}
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Comment Reply JS',
										'performance-optimisation'
									) }
									description={ __(
										'Remove comment-reply script.',
										'performance-optimisation'
									) }
									name="disableCommentReply"
									checked={ settings.disableCommentReply }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable oEmbed Discovery',
										'performance-optimisation'
									) }
									description={ __(
										'Remove oEmbed discovery links from head.',
										'performance-optimisation'
									) }
									name="disableOEmbedDiscovery"
									checked={ settings.disableOEmbedDiscovery }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Disable Block Widgets',
										'performance-optimisation'
									) }
									description={ __(
										'Disable block-based widgets editor.',
										'performance-optimisation'
									) }
									name="disableBlockWidgets"
									checked={ settings.disableBlockWidgets }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Load Block Assets On Demand',
										'performance-optimisation'
									) }
									description={ __(
										'Only load block CSS and JavaScript when blocks are actually used on the page. On WordPress 6.8 you must enable this toggle; on WordPress 6.9 and later classic themes load block assets on demand by default.',
										'performance-optimisation'
									) }
									name="blockAssetsOnDemand"
									checked={ settings.blockAssetsOnDemand }
									onChange={ onFieldChange }
								/>
								<SwitchField
									label={ __(
										'Load Combined Core Block Styles',
										'performance-optimisation'
									) }
									description={ __(
										'Load the combined core block stylesheet (wp-block-library) for compatibility with shortcodes or widgets that enqueue styles while content renders. Overrides on-demand block assets. Requires WordPress 6.9+.',
										'performance-optimisation'
									) }
									name="loadAllCoreBlockAssets"
									checked={ settings.loadAllCoreBlockAssets }
									onChange={ onFieldChange }
								/>
							</div>
						</FeatureCard>

						<FeatureCard
							title={ __(
								'Heartbeat Control',
								'performance-optimisation'
							) }
							icon={ <FontAwesomeIcon icon={ faRocket } /> }
						>
							<div className="wppo-field">
								<label
									className="wppo-field-label"
									htmlFor="heartbeatControl"
								>
									{ __(
										'API Frequency',
										'performance-optimisation'
									) }
								</label>
								<select
									className="wppo-select"
									id="heartbeatControl"
									name="heartbeatControl"
									value={ settings.heartbeatControl }
									onChange={ onFieldChange }
									aria-describedby="heartbeatControl-desc"
								>
									<option value="default">
										{ __(
											'Default Mode',
											'performance-optimisation'
										) }
									</option>
									<option value="60s">
										{ __(
											'Reduce Frequency (60s)',
											'performance-optimisation'
										) }
									</option>
									<option value="disable_ext">
										{ __(
											'Disable on Frontend',
											'performance-optimisation'
										) }
									</option>
									<option value="disable_all">
										{ __(
											'Disable Everywhere',
											'performance-optimisation'
										) }
									</option>
								</select>
								<p
									id="heartbeatControl-desc"
									className="wppo-text-muted wppo-mt-12 wppo-text-13"
								>
									{ __(
										'Restricting the Heartbeat API reduces server CPU usage by limiting polling.',
										'performance-optimisation'
									) }
								</p>
							</div>
						</FeatureCard>
					</div>
				) }
			</div>
		</div>
	);
};

export default FileOptimization;
