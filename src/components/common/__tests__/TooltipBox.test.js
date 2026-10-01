/**
 * The tooltip's width is written against its border box.
 *
 * `width: 200px` plus `padding: 8px 12px` under the default `content-box` laid
 * the element out at **224px**, so a rule reading 200px produced something 12%
 * wider than anyone reading it would expect — and a tooltip beside a trigger
 * near its container's right edge was clipped mid-sentence, with no scrollbar.
 *
 * An earlier version of this change also added a runtime re-anchoring that
 * flipped the tooltip to its trigger's end when it did not fit. An independent
 * review measured **five regressions** from it, three of which left only 26–28%
 * of the tooltip readable — worse than the bug it was meant to fix. That
 * mechanism was **removed rather than repaired**, and the second test here is
 * the guard against it quietly coming back.
 *
 * @package
 */

import fs from 'fs';
import path from 'path';

const SCSS = path.join( __dirname, '../../../css/components/_tooltip.scss' );
const COMPONENT = path.join( __dirname, '../Tooltip.js' );

const tooltipBlock = () => {
	const scss = fs.readFileSync( SCSS, 'utf8' );
	// Anchor on the **base** rule by its positioning declaration. A modifier
	// like `.wppo-tooltip-container--visible .wppo-tooltip-content {` appears
	// earlier in the file and is a different block entirely — matching on the
	// selector text alone gave a false failure.
	const start = scss.indexOf(
		'.wppo-tooltip-content {\n\tposition: absolute;'
	);
	if ( start < 0 ) {
		return '';
	}
	// To the end of the declaration block, not a fixed character count: a long
	// comment above the property pushed it past any window chosen in advance,
	// and an earlier version of this test failed for that reason rather than
	// because anything was wrong.
	const end = scss.indexOf( '\n}', start );
	return scss.slice( start, end < 0 ? start + 2000 : end );
};

describe( 'Tooltip box sizing', () => {
	it( 'declares the box the width and max-width were written against', () => {
		// A tripwire, not a CSS test. This repository has no CSS unit-testing
		// layer, and `box-sizing` is invisible until something is clipped. It
		// verifies source text only — not the compiled output, not the cascade,
		// not what a browser does with it. Live geometry is the real evidence;
		// this exists so the property cannot be dropped silently.
		expect( tooltipBlock() ).toMatch( /box-sizing:\s*border-box;/ );
	} );

	it( 'positions the tooltip with CSS alone, with no runtime re-anchoring', () => {
		// The regression that was reverted: a JS measurement that flipped the
		// anchor on the right-hand side only, leaving a 200px box with 55px
		// readable. If a JS anchor returns, this fails and the author has to
		// prove a two-sided check exists first.
		const scss = fs.readFileSync( SCSS, 'utf8' );
		expect( scss ).not.toMatch( /wppo-tooltip-content--end/ );
		expect( fs.readFileSync( COMPONENT, 'utf8' ) ).not.toMatch(
			/alignEnd|getBoundingClientRect/
		);
	} );
} );
