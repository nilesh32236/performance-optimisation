// ***********************************************************
// This example support/e2e.js is processed and
// loaded automatically before your test files.
//
// This is a great place to put global configuration and
// behavior that modifies Cypress.
//
// You can change the location of this file or turn off
// automatically serving support files with the
// 'supportFile' configuration option.
//
// You can read more here:
// https://on.cypress.io/configuration
// ***********************************************************

// Import commands.js using ES2015 syntax:
/**
 * Internal dependencies
 */
import './commands';

// Alternatively you can use CommonJS syntax:
// require('./commands')

// Hide fetch/XHR requests from command log
const app = window.top;
if ( ! app.document.head.querySelector( '[data-hide-command-log-request]' ) ) {
	const style = app.document.createElement( 'style' );
	style.innerHTML = '.command-name-request, .command-name-xhr { display: none }';
	style.setAttribute( 'data-hide-command-log-request', '' );
	app.document.head.appendChild( style );
}

// Global error handling
Cypress.on( 'uncaught:exception', ( err, runnable ) => {
	// Ignore WordPress admin JavaScript errors that don't affect our tests
	if ( err.message.includes( 'wp-admin' ) || err.message.includes( 'jQuery' ) ) {
		return false;
	}
	// Let other errors fail the test
	return true;
} );

// Custom assertions
chai.use( ( chai, utils ) => {
	chai.Assertion.addMethod( 'containText', function( text ) {
		const obj = this._obj;
		const assertion = new chai.Assertion( obj );
		assertion.to.contain.text( text );
	} );
} );

// Global before hook
beforeEach( () => {
	// Clear localStorage and sessionStorage
	cy.clearLocalStorage();
	cy.clearCookies();

	// Set viewport
	cy.viewport( 1280, 720 );
} );
