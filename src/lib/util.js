export const handleChange = ( setSettings ) => ( e ) => {
	const { name, type, value, checked } = e.target;

	setSettings( ( prevState ) => ( {
		...prevState,
		[ name ]: 'checkbox' === type ? checked : value,
	} ) );
};
