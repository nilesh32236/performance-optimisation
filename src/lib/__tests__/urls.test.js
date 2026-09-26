import { isSafeHttpUrl } from '../urls';

describe( 'isSafeHttpUrl', () => {
	it( 'accepts http(s) URLs', () => {
		expect( isSafeHttpUrl( 'https://example.com/?wppo_nocache=1' ) ).toBe(
			true
		);
		expect( isSafeHttpUrl( 'http://example.com/' ) ).toBe( true );
	} );

	it( 'rejects non-http(s) and malformed URLs', () => {
		expect( isSafeHttpUrl( 'javascript:alert(1)' ) ).toBe( false );
		expect( isSafeHttpUrl( 'data:text/html,hi' ) ).toBe( false );
		expect( isSafeHttpUrl( '/relative/path' ) ).toBe( false );
		expect( isSafeHttpUrl( '' ) ).toBe( false );
		expect( isSafeHttpUrl( null ) ).toBe( false );
	} );
} );
