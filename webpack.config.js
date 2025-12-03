/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );
/**
 * External dependencies
 */
const path = require( 'path' );

/**
 * Recursively remove CSS/SCSS rules from the default config
 * to prevent conflict/duplication with our custom Tailwind setup.
 */
function removeCssRules(rules) {
	if (!Array.isArray(rules)) return rules;
	
	return rules.map(rule => {
		if (rule.oneOf) {
			return { ...rule, oneOf: removeCssRules(rule.oneOf) };
		}
		if (rule.test) {
			const testStr = rule.test.toString();
			// Matches /\.css/, /\.scss/, /\.sass/, /\.(sc|sa)ss/
			if (testStr.includes('css') || testStr.includes('sass') || testStr.includes('scss') || testStr.includes('(sc|sa)ss')) {
				return null;
			}
		}
		return rule;
	}).filter(Boolean);
}

const cleanRules = removeCssRules(defaultConfig.module.rules);

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
	module: {
		...defaultConfig.module,
		rules: [
			...cleanRules,
			{
				test: /\.css$/,
				use: [
					MiniCssExtractPlugin.loader,
					'css-loader',
					{
						loader: 'postcss-loader',
						options: {
							postcssOptions: {
								plugins: [
									require('@tailwindcss/postcss'),
									require('autoprefixer'),
								],
							},
						},
					},
				],
			},
		],
	},
};