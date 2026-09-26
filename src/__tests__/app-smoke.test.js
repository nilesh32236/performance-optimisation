/** Smoke test: App must actually mount, not merely import. */
import { render } from '@testing-library/react';
import App from '../App';

describe( 'App smoke', () => {
	it( 'mounts without throwing', () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=performance-optimisation'
		);
		const { container } = render( <App /> );
		expect( container ).toBeTruthy();
	} );
} );
