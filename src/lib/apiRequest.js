/**
 * REST API barrel (audit maintainability).
 *
 * This module previously mixed transport + nonce retry, settings-cache
 * mutation, scan validation, and endpoint wrappers in one 660-line module.
 * It is now a re-export shim over focused modules with no import cycles:
 *
 * - lib/apiClient.js      — fetch transport + nonce retry (apiCall).
 * - lib/settingsCache.js  — wppoSettings.settings cache helpers.
 * - lib/scanValidation.js — scan URL/strategy allowlists.
 * - lib/scanApi.js        — scan endpoint wrappers.
 * - lib/logMessage.js     — safe log-message helpers.
 * - lib/authErrors.js     — auth error codes.
 *
 * Existing imports keep working unchanged.
 *
 * @since NEXT
 */

import {
	getLogMessage as getSharedLogMessage,
	redactLogSecrets as redactSharedLogSecrets,
} from './logMessage';

// Audit #1354: the raw lookup Set stays module-private in
// authErrors.js — re-export only the frozen list and the lookup.
export { AUTH_ERROR_CODES, isAuthErrorCode } from './authErrors';

export { apiCall } from './apiClient';

export {
	getWppoSettings,
	commitSettingsCache,
	patchSettingsCache,
} from './settingsCache';

export {
	isValidScanUrl,
	SCAN_STRATEGIES,
	buildAction,
	assertScanUrl,
	assertScanStrategy,
	isValidScanStrategy,
} from './scanValidation';

export {
	fetchRecentActivities,
	runPerformanceScan,
	fetchSystemInfo,
	queuePagespeedScan,
	getPagespeedResults,
	fetchWebVitalsTrends,
	fetchSuggestions,
	fetchServerRules,
	fetchWooCacheSelfTest,
} from './scanApi';

/**
 * Redact secret-looking substrings from a log message (defense-in-depth).
 *
 * Canonical implementation lives in `lib/logMessage.js` (audit
 * maintainability: one redact-then-truncate contract shared with the
 * dependency-free mirrors in lazyload.js/esi.js/main.js). Re-exported here
 * so existing imports keep working.
 *
 * @since NEXT
 * @param {string} raw Raw message.
 * @return {string} Redacted message.
 */
export const redactLogSecrets = redactSharedLogSecrets;

/**
 * Extract a safe log message from an error without leaking response bodies.
 *
 * Canonical implementation lives in `lib/logMessage.js` (audit
 * maintainability). Re-exported here so existing imports keep working.
 *
 * @since NEXT
 * @param {*} error Caught error value.
 * @return {string} Safe message string.
 */
export const getErrorLogMessage = getSharedLogMessage;
