const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        index: path.resolve(process.cwd(), 'src', 'index.js'),
        wizard: path.resolve(process.cwd(), 'src', 'wizard.js'),
    },
    output: {
        ...defaultConfig.output,
        filename: '[name].js',
    },
};