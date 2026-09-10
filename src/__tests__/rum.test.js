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
} );
