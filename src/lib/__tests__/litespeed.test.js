import {
	getEffectiveMode,
	shouldDisableOptimizer,
	getVaryEnv,
	modeLabel,
} from '../litespeed';

describe( 'litespeed helpers', () => {
	it( 'getEffectiveMode auto\u2192wppo/litespeed resolves correctly', () => {
		expect(
			getEffectiveMode( {
				mode: 'auto',
				isLiteSpeed: false,
				isLscacheActive: false,
			} )
		).toBe( 'standalone' );
		expect(
			getEffectiveMode( {
				mode: 'auto',
				isLiteSpeed: true,
				isLscacheActive: false,
			} )
		).toBe( 'wppo' );
		expect(
			getEffectiveMode( {
				mode: 'auto',
				isLiteSpeed: true,
				isLscacheActive: true,
			} )
		).toBe( 'litespeed' );
		expect(
			getEffectiveMode( {
				mode: 'wppo',
				isLiteSpeed: true,
				isLscacheActive: true,
			} )
		).toBe( 'wppo' );
		expect(
			getEffectiveMode( {
				mode: 'litespeed',
				isLiteSpeed: true,
				isLscacheActive: false,
			} )
		).toBe( 'litespeed' );
		expect(
			getEffectiveMode( {
				mode: 'standalone',
				isLiteSpeed: true,
				isLscacheActive: true,
			} )
		).toBe( 'standalone' );
	} );

	it( 'shouldDisableOptimizer true when litespeed owns cache', () => {
		expect(
			shouldDisableOptimizer( {
				isLscacheActive: true,
				mode: 'litespeed',
				isLiteSpeed: true,
			} )
		).toBe( true );
		expect(
			shouldDisableOptimizer( {
				isLscacheActive: true,
				mode: 'wppo',
				isLiteSpeed: true,
			} )
		).toBe( false );
		expect( shouldDisableOptimizer( { isLscacheActive: false } ) ).toBe(
			false
		);
	} );

	it( 'getVaryEnv joins groups', () => {
		expect( getVaryEnv( { role: true, mobile: true } ) ).toBe(
			'wppo_role_hash,mobile'
		);
		expect( getVaryEnv( { webp: true } ) ).toBe( 'webp' );
	} );

	it( 'modeLabel maps correctly', () => {
		expect( modeLabel( 'litespeed' ) ).toBe( 'LiteSpeed Cache' );
		expect( modeLabel( 'auto' ) ).toBe( 'Auto' );
	} );
} );
