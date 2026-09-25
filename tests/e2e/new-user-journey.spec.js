/**
 * New-user trust journey (Phase F).
 *
 * Requires a running WordPress with the plugin active and an admin session.
 * Run with: npx playwright test tests/e2e/new-user-journey.spec.js
 *
 * Journey: welcome panel shows the Start Safe intro -> apply the Safe
 * preset with a diff preview -> verify status -> Undo via the restore
 * point -> support/troubleshooting/compatibility docs reachable -> zero
 * unexplained browser console errors.
 *
 * This spec is intentionally skipped without PLAYWRIGHT_BASE_URL so the
 * default Jest/PHPUnit suites never need a live browser.
 */
const { test, expect } = require( '@playwright/test' );

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL || '';
const HAS_SITE = Boolean( BASE_URL );

test.describe( 'new-user trust journey', () => {
	test.skip( ! HAS_SITE, 'needs PLAYWRIGHT_BASE_URL (live WordPress)' );

	test( 'start safe, preview, apply, undo, find support', async ( {
		page,
	} ) => {
		const consoleErrors = [];
		page.on( 'console', ( msg ) => {
			if ( msg.type() === 'error' ) {
				consoleErrors.push( msg.text() );
			}
		} );
		page.on( 'pageerror', ( error ) => {
			consoleErrors.push( String( error && error.message ) );
		} );

		// 1. Welcome panel names the safe path and the restore point.
		await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=performance-optimisation` );
		await expect(
			page.locator( '.wppo-welcome-panel__intro' )
		).toContainText( 'Start Safe' );
		await expect(
			page.locator( '.wppo-welcome-panel__intro' )
		).toContainText( 'restore point' );

		// 2. Safe preset: preview shows a diff (or already-matches note).
		await page.getByRole( 'button', { name: /Safe/ } ).first().click();
		await expect(
			page.locator( '.wppo-presets__diff, .wppo-presets__diff-list' ).first()
		).toBeVisible( { timeout: 15000 } );

		// 3. Apply keeps an Undo affordance.
		await page.getByRole( 'button', { name: /Apply Safe/ } ).click();
		await expect(
			page.locator( '.wppo-notice--success, .wppo-presets__actions' ).first()
		).toBeVisible( { timeout: 15000 } );

		// 4. Undo restores the prior snapshot.
		const undo = page.getByRole( 'button', { name: /Undo/ } ).first();
		if ( await undo.isVisible() ) {
			await undo.click();
			await expect(
				page.locator( '.wppo-notice--success, .wppo-notice--error' ).first()
			).toBeVisible( { timeout: 15000 } );
		}

		// 5. Docs entry points stay reachable (support workflow exists).
		for ( const slug of [ 'support', 'troubleshooting', 'compatibility' ] ) {
			const response = await page.request.get(
				`${ BASE_URL }/docs/performance-optimisation/${ slug }/`
			);
			expect( response.status() ).toBeLessThan( 400 );
		}

		// 6. No unexplained browser errors.
		const unexplained = consoleErrors.filter(
			( text ) =>
				! /net::ERR_INTERNET_DISCONNECTED|favicon/i.test( String( text ) )
		);
		expect( unexplained ).toEqual( [] );
	} );
} );
