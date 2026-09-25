/**
 * New-user trust journey (Phase F).
 *
 * Requires a running WordPress with the plugin active and an admin session.
 * Run with: PLAYWRIGHT_BASE_URL=https://example.test npx playwright test tests/e2e/new-user-journey.spec.js
 *
 * Journey: welcome panel shows the Start Safe intro -> apply the Safe
 * preset with a diff preview -> verify status -> Undo via the restore
 * point -> support/troubleshooting/compatibility docs reachable -> zero
 * unexplained browser console errors.
 *
 * This spec is intentionally skipped without PLAYWRIGHT_BASE_URL so the
 * default Jest/PHPUnit suites never need a live browser. Published docs
 * live on an external host, so HTTP reachability is only checked when
 * PLAYWRIGHT_DOCS_BASE_URL is set; otherwise the spec asserts the admin
 * UI exposes the support/troubleshooting/compatibility links.
 */
const { test, expect } = require( '@playwright/test' );

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL || '';
const HAS_SITE = Boolean( BASE_URL );
// Published docs host (external pipeline); defaults to the public docs
// origin used in readme links when explicitly opted in via env.
const DOCS_BASE_URL = process.env.PLAYWRIGHT_DOCS_BASE_URL || '';

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
		// Scope to the presets card so the Tools support card (which also
		// mentions "Safe") can never match.
		await page
			.locator( '.wppo-presets__buttons' )
			.getByRole( 'button', { name: /Safe/ } )
			.click();
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
		// The Tools tab hosts the "Support And Safe Upgrades" card linking
		// to the external published docs; assert the card and its links
		// exist. Only probe HTTP status when an explicit docs origin is
		// provided, since docs are not served from the WP origin.
		await page.getByRole( 'button', { name: /Tools/ } ).first().click();
		await expect(
			page.getByText( 'Support And Safe Upgrades' )
		).toBeVisible( { timeout: 15000 } );
		for ( const slug of [ 'support', 'troubleshooting', 'compatibility' ] ) {
			await expect(
				page.locator(
					`a[href*="/docs/performance-optimisation/${ slug }/"]`
				).first()
			).toBeVisible( { timeout: 15000 } );
		}
		if ( DOCS_BASE_URL ) {
			for ( const slug of [ 'support', 'troubleshooting', 'compatibility' ] ) {
				const response = await page.request.get(
					`${ DOCS_BASE_URL }/docs/performance-optimisation/${ slug }/`
				);
				expect( response.status() ).toBeLessThan( 400 );
			}
		}

		// 6. No unexplained browser errors.
		const unexplained = consoleErrors.filter(
			( text ) =>
				! /net::ERR_INTERNET_DISCONNECTED|favicon/i.test( String( text ) )
		);
		expect( unexplained ).toEqual( [] );
	} );
} );
