import {
	classifyDeviceWidth,
	sanitizeRumValues,
	RUM_MAX_METRIC_MS,
} from '../rum';

describe( 'classifyDeviceWidth', () => {
	it( 'classifies a desktop from its physical screen even when the viewport is narrowed', () => {
		expect( classifyDeviceWidth( 1920, 900 ) ).toBe( false );
	} );

	it( 'classifies a 768px tablet as mobile', () => {
		expect( classifyDeviceWidth( 768, 768 ) ).toBe( true );
	} );

	it( 'classifies a 390px phone as mobile', () => {
		expect( classifyDeviceWidth( 390, 390 ) ).toBe( true );
	} );

	it( 'falls back to the viewport width when the screen width is unavailable', () => {
		expect( classifyDeviceWidth( 0, 390 ) ).toBe( true );
		expect( classifyDeviceWidth( 0, 1280 ) ).toBe( false );
	} );

	it( 'returns null when no width is available', () => {
		expect( classifyDeviceWidth( 0, 0 ) ).toBe( null );
	} );

	it( 'treats the 1024px boundary as mobile and 1025px as desktop', () => {
		expect( classifyDeviceWidth( 1024, 0 ) ).toBe( true );
		expect( classifyDeviceWidth( 1025, 0 ) ).toBe( false );
	} );

	it( 'coerces numeric strings', () => {
		expect( classifyDeviceWidth( '768', 0 ) ).toBe( true );
		expect( classifyDeviceWidth( '1280', 0 ) ).toBe( false );
	} );

	it( 'falls back to the viewport for NaN or negative screen widths', () => {
		expect( classifyDeviceWidth( NaN, 390 ) ).toBe( true );
		expect( classifyDeviceWidth( -1, 1280 ) ).toBe( false );
	} );

	it( 'returns null when both screen and viewport widths are invalid', () => {
		expect( classifyDeviceWidth( NaN, NaN ) ).toBe( null );
		expect( classifyDeviceWidth( -1, -1 ) ).toBe( null );
	} );
} );

describe( 'sanitizeRumValues', () => {
	it( 'caps time metrics at RUM_MAX_METRIC_MS', () => {
		expect( RUM_MAX_METRIC_MS ).toBe( 60000 );
		const clean = sanitizeRumValues( {
			ttfb: 120,
			fcp: 800,
			lcp: 2500,
			inp: 96,
			cls: 0.05,
		} );
		expect( clean ).toEqual( {
			ttfb: 120,
			fcp: 800,
			lcp: 2500,
			inp: 96,
			cls: 0.05,
		} );
	} );

	it( 'drops time metrics above 60000ms instead of sending them', () => {
		const clean = sanitizeRumValues( {
			lcp: 120000,
			inp: 99999,
			ttfb: 50,
		} );
		expect( clean.lcp ).toBeUndefined();
		expect( clean.inp ).toBeUndefined();
		expect( clean.ttfb ).toBe( 50 );
	} );

	it( 'drops negative, non-finite and non-numeric metrics', () => {
		const clean = sanitizeRumValues( {
			lcp: -5,
			fcp: NaN,
			inp: Infinity,
			ttfb: 'fast',
			cls: '0.1',
		} );
		expect( clean ).toEqual( {} );
	} );

	it( 'drops CLS outside 0–1', () => {
		expect( sanitizeRumValues( { cls: 2.5 } ) ).toEqual( {} );
		expect( sanitizeRumValues( { cls: -0.1 } ) ).toEqual( {} );
		expect( sanitizeRumValues( { cls: 0.25 } ) ).toEqual( {
			cls: 0.25,
		} );
	} );

	it( 'passes a validated lcpUrl through and ignores unknown input', () => {
		const clean = sanitizeRumValues( {
			lcp: 1200,
			lcpUrl: 'https://example.com/hero.jpg',
		} );
		expect( clean.lcpUrl ).toBe( 'https://example.com/hero.jpg' );
		expect( sanitizeRumValues( null ) ).toEqual( {} );
		expect( sanitizeRumValues( 'nope' ) ).toEqual( {} );
	} );
} );
