## 2025-01-20 - Expensive Class Instantiation in PHP Regex Loops

**Learning:** Instantiating classes inside high-frequency loops, such as regex replacements parsing HTML content (like `preg_replace_callback`), can create massive, hidden performance bottlenecks. Specifically, if a class constructor parses strings or does setup work (e.g., `Img_Converter` parsing URL strings to array with `explode` on every instantiation), and it's called for every image `src` and `srcset` item on a page, it can add significant time and memory overhead.
**Action:** Always check the constructor logic of classes being instantiated inside loops. Prefer instantiating dependencies once per class or request (caching the instance) and passing them or referencing them to avoid redundant setup operations inside high-frequency data processing paths.

## 2025-01-20 - Batching Option Updates in Loops

**Learning:** Frequent calls to `update_option()` within processing loops (such as parsing images in regex callbacks or executing cron background tasks) create massive, hidden database bottlenecks (N+1 query problem).
**Action:** When updating a persistent state array during high-frequency operations, batch the database writes. Cache the `get_option()` result in a static class property, modify the property in memory during the loop, and use `add_action( 'shutdown', ... )` to execute a single `update_option()` at the end of the request.

## 2025-01-20 - Optimizing Autoload Options and Script Enqueuing

**Learning:** Large options stored in `wp_options` (like `wppo_img_info` which tracks thousands of images) can cause severe memory bloat if they are autoloaded on every request. Additionally, calling `update_option()` without passing `false` as the third parameter will fall back to the default `yes` or retain its previous state. Enqueuing frontend scripts (like `lazyload.js`) unconditionally can also negatively impact page load times.

**Action:** When saving large datasets via `update_option()`, always explicitly pass `false` as the third parameter (`$autoload`) unless the data is strictly required on every single page load. When enqueuing scripts/styles, always wrap `wp_enqueue_script` inside conditional checks based on plugin settings.

## 2025-01-20 - Unconditional Transient Writes

**Learning:** Calling `set_transient()` unconditionally on every frontend page request (like `Asset_Manager::capture_page_assets()`) causes a massive N+1 database write bottleneck, inserting/updating into `wp_options` for every single page view.
**Action:** Always read the existing transient first using `get_transient()`. Only write using `set_transient()` if the value has actually changed or the cache has expired.

## 2025-01-20 - Short-Circuit Evaluation and Heavy Regex in File Processing

**Learning:** Executing heavy operations like file reading (`$this->filesystem->get_contents`) or regex string manipulation (`preg_split` or `substr_count`) inside loop conditions or frequently called functions (like CSS/JS minification checks) can destroy performance. Short-circuit evaluation in PHP means the order of `if` conditions matters immensely.
**Action:**

1. Always place the cheapest, most exclusionary checks (e.g., `is_user_logged_in()`, `empty( $var )`) first in `if` statements to leverage short-circuiting.
2. Replace memory-intensive regex functions like `preg_split` with faster, native string functions like `substr_count` when simply counting occurrences (like newlines).

## 2025-01-22 - Batching Option Updates with Shutdown Hook

**Learning:** `update_option()` operations inside high-frequency functions (e.g. queueing multiple image sizes during upload) can cause severe N+1 database bottlenecks.
**Action:** When updating a central state array, cache the array in a static class property. Modify the static array in memory and use `add_action( 'shutdown', ... )` to write the final state back to `wp_options` just once at the end of the request.

## 2024-05-15 - [Transient Caching for File System Operations]

**Learning:** Calculating file sizes or counts via recursive directory scanning (`dirlist`) on every WP-admin load creates a severe bottleneck that can crash or slow down the settings page.
**Action:** Always use WordPress Transients to cache expensive file system results, and invalidate these transients within `clear_cache` methods.

## 2025-01-20 - Early Return Before Expensive Computations

**Learning:** High-frequency operations like `Util::get_local_path` (which parses URLs) were being computed before short-circuit logic (`is_user_logged_in`, `empty( $href )`) in `minify_css` and `minify_js`. This caused significant performance overhead as they run on every page load.
**Action:** Always place lightweight conditionals before heavy variable assignments. Avoid executing computationally expensive operations unless strictly necessary.

## 2025-01-22 - Bypassing WP_Filesystem for large file reads

**Learning:** Using `$filesystem->get_contents()` to read entire asset files (CSS/JS) into memory just to determine if they are minified (e.g., checking if they have fewer than 10 lines) creates a massive memory bottleneck for large files.
**Action:** Use native PHP streaming functions like `fopen()` and `fgets()` to read files line-by-line and break early. Ensure you add `// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen` and `_fgets` (and `_fclose`) to bypass WPCS checks safely.

## 2025-01-22 - WPCS Error Suppression
**Learning:** WordPress Coding Standards (WPCS) strongly discourages using the error suppression operator (`@`) before functions like `fopen()`. While it suppresses warnings when a file doesn't exist, it is better to rely on `file_exists()` before opening or catch exceptions.
**Action:** Never use `@fopen()`. Rely on `file_exists()` and standard `$handle = fopen(...)` with a falsy check, and use `// phpcs:ignore` annotations to bypass strict file system rules.

## 2026-07-21 - Throttle Environment Failure Checks
**Learning:** When performing expensive environment checks (like file I/O to check or write to `wp-config.php`) inside high-frequency hooks like `admin_init`, failing to throttle the execution when the check *fails* will cause the application to repeatedly retry the failing operation on every single page load.
**Action:** Always unconditionally set the throttle transient (using `set_transient`) outside of success/failure conditional blocks to prevent constant retry loops.

## 2025-01-22 - Redundant Operations in Parsers
**Learning:** Functions called within high-frequency loops, such as parsing HTML tags, can cause significant performance degradation. Generating regular expressions via `preg_quote` and doing complex URL processing (like `Util::get_local_path`) for each tag or property evaluated can add substantial overhead across a large document.
**Action:** Lift static computations and string transformations out of loops when parsing. Delay expensive lookups (like resolving local filesystem paths) with lazy evaluation, ensuring they're executed at most once per distinct resource.

## 2025-01-22 - Array Processing in Regex Callback Loops
**Learning:** Calling array processing operations (like `Util::process_urls`, which filters, maps and unique-ifies arrays) inside high-frequency regex callbacks (like parsing every `<script>` tag on a page for minification or delayJS) can severely impact performance.
**Action:** When a static or configuration-based array needs to be processed to be used as exclusions or matches inside a regex callback loop, process and cache it once as a class property during instantiation instead of computing it dynamically for each match.

## 2025-01-28 - Caching WP Core Functions in Loops

**Learning:** Executing WordPress core functions like `home_url()`, `content_url()`, or `wp_upload_dir()` repeatedly inside high-frequency loops (e.g., regex callbacks parsing hundreds of images or CSS tags) creates significant performance overhead due to redundant hook executions and string processing within WP Core.

**Action:** When WordPress core utility functions that return static paths/URLs are needed inside parsing loops, cache their results in PHP `static` variables so they are computed only once per request and reused across all subsequent loop iterations.

**Exception (multisite):** In WordPress multisite, contexts can switch (e.g., via `switch_to_blog()`). When caching WP core function results, key the static cache by `get_current_blog_id()` to ensure lookups resolve correctly when the active site context changes within a single request.

## 2026-07-31 - Cache content_url in Asset Minification Loops

**Learning:** Executing WordPress core functions like `content_url()` repeatedly inside high-frequency execution paths (like when rewriting URLs for CSS and JS tags during minification) can add redundant performance overhead. Note that WP core itself already caches the filtered base URL in an internal static var, so the `content_url` filter runs at most once per request regardless of call count; the per-call cost is only cheap path string substitution.
**Action:** Declare a PHP static array keyed by `get_current_blog_id()` to resolve `content_url()` once per site per path and reuse the cached URL prefix during minification. Gate the cache with `has_filter( 'content_url' )` so context-dependent filtered output is never cached, mirroring the `class-main.php` convention; the measurable gain is minimal but the code stays consistent with the existing base-URL cache pattern.

## 2026-08-04 - Centralize content_url caching into Util::cached_content_url

**Learning:** The blog-ID-keyed `content_url()` cache introduced on 2026-07-31 was duplicated across the CSS and JS minifier constructors and `update_image_paths()`, and it lacked the `has_filter( 'content_url' )` guard already used in `class-main.php`. WP core already caches the filtered base URL statically, so the per-call win is marginal and the duplication was pure maintainability debt.
**Action:** Extract a single shared helper `Util::cached_content_url( $path )` that keys a static array by `get_current_blog_id()` and only caches when no `content_url` filter is registered (matching the `class-main.php` convention), then use it from every minifier call site.

## 2026-08-05 - Avoid redundant core function calls in string manipulation
**Learning:** Executing WordPress core functions like `home_url()` repeatedly within functions that normalize URLs dynamically (like `Image_Optimisation::normalize_image_url()`) incurs unnecessary hook resolution overhead when executed against a buffer containing many elements.
**Action:** Always cache the results of expensive functions such as `home_url()` using `static` variables, keyed per `get_current_blog_id()` for multisite safety, when the value remains constant during a request and is repeatedly used for comparison or transformation.

## 2026-08-05 - Centralize home_url caching into Util::cached_home_url

**Learning:** The blog-ID-keyed `home_url()` caching pattern was scattered across multiple classes (`class-util.php`, `class-img-converter.php`, `class-image-optimisation.php`) often with duplicate inline static arrays. WordPress core does not statically cache `home_url()`, meaning redundant calls in loops can add minor overhead, but duplicating the boilerplate to bypass this is poor maintainability.
**Action:** Extract a single shared helper `Util::cached_home_url()` that keys a static array by `get_current_blog_id()` and only caches when no `home_url` filter is registered, similar to `Util::cached_content_url()`, then use it from all relevant call sites.

## 2026-08-05 - Cache parsed URL parts in high-frequency hooks

**Learning:** Functions that parse URLs and execute string replacements (like `wp_parse_url( content_url( '/' ), PHP_URL_PATH )`) inside high-frequency hooks like `script_loader_src` or `style_loader_src` (e.g. `strip_static_query_strings` checking `is_plugin_cache_url()`) can cause measurable overhead due to the volume of assets processed on every page load.
**Action:** When working with base URLs inside asset enqueuing loops or hooks, statically cache the parsed paths and hostnames in a PHP `static` array keyed by `get_current_blog_id()` instead of parsing the core `content_url()` repeatedly.
