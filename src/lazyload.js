let scriptLoaded = false;
function load_script () {
	const inline_scripts = document.querySelectorAll( 'script[type="qtpo/javascript"]' )
	const scripts = document.querySelectorAll( 'script[qtpo-src]' );

	if ( inline_scripts.length ) {
		inline_scripts.forEach( script => {
			if ( script.hasAttribute( 'type' ) && 'qtpo/javascript' === script.getAttribute( 'type' ) ) {
				script.removeAttribute( 'type' );
			}

			if ( script.hasAttribute( 'qtpo-type' ) ) {
				const type = script.getAttribute( 'qtpo-type' );
				script.removeAttribute( 'qtpo-type' );
				script.setAttribute( 'type', type )
			}
		} );
	}

	if ( scripts.length ) {
		scripts.forEach( script => {
			if ( script.hasAttribute( 'qtpo-src' ) ) {
				const src = script.getAttribute( 'qtpo-src' );
				script.removeAttribute( 'qtpo-src' );
				script.setAttribute( 'src', src );
			}
		} );
	}
}

if ( ! scriptLoaded ) {
	document.addEventListener( 'mouseenter', load_script );
	document.addEventListener( 'mousedown', load_script );
	document.addEventListener( 'mouseover', load_script );
	document.addEventListener( 'touchstart', load_script );
	document.addEventListener( 'scroll', load_script );

	// setTimeout( load_script, 5000 );
}