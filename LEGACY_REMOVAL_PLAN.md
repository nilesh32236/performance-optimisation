# Legacy Code Removal Plan

## Analysis Summary

After analyzing the Performance Optimisation plugin codebase, I've identified the following legacy components that need to be completely removed and rewritten:

## Legacy Files to Remove

### 1. Legacy Directory Structure
```
includes/Legacy/
├── Cache.php                 - Legacy cache wrapper
├── ClassAliases.php         - Backward compatibility aliases  
├── ImgConverter.php         - Legacy image converter wrapper
├── Log.php                  - Legacy logging wrapper
├── MainLegacy.php           - Legacy main class wrapper
├── Util.php                 - Legacy utility wrapper
└── Minify/
    ├── CSS.php              - Legacy CSS minifier wrapper
    ├── HTML.php             - Legacy HTML minifier wrapper
    └── JS.php               - Legacy JS minifier wrapper
```

### 2. Legacy Loading in Main Plugin File
- `performance-optimisation.php` line 47: `require_once WPPO_PLUGIN_PATH . 'includes/Legacy/ClassAliases.php';`

## Dependencies Analysis

### Current State
✅ **Good News**: No active usage of legacy classes found in the codebase
- No imports of `PerformanceOptimisation\Legacy` namespace
- No usage of old `PerformanceOptimise\Inc` namespace  
- No direct calls to legacy class methods
- Legacy classes are only wrapper/proxy classes

### Legacy Class Functionality Mapping

#### 1. Legacy\Cache → Services\CacheService
**Current Implementation**: Wrapper that forwards to CacheService
**Functions to Rewrite**:
- `clear_cache()` → `CacheService::clearCache()`
- `clear_page_cache()` → `CacheService::clearCache('page')`
- `clear_object_cache()` → Direct `wp_cache_flush()`
- `clear_minified_cache()` → `CacheService::clearCache('minified')`
- `get_cache_size()` → `CacheService::getCacheSize()`

#### 2. Legacy\Log → Utils\LoggingUtil  
**Current Implementation**: Wrapper that forwards to LoggingUtil
**Functions to Rewrite**:
- `__construct($message)` → `LoggingUtil::info($message)`
- `get_recent_activities()` → `LoggingUtil::getRecentLogs()`

#### 3. Legacy\Util → Utils\FileSystemUtil + Utils\ValidationUtil
**Current Implementation**: Wrapper that forwards to modern utilities
**Functions to Rewrite**:
- `init_filesystem()` → `FileSystemUtil::getFilesystem()`
- `prepare_cache_dir()` → `FileSystemUtil::createDirectory()`
- `get_local_path()` → `FileSystemUtil::urlToPath()`
- `process_urls()` → `ValidationUtil::processUrls()` (needs creation)
- `is_already_minified()` → Move to OptimizationService
- `generate_preload_link()` → Move to new ResourceHintUtil
- `generate_resource_hint_link()` → Move to new ResourceHintUtil
- `get_image_mime_type()` → `ImageService::getImageMimeType()`

#### 4. Legacy\ImgConverter → Services\ImageService
**Current Implementation**: Wrapper that forwards to ImageService
**Functions to Rewrite**:
- `process_batch()` → `ImageService::processBatch()`
- `get_conversion_stats()` → `ImageService::getConversionStats()`
- `reset_conversion_data()` → `ImageService::resetConversionData()`

#### 5. Legacy\Minify\* → Services\OptimizationService
**Current Implementation**: Wrappers that forward to OptimizationService
**Functions to Rewrite**:
- `CSS::minify_all_css()` → `OptimizationService::optimizeAssets()`
- `JS::minify_all_js()` → `OptimizationService::optimizeAssets()`
- `HTML::minify_cached_pages()` → `OptimizationService::optimizeAssets()`

## Missing Utility Classes to Create

### 1. ValidationUtil (Partially exists, needs enhancement)
**Location**: `includes/Utils/ValidationUtil.php`
**Missing Methods**:
- `processUrls(string $input): array` - Process and validate URL lists
- `sanitizeFilePath(string $path): string` - Sanitize file paths
- `validateImageFormat(string $format): bool` - Validate image formats
- `sanitizeSettings(array $settings): array` - Sanitize settings arrays

### 2. ResourceHintUtil (New utility class needed)
**Location**: `includes/Utils/ResourceHintUtil.php`
**Methods to Create**:
- `generatePreloadLink(string $url, string $type): string`
- `generateResourceHint(string $url, string $rel): string`
- `generateDnsPrefetch(string $domain): string`
- `generatePreconnect(string $url): string`

### 3. CacheUtil (New utility class needed)
**Location**: `includes/Utils/CacheUtil.php`
**Methods to Create**:
- `clearCache(string $type): bool`
- `invalidateCache(string $path): bool`
- `getCacheSize(string $type): string`
- `getCacheStats(): array`

## Removal Strategy

### Phase 1: Create Missing Utilities
1. ✅ ValidationUtil enhancement
2. ✅ ResourceHintUtil creation
3. ✅ CacheUtil creation

### Phase 2: Update Services
1. ✅ Update OptimizationService to include minification check methods
2. ✅ Ensure all ImageService methods are properly implemented
3. ✅ Verify CacheService has all required functionality

### Phase 3: Remove Legacy Loading
1. ✅ Remove `require_once` for ClassAliases.php from main plugin file
2. ✅ Update any remaining references

### Phase 4: Delete Legacy Files
1. ✅ Delete entire `includes/Legacy/` directory
2. ✅ Verify no broken references remain

## Risk Assessment

### Low Risk ✅
- **No Active Usage**: Legacy classes are not actively used in the codebase
- **Wrapper Pattern**: Legacy classes only forward calls to modern implementations
- **Modern Services Exist**: All functionality already exists in modern services

### Mitigation Strategies
1. **Comprehensive Testing**: Test all functionality after removal
2. **Gradual Removal**: Remove files one by one and test
3. **Backup Strategy**: Keep backup of legacy files during transition

## Implementation Order

1. ✅ **Task 2.1-2.6**: Create enhanced utility classes
2. ✅ **Task 3**: Remove legacy class loading from main plugin file  
3. ✅ **Task 3**: Delete legacy directory and files
4. ✅ **Testing**: Verify all functionality works correctly

## COMPLETED ✅

**Legacy Removal Status**: COMPLETE
- All 9 legacy files removed (500+ lines of wrapper code eliminated)
- Legacy class loading removed from main plugin file
- No remaining references to legacy classes found
- Modern utility classes fully implemented and ready for use

## Expected Benefits

### Code Quality
- ✅ Eliminate 9 legacy files (~500+ lines of wrapper code)
- ✅ Remove backward compatibility burden
- ✅ Cleaner, more maintainable architecture

### Performance  
- ✅ Reduce autoloading overhead
- ✅ Eliminate proxy/wrapper call overhead
- ✅ Smaller plugin footprint

### Maintenance
- ✅ Single source of truth for each functionality
- ✅ Easier debugging and testing
- ✅ Modern PHP practices throughout

## Conclusion

The legacy removal is **LOW RISK** and **HIGH REWARD**. All legacy classes are simple wrappers around modern implementations, and there's no active usage in the codebase. The removal will significantly improve code quality and maintainability while reducing the plugin's complexity.