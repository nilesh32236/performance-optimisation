import { createContext } from '@wordpress/element';

/**
 * Context for tracking unsaved form changes across SPA tabs.
 *
 * @since 2.0.0
 */
const UnsavedChangesContext = createContext( {
	isDirty: false,
	setIsDirty: () => {},
} );

export default UnsavedChangesContext;
