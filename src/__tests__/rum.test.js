import {
	classifyDeviceWidth,
	classifyConnectionType,
	RUM_ALLOWED_CONNECTIONS,
	sanitizeRumValues,
	RUM_MAX_METRIC_MS,
	shouldSendSample,
	RUM_DEFAULT_SAMPLE_RATE,
	deriveLcpSelector,
	sanitizeSlowResourceEntry,
	collectSlowResources,
	RUM_MAX_LCP_SELECTOR_LENGTH,
	RUM_MAX_SLOW_RESOURCES,
	RUM_SLOW_RESOURCE_THRESHOLD_MS,
	RUM_ALLOWED_RESOURCE_TYPES,
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

describe( 'classifyConnectionType', () => {
	it( 'passes the allowlisted effective connection types through', () => {
		expect( RUM_ALLOWED_CONNECTIONS ).toEqual( [
			'slow-2g',
			'2g',
			'3g',
			'4g',
		] );
		expect( classifyConnectionType( '4g' ) ).toBe( '4g' );
		expect( classifyConnectionType( '3g' ) ).toBe( '3g' );
		expect( classifyConnectionType( '2g' ) ).toBe( '2g' );
		expect( classifyConnectionType( 'slow-2g' ) ).toBe( 'slow-2g' );
	} );

	it( 'normalizes case and surrounding whitespace', () => {
		expect( classifyConnectionType( ' 4G ' ) ).toBe( '4g' );
	} );

	it( 'omits unknown, empty and non-string values (fail-open)', () => {
		expect( classifyConnectionType( '5g' ) ).toBe( null );
		expect( classifyConnectionType( '' ) ).toBe( null );
		expect( classifyConnectionType( null ) ).toBe( null );
		expect( classifyConnectionType( undefined ) ).toBe( null );
		expect( classifyConnectionType( 4 ) ).toBe( null );
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

	it( 'rejects out-of-policy lcpUrl values and drops unknown keys', () => {
		expect(
			sanitizeRumValues( { lcp: 1200, lcpUrl: 'javascript:alert(1)' } )
				.lcpUrl
		).toBeUndefined();
		expect(
			sanitizeRumValues( {
				lcp: 1200,
				lcpUrl: 'data:image/png;base64,x',
			} ).lcpUrl
		).toBeUndefined();
		expect(
			sanitizeRumValues( {
				lcp: 1200,
				lcpUrl: `https://example.com/${ 'a'.repeat( 2048 ) }`,
			} ).lcpUrl
		).toBeUndefined();
		expect(
			sanitizeRumValues( { lcp: 1200, lcpUrl: '' } ).lcpUrl
		).toBeUndefined();
		expect(
			sanitizeRumValues( {
				lcp: 1200,
				lcpUrl: 'http://example.com/hero.jpg',
			} ).lcpUrl
		).toBe( 'http://example.com/hero.jpg' );
		expect(
			sanitizeRumValues( { lcp: 1200, lcpUrl: '/hero.jpg' } ).lcpUrl
		).toBe( '/hero.jpg' );
		const clean = sanitizeRumValues( {
			ttfb: 50,
			evilKey: 'evil',
			__proto__: 'pollution',
		} );
		expect( clean ).toEqual( { ttfb: 50 } );
		expect( clean.evilKey ).toBeUndefined();
	} );
} );

describe( 'shouldSendSample', () => {
	it( 'defaults to 100 (unsampled current behavior)', () => {
		expect( RUM_DEFAULT_SAMPLE_RATE ).toBe( 100 );
	} );

	it( 'sends about rate percent over 1k views', () => {
		let hits = 0;
		const views = 1000;
		for ( let i = 0; i < views; i++ ) {
			if ( shouldSendSample( 10, i / views ) ) {
				hits++;
			}
		}
		// Inclusive boundary (roll * 100 <= rate, matching PHP roll <= rate):
		// rolls 0..0.10 inclusive keep, so i=0..100 hit = 101 views.
		expect( hits ).toBe( 101 );
	} );

	it( 'clamps invalid rates to 100 (fail-open to unsampled)', () => {
		for ( const bad of [
			undefined,
			null,
			0,
			-5,
			101,
			1000,
			NaN,
			'nope',
			{},
			[],
		] ) {
			expect( shouldSendSample( bad, 0.999 ) ).toBe( true );
		}
	} );

	it( 'keeps only the first percentile at rate 1', () => {
		expect( shouldSendSample( 1, 0.0 ) ).toBe( true );
		expect( shouldSendSample( 1, 0.009 ) ).toBe( true );
		// Inclusive boundary, matching PHP should_keep_sample( 1, 1 ).
		expect( shouldSendSample( 1, 0.01 ) ).toBe( true );
		expect( shouldSendSample( 1, 0.0101 ) ).toBe( false );
	} );

	it( 'keeps a roll exactly on the boundary (matches PHP roll <= rate)', () => {
		expect( shouldSendSample( 10, 0.1 ) ).toBe( true );
		expect( shouldSendSample( 10, 0.1001 ) ).toBe( false );
	} );

	it( 'fails open on a non-finite roll', () => {
		expect( shouldSendSample( 10, NaN ) ).toBe( true );
	} );
} );

describe( 'deriveLcpSelector', () => {
	it( 'derives tag#id when the element has a valid id', () => {
		expect(
			deriveLcpSelector( {
				tagName: 'IMG',
				id: 'hero-image',
				classList: { length: 0 },
			} )
		).toBe( 'img#hero-image' );
	} );

	it( 'derives tag.first-class when no id is present', () => {
		expect(
			deriveLcpSelector( {
				tagName: 'DIV',
				id: '',
				classList: { length: 2, 0: 'hero', 1: 'lazy' },
			} )
		).toBe( 'div.hero' );
	} );

	it( 'falls back to the className string when classList is absent', () => {
		expect(
			deriveLcpSelector( {
				tagName: 'SECTION',
				className: 'lead  extra',
			} )
		).toBe( 'section.lead' );
	} );

	it( 'returns a bare tag when neither id nor class is usable', () => {
		expect( deriveLcpSelector( { tagName: 'H1' } ) ).toBe( 'h1' );
	} );

	it( 'ignores an invalid id and falls back to the first class', () => {
		expect(
			deriveLcpSelector( {
				tagName: 'IMG',
				id: 'has space<script>',
				classList: { length: 1, 0: 'hero' },
			} )
		).toBe( 'img.hero' );
	} );

	it( 'returns null for missing or invalid elements (fail-open)', () => {
		expect( deriveLcpSelector( null ) ).toBe( null );
		expect( deriveLcpSelector( undefined ) ).toBe( null );
		expect( deriveLcpSelector( {} ) ).toBe( null );
		expect( deriveLcpSelector( { tagName: '123-bad' } ) ).toBe( null );
		expect( deriveLcpSelector( { tagName: '<script>' } ) ).toBe( null );
	} );

	it( 'never emits markup, combinators or xpath', () => {
		const selector = deriveLcpSelector( {
			tagName: 'IMG',
			id: 'hero',
		} );
		expect( selector ).toBe( 'img#hero' );
		expect( selector ).not.toMatch( /[<>"'`/\\[\]()]/ );
	} );
} );

describe( 'sanitizeSlowResourceEntry', () => {
	it( 'shapes a valid entry and rounds the duration', () => {
		expect(
			sanitizeSlowResourceEntry( {
				name: 'https://example.com/app.js',
				initiatorType: 'script',
				duration: 450.6,
			} )
		).toEqual( {
			name: 'https://example.com/app.js',
			type: 'script',
			duration: 451,
		} );
	} );

	it( 'accepts the client-shaped type key as well as initiatorType', () => {
		expect(
			sanitizeSlowResourceEntry( {
				name: '/style.css',
				type: 'css',
				duration: 500,
			} ).type
		).toBe( 'css' );
	} );

	it( 'accepts every allowlisted initiator type', () => {
		expect( RUM_ALLOWED_RESOURCE_TYPES ).toContain( 'img' );
		for ( const type of RUM_ALLOWED_RESOURCE_TYPES ) {
			const entry = sanitizeSlowResourceEntry( {
				name: 'https://example.com/asset',
				initiatorType: type.toUpperCase(),
				duration: 400,
			} );
			expect( entry ).not.toBe( null );
			expect( entry.type ).toBe( type );
		}
	} );

	it( 'drops entries with disallowed types, bad urls or out-of-range durations', () => {
		expect(
			sanitizeSlowResourceEntry( {
				name: 'https://example.com/video.mp4',
				initiatorType: 'video',
				duration: 900,
			} )
		).toBe( null );
		expect(
			sanitizeSlowResourceEntry( {
				name: 'data:image/png;base64,x',
				initiatorType: 'img',
				duration: 900,
			} )
		).toBe( null );
		expect(
			sanitizeSlowResourceEntry( {
				name: 'javascript:alert(1)',
				initiatorType: 'script',
				duration: 900,
			} )
		).toBe( null );
		expect(
			sanitizeSlowResourceEntry( {
				name: 'https://example.com/a.js',
				initiatorType: 'script',
				duration: -5,
			} )
		).toBe( null );
		expect(
			sanitizeSlowResourceEntry( {
				name: 'https://example.com/a.js',
				initiatorType: 'script',
				duration: RUM_MAX_METRIC_MS + 1,
			} )
		).toBe( null );
		expect( sanitizeSlowResourceEntry( null ) ).toBe( null );
		expect( sanitizeSlowResourceEntry( 'nope' ) ).toBe( null );
	} );
} );

describe( 'collectSlowResources', () => {
	let originalGetEntriesByType;

	beforeEach( () => {
		originalGetEntriesByType = performance.getEntriesByType;
	} );

	afterEach( () => {
		performance.getEntriesByType = originalGetEntriesByType;
	} );

	const stubResources = ( entries ) => {
		performance.getEntriesByType = jest.fn( ( type ) =>
			type === 'resource' ? entries : []
		);
	};

	it( 'keeps only entries slower than the threshold', () => {
		expect( RUM_SLOW_RESOURCE_THRESHOLD_MS ).toBe( 300 );
		stubResources( [
			{
				name: 'https://example.com/slow.js',
				initiatorType: 'script',
				duration: 900,
			},
			{
				name: 'https://example.com/fast.css',
				initiatorType: 'css',
				duration: 50,
			},
		] );
		const collected = collectSlowResources();
		expect( collected ).toHaveLength( 1 );
		expect( collected[ 0 ].name ).toBe( 'https://example.com/slow.js' );
	} );

	it( 'falls back to the top-3 slowest when nothing crosses the threshold', () => {
		stubResources(
			[ 100, 90, 80, 70, 60 ].map( ( duration, i ) => ( {
				name: `https://example.com/a${ i }.js`,
				initiatorType: 'script',
				duration,
			} ) )
		);
		const collected = collectSlowResources();
		expect( collected ).toHaveLength( 3 );
		expect( collected.map( ( item ) => item.duration ) ).toEqual( [
			100, 90, 80,
		] );
	} );

	it( 'caps the audit at RUM_MAX_SLOW_RESOURCES entries', () => {
		expect( RUM_MAX_SLOW_RESOURCES ).toBe( 5 );
		stubResources(
			Array.from( { length: 8 }, ( _, i ) => ( {
				name: `https://example.com/a${ i }.js`,
				initiatorType: 'script',
				duration: 1000 - i * 10,
			} ) )
		);
		expect( collectSlowResources() ).toHaveLength( 5 );
	} );

	it( 'returns an empty array when resource timing is unavailable', () => {
		performance.getEntriesByType = undefined;
		expect( collectSlowResources() ).toEqual( [] );
	} );

	it( 'drops the fastest entries first when the ~1.5KB budget overflows', () => {
		stubResources(
			Array.from( { length: 5 }, ( _, i ) => ( {
				name: `https://example.com/${ 'a'.repeat( 380 ) }${ i }.js`,
				initiatorType: 'script',
				duration: 1000 - i * 10,
			} ) )
		);
		const collected = collectSlowResources();
		expect( collected.length ).toBeLessThan( 5 );
		expect( collected.length ).toBeGreaterThan( 0 );
		// The slowest entry survives budget trimming.
		expect( collected[ 0 ].duration ).toBe( 1000 );
		expect( JSON.stringify( collected ).length ).toBeLessThanOrEqual(
			1536
		);
	} );
} );

describe( 'sanitizeRumValues attribution branches', () => {
	it( 'passes a compact lcpSelector through', () => {
		expect( RUM_MAX_LCP_SELECTOR_LENGTH ).toBe( 256 );
		const clean = sanitizeRumValues( {
			lcp: 1200,
			lcpSelector: 'img#hero-image',
		} );
		expect( clean.lcpSelector ).toBe( 'img#hero-image' );
	} );

	it( 'drops malicious or out-of-gate lcpSelector values', () => {
		const cases = [
			'div > img',
			'img[src="hero.jpg"]',
			'<script>alert(1)</script>',
			'img`onerror=alert(1)`',
			'javascript:alert',
			'vbscript:msgbox',
			'imgdata:data:x',
			'a'.repeat( 257 ),
			123,
		];
		for ( const lcpSelector of cases ) {
			expect(
				sanitizeRumValues( { lcp: 1200, lcpSelector } ).lcpSelector
			).toBeUndefined();
		}
	} );

	it( 'shapes slowResources and omits the key when empty', () => {
		const clean = sanitizeRumValues( {
			lcp: 1200,
			slowResources: [
				{
					name: 'https://example.com/slow.js',
					type: 'script',
					duration: 800,
				},
				{ name: 'data:image/png;base64,x', type: 'img', duration: 9 },
				'nope',
			],
		} );
		expect( clean.slowResources ).toHaveLength( 1 );
		expect( clean.slowResources[ 0 ] ).toEqual( {
			name: 'https://example.com/slow.js',
			type: 'script',
			duration: 800,
		} );
		expect(
			sanitizeRumValues( { lcp: 1200, slowResources: [] } ).slowResources
		).toBeUndefined();
		expect(
			sanitizeRumValues( { lcp: 1200 } ).slowResources
		).toBeUndefined();
	} );

	it( 'caps slowResources at RUM_MAX_SLOW_RESOURCES entries', () => {
		const slowResources = Array.from( { length: 8 }, ( _, i ) => ( {
			name: `https://example.com/a${ i }.js`,
			type: 'script',
			duration: 500,
		} ) );
		expect(
			sanitizeRumValues( { lcp: 1200, slowResources } ).slowResources
		).toHaveLength( RUM_MAX_SLOW_RESOURCES );
	} );
} );
