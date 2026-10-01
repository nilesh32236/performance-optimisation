/**
 * Tests for the Images inspector copy.
 *
 * Enforces the writing rules in the subjects file header. The gate that matters
 * most here is the first one: this is the only screen where the settings can
 * break links and pages, so a consequence that is vague rather than named is
 * the difference between a person reading the warning and not.
 *
 * @package
 */

import IMAGE_SUBJECTS, { subjectFor } from '../subjects';

const KEYS = Object.keys( IMAGE_SUBJECTS );

describe( 'Images subjects', () => {
	it( 'covers every setting on the screen', () => {
		expect( KEYS ).toEqual(
			expect.arrayContaining( [
				'convertImg',
				'conversionFormat',
				'forceServerSideConversion',
				'clientSideMimeTypeOverride',
				'excludeConvertImages',
				'discardOversizedSibling',
				'maxWidthImgSize',
				'maxLongestEdgePx',
				'wrapInPicture',
				'excludeSize',
				'lazyLoadImages',
				'excludeFirstImages',
				'lazyLoadNative',
				'lazyLoadBackgroundImages',
				'lazyLoadVideos',
				'enableVideoPlaceholder',
				'placeholderType',
				'excludeVideos',
				'lazyRenderBelowFold',
				'lazyRenderExcludeBuilders',
				'autoPreloadLCP',
				'prioritizeLCPImages',
				'preloadFrontPageImages',
				'preloadFrontPageImagesUrls',
				'preloadPostTypeImage',
				'autoAltText',
				'excludePostTypeImgUrl',
			] )
		);
	} );

	it( 'gives every subject an id derived from its key, and marks it a setting', () => {
		KEYS.forEach( ( key ) => {
			expect( IMAGE_SUBJECTS[ key ].id ).toBe( `setting:${ key }` );
			expect( IMAGE_SUBJECTS[ key ].kind ).toBe( 'setting' );
		} );
	} );

	it( 'gives every subject a title and an explanation', () => {
		KEYS.forEach( ( key ) => {
			expect( IMAGE_SUBJECTS[ key ].title ).toBeTruthy();
			expect( IMAGE_SUBJECTS[ key ].does ).toBeTruthy();
		} );
	} );

	it( 'does not leak the implementation', () => {
		const INTERNALS =
			/wp_image|Image_Width|image_editor|wp-content|\$wpdb|wp_cache|function_|::|getimagesize/i;
		KEYS.forEach( ( key ) => {
			const { does, detail = '', cost = '' } = IMAGE_SUBJECTS[ key ];
			[ does, detail, cost ].forEach( ( t ) =>
				expect( t ).not.toMatch( INTERNALS )
			);
		} );
	} );

	it( 'names a real consequence rather than a vague one', () => {
		const VAGUE =
			/may cause issues|might break things|use with caution\.?$/i;
		KEYS.forEach( ( key ) => {
			expect( IMAGE_SUBJECTS[ key ].cost ?? '' ).not.toMatch( VAGUE );
		} );
	} );

	it( 'only uses tones the panel styles', () => {
		KEYS.forEach( ( key ) => {
			const tone = IMAGE_SUBJECTS[ key ].costTone ?? null;
			expect( tone === null || [ 'warn', 'bad' ].includes( tone ) ).toBe(
				true
			);
		} );
	} );

	it( 'warns on the settings that can break a page or a link', () => {
		// These are the ones where the failure is real, silent, and hard to
		// trace back here: a converted image with a new filename, a below-fold
		// section rebuilt mid-scroll, an image preloaded ahead of the CSS that
		// is needed to show anything at all.
		[
			'convertImg',
			'forceServerSideConversion',
			'lazyRenderBelowFold',
			'autoPreloadLCP',
			'autoAltText',
		].forEach( ( key ) => {
			expect( IMAGE_SUBJECTS[ key ].costTone ).toBe( 'warn' );
		} );
	} );

	it( 'says a safe setting is safe, in one line', () => {
		[
			'discardOversizedSibling',
			'lazyLoadNative',
			'lazyRenderExcludeBuilders',
		].forEach( ( key ) => {
			expect( IMAGE_SUBJECTS[ key ].cost ).toMatch(
				/no known downside|no real downside/i
			);
		} );
	} );

	it( 'tells a switch that depends on a list that the list is the point', () => {
		// The most common way to set this screen up wrong: turn the switch on,
		// never fill in the list, and conclude the feature is broken.
		[ 'preconnect', 'prefetchDNS', 'preloadFrontPageImages' ].forEach(
			( key ) => {
				const s = IMAGE_SUBJECTS[ key ];
				if ( ! s ) {
					return;
				}
				expect( `${ s.does } ${ s.cost }` ).toMatch(
					/does nothing|list empty/i
				);
			}
		);
	} );
} );

describe( 'subjectFor — the "where you stand now" block', () => {
	it( 'reports a boolean as On or Off', () => {
		expect( subjectFor( 'convertImg', { convertImg: true } ).now ).toEqual(
			[ { label: 'Currently', value: 'On', tone: 'good' } ]
		);
	} );

	it( 'counts a multi-line list rather than echoing it', () => {
		const rows = subjectFor( 'excludeVideos', {
			excludeVideos: 'youtube.com/one\nyoutube.com/two\n',
		} ).now;
		expect( rows ).toEqual( [
			{ label: 'Entries', value: '2', tone: 'idle' },
		] );
	} );

	it( 'reports a single value as it is', () => {
		// A format name is one value, not a list, so counting it would be
		// actively misleading.
		expect(
			subjectFor( 'conversionFormat', { conversionFormat: 'webp' } )
				.now[ 0 ]
		).toEqual( { label: 'Current value', value: 'webp', tone: 'idle' } );
	} );

	it( 'gives a pixel size its unit', () => {
		// A bare number in the panel is meaningless without its unit.
		expect(
			subjectFor( 'maxWidthImgSize', { maxWidthImgSize: 1920 } ).now[ 0 ]
				.value
		).toBe( '1920 px' );
	} );

	it( 'never renders a value the reader cannot see', () => {
		// The bug this guards: a blank value row, which reads as a failed panel.
		KEYS.forEach( ( key ) => {
			subjectFor( key, { [ key ]: '' } ).now.forEach( ( row ) => {
				expect( String( row.value ).trim() ).not.toBe( '' );
			} );
		} );
	} );

	it( 'returns undefined for an unknown key', () => {
		expect( subjectFor( 'noSuchSetting', {} ) ).toBeUndefined();
	} );
} );
