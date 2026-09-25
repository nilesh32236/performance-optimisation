/**
 * Phase C UX checks (issue #1630): copy/hierarchy only, no behavior change.
 *
 * Exercises the real shipped stylesheet (build/style-index.css) against
 * fixture markup mirroring the Phase C surfaces at 390×844, 768×800 and
 * 1280×800: no horizontal overflow, dialog usable, disclosure operable,
 * and no unexplained console/network errors.
 *
 * Run after `npm run build` so the compiled CSS under test is current:
 *   npx playwright test tests/e2e/ux-phase-c.spec.js
 */
const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const CSS_PATH = path.join(
	__dirname,
	'..',
	'..',
	'build',
	'style-index.css'
);

const VIEWPORTS = [
	{ width: 390, height: 844 },
	{ width: 768, height: 800 },
	{ width: 1280, height: 800 },
];

// Representative markup for the Phase C surfaces: presets hierarchy with a
// Recommended badge, a long unbroken diff key (overflow-wrap probe),
// the unsaved-change dialog, the welcome disclosure, and a step row.
const fixtureMarkup = ( css ) => `
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<style>${ css }</style>
</head>
<body>
<div class="wppo-container">
	<div class="wppo-feature-card wppo-presets" id="wppoSafeStart">
		<div class="wppo-feature-card__header"><h3>Optimization Presets</h3></div>
		<div class="wppo-feature-card__body">
			<div class="wppo-presets__buttons" role="group" aria-label="Presets">
				<button type="button" class="wppo-button wppo-button--primary" aria-pressed="true">Safe<span class="wppo-presets__badge" aria-hidden="true">Recommended</span></button>
				<button type="button" class="wppo-button wppo-button--secondary" aria-pressed="false">Balanced</button>
				<button type="button" class="wppo-button wppo-button--secondary" aria-pressed="false">Aggressive</button>
			</div>
			<div class="wppo-presets__diff" aria-live="polite">
				<ul class="wppo-presets__diff-list">
					<li><code>file_optimisation.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa</code> Off → On</li>
				</ul>
			</div>
			<div class="wppo-presets__actions">
				<button type="button" class="wppo-button wppo-button--primary">Apply Safe</button>
				<button type="button" class="wppo-button wppo-button--secondary">Export JSON</button>
			</div>
		</div>
	</div>
	<details class="wppo-welcome-advanced">
		<summary>Advanced steps: minify, lazy load, WooCommerce check</summary>
		<div class="wppo-welcome-steps">
			<div class="wppo-welcome-step"><span class="wppo-welcome-step__number">2</span></div>
		</div>
	</details>
	<div class="wppo-dialog-overlay">
		<div class="wppo-dialog" role="dialog" aria-modal="true" tabindex="-1">
			<h3>Discard unsaved changes?</h3>
			<p>You have unsaved changes. Switching tabs now will lose your edits. Choose Cancel to keep editing, or Discard to leave without saving.</p>
			<div class="wppo-dialog-actions">
				<button type="button" class="wppo-button wppo-button--secondary wppo-dialog-cancel">Cancel</button>
				<button type="button" class="wppo-button wppo-button--primary">Discard</button>
			</div>
		</div>
	</div>
</div>
</body>
</html>
`;

for ( const viewport of VIEWPORTS ) {
	test( `phase-c layout holds at ${ viewport.width}x${ viewport.height }`, async ( {
		page,
	} ) => {
		if ( ! fs.existsSync( CSS_PATH ) ) {
			throw new Error(
				`Missing compiled CSS at ${ CSS_PATH } — run npm run build first.`
			);
		}
		const css = fs.readFileSync( CSS_PATH, 'utf8' );
		const consoleErrors = [];
		const networkFailures = [];
		page.on( 'console', ( message ) => {
			if ( message.type() === 'error' ) {
				consoleErrors.push( message.text() );
			}
		} );
		page.on( 'requestfailed', ( request ) => {
			if ( ! /favicon\.ico$/.test( request.url() ) ) {
				networkFailures.push( request.url() );
			}
		} );
		await page.setViewportSize( viewport );
		await page.setContent( fixtureMarkup( css ), {
			waitUntil: 'domcontentloaded',
		} );

		// No horizontal overflow at this viewport.
		const overflow = await page.evaluate( () => ( {
			scrollWidth: document.documentElement.scrollWidth,
			innerWidth: window.innerWidth,
		} ) );
		expect(
			overflow.scrollWidth,
			`horizontal overflow at ${ viewport.width}x${ viewport.height }`
		).toBeLessThanOrEqual( overflow.innerWidth );

		// Dialog fits inside the viewport and its actions are clickable.
		const dialogBox = await page
			.getByRole( 'dialog' )
			.boundingBox();
		expect( dialogBox, 'dialog bounding box' ).not.toBeNull();
		expect( dialogBox.width ).toBeLessThanOrEqual( viewport.width );
		await expect(
			page.getByRole( 'button', { name: 'Discard' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Cancel' } )
		).toBeVisible();

		// Disclosure toggles and keeps its content reachable. The dialog
		// overlay above is fixed full-viewport by design, so remove it
		// first — mirroring the real app, where the page is only
		// interactive once the dialog is closed.
		await page.evaluate( () => {
			document.querySelector( '.wppo-dialog-overlay' )?.remove();
		} );
		const summary = page.locator( '.wppo-welcome-advanced > summary' );
		await expect( summary ).toBeVisible();
		await summary.click();
		await expect(
			page.locator( 'details.wppo-welcome-advanced[open]' )
		).toBeAttached();

		// No unexplained console/network errors (favicon 404 allowlisted).
		expect( consoleErrors ).toEqual( [] );
		expect( networkFailures ).toEqual( [] );
	} );
}
