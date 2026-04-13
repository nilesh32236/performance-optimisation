## 2025-01-20 - Expensive Class Instantiation in PHP Regex Loops
**Learning:** Instantiating classes inside high-frequency loops, such as regex replacements parsing HTML content (like `preg_replace_callback`), can create massive, hidden performance bottlenecks. Specifically, if a class constructor parses strings or does setup work (e.g., `Img_Converter` parsing URL strings to array with `explode` on every instantiation), and it's called for every image `src` and `srcset` item on a page, it can add significant time and memory overhead.
**Action:** Always check the constructor logic of classes being instantiated inside loops. Prefer instantiating dependencies once per class or request (caching the instance) and passing them or referencing them to avoid redundant setup operations inside high-frequency data processing paths.
## 2025-01-20 - Batching Option Updates in Loops
**Learning:** Frequent calls to `update_option()` within processing loops (such as parsing images in regex callbacks or executing cron background tasks) create massive, hidden database bottlenecks (N+1 query problem).
**Action:** When updating a persistent state array during high-frequency operations, batch the database writes. Cache the `get_option()` result in a static class property, modify the property in memory during the loop, and use `add_action( 'shutdown', ... )` to execute a single `update_option()` at the end of the request.
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
