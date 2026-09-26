import {
	WOO_SELF_TEST_TIMEOUT_MS,
	formatWooSummary,
	getWooCheckState,
	getWooSelfTestNotice,
	isWooSelfTestUnrunnable,
	shouldShowWooFixCta,
} from '../wooSelfTest';

describe( 'wooSelfTest', () => {
	it( 'exports a 5s timeout budget', () => {
		expect( WOO_SELF_TEST_TIMEOUT_MS ).toBe( 5000 );
	} );

	it( 'isWooSelfTestUnrunnable flags unrunnable, inactive, or malformed payloads', () => {
		expect(
			isWooSelfTestUnrunnable( { runnable: true, woo_active: true } )
		).toBe( false );
		expect(
			isWooSelfTestUnrunnable( { runnable: false, woo_active: true } )
		).toBe( true );
		expect(
			isWooSelfTestUnrunnable( { runnable: true, woo_active: false } )
		).toBe( true );
		expect( isWooSelfTestUnrunnable( null ) ).toBe( true );
		expect( isWooSelfTestUnrunnable( undefined ) ).toBe( true );
	} );

	it( 'shouldShowWooFixCta requires runnable, active, and explicit failure', () => {
		expect(
			shouldShowWooFixCta( {
				runnable: true,
				woo_active: true,
				all_pass: false,
			} )
		).toBe( true );
		expect(
			shouldShowWooFixCta( {
				runnable: true,
				woo_active: true,
				all_pass: true,
				force_exclude: true,
			} )
		).toBe( true );
		// Inactive Woo shows read-only copy, never the fix CTA.
		expect(
			shouldShowWooFixCta( {
				runnable: true,
				woo_active: false,
				all_pass: false,
			} )
		).toBe( false );
		// Missing all_pass with no force_exclude is inconclusive, no CTA.
		expect(
			shouldShowWooFixCta( { runnable: true, woo_active: true } )
		).toBe( false );
		expect( shouldShowWooFixCta( null ) ).toBe( false );
	} );

	it( 'getWooCheckState is an explicit tri-state', () => {
		expect( getWooCheckState( true ) ).toBe( 'pass' );
		expect( getWooCheckState( false ) ).toBe( 'fail' );
		expect( getWooCheckState( undefined ) ).toBe( 'inconclusive' );
		expect( getWooCheckState( null ) ).toBe( 'inconclusive' );
	} );

	it( 'getWooSelfTestNotice never warns on malformed payloads', () => {
		expect(
			getWooSelfTestNotice( { runnable: false, woo_active: true } ).type
		).toBe( 'info' );
		expect(
			getWooSelfTestNotice( {
				runnable: true,
				woo_active: false,
			} ).type
		).toBe( 'info' );
		expect(
			getWooSelfTestNotice( {
				runnable: true,
				woo_active: true,
				all_pass: true,
			} ).type
		).toBe( 'success' );
		expect(
			getWooSelfTestNotice( {
				runnable: true,
				woo_active: true,
				all_pass: false,
			} ).type
		).toBe( 'warning' );
		const inconclusive = getWooSelfTestNotice( {
			runnable: true,
			woo_active: true,
		} );
		expect( inconclusive.type ).toBe( 'info' );
		expect( inconclusive.message ).toMatch( /inconclusive/i );
	} );

	it( 'formatWooSummary renders a single translators string', () => {
		expect( formatWooSummary( true, [ 'cart', 'checkout' ] ) ).toBe(
			'Safe mode: On • Excluded paths: cart, checkout'
		);
		expect( formatWooSummary( false, null ) ).toBe(
			'Safe mode: Off • Excluded paths: '
		);
	} );
} );
