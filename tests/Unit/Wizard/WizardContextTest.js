/**
 * External dependencies
 */
import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import '@testing-library/jest-dom';
/**
 * Internal dependencies
 */
import { WizardProvider, useWizard } from '../../../admin/src/components/Wizard/WizardContext';

// Test component that uses the wizard context
function TestComponent() {
	const { state, nextStep, previousStep, updateData, validateCurrentStep, setError, setLoading } =
		useWizard();

	return (
		<div>
			<div data-testid="current-step">{ state.currentStep }</div>
			<div data-testid="total-steps">{ state.totalSteps }</div>
			<div data-testid="is-loading">{ state.isLoading.toString() }</div>
			<div data-testid="error">{ state.error || 'no-error' }</div>
			<div data-testid="data">{ JSON.stringify( state.data ) }</div>

			<button onClick={ nextStep } data-testid="next-button">
				Next
			</button>
			<button onClick={ previousStep } data-testid="previous-button">
				Previous
			</button>
			<button onClick={ () => updateData( 'test', 'value' ) } data-testid="update-data-button">
				Update Data
			</button>
			<button onClick={ () => setError( 'Test error' ) } data-testid="set-error-button">
				Set Error
			</button>
			<button onClick={ () => setLoading( true ) } data-testid="set-loading-button">
				Set Loading
			</button>
			<div data-testid="is-valid">{ validateCurrentStep().toString() }</div>
		</div>
	);
}

const mockSteps = [
	{
		id: 'step1',
		title: 'Step 1',
		component: () => <div>Step 1</div>,
		isValid: ( state ) => !! state.data.step1Valid,
	},
	{
		id: 'step2',
		title: 'Step 2',
		component: () => <div>Step 2</div>,
		isValid: () => true,
	},
	{
		id: 'step3',
		title: 'Step 3',
		component: () => <div>Step 3</div>,
		isValid: () => true,
	},
];

function renderWithProvider( steps = mockSteps ) {
	return render(
		<WizardProvider steps={ steps }>
			<TestComponent />
		</WizardProvider>
	);
}

describe( 'WizardContext', () => {
	test( 'initializes with correct default state', () => {
		renderWithProvider();

		expect( screen.getByTestId( 'current-step' ) ).toHaveTextContent( '1' );
		expect( screen.getByTestId( 'total-steps' ) ).toHaveTextContent( '3' );
		expect( screen.getByTestId( 'is-loading' ) ).toHaveTextContent( 'false' );
		expect( screen.getByTestId( 'error' ) ).toHaveTextContent( 'no-error' );
		expect( screen.getByTestId( 'data' ) ).toHaveTextContent( '{}' );
	} );

	test( 'updates data correctly', () => {
		renderWithProvider();

		fireEvent.click( screen.getByTestId( 'update-data-button' ) );

		expect( screen.getByTestId( 'data' ) ).toHaveTextContent( '{"test":"value"}' );
	} );

	test( 'sets error correctly', () => {
		renderWithProvider();

		fireEvent.click( screen.getByTestId( 'set-error-button' ) );

		expect( screen.getByTestId( 'error' ) ).toHaveTextContent( 'Test error' );
	} );

	test( 'sets loading state correctly', () => {
		renderWithProvider();

		fireEvent.click( screen.getByTestId( 'set-loading-button' ) );

		expect( screen.getByTestId( 'is-loading' ) ).toHaveTextContent( 'true' );
	} );

	test( 'validates current step correctly', () => {
		renderWithProvider();

		// Initially invalid because step1Valid is not set
		expect( screen.getByTestId( 'is-valid' ) ).toHaveTextContent( 'false' );

		// Update data to make step valid
		fireEvent.click( screen.getByTestId( 'update-data-button' ) );

		// Still invalid because we need step1Valid specifically
		expect( screen.getByTestId( 'is-valid' ) ).toHaveTextContent( 'false' );
	} );

	test( 'navigates to next step when valid', () => {
		renderWithProvider();

		// Make step valid first
		const { rerender } = renderWithProvider();

		// Update to make step valid
		fireEvent.click( screen.getByTestId( 'update-data-button' ) );

		// Manually set the required data for step validation
		const TestComponentWithValidData = () => {
			const { state, nextStep, updateData } = useWizard();

			React.useEffect( () => {
				updateData( 'step1Valid', true );
			}, [ updateData ] );

			return (
				<div>
					<div data-testid="current-step">{ state.currentStep }</div>
					<button onClick={ nextStep } data-testid="next-button">
						Next
					</button>
				</div>
			);
		};

		rerender(
			<WizardProvider steps={ mockSteps }>
				<TestComponentWithValidData />
			</WizardProvider>
		);

		fireEvent.click( screen.getByTestId( 'next-button' ) );

		expect( screen.getByTestId( 'current-step' ) ).toHaveTextContent( '2' );
	} );

	test( 'navigates to previous step', () => {
		renderWithProvider();

		// First go to step 2
		const TestComponentAtStep2 = () => {
			const { state, previousStep, goToStep } = useWizard();

			React.useEffect( () => {
				goToStep( 2 );
			}, [ goToStep ] );

			return (
				<div>
					<div data-testid="current-step">{ state.currentStep }</div>
					<button onClick={ previousStep } data-testid="previous-button">
						Previous
					</button>
				</div>
			);
		};

		const { rerender } = renderWithProvider();

		rerender(
			<WizardProvider steps={ mockSteps }>
				<TestComponentAtStep2 />
			</WizardProvider>
		);

		fireEvent.click( screen.getByTestId( 'previous-button' ) );

		expect( screen.getByTestId( 'current-step' ) ).toHaveTextContent( '1' );
	} );

	test( 'does not go beyond step boundaries', () => {
		renderWithProvider();

		// Try to go to previous step from step 1
		fireEvent.click( screen.getByTestId( 'previous-button' ) );
		expect( screen.getByTestId( 'current-step' ) ).toHaveTextContent( '1' );

		// Try to go beyond last step
		const TestComponentAtLastStep = () => {
			const { state, nextStep, goToStep } = useWizard();

			React.useEffect( () => {
				goToStep( 3 );
			}, [ goToStep ] );

			return (
				<div>
					<div data-testid="current-step">{ state.currentStep }</div>
					<button onClick={ nextStep } data-testid="next-button">
						Next
					</button>
				</div>
			);
		};

		const { rerender } = renderWithProvider();

		rerender(
			<WizardProvider steps={ mockSteps }>
				<TestComponentAtLastStep />
			</WizardProvider>
		);

		fireEvent.click( screen.getByTestId( 'next-button' ) );
		expect( screen.getByTestId( 'current-step' ) ).toHaveTextContent( '3' );
	} );

	test( 'throws error when used outside provider', () => {
		// Suppress console.error for this test
		const originalError = console.error;
		console.error = jest.fn();

		expect( () => {
			render( <TestComponent /> );
		} ).toThrow( 'useWizard must be used within a WizardProvider' );

		console.error = originalError;
	} );
} );
