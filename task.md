### Project Plan: Performance Optimisation Plugin Enhancement

This plan outlines the necessary steps to improve the plugin's functionality, code quality, and user experience. The goal is to create a robust, reliable, and user-friendly plugin for both novice and advanced users.

---

#### 1. Project Cleanup and Code Consolidation

-   [ ] **Consolidate `package.json`**: Merge the `admin/package.json` into the root `package.json`. All `npm` scripts and dependencies should be managed from the root directory to simplify the build process and dependency management. Update the `webpack.config.js` to reflect the new structure.
-   [ ] **Refactor Legacy PHP Code**:
    -   [ ] Move the logic from `includes/class-activate.php` into the `Core/Bootstrap/Plugin.php` class as an `activate` method.
    -   [ ] Move the logic from `includes/class-deactivate.php` into the `Core/Bootstrap/Plugin.php` class as a `deactivate` method.
    -   [ ] Ensure the main plugin file (`performance-optimisation.php`) calls these new methods in the activation/deactivation hooks.
    -   [ ] Review all files in the `includes/` root and refactor them into the new `Core` or `Services` namespaces where appropriate, following the established object-oriented and dependency injection patterns.
-   [ ] **Code Standards Audit**:
    -   [ ] Run `composer lint-fix` to automatically fix PHP coding standard violations.
    -   [ ] Run `npm run lint:fix` to automatically fix JavaScript, TypeScript, and SCSS coding standard violations.
    -   [ ] Manually review and fix any remaining issues reported by `phpcs` and `eslint`.
-   [ ] **Update Documentation**:
    -   [ ] Correct the `composer.json` and `package.json` examples in `readme.md` to match the actual project files.
    *   [ ] Review `readme.md` and `readme.txt` to ensure all features mentioned are implemented and described accurately.

#### 2. Enhance User Experience (UX) & User Interface (UI)

-   [ ] **Implement a Unified Admin Interface**:
    -   [ ] Merge the functionality from `admin/src/App.tsx` and `admin/src/pages/Dashboard/Dashboard.tsx`.
    -   [ ] The main admin page should be the `AnalyticsDashboard`. Add a prominent "Settings" button or tab to navigate to the detailed configuration.
-   [ ] **Improve Settings Pages for Usability**:
    -   [ ] Group settings into logical tabs (e.g., Caching, File Optimization, Image Optimization, Advanced).
    -   [ ] For each setting, provide a simple toggle for non-technical users.
    -   [ ] Add an "Advanced" toggle or expandable section for technical options (e.g., exclusion lists, specific quality numbers).
    -   [ ] Integrate the `ContextualHelp` components (`HelpTooltip`) next to each setting to explain what it does in simple terms.
-   [ ] **Ensure Setup Wizard is Fully Functional**:
    -   [ ] Verify that the wizard runs for all new users.
    -   [ ] Confirm that selecting a preset (`Safe`, `Recommended`, `Advanced`) correctly applies the settings by making an API call to the backend.
    -   [ ] Ensure the `onComplete` handler in `SetupWizard.tsx` correctly saves the final configuration and redirects the user to the main dashboard.

#### 3. Implement Core Functionality

-   [ ] **Make Settings Pages Functional**:
    -   [ ] In the React components for settings, implement API calls to save changes when the user clicks a "Save" button.
    -   [ ] The `SettingsController.php` already has the necessary endpoints (`get_settings`, `update_settings`). Wire the frontend to these endpoints.
    -   [ ] Provide feedback to the user on save (e.g., a success toast notification).
-   [ ] **Implement Optimization Logic**:
    -   [ ] **File Optimization**: Ensure the `OptimizationService` is correctly hooked into WordPress to apply HTML, CSS, and JS minification to the frontend output.
    -   [ ] **Image Optimization**: Ensure the `ImageService` correctly hooks into `the_content` and other relevant filters to replace standard `<img>` tags with lazy-loaded, WebP/AVIF versions.
    -   [ ] **Caching**: The `CacheService` should be hooked to `template_redirect` to serve cached HTML files when available and generate them when not.
-   [ ] **Implement Admin Bar Functionality**:
    -   [ ] The `Admin.php` class adds an "Admin Bar" menu.
    -   [ ] Ensure the "Clear All Cache" and "Clear Cache for This Page" buttons are functional by making API calls to the `CacheController.php` endpoints from `admin-bar.js`.

#### 4. Finalize and Polish

-   [ ] **Review and Refactor**: Review all implemented code for clarity, performance, and adherence to WordPress best practices.
-   [ ] **Test**: Perform a full manual test of the plugin based on the test plan.
-   [ ] **Update Version**: Update the plugin version number in `performance-optimisation.php`, `package.json`, and `readme.txt` to reflect the new release.
