const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
	entry: {
		index: path.resolve(__dirname, 'src', 'index.js'),
		wizard: path.resolve(__dirname, 'src', 'wizard.js'),
		'admin-bar': path.resolve(__dirname, 'src', 'admin-bar.js'),
		lazyload: path.resolve(__dirname, 'src', 'lazyload.js'),
		admin: path.resolve(__dirname, 'admin/src', 'index.tsx'),
	},
	output: {
		path: path.resolve(__dirname, 'assets/js'),
		filename: '[name].js',
		chunkFilename: '[name].chunk.js',
		clean: true,
	},
	module: {
		rules: [
			{
				test: /\.(js|jsx|ts|tsx)$/,
				exclude: /node_modules/,
				use: {
					loader: 'babel-loader',
					options: {
						presets: [
							'@babel/preset-env',
							'@babel/preset-react',
							'@babel/preset-typescript'
						],
					},
				},
			},
			{
				test: /\.scss$/,
				use: [
					MiniCssExtractPlugin.loader,
					'css-loader',
					'sass-loader',
				],
			},
			{
				test: /\.css$/,
				use: [
					MiniCssExtractPlugin.loader,
					'css-loader',
				],
			},
		],
	},
	plugins: [
		new MiniCssExtractPlugin({
			filename: '../css/[name].css',
		}),
	],
	resolve: {
		extensions: ['.js', '.jsx', '.ts', '.tsx'],
		alias: {
			'@': path.resolve(__dirname, 'src'),
			'@components': path.resolve(__dirname, 'admin/src/components'),
			'@utils': path.resolve(__dirname, 'src/utils'),
			'@services': path.resolve(__dirname, 'src/services'),
			'@hooks': path.resolve(__dirname, 'src/hooks'),
			'@types': path.resolve(__dirname, 'admin/src/types'),
		},
	},
	externals: {
		react: 'React',
		'react-dom': 'ReactDOM',
		jquery: 'jQuery',
	},
	optimization: {
		splitChunks: {
			cacheGroups: {
				vendor: {
					test: /[\\/]node_modules[\\/]/,
					name: 'vendors',
					chunks: 'all',
				},
			},
		},
	},
};