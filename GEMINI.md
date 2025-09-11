# About the Project

This is a WordPress plugin for performance optimization. It includes features like caching, HTML/CSS/JS minification, image optimization (WebP/AVIF), lazy loading, preloading, and an analytics dashboard.

## Key Technologies

-   **Backend:** PHP (>=7.4)
-   **Frontend:** React, TypeScript, SCSS
-   **Build Tools:** Webpack (`@wordpress/scripts`), Composer

## Project Structure

-   `admin/`: Contains the source code for the admin dashboard, built with React and TypeScript.
-   `build/`: Contains the compiled assets (JS, CSS) for the plugin.
-   `includes/`: Contains the PHP source code for the plugin.
-   `node_modules/`: Contains the JavaScript dependencies.
-   `vendor/`: Contains the PHP dependencies.
-   `performance-optimisation.php`: The main plugin file.
-   `package.json`: Defines the JavaScript dependencies and scripts.
-   `composer.json`: Defines the PHP dependencies and scripts.
-   `webpack.config.js`: Webpack configuration for building the frontend assets.

## Getting Started

1.  **Install PHP dependencies:**
    ```bash
    composer install
    ```
2.  **Install JavaScript dependencies:**
    ```bash
    npm install
    ```
3.  **Start the development server:**
    ```bash
    npm run start
    ```
4.  **Build for production:**
    ```bash
    npm run build
    ```

## Available Commands

### PHP (Composer)

-   `composer install`: Install PHP dependencies.
-   `composer run lint`: Lint PHP files using `phpcs`.
-   `composer run lint-fix`: Automatically fix PHP linting errors.
-   `composer run analyze`: Run static analysis using `phpstan`.
-   `composer run test`: Run PHP unit tests using `phpunit`.
-   `composer run quality`: Run all PHP quality checks (lint, analyze, test).

### JavaScript (npm)

-   `npm install`: Install JavaScript dependencies.
-   `npm run start` or `npm run dev`: Start the development server with hot-reloading.
-   `npm run build`: Build the production-ready assets.
-   `npm run lint`: Lint JavaScript and CSS files.
-   `npm run lint:fix`: Automatically fix JavaScript and CSS linting errors.
-   `npm run test`: Run JavaScript unit tests.
-   `npm run test:watch`: Run JavaScript unit tests in watch mode.
-   `npm run test:e2e`: Run end-to-end tests.
-   `npm run env:start`: Start the local WordPress environment.
-   `npm run env:stop`: Stop the local WordPress environment.
