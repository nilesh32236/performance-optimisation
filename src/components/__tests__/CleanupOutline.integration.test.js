/**
 * The real component, not a stand-in for it.
 *
 * The unit tests beside this one render `FeatureCard` directly, which proved
 * nothing about the two changes that matter: an independent review deleted
 * `titleAs="h4"` from `DatabaseCleanup.js` and **every test stayed green**,
 * because none of them ever loaded the component that carries the prop.
 *
 * A previous attempt pinned it with a source-text search instead. That was
 * rejected for two reasons, both fair: the stated reason ("the component issues
 * API calls on mount, so a render assertion is impractical") was simply false —
 * the existing `DatabaseCleanup.test.js` renders it in ~25 tests with `apiCall`
 * already mocked — and the search's scope was broken, because its anchor string
 * did not exist in the file, so it silently degraded to "this substring occurs
 * somewhere", which a comment would satisfy.
 *
 * So: render the component.
 *
 * @package
 */

// eslint-disable-next-line import/no-extraneous-dependencies
import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

jest.mock( '../../lib/apiRequest', () => ( {
	apiCall: jest.fn(),
	getErrorLogMessage: ( error ) =>
		error instanceof Error ? error.message : String( error ),
} ) );

import DatabaseCleanup from '../DatabaseCleanup';

import { apiCall } from '../../lib/apiRequest';

import { clearDbCountsCache } from '../../lib/dbCounts';

const COUNTS = {
	revisions: 12,
	autodrafts: 3,
	trashed_posts: 1,
	spam_comments: 0,
	trashed_comments: 0,
	expired_transients: 40,
	orphaned_meta: 0,
	unattached_media: 2,
};

const renderReal = async () => {
	apiCall.mockResolvedValue( { success: true, data: COUNTS } );
	const view = render( <DatabaseCleanup /> );
	await waitFor( () =>
		expect(
			screen.getByRole( 'heading', { name: /Granular Cleanup Options/i } )
		).toBeInTheDocument()
	);
	return view;
};

describe( 'DatabaseCleanup: the granular section and its ten types', () => {
	beforeEach( () => {
		global.wppoSettings = {};
		jest.clearAllMocks();
		clearDbCountsCache();
	} );

	it( 'nests the ten types under a peer section, with no level skipped', async () => {
		const { container } = await renderReal();

		// "Granular Cleanup Options" was an `<h4>` while the ten types were
		// `<h3>`, so the outline went back *up*. It is a DOM **sibling** of the
		// two `<h3>` cards above it, so it must be a peer `<h3>`.
		const section = screen.getByRole( 'heading', {
			name: /Granular Cleanup Options/i,
		} );
		expect( section.tagName ).toBe( 'H3' );

		// Every type is one level below its section, and still a heading.
		const typeHeadings = [
			...container.querySelectorAll( '.wppo-feature-card__header > h4' ),
		];
		expect( typeHeadings.length ).toBeGreaterThanOrEqual( 9 );
	} );

	it( 'keeps a navigation stop for every type name', async () => {
		await renderReal();
		for ( const name of [
			'Post Revisions',
			'Auto Drafts',
			'Trashed Posts',
			'Spam Comments',
			'Trashed Comments',
			'Expired Transients',
			'Orphaned Post Meta',
			'Unattached Media',
		] ) {
			expect(
				screen.getByRole( 'heading', {
					level: 4,
					name: new RegExp( name ),
				} )
			).toBeInTheDocument();
		}
	} );

	// The pre-existing bug this PR was filed to be adjacent to: ten rows whose
	// buttons were all named "Clean".
	it( 'gives every action a name that says which row it cleans', async () => {
		await renderReal();
		expect(
			screen.getByRole( 'button', {
				name: /Clean Post Revisions/,
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Clean Auto Drafts/ } )
		).toBeInTheDocument();
		// No button is left with the bare, ambiguous name.
		expect(
			screen.queryByRole( 'button', { name: /^Clean$/ } )
		).toBeNull();
	} );

	it( 'produces a valid outline: no heading level is skipped', async () => {
		const { container } = await renderReal();
		const levels = [
			...container.querySelectorAll( 'h1, h2, h3, h4, h5, h6' ),
		].map( ( h ) => Number( h.tagName[ 1 ] ) );

		expect( levels.length ).toBeGreaterThan( 0 );
		for ( let i = 1; i < levels.length; i++ ) {
			// A jump of more than one level going down is a skip; a jump of more
			// than one going back up is the defect this file exists for.
			expect(
				Math.abs( levels[ i ] - levels[ i - 1 ] )
			).toBeLessThanOrEqual( 1 );
		}
	} );
} );
