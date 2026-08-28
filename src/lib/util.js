export const handleChange = ( setSettings ) => ( e ) => {
	const { name, type, value, checked } = e.target;

	let nextValue;
	if ( 'checkbox' === type ) {
		nextValue = checked;
	} else if ( 'number' === type || 'delayJSIdleTimeout' === name ) {
		if ( '' === value ) {
			nextValue = 'delayJSIdleTimeout' === name ? 3000 : '';
		} else {
			const parsed = Number( value );
			if ( Number.isNaN( parsed ) ) {
				nextValue = 'delayJSIdleTimeout' === name ? 3000 : value;
			} else {
				nextValue = parsed;
				if ( 'delayJSIdleTimeout' === name ) {
					if ( ! Number.isFinite( nextValue ) || nextValue <= 0 ) {
						nextValue = 3000;
					} else {
						nextValue = Math.min(
							20000,
							Math.max( 500, nextValue )
						);
					}
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
