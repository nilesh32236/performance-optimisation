# SAFETY-RECOVERY.md — Safe at Every Step

## Safe Defaults `class-main.php:170-265`
All risky OFF: `minifyJS false:193`, `minifyCSS false:194`, `deferJS false:195`, `delayJS false:196`, `combineCSS false:197`, `removeUnusedCSS false:179`, `criticalCSS false:180`, `hostGoogleFontsLocally false:181`, `preloadSitemap false:220`, `lazyLoadImages false:223`, `rum_enabled false:238`, `llms_txt false:247`, `ai_adaptive false` — correct. Only `removeHTMLComments true:205`, `lazyLoadNative true:224`, `blockAssetsOnDemand auto ON 6.9:185` inert.

## Warnings (consequence not implementation)
- Before: "This changes X." After: "This can make pages load faster, but may affect interactive elements on some sites. Recommended: test cart. [Learn more]" — benefit+ risk+ recommendation §4.
- Existing warnings `FileOptimization.js:399-409` FOUC, `1124-1137` Delay break, `1166-1184` Woo verify, `1563-1581` FTP+LS restart, `1608-1623` Nginx — convert to above format + `Tooltip` `common/Tooltip.js:30`.

## Validation
- Textarea "one handle per line" `FileOptimization.js:411-575` → picker autocomplete from `Asset_Manager:245`.
- `delayJSIdleTimeout 3000` `54` → slider 1-10s with preview not raw ms.
- `dbRevMaxAge 30/KeepLatest 5` `DatabaseCleanup:376` math → "Older than 30 days but keep 5 latest" example.

## Conflict Detection
- Today only LS banner `Dashboard:686-693` `FileOptimization:1267` `LiteSpeed_Integration:1343` vs missing WP Rocket/FlyingPress/Perfmatters `SystemInfo:633` only cache constants.
- New: `is_plugin_active` check for WP Rocket, FlyingPress, SG Optimizer, Autoptimize — banner "Another plugin handles minify — leave off. Why? Details → Advanced override" `CONFLICT-DETECTION.md`.

## Rollback / Reset / Disable
- Before any `update_settings` `Rest.php:464-518` snapshot `wppo_settings_snapshot_{time}` transient 10m + Tools "Undo last change" + versioned export `PluginSetting.js:301` `REDACTED` `Rest.php:734-800` validate `ALLOWED_SETTINGS_KEYS:750`.
- Per-type rollback: cache `Clear All` `Rest.php:371` traversal guard + `Cache::clear_cache:2087` + admin bar `src/main.js` with 403 nonce refresh; DB "Optimize Everything Now" `DatabaseCleanup:468-487` needs sample before `ConfirmDialog:618`; Object Cache Disable `ObjectCache:858-898` red.
- Restore defaults: single "Reset to Recommended" `class-main.php:857 migrate_block_assets` style + "Disable all optimizations" that sets all `false` + clears `min/` `wp-content/cache/wppo/min/1`.
- Emergency: `?wppo_safe=1` kill-switch cookie 10min checked `setup_hooks:489` via `Util::is_safe_mode()` (new) — frontend `DONOTCACHEPAGE` style bypass `class-cache.php:980` `wppo_inline_combined_css` already skips when CDN; CLI `wp wppo reset --safe` `class-wppo-cli-command.php:1093`.

## Recovery
- `.htaccess` lockout `class-htaccess-handler.php` needs `FS_CHMOD_FILE 755` `class-main.php:989` — message "FTP permission" not `WP_Filesystem error`.
- 681 failed queue `PageSpeedPanel:479` → "Missing API key — add in Manage→API Keys `PluginSetting:108`".

## Emergency Mode
- Admin bar "Disable optimizations 10 min" cookie + `setup_hooks` bypass `Buffer::process_buffer_only` `class-cache.php`, plus CLI.

## Errors Translated
- Every screen `SAFETY-RECOVERY.md` states Loading/Empty/Success/Warning/Error/Disabled/Unsupported/Conflict/Partial defined — no raw `WP_Error`.
