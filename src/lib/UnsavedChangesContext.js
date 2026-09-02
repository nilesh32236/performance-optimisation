import { createContext } from '@wordpress/element';

/**
 * Context for tracking unsaved form changes across SPA tabs.
 *
 * @since NEXT
 */
const UnsavedChangesContext = createContext( {
	isDirty: false,
	setIsDirty: () => {},
} );

export default UnsavedChangesContext;
