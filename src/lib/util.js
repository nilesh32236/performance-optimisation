export const handleChange = ( setSettings ) => ( e ) => {
	const { name, type, value, checked, inputMode } = e.target;

	let nextValue;
	if ( 'checkbox' === type ) {
		nextValue = checked;
	} else if (
		'number' === type ||
		'numeric' === inputMode ||
		'delayJSIdleTimeout' === name
	) {
		if ( '' === value ) {
			nextValue = 'delayJSIdleTimeout' === name ? 3000 : '';
		} else {
			const parsed = Number( value );
			if ( Number.isNaN( parsed ) ) {
				nextValue = 'delayJSIdleTimeout' === name ? 3000 : value;
			} else {
				nextValue = parsed;
				if ( 'delayJSIdleTimeout' === name && ! nextValue ) {
					nextValue = 3000;
				}
			}
		}
	} else {
		nextValue = value;
	}

	setSettings( ( prevState ) => ( {
		...prevState,
		[ name ]: nextValue,
	} ) );
};
