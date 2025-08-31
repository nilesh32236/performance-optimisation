# Build Process

This plugin uses `@wordpress/scripts` for building and development, which provides WordPress-compatible webpack configuration and tooling.

## Prerequisites

- Node.js (version specified in `.nvmrc`)
- npm or yarn

## Installation

```bash
npm install
```

## Development

### Start Development Server
```bash
npm run start
# or
npm run dev
```

This will start webpack in watch mode with hot reloading for development.

### Build for Production
```bash
npm run build
```

This creates optimized production builds in the `build/` directory.

## File Structure

### Source Files
- `admin/src/` - React/TypeScript source files
- `admin/src/components/` - Reusable React components
- `admin/src/pages/` - Page-level components
- `admin/src/styles/` - SCSS stylesheets

### Build Output
- `build/` - Compiled JavaScript, CSS, and WordPress asset files
- `build/*.asset.php` - WordPress dependency files (auto-generated)

## Entry Points

The webpack configuration defines these entry points:

- `index` - Main admin interface (`admin/src/index.tsx`)
- `wizard` - Setup wizard (`admin/src/wizard.tsx`)
- `admin-bar` - WordPress admin bar integration (`admin/src/admin-bar.js`)
- `lazyload` - Lazy loading functionality (`admin/src/lazyload.js`)

## WordPress Integration

The build process automatically:
- Generates `.asset.php` files with WordPress dependencies
- Handles WordPress externals (React, ReactDOM, wp.element, etc.)
- Creates proper enqueue-ready assets
- Optimizes for WordPress coding standards

## TypeScript Support

TypeScript configuration is in `tsconfig.json` with path aliases:
- `@/*` → `admin/src/*`
- `@components/*` → `admin/src/components/*`
- `@pages/*` → `admin/src/pages/*`
- `@utils/*` → `admin/src/utils/*`
- `@types/*` → `admin/src/types/*`
- `@styles/*` → `admin/src/styles/*`

## Linting and Formatting

```bash
# Lint JavaScript/TypeScript
npm run lint:js

# Lint CSS/SCSS
npm run lint:css

# Fix linting issues
npm run lint:fix

# Format code
npm run format
```

## Testing

```bash
# Run unit tests
npm run test

# Run tests in watch mode
npm run test:watch

# Run end-to-end tests
npm run test:e2e
```

## Local WordPress Environment

```bash
# Start local WordPress environment
npm run env:start

# Stop local WordPress environment
npm run env:stop
```

## Package Management

```bash
# Update WordPress packages
npm run packages-update

# Create plugin zip file
npm run plugin-zip
```