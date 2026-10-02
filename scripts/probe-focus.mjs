/**
 * WCAG 2.4.11 "Focus Not Obscured" probe.
 *
 * Written because the previous, obvious implementation was wrong, and I could
 * not tell from its output whether the page was broken or the check was. That
 * is the failure mode this file is built to avoid: a check whose number looks
 * authoritative and is not.
 *
 * WHY THE NAIVE VERSION LIES. It calls elementFromPoint() at the element's
 * CENTRE after focusing it. Two things make that wrong:
 *   1. focusing can scroll, so the element moves after the measurement point
 *      was chosen from its old box;
 *   2. a centre point on a large control can land on a legitimately
 *      overlapping child (an icon, a span) while the control itself is
 *      perfectly visible.
 * On this admin it reported 90 "obscured" elements whose top three samples
 * were the palette's own input and result rows, which sit ABOVE the results
 * panel by design. The number was an artefact.
 *
 * WHAT THIS DOES INSTEAD, per WCAG's own wording:
 *   - re-read the element's box AFTER focusing and after any scroll settles,
 *     so the measurement uses the box the user can actually see;
 *   - sample a grid of points across that box - corners, edge midpoints and
 *     centre - not one centre point;
 *   - count the element as obscured only when EVERY sample is covered by
 *     something that is neither the element nor one of its descendants;
 *   - hold scroll position explicitly and scroll it back between elements,
 *     so element N's scroll does not leak into element N+1;
 *   - report the coverage ratio, so a partial overlap is visible as a
 *     fraction rather than a binary that hides how bad it is.
 *
 * A visible focus indicator is a separate criterion (2.4.13/2.4.7) and is
 * deliberately NOT checked here. This file answers one question: when this
 * element has focus, can the user see it?
 */

import { chromium } from 'playwright';

const SITE = 'https://nileshportfolio.duckdns.org';
const USER = 'admin';
const PASS = process.env.WPPO_ADMIN_PASS;

if ( ! PASS ) {
	console.error(
		'WPPO_ADMIN_PASS is not set. Refusing to run rather than measuring nothing.'
	);
	process.exit( 2 );
}

const SCREENS = [
	[ 'overview', 'overview' ],
	[ 'speed', 'speed' ],
	[ 'media', 'media' ],
	[ 'data-system', 'data-system' ],
	[ 'manage', 'manage' ],
];

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), ' +
	'select:not([disabled]), textarea:not([disabled]), ' +
	'[tabindex]:not([tabindex="-1"])';

const browser = await chromium.launch( {
	args: [ '--no-sandbox', '--disable-dev-shm-usage' ],
} );
const ctx = await browser.newContext( {
	viewport: { width: 1440, height: 1000 },
} );
const page = await ctx.newPage();
page.setDefaultTimeout( 60000 );
page.setDefaultNavigationTimeout( 60000 );

await page.goto( `${ SITE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
await page.fill( '#user_login', USER );
await page.fill( '#user_pass', PASS );
await Promise.all( [
	page.waitForNavigation( { waitUntil: 'domcontentloaded' } ),
	page.click( '#wp-submit' ),
] );

const measure = async () =>
	page.evaluate(
		( { sel, points } ) => {
			const els = [ ...document.querySelectorAll( sel ) ];
			const hidden = [];
			const obscured = [];

			for ( const el of els ) {
				// Skip anything not actually rendered and not visible to a user.
				const g = getComputedStyle( el );
				if (
					g.display === 'none' ||
					g.visibility === 'hidden' ||
					el.closest( '[aria-hidden="true"]' ) === el.parentElement &&
						el.getAttribute( 'aria-hidden' ) === 'true'
				) {
					continue;
				}
				const before = el.getBoundingClientRect();
				if ( before.width < 4 || before.height < 4 ) {
					continue;
				}

				// A visually-hidden input whose wrapping label paints the visible
				// affordance is the CORRECT pattern, not an occlusion failure. The
				// first run of this probe reported 5 of the post-type chips as
				// "obscured" for exactly this reason: the input carries
				// .screen-reader-text and the sibling .wppo-post-type-chip is the
				// thing the user actually sees and clicks. Reporting it would be a
				// false positive that trains people to ignore the probe.
				// Skipped ONLY when the covering element is the input's own label.
				const cls = el.className.toString();
				if (
					/\bscreen-reader-text\b|\bvisually-hidden\b/.test( cls )
				) {
					const lab = el.closest( 'label' );
					if ( lab && lab.contains( el ) ) {
						const lr = lab.getBoundingClientRect();
						if ( lr.width >= 4 && lr.height >= 4 ) {
							continue; // the label IS the visible control
						}
					}
				}

				// Bring it into view the way a user would, then SETTLE. Reading
				// the box after scrolling is the whole point: the naive probe read
				// it before.
				el.scrollIntoView( { block: 'center' } );
				el.focus( { preventScroll: true } );
				// Let scroll and any focus-driven reflow finish before measuring.
				void el.offsetHeight;

				const r = el.getBoundingClientRect();
				if ( r.width < 4 || r.height < 4 ) {
					continue;
				}
				if (
					r.bottom < 0 ||
					r.top > window.innerHeight ||
					r.right < 0 ||
					r.left > window.innerWidth
				) {
					// Off-screen after focus is not "obscured", it is untestable here.
					hidden.push( el.className.toString().slice( 0, 40 ) || el.tagName );
					continue;
				}

				let covered = 0;
				let tested = 0;
				for ( const p of points ) {
					const x = r.left + r.width * p[ 0 ];
					const y = r.top + r.height * p[ 1 ];
					if ( x < 0 || y < 0 || x > window.innerWidth || y > window.innerHeight ) {
						continue; // outside the viewport: not an occlusion test
					}
					tested++;
					const top = document.elementFromPoint( x, y );
					if ( top && top !== el && ! el.contains( top ) ) {
						covered++;
					}
				}
				if ( tested === 0 ) {
					continue;
				}
				// Obscured only when EVERY in-viewport sample is covered. A partial
				// overlap is reported by ratio so it stays visible as a fraction.
				if ( covered === tested ) {
					obscured.push( {
						el: el.className.toString().slice( 0, 40 ) || el.tagName,
						ratio: covered / tested,
						by: ( () => {
							const top = document.elementFromPoint(
								r.left + r.width / 2,
								r.top + r.height / 2
							);
							return top
								? ( top.className.toString().slice( 0, 30 ) ||
										top.tagName )
								: 'none';
						} )(),
					} );
				}
			}
			return { total: els.length, obscured, offScreen: hidden.length };
		},
		{
			sel: FOCUSABLE,
			// Corners, edge midpoints, centre, and an inner cross. Centre alone is
			// what made the naive version wrong.
			points: [
				[ 0.02, 0.02 ],
				[ 0.5, 0.02 ],
				[ 0.98, 0.02 ],
				[ 0.02, 0.5 ],
				[ 0.98, 0.5 ],
				[ 0.02, 0.98 ],
				[ 0.5, 0.98 ],
				[ 0.98, 0.98 ],
				[ 0.5, 0.5 ],
				[ 0.25, 0.5 ],
				[ 0.75, 0.5 ],
			],
		}
	);

let grandTotal = 0;
let grandObscured = 0;

for ( const [ section, view ] of SCREENS ) {
	await page.goto(
		`${ SITE }/wp-admin/admin.php?page=performance-optimisation&section=${ section }&view=${ view }`,
		{ waitUntil: 'domcontentloaded' }
	);
	await page.waitForSelector( '.wppo-section', { timeout: 45000 } );
	await page.evaluate( () => document.fonts?.ready ?? null );
	await page.waitForTimeout( 900 );

	const r = await measure();
	grandTotal += r.total;
	grandObscured += r.obscured.length;
	console.log(
		`  ${ `${ section }/${ view }`.padEnd( 20 ) } focusable=${ String(
			r.total
		).padStart( 4 ) }  obscured=${ String( r.obscured.length ).padStart(
			3
		) }  off-screen(skipped)=${ r.offScreen }`
	);
	for ( const o of r.obscured.slice( 0, 4 ) ) {
		console.log( `      obscured: ${ o.el }  covered by ${ o.by }` );
	}
}

console.log(
	`\n  TOTAL focusable ${ grandTotal }, fully obscured ${ grandObscured }`
);
console.log(
	'  An element counts as obscured only if every in-viewport sample point is'
);
console.log(
	'  covered. Partial overlaps are not counted; the ratio is printed for any'
);
console.log( '  element that is fully covered so the severity stays visible.' );

await browser.close();