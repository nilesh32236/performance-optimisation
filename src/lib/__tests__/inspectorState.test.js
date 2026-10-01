/**
 * Tests for the inspector state machine.
 *
 * The behaviour worth protecting is the precedence between the three sources
 * that compete for the panel. Pointer hover is the weakest: a mouse drifting
 * across a settings list must never replace what someone navigated to with the
 * Tab key, and an explicit click must survive the pointer leaving entirely.
 *
 * These are pure-function tests — no DOM, no rendering — which is the reason
 * the transition logic was kept out of the provider in the first place.
 *
 * @package
 */

import {
	EMPTY_INSPECTOR_STATE,
	SOURCE_RANK,
	reduceInspector,
} from '../InspectorContext';

const a = { id: 'setting:a', title: 'A' };
const b = { id: 'setting:b', title: 'B' };

const show = ( state, subject, source ) =>
	reduceInspector( state, { type: 'show', subject, source } );
const hide = ( state, source ) =>
	reduceInspector( state, { type: 'hide', source } );

describe( 'inspector source ranking', () => {
	it( 'orders hover below focus below pinned', () => {
		expect( SOURCE_RANK.hover ).toBeLessThan( SOURCE_RANK.focus );
		expect( SOURCE_RANK.focus ).toBeLessThan( SOURCE_RANK.pinned );
	} );
} );

describe( 'reduceInspector — show', () => {
	it( 'shows a subject from hover when nothing is focused', () => {
		expect( show( EMPTY_INSPECTOR_STATE, a, 'hover' ) ).toEqual( {
			subject: a,
			source: 'hover',
		} );
	} );

	it( 'lets focus replace a hover preview', () => {
		const hovered = show( EMPTY_INSPECTOR_STATE, a, 'hover' );
		expect( show( hovered, b, 'focus' ) ).toEqual( {
			subject: b,
			source: 'focus',
		} );
	} );

	it( 'IGNORES hover while a subject is focused — the rule that matters', () => {
		const focused = show( EMPTY_INSPECTOR_STATE, a, 'focus' );
		// A pointer passing over another row must not steal the panel.
		expect( show( focused, b, 'hover' ) ).toBe( focused );
	} );

	it( 'lets a pin replace focus', () => {
		const focused = show( EMPTY_INSPECTOR_STATE, a, 'focus' );
		expect( show( focused, b, 'pinned' ) ).toEqual( {
			subject: b,
			source: 'pinned',
		} );
	} );

	it( 'IGNORES focus and hover while a subject is pinned', () => {
		const pinned = show( EMPTY_INSPECTOR_STATE, a, 'pinned' );
		expect( show( pinned, b, 'focus' ) ).toBe( pinned );
		expect( show( pinned, b, 'hover' ) ).toBe( pinned );
	} );

	it( 'lets a second pin replace the first', () => {
		const pinned = show( EMPTY_INSPECTOR_STATE, a, 'pinned' );
		expect( show( pinned, b, 'pinned' ) ).toEqual( {
			subject: b,
			source: 'pinned',
		} );
	} );

	it( 'is a no-op when the same subject is shown from the same source', () => {
		const focused = show( EMPTY_INSPECTOR_STATE, a, 'focus' );
		// Repeated focus events must not churn the panel.
		expect( show( focused, a, 'focus' ) ).toBe( focused );
	} );

	it( 'treats an unknown source as weakest rather than crashing', () => {
		const focused = show( EMPTY_INSPECTOR_STATE, a, 'focus' );
		expect( show( focused, b, 'nonsense' ) ).toBe( focused );
	} );
} );

describe( 'reduceInspector — hide', () => {
	it( 'clears when the owning source releases', () => {
		const focused = show( EMPTY_INSPECTOR_STATE, a, 'focus' );
		expect( hide( focused, 'focus' ) ).toEqual( EMPTY_INSPECTOR_STATE );
	} );

	it( 'does NOT clear when a different source releases', () => {
		const focused = show( EMPTY_INSPECTOR_STATE, a, 'focus' );
		// The mouse leaving the row must not blank a keyboard-focused subject.
		expect( hide( focused, 'hover' ) ).toBe( focused );
	} );

	it( 'does not let hover release a pin', () => {
		const pinned = show( EMPTY_INSPECTOR_STATE, a, 'pinned' );
		expect( hide( pinned, 'hover' ) ).toBe( pinned );
		expect( hide( pinned, 'focus' ) ).toBe( pinned );
	} );

	it( 'lets a pin release itself', () => {
		const pinned = show( EMPTY_INSPECTOR_STATE, a, 'pinned' );
		expect( hide( pinned, 'pinned' ) ).toEqual( EMPTY_INSPECTOR_STATE );
	} );
} );

describe( 'reduceInspector — clear and unknown actions', () => {
	it( 'clears from any source', () => {
		const pinned = show( EMPTY_INSPECTOR_STATE, a, 'pinned' );
		expect( reduceInspector( pinned, { type: 'clear' } ) ).toEqual(
			EMPTY_INSPECTOR_STATE
		);
	} );

	it( 'leaves state untouched for an unknown action', () => {
		const focused = show( EMPTY_INSPECTOR_STATE, a, 'focus' );
		expect( reduceInspector( focused, { type: 'wat' } ) ).toBe( focused );
	} );
} );
