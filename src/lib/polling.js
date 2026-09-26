/**
 * Shared polling-backoff constants and delay calculation.
 *
 * Single source for the REST poll backoff used by the Dashboard image card
 * (`useImageJobPolling`) and the PageSpeed panel. These values are pinned by
 * architecture item P3-020 ("polling fetch count and action contract
 * unchanged") — do not retune them without a matching architecture change.
 *
 * This is intentionally constants plus one pure function, NOT a polling
 * framework: P3-019/P3-020 record `non_goals: "No router, global store, or
 * polling framework."` Each caller keeps its own timer and AbortSignal
 * lifecycle.
 *
 * @since 2.3.0
 */

/**
 * Base polling interval in milliseconds.
 *
 * @since 2.3.0
 * @type {number}
 */
export const POLL_INTERVAL_MS = 5000;

/**
 * Maximum number of poll attempts before giving up (~5 minutes).
 *
 * @since 2.3.0
 * @type {number}
 */
export const MAX_POLL_ATTEMPTS = 60;

/**
 * Maximum delay between poll ticks.
 *
 * Polling backs off (5s for the first 10 attempts, then +5s per 10
 * attempts, capped at 15s) so slow jobs do not hit the REST endpoint at the
 * same rate as near-complete ones.
 *
 * @since 2.3.0
 * @type {number}
 */
export const MAX_POLL_DELAY_MS = 15000;

/**
 * Delay before the next poll tick, backing off with the attempt count.
 *
 * The attempt count is normalised with `Number.isFinite()` and defaulted to
 * 1. A non-finite count (NaN, Infinity, undefined) used to propagate straight
 * into `setTimeout()`, and `setTimeout( NaN )` is coerced to a 1ms delay —
 * turning a single bad count into a hot poll loop against the REST API
 * instead of a backoff.
 *
 * @since 2.3.0
 * @param {number} attempts 1-based poll attempt count.
 * @return {number} Milliseconds to wait before the next tick.
 */
export const getPollDelay = ( attempts ) =>
	Math.min(
		POLL_INTERVAL_MS *
			Math.max(
				1,
				Math.ceil( ( Number.isFinite( attempts ) ? attempts : 1 ) / 10 )
			),
		MAX_POLL_DELAY_MS
	);
