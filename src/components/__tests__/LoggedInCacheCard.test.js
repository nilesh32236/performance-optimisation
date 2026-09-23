import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
// eslint-disable-next-line import/no-extraneous-dependencies -- React is required for JSX rendering in tests
import React from 'react';
import LoggedInCacheCard from '../dashboard/LoggedInCacheCard';

describe( 'LoggedInCacheCard', () => {
	beforeEach( () => {
		global.wppoSettings = { translations: {} };
		jest.clearAllMocks();
	} );

	const userRoles = {
		administrator: 'Administrator',
		subscriber: 'Subscriber',
	};

	it( 'hides roles when disabled', () => {
		render(
			<LoggedInCacheCard
				enabled={ false }
				selectedRoles={ [] }
				saving={ false }
				userRoles={ userRoles }
				onToggle={ jest.fn() }
				onRoleChange={ jest.fn() }
				onSave={ jest.fn() }
			/>
		);
		expect(
			screen.queryByText(
				'Select which user roles should receive cached pages:'
			)
		).not.toBeInTheDocument();
		expect(
			screen.getByText( 'Cache for Logged-in Users' )
		).toBeInTheDocument();
	} );

	it( 'shows roles and empty-roles copy when enabled', () => {
		render(
			<LoggedInCacheCard
				enabled={ true }
				selectedRoles={ [] }
				saving={ false }
				userRoles={ userRoles }
				onToggle={ jest.fn() }
				onRoleChange={ jest.fn() }
				onSave={ jest.fn() }
			/>
		);
		expect( screen.getByText( 'Administrator' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Subscriber' ) ).toBeInTheDocument();
		expect(
			screen.getByText(
				'When no roles are selected, caching applies to all logged-in users.'
			)
		).toBeInTheDocument();
	} );

	it( 'forwards toggle, role and save callbacks', () => {
		const onToggle = jest.fn();
		const onRoleChange = jest.fn();
		const onSave = jest.fn();
		render(
			<LoggedInCacheCard
				enabled={ true }
				selectedRoles={ [ 'subscriber' ] }
				saving={ false }
				userRoles={ userRoles }
				onToggle={ onToggle }
				onRoleChange={ onRoleChange }
				onSave={ onSave }
			/>
		);
		fireEvent.click( screen.getByLabelText( 'Enable' ) );
		expect( onToggle ).toHaveBeenCalledTimes( 1 );
		fireEvent.click( screen.getByLabelText( 'Administrator' ) );
		expect( onRoleChange ).toHaveBeenCalledTimes( 1 );
		fireEvent.click(
			screen.getByRole( 'button', { name: /Save Settings/i } )
		);
		expect( onSave ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reflects saving state on the save button', () => {
		render(
			<LoggedInCacheCard
				enabled={ false }
				selectedRoles={ [] }
				saving={ true }
				userRoles={ userRoles }
				onToggle={ jest.fn() }
				onRoleChange={ jest.fn() }
				onSave={ jest.fn() }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: /Saving/i } )
		).toBeDisabled();
	} );
} );
