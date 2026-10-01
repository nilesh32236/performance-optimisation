/**
 * Inspector context and state machine.
 *
 * ## What the inspector is for
 *
 * This plugin has 246 settings across 14 tabs, and many of them carry real
 * consequences — a stylesheet that flashes unstyled content, a deferred script
 * that breaks a cart, a cache rule that serves a stale page. The question a
 * site owner actually has is never "what is this control called". It is:
 *
 *   is this safe for my site, and what changes if I turn it on?
 *
 * So whatever control or metric is focused gets explained in a persistent
 * panel, always answering the same three questions in the same order:
 * *what it does · what it costs you · where you stand now*.
 *
 * ## Why the transition logic lives here, not in the component
 *
 * Three input sources compete for the panel — pointer hover, keyboard focus,
 * and an explicit click. Their precedence is the whole behaviour, and it is
 * easy to get wrong: a pointer drifting across a settings list must not
 * overwrite what someone navigated to with the Tab key.
 *
 * Keeping that as a pure reducer means it can be tested without a DOM, and the
 * provider stays a thin wrapper. See `__tests__/inspectorState.test.js`.
 *
 * @since x-release-please-version
 */

import { createContext, useContext } from '@wordpress/element';

/**
 * How a subject reached the panel, in ascending order of authority.
 *
 * `pinned` is an explicit click — it survives the pointer leaving and the
 * element losing focus, and is only replaced by another explicit action.
 */
export const SOURCE_RANK = {
	hover: 1,
	focus: 2,
	pinned: 3,
};

/** The empty state: nothing focused, nothing explained. */
export const EMPTY_INSPECTOR_STATE = {
	subject: null,
	source: null,
};

/**
 * The single state transition for the inspector.
 *
 * Pure, so the precedence rules are testable without rendering anything.
 *
 * @param {Object} state  Current state: `{ subject, source }`.
 * @param {Object} action `{ type: 'show', subject, source }`, `{ type: 'hide', source }`,
 *                        or `{ type: 'clear' }`.
 * @return {Object} The next state.
 */
export const reduceInspector = ( state, action ) => {
	switch ( action.type ) {
		case 'show': {
			const incoming = SOURCE_RANK[ action.source ] ?? 0;
			const current = SOURCE_RANK[ state.source ] ?? 0;

			// A weaker source never displaces a stronger one. This is the rule
			// that stops a mouse passing over the page from replacing what the
			// keyboard focused.
			if ( incoming < current ) {
				return state;
			}

			// Re-showing the same subject from the same source is a no-op, so
			// repeated focus events do not churn the panel.
			if (
				state.subject === action.subject &&
				state.source === action.source
			) {
				return state;
			}

			return { subject: action.subject, source: action.source };
		}

		case 'hide': {
			// Only the source that owns the panel may release it.
			if ( state.source !== action.source ) {
				return state;
			}
			return EMPTY_INSPECTOR_STATE;
		}

		case 'clear':
			return EMPTY_INSPECTOR_STATE;

		default:
			return state;
	}
};

/**
 * Returned when a component uses the inspector outside a provider.
 *
 * Deliberately inert rather than throwing: a settings row must render and be
 * testable on its own, and the panel is an enhancement, never a dependency.
 */
const NO_PROVIDER = {
	subject: null,
	source: null,
	show: () => {},
	hide: () => {},
	clear: () => {},
	isOpen: false,
	setOpen: () => {},
};

const InspectorContext = createContext( NO_PROVIDER );

/**
 * Read the inspector from any component.
 *
 * @return {{subject: Object|null, source: string|null, show: Function, hide: Function, clear: Function, isOpen: boolean, setOpen: Function}} The inspector API.
 */
export const useInspector = () => useContext( InspectorContext );

export default InspectorContext;
