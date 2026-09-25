/**
 * Shared polling backoff constants (audit #1628).
 *
 * Single home for the backoff schedule previously duplicated verbatim
 * between PageSpeedPanel.js and useImageJobPolling.js. A backoff tuning
 * (or document.hidden throttle) now lands in both pollers at once.
 *
 * @since NEXT
 */

/**
 * Base polling interval in milliseconds.
 *
 * @since NEXT
 * @type {number}
 */
export const POLL_INTERVAL_MS = 5000;

/**
 * Maximum number of poll attempts before giving up (~5 minutes).
 *
 * @since NEXT
 * @type {number}
 */
export const MAX_POLL_ATTEMPTS = 60;

/**
 * Maximum delay between poll ticks.
 *
 * @since NEXT
 * @type {number}
 */
export const MAX_POLL_DELAY_MS = 15000;

/**
 * Delay before the next poll tick, backing off with the attempt count
 * (5s for the first 10 attempts, then +5s per 10 attempts, capped at 15s).
 *
 * @since NEXT
 * @param {number} attempts 1-based poll attempt count.
 * @return {number} Milliseconds to wait before the next tick.
 */
export const getPollDelay = ( attempts ) => {
	const safe = Number.isFinite( attempts ) ? attempts : 1;
	return Math.min(
		POLL_INTERVAL_MS * Math.max( 1, Math.ceil( safe / 10 ) ),
		MAX_POLL_DELAY_MS
	);
};

export default getPollDelay;
