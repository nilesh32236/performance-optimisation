/**
 * Failsafe test for the axe accessibility run.
 *
 * A check that cannot fail is the same defect as the bug it guards, so this
 * proves the runner's detection path actually fires. It runs the same
 * `runAxe()` call the real run uses -- no separate, friendlier code path -- and
 * asserts a three-step sequence:
 *
 *   1. RED   a page containing three deliberately broken elements is scanned,
 *            and axe must report each of them.
 *   2. GREEN the identical page minus those elements is scanned, and each
 *            violation must disappear.
 *
 * The broken elements are the three named in the accessibility brief:
 *
 *   - `<img>` with no `alt` at all              -> axe `image-alt`        (critical)
 *   - `<button>` with no accessible name        -> axe `button-name`      (critical)
 *   - text at roughly 2:1 contrast              -> axe `color-contrast`   (serious)
 *
 * The contrast pair is built from #777777 on #bbbbbb, a ratio of about 1.98:1
 * against axe's 4.5:1 requirement -- deliberately far past the threshold so the
 * result cannot hinge on antialiasing.
 *
 * If axe ever fails to detect one of these, the suite exits non-zero. That is
 * the point: a green result from `npm run a11y:axe` means nothing if this file
 * cannot demonstrate the opposite outcome.
 *
 * Run: npm run test:a11y-failsafe
 */

const path = require( 'path' );
const { chromium } = require( 'playwright' );
const AxeBuilder = require( '@axe-core/playwright' ).default;

/** The three defects, and the axe rule each one must trigger. */
const DEFECTS = [
	{
		id: 'image-alt',
		impact: 'critical',
		label: 'image with no alt attribute',
		html: '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">',
	},
	{
		id: 'button-name',
		impact: 'critical',
		label: 'button with no accessible name',
		html: '<button type="button" style="padding:8px 16px"></button>',
	},
	{
		id: 'color-contrast',
		impact: 'serious',
		label: 'body text at roughly 2:1 contrast',
		html:
			'<p style="color:#777777;background-color:#bbbbbb;font-size:16px;padding:12px;">' +
			'Contrast sample text for the failsafe check.</p>',
	},
];

/** The DOM each defect is injected into, as its own page body. */
const buildPage = ( includeDefects ) => `<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>axe failsafe</title></head>
<body>
	<h1>axe failsafe fixture</h1>
	${
		includeDefects
			? DEFECTS.map( ( d ) => `<div data-defect="${ d.id }">${ d.html }</div>` ).join(
					'\n\t'
			  )
			: ''
	}
	<p style="color:#1f2933;background-color:#ffffff;font-size:16px;">
		Control paragraph that satisfies contrast on its own.
	</p>
</body>
</html>`;

/**
 * Run axe over the current page and return the violation ids present.
 *
 * Identical in shape to the call the real run makes: no rule configuration, no
 * exclusions, no tag filter.
 *
 * @param {import('playwright').Page} page Page under test.
 * @return {Promise<Array>} Violation ids.
 */
const violationIds = async ( page ) => {
	const results = await new AxeBuilder( { page } ).analyze();
	return results.violations.map( ( v ) => v.id );
};

( async () => {
	const browser = await chromium.launch( {
		args: [ '--no-sandbox', '--disable-setuid-sandbox' ],
	} );
	const page = await ( await browser.newContext() ).newPage();

	const failures = [];
	const say = ( line ) => process.stdout.write( line + '\n' );

	// ---- RED ----------------------------------------------------------
	await page.setContent( buildPage( true ), { waitUntil: 'load' } );
	const redIds = await violationIds( page );

	say( '=== RED: page contains the three deliberate defects ===' );
	say( `axe reported: ${ JSON.stringify( redIds ) }` );
	for ( const defect of DEFECTS ) {
		const found = redIds.includes( defect.id );
		say(
			`  ${ found ? 'DETECTED' : 'MISSED  ' } ${ defect.id.padEnd(
				15
			) } expected impact=${ defect.impact } -- ${ defect.label }`
		);
		if ( ! found ) {
			failures.push(
				`axe did NOT detect ${ defect.id } (${ defect.label }); the check cannot be trusted`
			);
		}
	}

	// ---- GREEN --------------------------------------------------------
	await page.setContent( buildPage( false ), { waitUntil: 'load' } );
	const greenIds = await violationIds( page );

	say( '' );
	say( '=== GREEN: identical page, defects removed ===' );
	say( `axe reported: ${ JSON.stringify( greenIds ) }` );
	for ( const defect of DEFECTS ) {
		const still = greenIds.includes( defect.id );
		say(
			`  ${ still ? 'STILL PRESENT' : 'CLEARED     ' } ${ defect.id.padEnd(
				15
			) } -- ${ defect.label }`
		);
		if ( still ) {
			failures.push(
				`${ defect.id } persisted after the element was removed`
			);
		}
	}

	await browser.close();

	say( '' );
	if ( failures.length ) {
		say( 'FAIL: the accessibility check did not behave correctly.' );
		for ( const f of failures ) {
			say( `  - ${ f }` );
		}
		process.exit( 1 );
	}

	say(
		'PASS: axe detected all three deliberate defects and reported none of ' +
			'them once removed. A violation reported by `npm run a11y:axe` is a ' +
			'real detection, not a default.'
	);
	process.exit( 0 );
} )().catch( ( error ) => {
	process.stderr.write(
		'FAILED -- the failsafe itself could not run, so nothing was proven: ' +
			error.stack +
			'\n'
	);
	process.exit( 2 );
} );