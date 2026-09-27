/**
 * Every key the status model can emit must have copy, and the copy must take the
 * model's arguments.
 *
 * The model returns a key plus arguments rather than English prose, so a key
 * with no entry here would render a **blank row** — a silent failure that looks
 * like a layout problem rather than a missing translation. That is exactly the
 * failure mode this file exists to prevent.
 *
 * @package
 */

import { DETAIL_COPY, renderDetail } from '../detailCopy';
import { DETAIL } from '../../../lib/overviewStatus';

describe( 'Overview detail copy', () => {
	it( 'has an entry for every key the model can emit', () => {
		const missing = Object.values( DETAIL ).filter(
			( key ) => ! DETAIL_COPY[ key ]
		);
		expect( missing ).toEqual( [] );
	} );

	it( 'has no entry the model cannot emit', () => {
		// A stale entry is dead copy that translators would be asked to
		// translate for a sentence no user can see.
		const emit = new Set( Object.values( DETAIL ) );
		const orphan = Object.keys( DETAIL_COPY ).filter(
			( k ) => ! emit.has( k )
		);
		expect( orphan ).toEqual( [] );
	} );

	it( 'renders a sentence rather than a key or a blank', () => {
		const sentence = renderDetail( {
			detailKey: DETAIL.cache_active,
			detailArgs: { stored: '9 MB' },
		} );
		expect( sentence ).toMatch( /Page cache is on, with 9 MB/ );
		expect( sentence ).not.toContain( 'cache-active' );
	} );

	it( 'interpolates both versions in the compatibility sentence', () => {
		const sentence = renderDetail( {
			detailKey: DETAIL.system_versions,
			detailArgs: { wp: '7.1.2', php: '8.3' },
		} );
		// Positional placeholders, so a translator can reorder them.
		expect( sentence ).toBe( 'Running on WordPress 7.1.2 and PHP 8.3.' );
	} );

	it( 'interpolates the metric, value and advice for a vital', () => {
		const sentence = renderDetail( {
			detailKey: DETAIL.vital_poor,
			detailArgs: {
				label: 'Loading (LCP)',
				value: '4000 ms',
				hint: 'How long the main content takes to appear.',
			},
		} );
		expect( sentence ).toContain( 'Loading (LCP)' );
		expect( sentence ).toContain( '4000 ms' );
		expect( sentence ).toContain(
			'How long the main content takes to appear.'
		);
	} );

	it( 'renders nothing for an unknown key, not the key itself', () => {
		expect( renderDetail( { detailKey: 'no-such-key' } ) ).toBe( '' );
		expect( renderDetail( {} ) ).toBe( '' );
		expect( renderDetail( null ) ).toBe( '' );
	} );

	it( 'never emits an unresolved sprintf placeholder', () => {
		// A key whose mapping and args disagree would leak a literal %s into
		// the UI. Every key is rendered with a plausible argument set.
		const args = {
			stored: '1 MB',
			wp: '7.1.2',
			php: '8.3',
			label: 'Metric',
			value: '1 ms',
			hint: 'Advice.',
		};
		Object.values( DETAIL ).forEach( ( key ) => {
			expect(
				renderDetail( { detailKey: key, detailArgs: args } )
			).not.toMatch( /%[sd]/ );
		} );
	} );
} );
