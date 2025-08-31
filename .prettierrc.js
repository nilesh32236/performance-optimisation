module.exports = {
	...require('@wordpress/prettier-config'),
	// Override any WordPress defaults here if needed
	printWidth: 100,
	tabWidth: 4,
	useTabs: true,
	semi: true,
	singleQuote: true,
	quoteProps: 'as-needed',
	trailingComma: 'es5',
	bracketSpacing: true,
	bracketSameLine: false,
	arrowParens: 'avoid',
};