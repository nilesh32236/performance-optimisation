/**
 * Routing tests for `useSectionRoute`.
 *
 * The defect being fixed was proven live: walking all seven tabs left
 * `location.href` unchanged, so refresh lost the section and Back/Forward were
 * inert. These tests pin the contract that replaces that.
 */

import { act, renderHook } from '@testing-library/react';

import {
	buildSearch,
	buildSections,
	readSection,
	SECTION_PARAM,
	useSectionRoute,
} from '../useSectionRoute';

const SECTIONS = [ 'overview', 'speed', 'media', 'data-system', 'manage' ];

describe( 'readSection', () => {
	it( 'reads a valid section from the URL', () => {
		expect(
			readSection(
				'?page=performance-optimisation&section=speed',
				'overview',
				SECTIONS
			)
		).toBe( 'speed' );
	} );

	it( 'falls back when the key is absent', () => {
		expect(
			readSection(
				'?page=performance-optimisation',
				'overview',
				SECTIONS
			)
		).toBe( 'overview' );
	} );

	it( 'falls back on an unknown value rather than rendering nothing', () => {
		expect(
			readSection( '?section=not-a-section', 'overview', SECTIONS )
		).toBe( 'overview' );
	} );

	it( 'falls back on an empty value', () => {
		expect( readSection( '?section=', 'overview', SECTIONS ) ).toBe(
			'overview'
		);
	} );

	it( 'falls back rather than throwing on a malformed query string', () => {
		// A stray '%' makes URLSearchParams parsing hostile; the admin must
		// still render.
		expect( () =>
			readSection( '?section=%E0%A4%A', 'overview', SECTIONS )
		).not.toThrow();
		expect( readSection( '?section=%E0%A4%A', 'overview', SECTIONS ) ).toBe(
			'overview'
		);
	} );

	it( 'is not fooled by a section name in a different parameter', () => {
		expect( readSection( '?tab=speed', 'overview', SECTIONS ) ).toBe(
			'overview'
		);
	} );
} );

describe( 'buildSearch', () => {
	it( 'adds the section while preserving the WordPress page key', () => {
		const result = buildSearch( 'media', '?page=performance-optimisation' );
		expect( result ).toContain( 'page=performance-optimisation' );
		expect( result ).toContain( `${ SECTION_PARAM }=media` );
	} );

	it( 'preserves unrelated admin query args such as a nonce', () => {
		const result = buildSearch(
			'manage',
			'?page=performance-optimisation&_wpnonce=abc123'
		);
		expect( result ).toContain( '_wpnonce=abc123' );
	} );

	it( 'replaces an existing section rather than duplicating it', () => {
		const once = buildSearch( 'speed', '?page=x' );
		const twice = buildSearch( 'media', once );
		expect(
			twice.match( new RegExp( `${ SECTION_PARAM }=`, 'g' ) )
		).toHaveLength( 1 );
		expect( twice ).toContain( `${ SECTION_PARAM }=media` );
	} );
} );

describe( 'buildSections', () => {
	it( 'produces the five target areas in order', () => {
		const sections = buildSections( ( s ) => s );
		expect( sections.map( ( s ) => s.id ) ).toEqual( SECTIONS );
		expect( sections.map( ( s ) => s.label ) ).toEqual( [
			'Overview',
			'Speed',
			'Media',
			'Data & System',
			'Manage',
		] );
	} );
} );

describe( 'useSectionRoute', () => {
	beforeEach( () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=performance-optimisation'
		);
	} );

	it( 'reads the initial section from the URL', () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=performance-optimisation&section=media'
		);
		const { result } = renderHook( () =>
			useSectionRoute( {
				defaultSection: 'overview',
				sections: SECTIONS,
			} )
		);
		expect( result.current.active ).toBe( 'media' );
	} );

	it( 'writes the default section into a bare URL without a reload', () => {
		const { result } = renderHook( () =>
			useSectionRoute( {
				defaultSection: 'overview',
				sections: SECTIONS,
			} )
		);
		expect( result.current.active ).toBe( 'overview' );
		expect( window.location.search ).toContain(
			`${ SECTION_PARAM }=overview`
		);
		// replaceState, not a navigation: the pathname is untouched.
		expect( window.location.pathname ).toBe( '/wp-admin/admin.php' );
	} );

	it( 'pushes history on in-app navigation so Back returns to the previous section', () => {
		const { result } = renderHook( () =>
			useSectionRoute( {
				defaultSection: 'overview',
				sections: SECTIONS,
			} )
		);

		act( () => {
			result.current.navigate( 'speed' );
		} );
		expect( result.current.active ).toBe( 'speed' );
		expect( window.location.search ).toContain(
			`${ SECTION_PARAM }=speed`
		);
		expect( window.history.length ).toBeGreaterThan( 1 );
	} );

	it( 'follows popstate so Back and Forward drive the section', () => {
		const { result } = renderHook( () =>
			useSectionRoute( {
				defaultSection: 'overview',
				sections: SECTIONS,
			} )
		);
		act( () => {
			result.current.navigate( 'data-system' );
		} );

		// Simulate the browser moving back to the previous entry.
		window.history.replaceState(
			{},
			'',
			'?page=performance-optimisation&section=overview'
		);
		act( () => {
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );

		expect( result.current.active ).toBe( 'overview' );
	} );

	it( 'refuses an unknown section without touching the URL', () => {
		const { result } = renderHook( () =>
			useSectionRoute( {
				defaultSection: 'overview',
				sections: SECTIONS,
			} )
		);
		const before = window.location.search;
		act( () => {
			result.current.navigate( 'nope' );
		} );
		expect( result.current.active ).toBe( 'overview' );
		expect( window.location.search ).toBe( before );
	} );

	it( 'keeps the URL and the visible section in agreement after a popstate to an unknown value', () => {
		const { result } = renderHook( () =>
			useSectionRoute( {
				defaultSection: 'overview',
				sections: SECTIONS,
			} )
		);
		window.history.replaceState( {}, '', '?page=x&section=garbage' );
		act( () => {
			window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
		} );
		expect( result.current.active ).toBe( 'overview' );
	} );

	it( 'unsubscribes its popstate listener on unmount', () => {
		const remove = jest.spyOn( window, 'removeEventListener' );
		const { unmount } = renderHook( () =>
			useSectionRoute( {
				defaultSection: 'overview',
				sections: SECTIONS,
			} )
		);
		unmount();
		expect( remove ).toHaveBeenCalledWith(
			'popstate',
			expect.any( Function )
		);
		remove.mockRestore();
	} );
} );
