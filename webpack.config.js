/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
/**
 * External dependencies
 */
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( process.cwd(), 'admin/src', 'index.tsx' ),
		wizard: path.resolve( process.cwd(), 'admin/src', 'wizard.tsx' ),
		'admin-bar': path.resolve( process.cwd(), 'admin/src', 'admin-bar.js' ),
		lazyload: path.resolve( process.cwd(), 'admin/src', 'lazyload.js' ),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve.alias,
			'@': path.resolve( __dirname, 'admin/src' ),
			'@components': path.resolve( __dirname, 'admin/src/components' ),
			'@pages': path.resolve( __dirname, 'admin/src/pages' ),
			'@utils': path.resolve( __dirname, 'admin/src/utils' ),
			'@types': path.resolve( __dirname, 'admin/src/types' ),
			'@styles': path.resolve( __dirname, 'admin/src/styles' ),
		},
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
