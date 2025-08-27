### Manual Test Plan

This test plan should be executed after the AI has implemented the requested changes to ensure quality and functionality.

**Test Environment:**
*   WordPress Version: 6.4+
*   PHP Version: 7.4+
*   Browser: Google Chrome, Mozilla Firefox

**Part 1: Installation and Setup Wizard**

| Test Case ID | Test Step                                                                                                                              | Expected Result                                                                                                          | Pass/Fail | Notes |
| :----------- | :------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------- | :-------- | :---- |
| WIZ-01       | Activate the plugin for the first time on a fresh install.                                                                             | The user is automatically redirected to the Setup Wizard (`admin.php?page=performance-optimisation-setup`).             |           |       |
| WIZ-02       | In the wizard, proceed to the "Site Analysis" step.                                                                                    | The wizard displays an analysis of the site environment (hosting, plugins, etc.) without errors.                       |           |       |
| WIZ-03       | In the wizard, select the "Recommended" preset.                                                                                        | The "Recommended" card becomes visually selected.                                                                        |           |       |
| WIZ-04       | Proceed to the "Advanced Features" step and enable "Cache Preloading".                                                                 | The toggle for "Cache Preloading" switches to the "on" state.                                                            |           |       |
| WIZ-05       | Proceed to the "Summary" step.                                                                                                         | The summary correctly reflects the "Recommended" preset and that "Cache Preloading" is enabled.                          |           |       |
| WIZ-06       | Click the "Complete Setup" button.                                                                                                     | The settings are saved, and the user is redirected to the main dashboard (`admin.php?page=performance-optimisation`). |           |       |
| WIZ-07       | Navigate to another admin page and then back to the plugin's main page.                                                                | The user is not redirected to the wizard again.                                                                          |           |       |

**Part 2: Dashboard and Settings UI**

| Test Case ID | Test Step                                                                                                                              | Expected Result                                                                                                          | Pass/Fail | Notes |
| :----------- | :------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------- | :-------- | :---- |
| UI-01        | Navigate to the "Performance Optimisation" menu.                                                                                       | The `AnalyticsDashboard` is displayed, showing performance metrics and charts.                                           |           |       |
| UI-02        | Navigate to the "Settings" area from the dashboard.                                                                                    | A tabbed interface with "Caching", "File Optimization", etc., is displayed.                                              |           |       |
| UI-03        | In "File Optimization" settings, enable "Minify CSS".                                                                                  | The toggle for "Minify CSS" switches to the "on" state.                                                                  |           |       |
| UI-04        | Click the "Save Settings" button.                                                                                                      | A success notification appears. The page reloads, and the "Minify CSS" toggle is still in the "on" state.                  |           |       |
| UI-05        | Hover over the question mark icon next to "Minify CSS".                                                                                | A tooltip appears explaining what CSS minification does.                                                                 |           |       |

**Part 3: Core Functionality (Frontend)**

| Test Case ID | Test Step                                                                                                                              | Expected Result                                                                                                          | Pass/Fail | Notes |
| :----------- | :------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------- | :-------- | :---- |
| FE-01        | **(Setup)** Enable "Minify HTML" in settings and save. Visit the site's homepage as a logged-out user.                                   | View the page source. The HTML should be on a single line or have significantly reduced whitespace.                      |           |       |
| FE-02        | **(Setup)** Enable "Minify CSS" and "Combine CSS". Visit the homepage as a logged-out user.                                              | View the page source. Most `<link rel="stylesheet">` tags should be replaced by a single, combined, minified CSS file.   |           |       |
| FE-03        | **(Setup)** Enable "Lazy Loading" for images. Visit a page with many images that go below the fold.                                    | Images below the fold should not load initially. As you scroll down, they should appear. Check the `<img>` tags for `data-src` attributes. |           |       |
| FE-04        | **(Setup)** Enable "Page Caching". Visit a page as a logged-out user. Refresh the page.                                                | The page should load faster on the second visit. The HTML source should contain a comment at the bottom indicating it was served from the cache. |           |       |
| FE-05        | **(Setup)** Log in as an admin and view a page with the Admin Bar. Click "Performance Optimisation" -> "Clear Cache for This Page".      | A confirmation message appears. The cache for that specific page is cleared.                                             |           |       |
| FE-06        | **(Setup)** Log in and click "Clear All Cache" from the Admin Bar.                                                                     | A confirmation message appears. Visiting any page on the frontend should generate a fresh, uncached version.           |           |       |

**Part 4: Deactivation and Cleanup**

| Test Case ID | Test Step                                                                                                                              | Expected Result                                                                                                          | Pass/Fail | Notes |
| :----------- | :------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------------------------- | :-------- | :---- |
| CLEAN-01     | Deactivate the plugin from the "Plugins" page.                                                                                         | All optimizations (caching, minification) are no longer applied to the frontend. The site functions normally.          |           |       |
| CLEAN-02     | Check the `wp-config.php` file after deactivation.                                                                                     | The `define( 'WP_CACHE', true );` line added by the plugin should be removed.                                            |           |       |
| CLEAN-03     | Check the `wp-content/` directory.                                                                                                     | The `advanced-cache.php` file created by the plugin should be deleted.                                                   |           |       |
| CLEAN-04     | Delete the plugin from the "Plugins" page.                                                                                             | All plugin options (e.g., `wppo_settings`) and custom database tables (`wp_wppo_*`) should be removed from the database. |           |       |

