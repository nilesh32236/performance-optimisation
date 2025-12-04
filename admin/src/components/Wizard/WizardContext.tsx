/**
 * External dependencies
 */
import React, { createContext, useContext, useReducer, ReactNode } from 'react';

// Types
export interface WizardStep {
	id: string;
	title: string;
	component: React.ComponentType<any>;
	isValid?: ( state: WizardState ) => boolean;
	isRequired?: boolean;
}

export interface WizardState {
	currentStep: number;
	totalSteps: number;
	isLoading: boolean;
	error: string | null;
	data: Record<string, any>;
	completedSteps: Set<number>;
	visitedSteps: Set<number>;
	steps: WizardStep[];
}

export interface WizardContextType {
	state: WizardState;
	dispatch: React.Dispatch<WizardAction>;
	goToStep: ( step: number ) => void;
	nextStep: () => void;
	previousStep: () => void;
	updateData: ( key: string, value: any ) => void;
	validateCurrentStep: () => boolean;
	setError: ( error: string | null ) => void;
	setLoading: ( loading: boolean ) => void;
}

// Actions
export type WizardAction =
	| { type: 'SET_CURRENT_STEP'; payload: number }
	| { type: 'SET_TOTAL_STEPS'; payload: number }
	| { type: 'SET_LOADING'; payload: boolean }
	| { type: 'SET_ERROR'; payload: string | null }
	| { type: 'UPDATE_DATA'; payload: { key: string; value: any } }
	| { type: 'MARK_STEP_COMPLETED'; payload: number }
	| { type: 'MARK_STEP_VISITED'; payload: number }
	| { type: 'RESET_WIZARD' };

// Initial state
const initialState: WizardState = {
	currentStep: 1,
	totalSteps: 5,
	isLoading: false,
	error: null,
	data: {},
	completedSteps: new Set(),
	visitedSteps: new Set( [ 1 ] ),
	steps: [],
};

// Reducer
function wizardReducer( state: WizardState, action: WizardAction ): WizardState {
	switch ( action.type ) {
		case 'SET_CURRENT_STEP':
			return {
				...state,
				currentStep: action.payload,
				visitedSteps: new Set( [ ...state.visitedSteps, action.payload ] ),
			};
		case 'SET_TOTAL_STEPS':
			return {
				...state,
				totalSteps: action.payload,
			};
		case 'SET_LOADING':
			return {
				...state,
				isLoading: action.payload,
			};
		case 'SET_ERROR':
			return {
				...state,
				error: action.payload,
			};
		case 'UPDATE_DATA':
			return {
				...state,
				data: {
					...state.data,
					[ action.payload.key ]: action.payload.value,
				},
			};
		case 'MARK_STEP_COMPLETED':
			return {
				...state,
				completedSteps: new Set( [ ...state.completedSteps, action.payload ] ),
			};
		case 'MARK_STEP_VISITED':
			return {
				...state,
				visitedSteps: new Set( [ ...state.visitedSteps, action.payload ] ),
			};
		case 'RESET_WIZARD':
			return initialState;
		default:
			return state;
	}
}

// Context
const WizardContext = createContext<WizardContextType | undefined>( undefined );

// Provider
interface WizardProviderProps {
	children: ReactNode;
	steps: WizardStep[];
}

export function WizardProvider( { children, steps }: WizardProviderProps ) {
	const [ state, dispatch ] = useReducer( wizardReducer, {
		...initialState,
		totalSteps: steps.length,
		steps: steps,
	} );

	const goToStep = ( step: number ) => {
		if ( step >= 1 && step <= state.totalSteps ) {
			dispatch( { type: 'SET_CURRENT_STEP', payload: step } );
		}
	};

	const nextStep = () => {
		if ( validateCurrentStep() && state.currentStep < state.totalSteps ) {
			dispatch( { type: 'MARK_STEP_COMPLETED', payload: state.currentStep } );
			dispatch( { type: 'SET_CURRENT_STEP', payload: state.currentStep + 1 } );
		}
	};

	const previousStep = () => {
		if ( state.currentStep > 1 ) {
			dispatch( { type: 'SET_CURRENT_STEP', payload: state.currentStep - 1 } );
		}
	};

	const updateData = ( key: string, value: any ) => {
		dispatch( { type: 'UPDATE_DATA', payload: { key, value } } );
	};

	const validateCurrentStep = (): boolean => {
		const currentStepConfig = steps[ state.currentStep - 1 ];
		if ( currentStepConfig?.isValid ) {
			return currentStepConfig.isValid( state );
		}
		return true;
	};

	const setError = ( error: string | null ) => {
		dispatch( { type: 'SET_ERROR', payload: error } );
	};

	const setLoading = ( loading: boolean ) => {
		dispatch( { type: 'SET_LOADING', payload: loading } );
	};

	const contextValue: WizardContextType = {
		state,
		dispatch,
		goToStep,
		nextStep,
		previousStep,
		updateData,
		validateCurrentStep,
		setError,
		setLoading,
	};

	return <WizardContext.Provider value={ contextValue }>{ children }</WizardContext.Provider>;
}

// Hook
export function useWizard(): WizardContextType {
	const context = useContext( WizardContext );
	if ( context === undefined ) {
		throw new Error( 'useWizard must be used within a WizardProvider' );
	}
	return context;
}
