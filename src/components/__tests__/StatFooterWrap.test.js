/**
 * A stat card's action must not be silently cut.
 *
 * `.wppo-stat-item` is `overflow: hidden`, and the action inside its footer was
 * a flex item with the default `min-width: auto` while `.wppo-button` sets
 * `white-space: nowrap`. Those two together make the button's min-content equal
 * its whole label, so it could not shrink and the excess was cut with no
 * scrollbar and no indication.
 *
 * Measured on All diagnostics at 1280px: "Manage →" painted to x=671 inside a
 * card ending at x=648.
 *
 * This is a **CSS tripwire, not a layout test** — jsdom has no layout, so the
 * only thing a test can honestly assert is that the declarations which make
 * shrinking possible are still declared. The evidence is the live measurement
 * in the commit message: 0 clipped elements at 1024, 768, 390, 1280 and 1440.
 *
 * @package
 */

import fs from 'fs';
import path from 'path';

const SCSS = fs.readFileSync(
	path.join( __dirname, '../../css/components/_stats.scss' ),
	'utf8'
);
const CSS = fs.readFileSync(
	path.join( __dirname, '../../../build/style-index.css' ),
	'utf8'
);

describe( 'the stat card action can shrink', () => {
	it( 'clears nowrap, which is what blocks the wrap', () => {
		// `overflow-wrap: anywhere` was already on the button and inert against
		// `nowrap` — the same trap as the `min-width: 0`-only half-fix in round
		// 34, where a declaration that looked load-bearing did nothing.
		const block = SCSS.slice(
			SCSS.indexOf( '.wppo-stat-footer {' ),
			SCSS.indexOf(
				'.wppo-stat-footer {',
				SCSS.indexOf( '.wppo-stat-footer {' ) + 1
			)
		);
		expect( block ).toMatch( /flex-wrap:\s*wrap;/ );
		expect( block ).toMatch( /> \.wppo-button \{/ );
		expect( block ).toMatch( /min-width:\s*max-content;/ );
	} );

	it( 'ships those declarations in the built stylesheet', () => {
		expect( CSS ).toMatch( /\.wppo-stat-footer\{[^}]*flex-wrap:wrap/ );
		expect( CSS ).toMatch(
			/\.wppo-stat-footer>\.wppo-button\{[^}]*min-width:max-content/
		);
	} );

	it( 'keeps the label on one line, and bounds the block to the rule', () => {
		const block = SCSS.slice(
			SCSS.indexOf( '> .wppo-button {' ),
			SCSS.indexOf( '> .wppo-button {' ) + 240
		);
		// `anywhere` is not enough on its own; without `normal` it is inert.
		expect( block ).not.toMatch( /overflow-wrap:\s*anywhere;/ );
	} );
} );
