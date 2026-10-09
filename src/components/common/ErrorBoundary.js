/**
 * Shared error boundary for the admin SPA.
 *
 * Contains render/lifecycle crashes and renders an accessible reload fallback
 * instead of blanking wp-admin.
 *
 * Three invariants, all pinned by
 * src/components/common/__tests__/ErrorBoundary.test.js:
 *
 * 1. The logged message is ALWAYS redacted and never gated on a setting or
 *    flag (audit #1776 removed the dead `wppoSettings.debug` gate).
 * 2. `errorInfo.componentStack` is NEVER logged — it can embed props/state
 *    fragments (settings, request payloads) that must not sit in a shared
 *    console (audit #1493). Nothing from `errorInfo` may reach the console.
 * 3. `componentDidCatch` must never throw. React treats a throw there as a
 *    new commit-phase error, propagates it to the next boundary and, with no
 *    parent boundary, unmounts the root — so a crash handler that itself
 *    throws escalates the very failure it exists to contain. Message
 *    extraction is therefore delegated to `getErrorLogMessage()`
 *    (src/lib/logSecrets.js, re-exported by src/lib/apiRequest.js), the
 *    log-message contract: nullish input short-circuits to a sentinel, the
 *    `String()` coercion is guarded, and the result is redacted and then
 *    capped at 500 chars. Never re-implement it inline here — that is how a
 *    fifth, weaker copy of the helper appeared here in the first place.
 *
 * Recovery: pass `resetKey` (the routed area/view in App.js) so switching tabs
 * clears a latched fallback instead of leaving a dead-end panel whose only
 * escape reloads the page and discards every other tab's unsaved work.
 *
 * @since NEXT
 * @param {Object}                                             props            Component props.
 * @param {import('react').ReactNode}                          [props.children] Content to guard.
 * @param {string|number|boolean|bigint|symbol|null|undefined} [props.resetKey] Changing primitive value clears a latched error state.
 * @param {Function}                                           [props.onReload] Reload handler (defaults to a full page reload; non-functions are ignored).
 */
import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getErrorLogMessage } from '../../lib/logSecrets';

/**
 * Reload the whole admin page. Split out so the Reload button's behaviour can
 * be asserted without touching jsdom's non-configurable `window.location`.
 *
 * @since NEXT
 * @return {void}
 */
export const reloadPage = () => {
	window.location.reload();
};

/**
 * Whether a resetKey value is safe for identity comparison.
 *
 * Object (including array) values compare by identity, so an inline literal
 * would differ on every update and clear the fallback on every render.
 * Only primitives (plus null/undefined) participate in the reset check.
 *
 * @since NEXT
 * @param {*} value Candidate resetKey value.
 * @return {boolean} True when the value is nullish or a non-object primitive.
 */
export const isPrimitive = ( value ) =>
	value === null ||
	value === undefined ||
	( typeof value !== 'object' && typeof value !== 'function' );

class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false, error: null };
	}

	static getDerivedStateFromError( error ) {
		return { hasError: true, error };
	}

	componentDidCatch( error ) {
		// Minimal logging by design (invariants 1 and 2 in the file header).
		// getErrorLogMessage() cannot throw for any thrown value, which is what
		// keeps this crash handler from escalating the crash (invariant 3).
		console.error( 'ErrorBoundary caught:', getErrorLogMessage( error ) );
	}

	componentDidUpdate( prevProps ) {
		// A latched fallback would otherwise survive every tab switch: nothing
		// else ever sets `hasError` back, and App.js renders one stable
		// boundary instance. Resetting (rather than remounting via `key`) keeps
		// each panel's own state, so a sub-tab switch that re-renders the same
		// component does not discard its in-flight work.
		//
		// Only primitive resetKeys participate: an object-valued resetKey
		// compares by identity, so an inline object literal would differ on
		// every update and reset the fallback on every render. Objects are
		// ignored here — remount via `key` instead for those cases.
		const nextKey = this.props.resetKey;
		const prevKey = prevProps.resetKey;
		if (
			this.state.hasError &&
			isPrimitive( nextKey ) &&
			isPrimitive( prevKey ) &&
			! Object.is( nextKey, prevKey )
		) {
			this.setState( { hasError: false, error: null } );
		}
	}

	render() {
		if ( this.state.hasError ) {
			return (
				// Audit #1354: announce crashes to screen readers.
				<div className="wppo-error-boundary" role="alert">
					<h3>
						{ __(
							'Something went wrong',
							'performance-optimisation'
						) }
					</h3>
					<p>
						{ __(
							'An unexpected error occurred. Please reload the page.',
							'performance-optimisation'
						) }
					</p>
					<button
						type="button"
						className="wppo-button wppo-button--primary"
						onClick={
							'function' === typeof this.props.onReload
								? this.props.onReload
								: reloadPage
						}
					>
						{ __( 'Reload', 'performance-optimisation' ) }
					</button>
				</div>
			);
		}

		return this.props.children;
	}
}

export default ErrorBoundary;
