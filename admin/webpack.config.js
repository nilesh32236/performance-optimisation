const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = (env, argv) => {
  const isProduction = argv.mode === 'production';

  return {
    entry: {
      index: './src/index.tsx',
      dashboard: './src/pages/Dashboard/index.tsx',
      settings: './src/pages/Settings/index.tsx',
      'admin-bar': './src/admin-bar.js',
      lazyload: './src/lazyload.js',
    },
    output: {
      path: path.resolve(__dirname, '../assets/js'),
      filename: '[name].js',
      clean: true
    },
    resolve: {
      extensions: ['.tsx', '.ts', '.js', '.jsx'],
      alias: {
        '@': path.resolve(__dirname, 'src'),
        '@components': path.resolve(__dirname, 'src/components'),
        '@pages': path.resolve(__dirname, 'src/pages'),
        '@utils': path.resolve(__dirname, 'src/utils'),
        '@types': path.resolve(__dirname, 'src/types'),
        '@styles': path.resolve(__dirname, 'src/styles')
      }
    },
    module: {
      rules: [
        {
          test: /\.(ts|tsx|js|jsx)$/,
          exclude: /node_modules/,
          use: {
            loader: 'babel-loader',
            options: {
              presets: [
                '@babel/preset-env',
                '@babel/preset-react',
                '@babel/preset-typescript'
              ]
            }
          }
        },
        {
          test: /\.(scss|css)$/,
          use: [
            isProduction ? MiniCssExtractPlugin.loader : 'style-loader',
            'css-loader',
            'sass-loader'
          ]
        }
      ]
    },
    plugins: [
      new MiniCssExtractPlugin({
        filename: '../js/[name].css'
      })
    ],
    externals: {
      'react': 'React',
      'react-dom': 'ReactDOM',
      '@wordpress/element': 'wp.element',
      '@wordpress/components': 'wp.components',
      '@wordpress/i18n': 'wp.i18n',
      '@wordpress/api-fetch': 'wp.apiFetch',
      '@wordpress/icons': 'wp.icons'
    },
    devtool: isProduction ? false : 'source-map',
    optimization: {
      splitChunks: {
        chunks: 'all',
        cacheGroups: {
          vendor: {
            test: /[\\/]node_modules[\\/]/,
            name: 'vendors',
            chunks: 'all'
          }
        }
      }
    },
    devServer: {
      static: {
        directory: path.join(__dirname, '../assets')
      },
      compress: true,
      port: 3000,
      hot: true
    }
  };
};