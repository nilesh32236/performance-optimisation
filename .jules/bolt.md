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
## 2025-01-20 - Early Return Before Expensive Computations\n\n**Learning:** High-frequency operations like `Util::get_local_path` (which parses URLs) were being computed before short-circuit logic (`is_user_logged_in`, `empty( $href )`) in `minify_css` and `minify_js`. This caused significant performance overhead as they run on every page load.\n**Action:** Always place lightweight conditionals before heavy variable assignments. Avoid executing computationally expensive operations unless strictly necessary.


## 2025-01-22 - Bypassing WP_Filesystem for large file reads

**Learning:** Using `$filesystem->get_contents()` to read entire asset files (CSS/JS) into memory just to determine if they are minified (e.g., checking if they have fewer than 10 lines) creates a massive memory bottleneck for large files.
**Action:** Use native PHP streaming functions like `fopen()` and `fgets()` to read files line-by-line and break early. Ensure you add `// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen` and `_fgets` (and `_fclose`) to bypass WPCS checks safely.

## 2025-01-22 - WPCS Error Suppression
**Learning:** WordPress Coding Standards (WPCS) strongly discourages using the error suppression operator (`@`) before functions like `fopen()`. While it suppresses warnings when a file doesn't exist, it is better to rely on `file_exists()` before opening or catch exceptions.
**Action:** Never use `@fopen()`. Rely on `file_exists()` and standard `$handle = fopen(...)` with a falsy check, and use `// phpcs:ignore` annotations to bypass strict file system rules.

## 2025-01-22 - Optimizing Pre-processing Arrays Before Loops

**Learning:** When dealing with functions inside batch loops like `schedule_page_cron_jobs` (e.g., iterating through 200 items), any logic that runs inside the inner loop is multiplied by N*M times (where N is batch size and M is the size of the array to match against). Performing operations like `home_url()` and `str_replace` for every combination was a massive overhead.
**Action:** When filtering or matching against a static list within a loop, always extract the normalization/parsing logic *outside* the loop. Create a pre-processed array that contains exactly the parsed data needed (e.g., boolean flags for prefix vs exact match) so the inner loop merely executes highly optimized array element comparisons.

## 2025-01-22 - Avoid useless array iterations with early Breaks

**Learning:** Inside `img_convert_cron()`, iterating over an array to process a subset (`$counter <= $batch_size`) but failing to `break` after the batch limit is reached causes PHP to needlessly loop over the remaining thousands of items in the array, consuming CPU.
**Action:** Whenever iterating over a large array to process a specific 'batch' or 'limit', explicitly include a `break` statement as soon as the limit is hit.
