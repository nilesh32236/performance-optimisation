import { classifyDeviceWidth } from '../rum';

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
