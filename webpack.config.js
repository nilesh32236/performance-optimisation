const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
  ...defaultConfig,
  entry: {
    redesign: './src/redesign/index.js',
    'admin-bar': './src/admin-bar.js',
    lazyload: './src/lazyload.js',
  },
};
