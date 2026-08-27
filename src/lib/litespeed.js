/**
 * LiteSpeed helper — pure JS, no WordPress dependencies.
 *
 * Mirrors the PHP LiteSpeed_Integration::effective_mode() logic for the SPA
 * so the UI can decide ownership without an extra REST call.
 *
 * @since NEXT
 */

/**
 * Resolve the effective LiteSpeed mode from config + detection.
 *
 * @param {Object}  opts                 - Options.
 * @param {string}  opts.mode            - Configured mode (auto|wppo|litespeed|standalone).
 * @param {boolean} opts.isLiteSpeed     - Whether LiteSpeed server is detected.
 * @param {boolean} opts.isLscacheActive - Whether LSCache plugin is active.
 * @return {string} Effective mode (wppo|litespeed|standalone).
 */
export const getEffectiveMode = ( {
	mode = 'auto',
	isLiteSpeed = false,
	isLscacheActive = false,
} = {} ) => {
	if ( ! isLiteSpeed ) {
		return 'standalone';
	}
	if ( mode === 'standalone' ) {
		return 'standalone';
	}
	if ( mode === 'wppo' ) {
		return 'wppo';
	}
	if ( mode === 'litespeed' ) {
		return 'litespeed';
	}
	// auto
	return isLscacheActive ? 'litespeed' : 'wppo';
};

/**
 * Whether WPPO optimizer should be disabled in the current LiteSpeed mode.
 *
 * @param {Object} opts - Options.
 * @return {boolean} True if optimizer should be disabled.
 */
export const shouldDisableOptimizer = ( opts = {} ) => {
	if ( ! opts.isLscacheActive ) {
		return false;
	}
	return getEffectiveMode( opts ) !== 'wppo';
};

/**
 * Human label for a mode value.
 *
 * @param {string} mode - Mode value.
 * @return {string} Human readable label.
 */
export const modeLabel = ( mode ) => {
	const map = {
		auto: 'Auto',
		wppo: 'WPPO',
		litespeed: 'LiteSpeed Cache',
		standalone: 'Standalone',
	};
	return map[ mode ] || mode;
};
