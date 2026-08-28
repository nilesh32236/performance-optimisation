# FINAL PERFORMANCE REVIEW — Post-Fix Verification

**Reviewer:** Final Performance Agent (independent)
**Date:** 2026-08-28
**Workspace:** `/var/www/nileshportfolio.duckdns.org/wp-content/plugins/performance-optimisation`
**Branch:** `fix/deep-dashboard-2026` (`9ce35209`)
**Git range inspected:** `origin/master (c127b865)` → `HEAD` ; detailed `git diff HEAD` (46 files unstaged) + `git diff origin/master` + direct `Read` of `includes/class-util.php`, `class-cache.php`, `class-rum.php`, `class-image-optimisation.php`, `class-cron.php`, `class-database-cleanup.php`, `class-main.php`, `class-rest.php`, `class-telemetry.php`, `class-used-css.php`
**Method:** Independent re-read of every changed file (`Read` with offsets), traced `get_option`/`transient`/`WP_Filesystem`/`file_exists`/`uasort` hot paths via `grep -rn`, measured complexity before/after by static counting (no reliance on implementor claims). No production code executed — static reasoning only.

---

## 1. Scope

**Files changed (46):** `includes/class-util.php`, `class-cache.php`, `class-rum.php`, `class-image-optimisation.php`, `class-cron.php`, `class-database-cleanup.php`, `class-main.php`, `class-rest.php`, `class-abilities.php`, `class-activate.php`, `class-advanced-cache-handler.php`, `class-critical-css.php`, `class-google-fonts.php`, `class-used-css.php`, `class-wppo-cli-command.php`, `src/components/*`, `src/lib/litespeed.js`, `src/css/*`, `tests/php/*`, `build/*`, `readme.txt`, `uninstall.php`

**Performance surface inspected (per agent-A09):** PHP CPU (loops, `uasort`, `preg`, `WP_HTML_Tag_Processor`, `filesize`/`file_exists`/`getimagesize`), WordPress (`get_option`×6, `update_option` write-hotspot, transients, `WP_Query`, `wp_remote_*`, hooks), Database (`$wpdb` batches, `COUNT(*)`, N+1), Frontend (JS idle/observer — out of scope for this PHP review except `rum.js` beacon), Caching (stampede, atomic rename, object-cache compat, multisite `transient_key`).

**IDs under verdict:** `P-WP-01`, `P-CACHE-03`, `P-DB-01`, `P-CPU-01`, `P-CPU-02`, `P-CPU-04` (task prompt). Remaining opportunities `P-CPU-03/05/06/07`, `P-WP-02/03/05` traced for completeness.

---

## 2. Fixes Verified (per finding, with before/after and file:line evidence)

### 2.1 P-WP-01 — `get_option('wppo_settings')` 6× deserialization per frontend render (HIGH)

**Original — HIGH:**
- `includes/class-main.php:169-196` `__construct` did `get_option('wppo_settings', array(cache_settings/file_optimisation/...12 defaults...))` every frontend request (bootstrap `new Main()` at `performance-optimisation.php:44` before `plugins_loaded`).
- `includes/class-cache.php:261` (pre-fix) ` $this->options = !empty($options)?$options:get_option('wppo_settings',array())`
- `includes/class-cron.php:115,141` `schedule_cron_jobs()` did `get_option`×2 + 6× `wp_next_scheduled` (each `get_option('cron')` unserialize)
- `includes/class-used-css.php`, `class-critical-css.php`, `class-image-optimisation.php` (receives `$this->options` but fallback `get_option`), `class-cache.php` etc. — **up to 6 deserializations** of ~8–15 KB serialized array per single frontend `GET /` when `enableCache+convertImg+removeUnusedCSS` all on.
- Option is `autoload=yes` → single `wp_load_alloptions` DB hit, but each `get_option` still does `maybe_unserialize` + `wp_cache_get` + `apply_filters('option_wppo_settings')` + array copy.

**Measured impact (pre-fix, per agent-A09):** 6× `maybe_unserialize` + 6× memcpy + 6× filter ≈ **3–6 ms** + 6× memory copies per request; scales with `wppo_settings` growth (10 tabs).

**Fix — PARTIAL PASS (hot path fixed, long tail remains):**
- `includes/class-util.php:84-213` introduces per-request memo:
  ```php
  private static ?array $settings_cache = null;
  private static bool $settings_cache_loaded = false;
  public static function get_settings(): array {
    if (self::$settings_cache_loaded) return self::$settings_cache ?? [];
    self::ensure_settings_cache_hook();
    $raw = get_option('wppo_settings',[]);
    if (!is_array($raw)) $raw=[];
    self::$settings_cache=$raw; self::$settings_cache_loaded=true; return $raw;
  }
  public static function set_settings_cache(array $s): void { ... }
  public static function clear_settings_cache(): void { ... }
  public static function reset_all_caches(): void { ... }
  private static function ensure_settings_cache_hook(): void {
    static $hooked=false; if($hooked) return; $hooked=true;
    add_action('update_option_wppo_settings',[self::class,'on_settings_update'],10,2);
    add_action('add_option_wppo_settings',[self::class,'on_settings_add'],10,2);
    add_action('delete_option_wppo_settings',[self::class,'clear_settings_cache']);
  }
  public static function on_settings_update($o,$v): void { self::$settings_cache=is_array($v)?$v:[]; self::$settings_cache_loaded=true; }
  ```
- Consumers migrated (verified via `grep -rn get_option.*wppo_settings` vs `Util::get_settings`):
  - `includes/class-cache.php:261` now `Util::get_settings()` — **fixed** (`includes/class-cache.php:261`)
  - `includes/class-main.php:251` ` $stored=Util::get_settings(); $this->options=!empty($stored)?$stored:$defaults` — **fixed**, eliminates the constructor's inline default array copy on every request (`includes/class-main.php:251`)
  - `includes/class-cron.php:115,168,184,202,256,307,378,629,698` — all 8 call sites now `Util::get_settings()` — **fixed** (`includes/class-cron.php:115`)
  - `includes/class-cache.php:1633` Brotli fallback branch now `Util::get_settings()` not `get_option` — **fixed**

- **Not yet migrated (still direct `get_option`):** `includes/class-litespeed-integration.php:305,475,690,771,818,1114,1154,1189` (8 sites), `includes/class-cdn-purger.php:55,112` (2), `includes/class-llms.php:38,236,342,361` (4), `includes/class-advanced-cache-handler.php:153`, `includes/class-critical-css.php:925,1015,1026` (3), `includes/class-htaccess-handler.php:163`, `includes/class-object-cache.php:120`, `includes/class-pagespeed.php:325`, `includes/class-rest.php:470,647,754,865`, `includes/class-server-rules.php:74,123`, `includes/class-system-info.php:368`, `includes/class-wppo-cli-command.php:309,560,652`, `includes/class-abilities.php:337,348,359,370,381` (5), `includes/class-main.php:795,1069,1114` (3). Total **~32 residual direct `get_option('wppo_settings')`** sites remain.

- **Invalidation correctness:** `ensure_settings_cache_hook()` with `static $hooked` ensures hooks registered once per request (not per `get_settings` call). `on_settings_update`/`on_settings_add` keep memo coherent when `update_option` is used via WP API (all `Rest::update_settings:470` + `import_settings:754` use `update_option`). **Edge:** direct `$wpdb->update('wp_options', ...)` or `wp_cache_set('wppo_settings','options',...)` bypasses hooks → stale memo for remainder of request (unlikely, no such path in plugin). `delete_option` clears via `clear_settings_cache`. Test helpers `clear_settings_cache`/`reset_all_caches` correctly bound.

**Before/after:**
| Metric | Before | After (hot path) | Delta |
|---|---|---|---|
| `get_option('wppo_settings')` deserializations per frontend `GET /` (cache+convert+usedCSS) | **6** (Main + Cache + Cron×2 + Used_CSS fallback + Image_Opt via Main) | **1** (first `Util::get_settings` does `get_option`; next 5 hit static memo) | **-5 (83%)** |
| PHP churn | 6× `maybe_unserialize` (~8–15 KB) + 6× `apply_filters` | 1× unserialize + 1× filter + 5× array return | **-3–5 ms per cache-miss render** (agent-A09's 3–6 ms → ~0.5–1 ms) |
| DB queries | 1 `wp_load_alloptions` (autoload) + 0 extra | same | **0** (autoload already single hit) |
| Memory copies | 6× array copy | 1× copy (memo returns same array reference; PHP copy-on-write keeps it zero-copy until mutated) | **-5×** |
| Long-tail (LSCache/CDN/LLMS paths) | 32 extra deserializations on LS purge, admin `system_info`, CLI | **still 32** — not yet collapsed | **0** for non-hot-path callers |

**Verdict: PARTIAL PASS — hot frontend render path (P-WP-01's 6×) is fixed and measurable; long-tail callers not yet migrated so full `grep get_option wppo_settings = 45 → 32` reduction is only 29%. No regression, but remaining opportunity is real. Complexity cost is low (+90 lines, well-documented, test helpers added). Missed optimization: not migrating `LiteSpeed_Integration`, `CDN_Purger`, `LLMS`, `Abilities` etc. would yield another ~1 ms on LS-coexistence pages and admin REST.

---

### 2.2 P-CACHE-03 — Static HTML cache stampede (concurrent writes to same `index.html`) (HIGH)

**Original — HIGH:**
- `includes/class-cache.php:1588-1643` (pre-fix) `save_cache_files($buffer,$file_path)` did ` $fs->put_contents($file_path,$buffer,FS_CHMOD_FILE)` + `gzencode` + `put_contents(.gz)` + `brotli_compress` via direct `put_contents` **without lock or atomic rename**. `Cache::process_buffer_for_cache` + `stash_cache` (WP 6.9+ `wp_finalized_template_enhancement_output_buffer`) both call `save_processed_buffer` → `save_cache_files` after `is_cache_allowed`+`is_not_cacheable` checks. Concurrent uncached-URL requests (cold cache after `clear_all` + 100 RPS burst) all compute `process_buffer_only` (full image/CDN/used-CSS/minify) and truncate+write same `index.html(.gz/.br)` concurrently. `advanced-cache.php` drop-in serves via `readfile` and could read torn file. No `flock`/`atomic rename` (unlike `Used_CSS::save_used_css` which already did `.tmp.wp_rand`+`move`).

**Measured impact (pre-fix):** 100 workers × 50 KB HTML + 2 compress = **5 MB write I/O** + 100× DB render + interleaved truncate → **stale/Torn cache** and TTFB spike for burst (agent-A09's thundering-herd).

**Fix — PASS (atomic + advisory lock, with minor race noted):**
- `includes/class-cache.php:1569-1587` new `atomic_put_contents(string $path, string $contents): bool`:
  ```php
  $tmp=$path.'.tmp.'.wp_rand();
  if(!$fs->put_contents($tmp,$contents,FS_CHMOD_FILE)){ $fs->delete($tmp); return false; }
  $moved=$fs->move($tmp,$path,true);
  if(!$moved){ $fs->delete($tmp); return (bool)$fs->put_contents($path,$contents,FS_CHMOD_FILE); }
  return true;
  ```
  - Writes to sibling tmp file then atomic `move`/`rename` (POSIX atomic on same filesystem). Readers never see partial write. Falls back to direct `put_contents` if `move` unsupported (FS_CHMOD path) — graceful.
  - `tmp` name uses `wp_rand()` (not `uniqid`+PID) — collision probability `1/2^31` per concurrent pair, negligible for 100 RPS but not zero; acceptable.
- `includes/class-cache.php:1608-1621` `save_cache_files` now:
  ```php
  $lock_key=Util::transient_key('wppo_cache_write_'.md5($file_path));
  if(get_transient($lock_key)) return;
  set_transient($lock_key,1,5);
  try {
    $this->atomic_put_contents($file_path,$buffer);
    if(function_exists('gzencode')){ $gzip=gzencode($buffer,9); if(false!==$gzip) $this->atomic_put_contents($gzip_file_path,$gzip); }
    // Brotli .br via atomic_put_contents (both branches now)
    if('html'===$type){ $this->delete_cache_files(trailingslashit(dirname($file_path)).'.wppo-no-cache'); }
  } finally { delete_transient($lock_key); }
  ```
  - Per-file transient lock 5 s, multisite-safe via `Util::transient_key`. If lock present, writer bails (last-writer-wins eliminated for burst).
  - `finally` ensures lock cleared even on exception.

- Additional centralization: `includes/class-cache.php:380-388` `should_bypass_for_litespeed(): bool` extracts `LiteSpeed_Integration::should_disable_wppo_optimizer() || has_filter(litespeed_can_optm)&&!apply_filters(...)` used by `combine_css:397` and `minify_buffer:1362` — DRY, not perf but reduces branch duplication.

**Before/after:**
| Metric | Before | After | Delta |
|---|---|---|---|
| Write atomicity | `put_contents` truncate-in-place (torn reads possible) | tmp+`move` atomic | **torn-read eliminated** |
| Concurrent burst (100 RPS cold) | 100× full render + 100× gz(9)+br(4) + 100× FS write (5 MB) | 1× render+writes wins, **99× early-return on transient lock** (hit `get_transient` only) | **-99× CPU+IO** for burst |
| `atomic_put_contents` I/O | 1× `put_contents` per file | 1× `put_contents(tmp)` + 1× `move` per file (≈1 extra `rename` syscall) | **+1 syscall** per file, negligible vs 50 KB `gzencode(9)` cost |
| Residual race | N/A (no lock) | `get_transient→set_transient` is **not atomic** (`wp_cache_add` semantics) — two workers can both see miss and both write (advisory, not mutex) | **low** — still bounded to 2 concurrent writes vs 100, and atomic rename prevents torn file; last writer wins but file stays consistent |

**Verdict: PASS — real fix, measurable.** Stampede is bounded from 100× to ≤2× renders per 5 s window per URL, and file corruption is eliminated. Minor residual: transient lock is advisory, not `wp_cache_add`/CAS; a `try { wp_cache_add($lock_key,1, '',5) }` would be stricter. `WP_Filesystem::move` fallback branch repeats `brotli_compress` existence check twice (dead `elseif` still present: `if($use_brotli && function_exists('brotli_compress')) ... elseif($use_brotli && extension_loaded('brotli')) { if(function_exists...) }` — second branch unreachable, flagged as `P-CPU-10 INFO` previously, still present). No functional regression.

---

### 2.3 P-DB-01 — RUM per-beacon `get_option+update_option('wppo_web_vitals_rum')` write hotspot (HIGH)

**Original — HIGH:**
- `includes/class-rum.php:311-330` `store_sample()` did ` $all=get_option(OPTION,[]); ...; update_option(OPTION,$all,false)` on **every beacon** (anonymous frontend `POST rum_collect`). Option `autoload=no` → each beacon does `SELECT`+`UPDATE` on `wp_options` (row-level `SELECT ... FOR UPDATE` implicit). `MAX_DAYS 14 × MAX_PATHS 200 ×5 metrics` ≈ 15k numbers → ~200 KB serialized. At 10k views/day → **10k SELECT + 10k UPDATE of 200 KB = 2 GB write I/O/day**, plus `wp_cache_flush` invalidation. Concurrent beacons race `get_option→mutate→update_option` → last-writer-wins **sample loss**. On Redis object-cache, each `update_option` is `SET`+`DELETE`.

**Fix — PASS (batched queue), measurable, with data-loss caveat:**
- `includes/class-rum.php:58-82` constants:
  ```php
  private const QUEUE_KEY='wppo_rum_queue';
  private const FLUSH_LOCK_KEY='wppo_rum_flush_lock';
  private const QUEUE_MAX=100;
  private const FLUSH_THRESHOLD=20;
  ```
- `includes/class-rum.php:317-344` `store_sample()` now:
  ```php
  $sample['_ts']=time();
  $queue_key=Util::transient_key(self::QUEUE_KEY);
  $queue=get_transient($queue_key); if(!is_array($queue)) $queue=[];
  $queue[]=$sample;
  if(count($queue)>self::QUEUE_MAX) $queue=array_slice($queue,-self::QUEUE_MAX);
  set_transient($queue_key,$queue,HOUR_IN_SECONDS);
  if(count($queue)>=self::FLUSH_THRESHOLD) self::flush_queue();
  elseif(wp_rand(1,10)===1) self::flush_queue();
  else if(!wp_next_scheduled('wppo_rum_flush')) wp_schedule_single_event(time()+300,'wppo_rum_flush');
  ```
  - Per beacon now does **transient queue append** (object-cache `SET` or `wp_options` transient row) instead of `wppo_web_vitals_rum` `get_option+update_option`. Flush triggered deterministically at 20, randomly 10%, else cron 300 s.
- `includes/class-rum.php:357-431` `flush_queue(): void`:
  ```php
  $lock_key=Util::transient_key(self::FLUSH_LOCK_KEY);
  if(get_transient($lock_key)) return;
  set_transient($lock_key,1,30);
  try {
    $queue_key=Util::transient_key(self::QUEUE_KEY);
    $queue=get_transient($queue_key);
    if(empty($queue)||!is_array($queue)) return;
    delete_transient($queue_key); // copy-then-clear before aggregation
    $all=get_option(self::OPTION,[]); if(!is_array($all)) $all=[];
    foreach($queue as $sample){ $ts=$sample['_ts']??time(); $date=gmdate('Y-m-d',$ts); ... // per-metric n/sum/min/max, MAX_PATHS 200 array_shift, ... }
    $cutoff=gmdate('Y-m-d',time()-MAX_DAYS*DAY_IN_SECONDS); foreach(array_keys($all) as $k) if($k<$cutoff) unset($all[$k]);
    update_option(self::OPTION,$all,false);
  } finally { delete_transient($lock_key); }
  ```
  - Single `get_option+update_option` per flush (≤100 samples), with 30 s lock and **copy-then-delete** queue so beacons arriving during aggregation queue separately. Timestamp bucketed by `sample['_ts']` not flush day.
- `includes/class-rum.php:153-157` `get_data()` now opportunistically `flush_queue()` before reading — dashboard sees near-real-time.
- `includes/class-cron.php:74,409` wires `add_action('wppo_rum_flush',['RUM','flush_queue'])` + `wp_clear_scheduled_hook('wppo_rum_flush')` on unschedule.

**Before/after (model: 10k beacons/day, ~7 beacons/min avg, burst 100/min):**
| Metric | Before | After | Delta |
|---|---|---|---|
| `wppo_web_vitals_rum` `get_option+update_option` per day | **10,000× (200 KB)** | **~500× (200 KB)** (`10k/20` threshold) + transient `get/set` per beacon (≈10k transient ops) | **-95% option writes** |
| Write I/O | 2 GB/day (10k×200 KB) + `wp_cache_flush`×10k | ~100 MB/day (500×200 KB) + transient I/O (if Redis, `SET` fast; if DB, `wp_options` transient row ~1 KB vs 200 KB) | **-95%** |
| Race sample loss | last-writer-wins on `update_option` (unbounded) | last-writer-wins on **transient queue** (`get_transient→set_transient` not atomic) — two concurrent beacons can both read same queue length N, both append one, both `set_transient` N+1 → **1 sample lost per race window**; but `QUEUE_MAX 100` + threshold 20 + 10% random keeps queue short, and flush does copy-then-delete so in-flight beacons re-queue | **loss bounded to queue race vs option race**, lower impact |
| Retention | per-sample `gmdate('Y-m-d')` (flush day) could mis-bucket samples delayed across midnight | now `_ts` bucketed correctly (sample day) | **correct** |
| Data loss on crash | none (per-beacon durable) | if worker dies between `delete_transient($queue_key)` and `update_option` → **≤100 samples lost** (queue cleared before persisted) | **new risk** (see §3) |
| Cron chatter | none | `wp_next_scheduled` check on every non-threshold beacon (9/10 beacons in low-traffic) — extra DB query per beacon | **+1 query per 90% of beacons** (see §3) |

**Verdict: PASS — real, measurable improvement; bottleneck shifted correctly from `wp_options` hotspot to transient queue.** 95% reduction in 200 KB option writes is the headline win. New issues are bounded (queue race + crash window + cron chatter) and do not outweigh the gain. Recommend follow-up: use `wp_cache_add` CAS or `wp_cache_incr` for queue, and schedule single flush only when queue transitions 0→1 (not every beacon).

---

### 2.4 P-CPU-01 — `combine_css` triple classify (HIGH) + P-CPU-02 — `core_will_inline` double budget sim 120× (HIGH)

**Prompt groups these; verified together (`includes/class-cache.php`).**

**Original — HIGH (P-CPU-01):**
- `Cache::combine_css:365-557` duplicated skip-rule logic: `get_combined_handles($styles, $exclude)` iterates `queue×exclude` (`strpos` O(n·m)), then freshness loop `428-469` iterated `queue×core_will_inline×is_core_block_asset×exclude`, then generation loop `474-534` repeated **same 3 predicates + `fetch_remote_css`**. Each handle classified **3×** per request.
- On 30 styles ×5 excludes = **90 `strpos`×3 = 270 checks + 30× `core_will_inline` budget sims ×3 = 90 budget passes** per render. Cost ≈ **0.5–1 ms** extra CPU + double FS stat.

**Original — HIGH (P-CPU-02):**
- `core_will_inline($handle):732-878` called `core_inline_budget_will_inline($handle,$limit,false)` (prediction) **and** `core_inline_budget_will_inline($handle,$limit,true)` (reference) per handle, comparing drift. Each `core_inline_budget_will_inline` walks `inline_size_map` (built lazily via `is_file+filesize+is_readable` per queued handle). For 30 queued styles, `combine_css` called `core_will_inline` 30 (freshness) +30 (generation) =60×2= **120 simulations** each `uasort`+cumulative loop. `inline_size_map` cached after first, but double-sim still 120× `uasort` (≈3600 comparisons, **1–2 ms**).

**Fix — PASS (both):**
- **P-CPU-01:** `includes/class-cache.php:442-494` now:
  ```php
  $eligible_handles = $this->get_combined_handles($styles,$exclude_combine_css); // single classify
  // freshness loop: foreach($eligible_handles as $handle) { if(!isset($registered[$h])) continue; $src...; if(fs->mtime(src)>cache_mtime) ... }
  // generation loop: foreach($eligible_handles as $handle) { $src=$registered[$h]->src; $css=$this->fetch_remote_css($src); ... }
  ```
  - Freshness and generation loops now iterate the **pre-classified** `eligible_handles` only — no re-running `core_will_inline`/`is_core_block_asset`/`exclude` checks. `get_combined_handles:605-650` remains single source (skips `core_will_inline`, block assets, excludes, non-`all` media). Comment `// Reuse the pre-classified eligible handles to avoid re-running ... (triple classify).` at `459-460` + `492-493`.
  - Correctness: eligible set is identical to prior triple loops' intersection (same skip predicates), so `mtime` freshness and `fetch_remote_css` generation remain equivalent. Verified no `core_will_inline` call remains in those two loops.

- **P-CPU-02:** `includes/class-cache.php:119-129` + `719-768` new memo:
  ```php
  private array $core_will_inline_memo = [];
  private function core_will_inline($handle): bool {
    if(isset($this->core_will_inline_memo[$handle])) return $this->core_will_inline_memo[$handle];
    if(!function_exists('wp_maybe_inline_styles')){ $this->core_will_inline_memo[$handle]=false; return false; }
    if(!isset($wp_styles->registered[$handle])){ $this->core_will_inline_memo[$handle]=false; return false; }
    if($this->inline_candidates_require_src() && empty($registered->src)){ $this->core_will_inline_memo[$handle]=false; return false; }
    $limit=...; $pred=$this->core_inline_budget_will_inline($handle,$limit,false);
    $ref =$this->core_inline_budget_will_inline($handle,$limit,true);
    if($pred!==$ref){ $this->inline_drift_detected=true; $this->log_inline_budget_drift(...); $this->core_will_inline_memo[$handle]=true; return true; }
    $this->core_will_inline_memo[$handle]=$pred; return $pred;
  }
  ```
  - Memo invalidated correctly: `register_combine_css_path:965-966` resets both `inline_size_map=null` and `core_will_inline_memo=[]` when combined handle gains `path` data.
  - `inline_size_map:117` remains cached after first `core_inline_budget_will_inline` call (single `is_file+filesize+is_readable` pass per queue snapshot), so repeated simulations reuse it.

**Before/after (30-style page):**
| Metric | Before | After | Delta |
|---|---|---|---|
| Classify iterations | 3× loops over 30 handles (≈90 classify ops, 90 `core_will_inline` calls) | **1×** `get_combined_handles` (30 calls) + 2× loops over `eligible_handles` (≤30 each, no classify) | **-60 classify ops (-67%)** |
| `core_will_inline` simulations | 120 (60 handles×2 sims, across freshness+generation) | **≤60** (30 unique handles×2 sims memo'd, second loop hits memo) | **-50% (120→60)** |
| `uasort` + budget walks | 120 | **60** | **-50%** |
| Wall CPU | ~1.5–3 ms combined (0.5–1 ms classify + 1–2 ms budget) | ~0.7–1.5 ms | **-0.8–1.5 ms per cache-miss render** |
| FS stats | `inline_size_map` built once (30× `is_file+filesize+is_readable` ≈30 stats) + `should_skip_combine_for_inline_budget` `filesize` per eligible handle (≤30) | same | **0** (already cached) |

**Verdict: PASS — both cleanly fixed with zero behavioural change.** Memo + single-classify are minimal, well-commented, and correctly invalidated. Remaining micro-gap: each handle still does **dual simulation** (`false`+`true`) even when `prediction===false` (drift only matters when plugin thinks it will inline). Computing `reference` only when `prediction===true` would halve again to ~30 sims, but current 60 is already within noise.

---

### 2.5 P-CPU-04 — `file_exists` per image without memo (HIGH, `class-image-optimisation.php`)

**Original — HIGH:**
- `replace_image_with_next_gen:912-938` did `file_exists($avif_img_path)` + `file_exists($webp_img_path)` per image per render, plus source `file_exists` on queue-miss. Call chain: `maybe_serve_next_gen_images` TagProcessor loop per `<img>` + per `srcset` candidate calls `replace_image_with_next_gen`. Gallery 80 images ×3 srcset = **240 images ×2 = 480 `stat` syscalls** per HTML render. No in-request memo. `post_process_img_dimensions` had its own `getimagesize` LRU (100 entries) but `replace_image_with_next_gen` did not share it.

**Measured impact:** 480× `file_exists` (0.02 ms local FS, 0.1 ms NFS/EFS) = **10–50 ms per cache-miss render**; amplified while images are `pending` (queue grows).

**Fix — PASS:**
- `includes/class-image-optimisation.php:117-134` new:
  ```php
  private static array $file_exists_cache = [];
  private const FILE_EXISTS_CACHE_LIMIT = 500;
  ```
- `includes/class-image-optimisation.php:820-844` `cached_file_exists(string $path): bool` — FIFO bounded (evict `array_shift` when ≥500), `file_exists` once per unique path per request. `clear_file_exists_cache(): void` for tests.
- `includes/class-image-optimisation.php:857-884` `get_cached_image_size(string $local_path): array|false` consolidates the `getimagesize` LRU that was copy-pasted between `post_process_img_dimensions:338-339` and `add_delay_load_img:1648-1649` (D-14), with `IMG_SIZE_CACHE_LIMIT` (existing 100) + now `FILE_EXISTS_CACHE_LIMIT 500`. Both `post_process_img_dimensions:338` and `add_delay_load_img:1648` and `add_delay_load_img`'s other call `1848` now go through `cached_file_exists` + `get_cached_image_size`.
- `includes/class-image-optimisation.php:912-938` `replace_image_with_next_gen` now:
  ```php
  if(!$this->cached_file_exists($avif_img_path)){ $source=Util::get_local_path($img_url); if($this->cached_file_exists($source)) $img_converter->add_img_into_queue($source,'avif'); }
  ... if(!$this->cached_file_exists($webp_img_path)) ...
  if((avif||both)&&$supports_avif && $this->cached_file_exists($avif_img_path)) return get_img_url(avif);
  if((webp||both)&&$supports_webp && $this->cached_file_exists($webp_img_path)) return get_img_url(webp);
  ```
  - All 5 `file_exists` sites in this method now memo'd.

- **Fallback regex branch** also fixed: `maybe_serve_next_gen_images:675-702` TagProcessor path already used `WP_HTML_Tag_Processor`; regex fallback for WP <6.4 now also handles `<source src/srcset>` and `<video poster>` (previously only `<img>`), via `cached_file_exists` indirectly through `replace_image_with_next_gen`.

**Before/after (80-image gallery, 240 srcset candidates, 80 unique avif + 80 unique webp paths):**
| Metric | Before | After | Delta |
|---|---|---|---|
| `file_exists` syscalls per cache-miss | **~480** (240×2) + source checks on miss | **≤160** unique paths (80 avif +80 webp) + bounded repeats hit memo | **-66–80%** |
| Wall | 10–50 ms (NFS worst) | **2–8 ms** | **-8–42 ms** |
| `getimagesize` LRU duplication | copy-pasted LRU in 2 methods | **single `get_cached_image_size`** | **-40 lines dup**, same hit rate |
| Cache bound | unbounded static `img_size_cache` (100) per method, separate | unified, bounded 500 (file_exists) + 100 (image size) FIFO | **bounded** |
| Stale negative cache | N/A | per-request only; if `avif` generated mid-request (Action Scheduler worker) not visible until next request — **acceptable** (per-request scope, next hit sees new file). Negative cache TTL is request lifetime only. | **no cross-request staleness** |

**Verdict: PASS — real, measurable.** Duplicate-stat reduction is the largest FE-CPU win in this patch. No regression; FIFO eviction order (`array_shift` preserves insertion order, not LRU) is slightly less optimal than LRU for `file_exists_cache` but at 500 cap with 160 uniques rarely evicts. `get_cached_image_size` retains LRU (`unset+re-insert` on hit) correctly.

---

## 3. New Issues / Regressions Introduced by This Patch

No new HIGH vuln (security review covers that), but **3 performance-relevant regressions/bottlenecks introduced** and **2 pre-existing bottlenecks left visible**:

| # | Severity | File:Line | Detail | Impact |
|---|---|---|---|---|
| **N-01** | **MEDIUM** | `includes/class-rum.php:323-343` `store_sample` + `flush_queue:360-368` | **Transient queue is not atomic + truncates under burst.** `get_transient→set_transient` race can lose 1 sample per concurrent pair (both read N, both append, one overwrites). Under burst 100 beacons in same second, queue could be appended 100× but `set_transient` last-writer-wins → many losses. Cap `QUEUE_MAX 100` does `array_slice(-100)` → **newest 100 kept, oldest dropped** (silent data loss). Flush does `delete_transient($queue_key)` before `update_option` → crash between deletes loses ≤100 samples. `wp_next_scheduled` check on 90% of beacons (the `else` branch) adds **1 DB query per non-threshold beacon**. | Sample loss + extra queries partially offset the 95% option-write win. Recommend: use `wp_cache_add`/`wp_cache_incr` or `transient` with `add` semantics, schedule cron only when queue transitions 0→1, and persist queue to `wp_options` with `autoload=no` + `INSERT` vs transient if object-cache not present. Low-traffic sites pay 1 extra query per beacon (~10k extra `SELECT cron` /day). |
| **N-02** | **LOW** | `includes/class-cache.php:1575-1579` `atomic_put_contents` + `1608-1621` | **Advisory lock not atomic.** `get_transient→set_transient` same race: 2 workers can both miss lock and both write. `move` fallback branch unreachable (`if(!$moved){ delete tmp; return put_contents(...) }`) defeats atomicity when `WP_Filesystem::move` fails (some FTP/ssh FS don't support rename). `wp_rand()` tmp name not `uniqid(pid+rand)` — 1/2^31 collision under 100 RPS burst (theoretical). | Lock reduces stampede 100→≤2 writers, but not 1. Torn file still prevented by atomic `move` path, so regression is only duplicate CPU (2× render vs 1×) not corruption. Acceptable, but a `wp_cache_add` lock would be stricter. |
| **N-03** | **LOW** | `includes/class-cache.php:1633-1660` Brotli branch | **Dead code `elseif` retained.** `if($use_brotli && function_exists('brotli_compress')) { try { brotli_compress } } elseif($use_brotli && extension_loaded('brotli')) { if(function_exists('brotli_compress')) { same } }` — second branch is unreachable when first condition is true; duplicates the same call. Flagged as `P-CPU-10 INFO` (agent-A09:105) before fix, still present. | Wastes a branch test only (~ns). Not a perf regression, but indicates the dedup task was not applied here. Collapse to single `if($use_brotli && function_exists(...))` or `extension_loaded` gate. `gzencode level 9` still used (1.5× slower than 6) — `P-CPU-10` suggested 6. |
| **N-04** | **INFO** | `includes/class-util.php:84-213` memo | **Memo never auto-warmed for `add_option` via `maybe_seed_settings` on fresh install.** `Activate::maybe_seed_settings:111` does `get_option('wppo_settings', null)` direct, then `add_option` — hook `add_option_wppo_settings` populates memo, but if `Main::__construct` runs before activation (race on first request), memo may be stale empty. `set_settings_cache` not called in `Rest::update_settings:470` after `update_option` — relies on `update_option_wppo_settings` hook (which fires). If `update_option` is `maybe_serialize` no-op (same value), hook does not fire → memo not refreshed (but value unchanged, so no staleness). | Edge, low impact. Could call `Util::set_settings_cache($merged)` explicitly after `update_option` in `Rest` to guarantee. |
| **N-05** | **INFO** | `includes/class-image-optimisation.php:117-134` | **`file_exists_cache` is `static` per class, not per request instance.** Two `Image_Optimisation` instances in same request share the cache (correct), but cache persists across multiple `Cache::process_buffer_only` calls in same request if plugin re-instantiates (e.g., `Cache` creates new `Image_Optimisation` per buffer). `static` + `clear_file_exists_cache` only in tests → no cross-request persistence, so correct. FIFO eviction (`array_shift` on associative array) relies on insertion order preserved (PHP 8.2 guarantees) — okay. | No regression. |

**Net assessment:** N-01 is the only performance-relevant new bottleneck worth a follow-up; N-02/N-03 are cosmetic/acceptable.

---

## 4. Remaining Opportunities (not fixed, still measurable)

These were **HIGH/MEDIUM** in agent-A09 but unchanged in this patch. Each is independent of the shipped fixes.

| ID | Severity | File:Line | Evidence (still present) | Measurable if fixed | Recommendation (cost) |
|---|---|---|---|---|---|
| **P-CPU-03** | **HIGH** | `includes/class-telemetry.php:682-744` `calculate_sizes` | `$get_size` does **sequential blocking `wp_remote_head($url, timeout 5, sslverify)`** per asset when `filesize` misses (off-site/CDN). `parse_resources` can yield 110 assets → **550 s worst wall** (110×5 s). No `Requests::request_multiple`/curl_multi. | Telemetry admin scan (triggered via `REST run_performance_scan`) can block PHP-FPM worker for tens of seconds on CDN-heavy pages; `wppo_web_vitals_rescan_cron` not affected (PageSpeed path). | Skip HEAD for remote assets (return 0) or batch via `wp_remote_request` multi or Action Scheduler async. **M — ~2 days**. |
| **P-CPU-06** | **MEDIUM** | `includes/class-used-css.php:251-390` `parse_css` | Char loop `while offset<length` + `brace_depth` nested scan on 500 KB combined CSS (~500k iterations + 2k `substr`) per used-CSS cache-miss. No `md5(combined_css)` transient memo. | 5–15 ms per cache-busted page; 200-page warm (`wppo_used_css_cron`) amortized but bursts on mtime drift still pay synchronously. | Cache purged result keyed by `md5(combined_css)` 1 h. **S — 1 day**. |
| **P-CPU-07** | **MEDIUM** | `includes/class-database-cleanup.php:209-320` `clean_revisions_advanced` | Outer `GROUP BY HAVING COUNT>keep_latest LIMIT 200` + inner `LIMIT 500 OFFSET 0/500/... ORDER BY post_date_gmt DESC` per parent. For 10k-revision parent, `OFFSET` scan is **O(offset)**. | Site with 5k parents ×20 revisions → ~5k parents + inner paging; worst 10–30 s per `clean_all`. | Rewrite inner pagination as **keyset** `WHERE post_parent=? AND post_date_gmt < last_seen ORDER BY date DESC LIMIT 500`. **M — 1 day**. |
| **P-WP-02** | **HIGH** | `includes/class-cron.php:274-339` `schedule_page_cron_jobs` + `schedule_cron_jobs:113-128` | `get_posts(fields=ids, posts_per_page 200, paged=ceil(...))` without `'no_found_rows'=>true, 'update_post_meta_cache'=>false, 'update_post_term_cache'=>false`. `wp_next_scheduled('wppo_generate_static_page',[$page_id])` **200× per batch** (`get_option('cron')` unserialize×200 ≈2–5 ms/batch). `schedule_cron_jobs` runs on `init` every request (6× `wp_next_scheduled`). | Cron tick cost 10–20 ms per `init` even when `preloadSitemap` off; `get_posts` does `SQL_CALC_FOUND_ROWS` + term hydration for 200 IDs unnecessarily. | Add `'no_found_rows'=>true, 'update_post_meta_cache'=>false, 'update_post_term_cache'=>false, 'suppress_filters'=>true` + debounce `schedule_cron_jobs` behind 5-min transient. **S — 2 h**. |
| **P-WP-03** | **HIGH (admin)** | `includes/class-main.php:1444-1462` `admin_enqueue_scripts` | `Cache::get_cache_size()` → `calculate_directory_size` recursive `dirlist` walk of `cache/wppo/{domain}` (5k pages ×1 file+.gz) + `Util::get_js_css_minified_file` walk `cache/wppo/min/{id}/js+css`. Both gated by `wppo_cache_size` 15-min transient, but first admin hit after expiry does **200–800 ms FS walk** blocking `wp-admin` TTFB (guarded by `get_current_screen` early return, so only on `toplevel_page_performance-optimisation`). | Mitigated but still worst-case admin. | Warm via `wppo_after_cache_clear` cron or store `cached_pages` count alongside size in single option to avoid double walk. **S — 2 h**. |
| **P-WP-05** | **MEDIUM** | `includes/class-database-cleanup.php:821-988` `get_counts` | 9× `COUNT(*)` (posts×3, comments×2, transients JOIN `CONCAT+SUBSTRING` not index-friendly, orphan `LEFT JOIN IS NULL` full scan). Cached 5 min via `wp_cache_get_salted` but Dashboard `fetchDbCounts` on mount still hits 100–800 ms when uncached on large DB (500k posts, 2M postmeta). | Uncached `get_counts` is slowest Dashboard path. | Keep salt-cache HOUR + lazy per-type fetch vs 9-in-one. **M — 1 day**. |
| **P-CPU-05** | **MEDIUM** | `includes/class-image-optimisation.php:1256-1339` `set_loading_optimization_attributes` | `wp_get_loading_optimization_attributes('img', $tag_attr, ['context'=>'wp-html-tag-processor'])` called **per `<img>`** (80× filter chain). Already sets `fetchpriority=low` hardcoded for lazy images (`1414-1421`) but still invokes filter for excluded images. | 80× `apply_filters('wp_get_loading_optimization_attributes')` per page. | Skip for lazy image path (already hardcoded) or batch. **S — 2 h**. |
| **P-CPU-10** | **INFO** | `includes/class-cache.php:1612-1622` | Duplicate Brotli branch + `gzencode 9` (1.5× slower than 6). | ~1 ms per HTML cache-miss. | Collapse `elseif` + switch `gzencode level 6`. **S — 30 min**. |
| **P-WP-01 residual** | **MEDIUM** | 32 sites listed in §2.1 | Long-tail `get_option('wppo_settings')` still direct in LS/CDN/LLMS/CriticalCSS/Abilities. | Extra 1 ms on LS purge + admin `system_info`. | Migrate remaining 32 sites to `Util::get_settings()`. **S — 1 h**. |

**Note:** `P-CPU-01/02/04`Fixes already reduce the aggregate **hot-path** cost from **~20–70 ms** (6× deserialization 3–6 ms + 480× stat 10–50 ms + 120× budget 1–2 ms + classify 0.5–1 ms + stampede 5 MB burst) to **~8–15 ms** per cache-miss (partially) and **~500 writes/day → 500 vs 10k** for RUM. The remaining opportunities above суммарно account for another **10–60 s** (telemetry HEADs) + **10–30 s** (revisions OFFSET) in edge cases, but are not on the critical frontend `GET /` path except `P-WP-02`.

---

## 5. Complexity Cost & Duplication

- **Lines added:** ~+1158 added / ~676 removed per `git diff HEAD` (46 files) — many are docblocks + guards + comments (`Duplication note D-13/D-14` etc.). Net PHP logic ~+300 lines.
- **Duplication reduced:**
  - `Util::ALLOWED_SETTINGS_KEYS` + `ALLOWED_SETTINGS_TABS` single source (`class-util.php:43-64`) replaces 3-way allowlist drift (`Rest::update_settings` + `import_settings` + `CLI` + JS `PLUGIN_SETTING`). Verified `Rest:451,731` + `Database_Cleanup::CLEANUP_METHOD_MAP:81` + `delete_in_batches:138` centralize 5× `clean_*` loops — **-~250 lines dup**, correct.
  - `Cache::should_bypass_for_litespeed:380` centralizes `litespeed_can_optm` gate that was copy-pasted `combine_css+minify_buffer+maybe_apply_cdn` — **DRY**, good.
  - `Image_Optimisation::cached_file_exists` + `get_cached_image_size` consolidate `file_exists` + `getimagesize` LRUs (`D-14`) — **-40 lines dup**, correct.
  - `combine_css:459-493` single `get_combined_handles` vs triple loops — **DRY**, good.
- **Complexity added:**
  - `Util::get_settings` + `ensure_settings_cache_hook` (static `$hooked`) + 3 hooks + `on_settings_update/add` + `clear/reset_all_caches` — adds 1 static flag + 3 hooks per request (first call only). Well-isolated, testable (`RumTest`, `CronSitemapTest` added `reset_all_caches` calls). Acceptable.
  - `RUM` queue + `flush_queue` + `QUEUE_MAX/FLUSH_THRESHOLD` + `wppo_rum_flush` cron adds state machine (transient queue + 30 s lock + scheduled event). Harder to reason about than single `update_option`, but bounded and documented.
  - `Cache::atomic_put_contents` + transient lock `try/finally` adds 1 extra `rename` syscall per file.
- **Net verdict:** Duplication down, complexity up modestly, **justified** — each perf win required the added state. No dead code introduced except `P-CPU-10` `elseif` (pre-existing).

---

## 6. Missed Optimizations (worth noting, not blocking)

- **Not yet collapsed:** `P-WP-01` long-tail 32 sites, `P-WP-02` `get_posts` tuning, `P-CPU-03` HEAD batching, `P-CPU-06` `md5(combined_css)` memo, `P-CPU-07` keyset pagination, `P-CPU-10` `gzencode 6`.
- **Micro:** `Util::generate_preload_link` still does `wp_kses` round-trip per preload (`P-WP-09 LOW` — 10× `wp_kses` ≈2–5 ms) — could be `printf` with already-escaped values.
- **Cron `get_sitemap_urls:474-513`** now correctly gates on `200 !== wp_remote_retrieve_response_code` (added `502` continue) — good, but still 15 s wall-clock budget with 5 s `wp_remote_get`×50 indexes = could overlap deadline; not a regression.

---

## 7. Verdict

**PASS — with low residual and deferred opportunities.**

| Question | Answer | Evidence |
|---|---|---|
| **Was finding really fixed?** | **Yes for the 5 hot findings; partial for P-WP-01 long-tail.** | `P-WP-01`: 6→1 deserializations on frontend hot path (`class-cache.php:261`, `class-main.php:251`, `class-cron.php:115` all memo'd; 32 residual sites remain). `P-CACHE-03`: torn reads eliminated + stampede 100→≤2 writers (`class-cache.php:1569,1608`). `P-DB-01`: 10k→500 option writes/day (-95%) via transient queue (`class-rum.php:317,357`). `P-CPU-01/02`: triple classify → single + 120→60 sims (`class-cache.php:442,719`). `P-CPU-04`: 480→≤160 `file_exists` per gallery (`class-image-optimisation.php:820,912`). All verified by `Read` + `grep`. |
| **Measurable improvement?** | **Yes, aggregate ~15–55 ms per cache-miss + 2 GB→100 MB option write I/O/day.** | Modelled in §2 tables: `-3–5 ms` (deserialization) + `-8–42 ms` (stat memo) + `-0.8–1.5 ms` (classify/memo) + `-99×` burst CPU for stampede. RUM dominates persistent win. |
| **Regressions?** | **Low — one medium (N-01 queue race + truncation + cron chatter), two low (advisory lock race, dead `elseif`), no corruption.** | N-01: last-writer-wins sample loss + 100-cap truncation + `wp_next_scheduled` per beacon. N-02: transient lock not CAS but atomic `move` still prevents torn file. No high regression. |
| **New bottlenecks?** | **Only N-01 (transient queue) is a new contention point; not worse than original `wp_options` hotspot.** | Queue lives in object-cache if present (fast), else `wp_options` transient row (~1 KB vs 200 KB). Bounded 100, flushed ≤300 s. |
| **Complexity cost?** | **Justified — dup down, state up modestly.** | +~300 net logic lines, well-documented, single-source constants, test helpers added. |
| **Missed optimizations?** | **7 deferred (P-CPU-03/06/07, P-WP-02/03/05, P-CPU-10 + P-WP-01 residual) — none on critical burst path except P-WP-02.** | See §4 table; each S/M effort, independently shippable. |

**Recommendation:** Ship. Follow-up sprint (S) should address N-01 (atomic queue or `wp_cache_add` + schedule-on-transition) and `P-WP-02` `get_posts` tuning (`no_found_rows` etc.) as they are the next measurable wins after this patch. `P-CPU-03` HEAD batching and `P-CPU-07` keyset pagination can be deferred to Tier-2. No revert needed.

---

*Evidence produced by independent re-read of `git diff HEAD` + `git diff origin/master` and direct file contents; no reliance on implementation-agent self-report. All `file:line` refs point to `includes/class-*.php` at `HEAD` (unstaged).*

